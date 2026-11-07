use axum::body::Body;
use axum::http::{Request, StatusCode};
use reelforge_core::{
    AspectRatio, CutStrategy, Dimensions, TimeRange, VerticalMode, VideoTask,
};
use reelforge_cutter::VideoCutter;
use reelforge_server::{create_router, AppState};
use std::path::PathBuf;
use tempfile::tempdir;
use tower::ServiceExt;
use uuid::Uuid;

#[tokio::test]
async fn test_adversarial_malformed_json_returns_rejection() {
    let state = AppState::new(1);
    let app = create_router(state);

    let bad_json_req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(r#"{"job_id": "not-a-valid-json"#))
        .unwrap();

    let response = app.oneshot(bad_json_req).await.unwrap();
    assert!(
        response.status() == StatusCode::BAD_REQUEST
            || response.status() == StatusCode::UNPROCESSABLE_ENTITY,
        "Malformed JSON must be rejected with 4xx, got {}",
        response.status()
    );
}

#[tokio::test]
async fn test_adversarial_invalid_uuid_in_path_returns_bad_request() {
    let state = AppState::new(1);
    let app = create_router(state);

    let req = Request::builder()
        .uri("/api/v1/jobs/not-a-valid-uuid-1234")
        .body(Body::empty())
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::BAD_REQUEST,
        "Invalid UUID in path must return 400 Bad Request"
    );
}

#[tokio::test]
async fn test_adversarial_negative_interval_rejected() {
    let state = AppState::new(1);
    let app = create_router(state);

    let payload = serde_json::json!({
        "source_url": "test://fixture",
        "interval_seconds": -15.0
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::BAD_REQUEST,
        "Negative interval must be rejected"
    );
}

#[tokio::test]
async fn test_adversarial_zero_interval_rejected() {
    let state = AppState::new(1);
    let app = create_router(state);

    let payload = serde_json::json!({
        "source_url": "test://fixture",
        "interval_seconds": 0.0
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::BAD_REQUEST,
        "Zero interval must be rejected"
    );
}

#[tokio::test]
async fn test_adversarial_missing_both_sources_rejected() {
    let state = AppState::new(1);
    let app = create_router(state);

    let payload = serde_json::json!({
        "strategy": "fixed_interval"
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::BAD_REQUEST,
        "Missing both source_url and local_video_path must be rejected"
    );
}

#[tokio::test]
async fn test_adversarial_inverted_custom_ranges_rejected() {
    let state = AppState::new(1);
    let app = create_router(state);

    let payload = serde_json::json!({
        "source_url": "test://fixture",
        "strategy": "manual_timestamps",
        "custom_ranges": [
            {"start": 30.0, "end": 10.0}
        ]
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::BAD_REQUEST,
        "Inverted custom range [30, 10] must be rejected"
    );
}

#[tokio::test]
async fn test_adversarial_cancel_nonexistent_job_returns_404() {
    let state = AppState::new(1);
    let app = create_router(state);

    let missing_id = Uuid::new_v4();
    let req = Request::builder()
        .method("POST")
        .uri(format!("/api/v1/jobs/{}/cancel", missing_id))
        .body(Body::empty())
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(
        response.status(),
        StatusCode::NOT_FOUND,
        "Cancelling non-existent job must return 404"
    );
}

#[tokio::test]
async fn test_adversarial_double_cancellation_rejected() {
    let state = AppState::new(1);
    let app = create_router(state.clone());

    let job_id = Uuid::new_v4();
    let payload = serde_json::json!({
        "job_id": job_id,
        "source_url": "test://fixture_sample",
        "interval_seconds": 5.0
    });

    // 1. Submit job
    let post_req = Request::builder()
        .method("POST")
        .uri("/api/v1/jobs")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();
    let resp1 = app.clone().oneshot(post_req).await.unwrap();
    assert_eq!(resp1.status(), StatusCode::ACCEPTED);

    // 2. First cancel
    let cancel1 = Request::builder()
        .method("POST")
        .uri(format!("/api/v1/jobs/{}/cancel", job_id))
        .body(Body::empty())
        .unwrap();
    let resp2 = app.clone().oneshot(cancel1).await.unwrap();
    assert_eq!(resp2.status(), StatusCode::OK);

    // 3. Second cancel must fail with 400 Bad Request (terminal state)
    let cancel2 = Request::builder()
        .method("POST")
        .uri(format!("/api/v1/jobs/{}/cancel", job_id))
        .body(Body::empty())
        .unwrap();
    let resp3 = app.oneshot(cancel2).await.unwrap();
    assert_eq!(
        resp3.status(),
        StatusCode::BAD_REQUEST,
        "Cancelling already cancelled job must return 400"
    );
}

#[tokio::test]
async fn test_adversarial_media_zero_byte_direct_cut_rejection() {
    let state = AppState::new(1);
    let app = create_router(state);

    let tmp = tempdir().unwrap();
    let zero_file = tmp.path().join("empty.mp4");
    tokio::fs::write(&zero_file, b"").await.unwrap();

    let payload = serde_json::json!({
        "source_path": zero_file.to_string_lossy(),
        "output_dir": tmp.path().join("out").to_string_lossy(),
        "ranges": [{"start": 0.0, "end": 2.0}]
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/direct-cut")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert!(
        response.status() == StatusCode::INTERNAL_SERVER_ERROR
            || response.status() == StatusCode::BAD_REQUEST,
        "Zero-byte direct cut must fail gracefully, got {}",
        response.status()
    );
}

#[tokio::test]
async fn test_adversarial_media_corrupted_payload_handled_safely() {
    let tmp = tempdir().unwrap();
    let corrupt_file = tmp.path().join("corrupt.mp4");
    let garbage: Vec<u8> = (0..50_000).map(|i| (i * 37 % 256) as u8).collect();
    tokio::fs::write(&corrupt_file, &garbage).await.unwrap();

    let out_dir = tmp.path().join("out");
    let clip_path = out_dir.join("clip.mp4");
    let thumb_path = out_dir.join("thumb.jpg");

    let result = VideoCutter::slice_clip(
        &corrupt_file,
        &clip_path,
        &thumb_path,
        &TimeRange {
            start: 0.0,
            end: 2.0,
        },
        Dimensions {
            width: 1080,
            height: 1920,
        },
        VerticalMode::BlurPadding,
        None,
    )
    .await;

    assert!(
        result.is_err(),
        "Corrupted video payload must produce an Err result"
    );
}

#[tokio::test]
async fn test_adversarial_video_without_audio_stream_cuts_safely() {
    let tmp = tempdir().unwrap();
    let video_no_audio = tmp.path().join("video_no_audio.mp4");

    let status = tokio::process::Command::new("ffmpeg")
        .args([
            "-y",
            "-threads",
            "1",
            "-f",
            "lavfi",
            "-i",
            "testsrc=duration=3:size=640x360:rate=30",
            "-c:v",
            "libx264",
            "-pix_fmt",
            "yuv420p",
            video_no_audio.to_str().unwrap(),
        ])
        .status()
        .await
        .unwrap();
    assert!(status.success());

    let out_dir = tmp.path().join("out");
    let clip_path = out_dir.join("clip_no_audio.mp4");
    let thumb_path = out_dir.join("thumb_no_audio.jpg");

    let result = VideoCutter::slice_clip(
        &video_no_audio,
        &clip_path,
        &thumb_path,
        &TimeRange {
            start: 0.0,
            end: 2.0,
        },
        Dimensions {
            width: 1080,
            height: 1920,
        },
        VerticalMode::BlurPadding,
        None,
    )
    .await;

    assert!(
        result.is_ok(),
        "Video without audio must cut successfully: {:?}",
        result.err()
    );
    let clip = result.unwrap();
    assert_eq!(clip.width, 1080);
    assert_eq!(clip.height, 1920);
    assert!(PathBuf::from(clip.file_path).exists());
    assert!(PathBuf::from(clip.thumbnail_path).exists());
}

#[tokio::test]
async fn test_adversarial_concurrency_semaphore_invariants() {
    let state = AppState::new(1);
    assert_eq!(state.max_concurrent_jobs, 1);
    assert_eq!(state.available_permits(), 1);
    assert_eq!(state.active_jobs_count(), 0);

    let permit = state.semaphore.acquire().await.unwrap();
    assert_eq!(state.available_permits(), 0);

    drop(permit);
    assert_eq!(state.available_permits(), 1);
}

#[tokio::test]
async fn test_adversarial_nan_interval_seconds_rejected_by_validation() {
    let task = VideoTask {
        job_id: Uuid::new_v4(),
        source_url: Some("test://fixture".to_string()),
        local_video_path: None,
        strategy: CutStrategy::FixedInterval,
        interval_seconds: f64::NAN,
        max_duration_seconds: 60.0,
        aspect_ratio: AspectRatio::Ratio9x16,
        vertical_mode: VerticalMode::BlurPadding,
        output_dir: "media/clips/test".to_string(),
        webhook_url: None,
        custom_ranges: None,
        created_at: chrono::Utc::now(),
    };

    let validation_result = task.validate();
    assert!(
        validation_result.is_err(),
        "Hardened: NaN interval_seconds must be rejected"
    );
}

#[tokio::test]
async fn test_adversarial_direct_cut_rejects_traversal_and_restricted_paths() {
    let state = AppState::new(1);
    let app = create_router(state);

    let payload = serde_json::json!({
        "source_path": "/tmp/dummy.mp4",
        "output_dir": "../../etc/cron.d",
        "ranges": []
    });

    let req = Request::builder()
        .method("POST")
        .uri("/api/v1/direct-cut")
        .header("Content-Type", "application/json")
        .body(Body::from(payload.to_string()))
        .unwrap();

    let response = app.oneshot(req).await.unwrap();
    assert_eq!(response.status(), StatusCode::BAD_REQUEST);

    let payload_sys = serde_json::json!({
        "source_path": "/tmp/dummy.mp4",
        "output_dir": "/etc/reelforge",
        "ranges": []
    });

    let req_sys = Request::builder()
        .method("POST")
        .uri("/api/v1/direct-cut")
        .header("Content-Type", "application/json")
        .body(Body::from(payload_sys.to_string()))
        .unwrap();

    let response_sys = create_router(AppState::new(1)).oneshot(req_sys).await.unwrap();
    assert_eq!(response_sys.status(), StatusCode::BAD_REQUEST);
}
