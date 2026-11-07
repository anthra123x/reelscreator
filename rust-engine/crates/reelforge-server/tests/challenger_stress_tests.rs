use axum::body::Body;
use axum::http::{Request, StatusCode};
use chrono::Duration;
use reelforge_core::{JobState, JobStatusRecord};
use reelforge_downloader::generate_synthetic_mp4;
use reelforge_server::{create_router, AppState};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use tempfile::tempdir;
use tower::ServiceExt;
use uuid::Uuid;

#[tokio::test]
async fn test_stress_appstate_prune_ttl_and_capacity_eviction() {
    let state = AppState::new(1);
    let now = chrono::Utc::now();

    let mut expired_ids = Vec::new();
    let mut recent_terminal_ids = Vec::new();
    let mut active_ids = Vec::new();

    {
        let mut jobs = state.jobs.write().await;

        // 1. 100 expired terminal jobs (2 hours old)
        for i in 0..100 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = if i % 2 == 0 {
                JobState::Completed
            } else {
                JobState::Failed
            };
            rec.updated_at = now - Duration::seconds(7200 + i as i64);
            jobs.insert(id, rec);
            expired_ids.push(id);
        }

        // 2. 100 recent terminal jobs (5 minutes old to 10 minutes old)
        for i in 0..100 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = if i % 2 == 0 {
                JobState::Completed
            } else {
                JobState::Failed
            };
            rec.updated_at = now - Duration::seconds(300 + (100 - i) as i64);
            jobs.insert(id, rec);
            recent_terminal_ids.push(id);
        }

        // 3. 50 active jobs (3 hours old - should NOT be pruned despite age or capacity)
        for i in 0..50 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = match i % 3 {
                0 => JobState::Queued,
                1 => JobState::Downloading,
                _ => JobState::Cutting,
            };
            rec.updated_at = now - Duration::seconds(10800);
            jobs.insert(id, rec);
            active_ids.push(id);
        }
    }

    assert_eq!(state.jobs.read().await.len(), 250);

    // Prune with TTL = 3600s (1h) and max_jobs = 70
    // Expected behavior:
    // 1. All 100 expired terminal jobs (> 3600s) are evicted.
    // 2. Remaining jobs: 100 recent terminal + 50 active = 150 jobs.
    // 3. Since 150 > max_jobs (70), excess = 80 jobs.
    // 4. Oldest 80 of the 100 recent terminal jobs are evicted.
    // 5. Remaining: 20 newest terminal + 50 active = 70 total.
    // 6. ALL 50 active jobs MUST BE INTACT.
    state.prune_jobs_with_policy(3600, 70).await;

    let jobs = state.jobs.read().await;
    assert_eq!(jobs.len(), 70, "Final job count must equal max_jobs");

    // Verify all expired jobs were purged
    for id in &expired_ids {
        assert!(!jobs.contains_key(id), "Expired job {:?} must be evicted", id);
    }

    // Verify all active jobs are strictly preserved
    for id in &active_ids {
        assert!(
            jobs.contains_key(id),
            "Active job {:?} must NEVER be evicted",
            id
        );
    }

    // Verify that the remaining terminal jobs are the newest ones
    let remaining_terminal_count = jobs.values().filter(|r| r.status.is_terminal()).count();
    assert_eq!(remaining_terminal_count, 20);
}

#[tokio::test]
async fn test_stress_appstate_prune_all_active_never_evicted() {
    let state = AppState::new(1);
    let now = chrono::Utc::now();

    {
        let mut jobs = state.jobs.write().await;
        for i in 0..50 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = if i % 2 == 0 {
                JobState::Queued
            } else {
                JobState::Cutting
            };
            // 24 hours old
            rec.updated_at = now - Duration::seconds(86400);
            jobs.insert(id, rec);
        }
    }

    // Request max capacity of 5 and TTL of 1 second
    state.prune_jobs_with_policy(1, 5).await;

    let jobs = state.jobs.read().await;
    assert_eq!(
        jobs.len(),
        50,
        "Active jobs must never be evicted even if older than TTL and exceeding max_jobs"
    );
}

#[tokio::test]
async fn test_stress_appstate_concurrent_operations_no_deadlock() {
    let state = AppState::new(2);
    let running = Arc::new(AtomicBool::new(true));

    // Spawn 8 writer tasks inserting jobs continuously
    let mut handles = Vec::new();
    for thread_idx in 0..8 {
        let state_clone = state.clone();
        let running_clone = running.clone();
        handles.push(tokio::spawn(async move {
            let mut count = 0;
            while running_clone.load(Ordering::Relaxed) && count < 200 {
                let id = Uuid::new_v4();
                let mut rec = JobStatusRecord::new(id);
                rec.status = match (thread_idx + count) % 4 {
                    0 => JobState::Completed,
                    1 => JobState::Failed,
                    2 => JobState::Cancelled,
                    _ => JobState::Cutting,
                };
                {
                    let mut jobs = state_clone.jobs.write().await;
                    jobs.insert(id, rec);
                }
                count += 1;
                tokio::task::yield_now().await;
            }
        }));
    }

    // Concurrently run prune_jobs repeatedly
    let state_pruner = state.clone();
    let pruner_handle = tokio::spawn(async move {
        for _ in 0..50 {
            state_pruner.prune_jobs_with_policy(0, 50).await;
            tokio::time::sleep(tokio::time::Duration::from_millis(5)).await;
        }
    });

    for h in handles {
        h.await.unwrap();
    }
    running.store(false, Ordering::Relaxed);
    pruner_handle.await.unwrap();

    let final_jobs = state.jobs.read().await;
    println!(
        "Final jobs count after concurrent stress test: {}",
        final_jobs.len()
    );
}

#[tokio::test]
async fn test_stress_direct_cut_handler_blocks_until_semaphore_released() {
    let tmp = tempdir().unwrap();
    let sample_video = tmp.path().join("sample_direct.mp4");
    generate_synthetic_mp4(&sample_video, 4, 640, 360)
        .await
        .expect("Synthetic video generation must succeed");

    let out_dir = tmp.path().join("clips_direct");

    let state = AppState::new(1);
    assert_eq!(state.available_permits(), 1);

    // 1. Manually acquire the sole concurrency permit to saturate capacity
    let permit = state
        .semaphore
        .clone()
        .acquire_owned()
        .await
        .expect("Must acquire initial permit");
    assert_eq!(state.available_permits(), 0);

    let app = create_router(state.clone());

    let payload = serde_json::json!({
        "source_path": sample_video.to_str().unwrap(),
        "output_dir": out_dir.to_str().unwrap(),
        "ranges": [
            { "start": 0.0, "end": 2.0 }
        ]
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/direct-cut")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    // Spawn the direct-cut request in a background task
    let mut direct_cut_task = tokio::spawn(async move {
        app.oneshot(req).await
    });

    // Verify: While permit is held, the task MUST NOT finish (it is blocked on semaphore)
    tokio::select! {
        _ = &mut direct_cut_task => {
            panic!("direct_cut_handler proceeded without waiting for the semaphore permit!");
        }
        _ = tokio::time::sleep(tokio::time::Duration::from_millis(400)) => {
            // Success: task is still blocked waiting on permit
        }
    }

    assert_eq!(
        state.available_permits(),
        0,
        "Permit must still be saturated before release"
    );

    // 2. Now release the permit
    drop(permit);

    // 3. The direct_cut task should now resume, acquire permit, and complete successfully
    let res = tokio::time::timeout(tokio::time::Duration::from_secs(10), direct_cut_task)
        .await
        .expect("Task must complete after permit is released")
        .expect("Join error")
        .expect("Oneshot call failed");

    assert_eq!(
        res.status(),
        StatusCode::OK,
        "Direct cut must succeed once permit is freed"
    );

    // Verify permit is released back to the state after handler finishes
    assert_eq!(
        state.available_permits(),
        1,
        "Permit must be released after direct_cut_handler finishes"
    );
}


#[tokio::test]
async fn test_stress_appstate_prune_zero_capacity_and_boundary_cases() {
    let state = AppState::new(1);
    let now = chrono::Utc::now();

    {
        let mut jobs = state.jobs.write().await;
        // 10 terminal jobs with identical updated_at
        for _ in 0..10 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = JobState::Completed;
            rec.updated_at = now;
            jobs.insert(id, rec);
        }
        // 5 active jobs
        for _ in 0..5 {
            let id = Uuid::new_v4();
            let mut rec = JobStatusRecord::new(id);
            rec.status = JobState::Cutting;
            rec.updated_at = now;
            jobs.insert(id, rec);
        }
    }

    // Prune with max_jobs = 0 (evicts all terminal jobs)
    state.prune_jobs_with_policy(3600, 0).await;

    let jobs = state.jobs.read().await;
    assert_eq!(jobs.len(), 5, "Only active jobs should remain when max_jobs is 0");
    for (_, rec) in jobs.iter() {
        assert!(!rec.status.is_terminal());
    }
}

#[tokio::test]
async fn test_stress_direct_cut_serialized_execution_under_saturation() {
    let tmp = tempdir().unwrap();
    let sample_video = tmp.path().join("sample_direct_serial.mp4");
    generate_synthetic_mp4(&sample_video, 3, 640, 360)
        .await
        .expect("Synthetic video generation must succeed");

    let state = AppState::new(1);
    let app = create_router(state.clone());

    let mut handles = Vec::new();
    for i in 0..3 {
        let app_clone = app.clone();
        let source_path = sample_video.to_str().unwrap().to_string();
        let out_dir = tmp.path().join(format!("out_serial_{}", i));

        handles.push(tokio::spawn(async move {
            let payload = serde_json::json!({
                "source_path": source_path,
                "output_dir": out_dir.to_str().unwrap(),
                "ranges": [{ "start": 0.0, "end": 1.0 }]
            });

            let req = Request::builder()
                .method("POST")
                .uri("/api/v1/direct-cut")
                .header("Content-Type", "application/json")
                .body(Body::from(payload.to_string()))
                .unwrap();

            app_clone.oneshot(req).await.unwrap()
        }));
    }

    for h in handles {
        let resp = h.await.unwrap();
        assert_eq!(
            resp.status(),
            StatusCode::OK,
            "Serialized direct-cut under queue saturation must complete with 200"
        );
    }

    assert_eq!(
        state.available_permits(),
        1,
        "Permit must return to 1 after all serialized tasks complete"
    );
}
