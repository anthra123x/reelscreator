use reelforge_core::{JobState, VideoTask};
use reelforge_cutter::VideoCutter;
use reelforge_downloader::{DownloaderOptions, VideoDownloader};
use std::path::PathBuf;
use std::time::Instant;
use tokio_util::sync::CancellationToken;
use tracing::{error, info, warn};

use crate::state::AppState;

pub async fn run_job_pipeline(state: AppState, task: VideoTask, cancel_token: CancellationToken) {
    let job_id = task.job_id;
    let start_time = Instant::now();

    info!("Job [{}] queued; awaiting concurrency permit...", job_id);

    // Acquire semaphore permit
    let permit = match state.semaphore.acquire().await {
        Ok(p) => p,
        Err(e) => {
            error!("Job [{}] failed to acquire concurrency permit: {}", job_id, e);
            let mut jobs = state.jobs.write().await;
            if let Some(record) = jobs.get_mut(&job_id) {
                record.fail(format!("Concurrency permit acquisition failed: {}", e));
            }
            drop(jobs);
            state.cancellations.write().await.remove(&job_id);
            state.prune_jobs().await;
            return;
        }
    };

    info!("Job [{}] acquired permit; starting execution", job_id);

    // Initial state update
    {
        let mut jobs = state.jobs.write().await;
        if let Some(record) = jobs.get_mut(&job_id) {
            if record.status == JobState::Cancelled {
                info!("Job [{}] was cancelled before execution started", job_id);
                drop(jobs);
                state.cancellations.write().await.remove(&job_id);
                drop(permit);
                state.prune_jobs().await;
                return;
            }
        }
    }

    let result = execute_pipeline(&state, &task, &cancel_token).await;
    let duration_ms = start_time.elapsed().as_millis() as u64;

    match result {
        Ok(clips) => {
            info!("Job [{}] successfully completed with {} clips in {}ms", job_id, clips.len(), duration_ms);
            let mut jobs = state.jobs.write().await;
            if let Some(record) = jobs.get_mut(&job_id) {
                let _ = record.complete(clips.clone(), duration_ms);
            }

            // Webhook dispatch
            if let Some(webhook_url) = &task.webhook_url {
                let client = reqwest::Client::builder()
                    .timeout(std::time::Duration::from_secs(10))
                    .build()
                    .unwrap_or_else(|_| reqwest::Client::new());
                let payload = serde_json::json!({
                    "job_id": job_id,
                    "status": "completed",
                    "clips": clips,
                    "execution_duration_ms": duration_ms
                });
                let url = webhook_url.clone();
                let secret = std::env::var("INTERNAL_WEBHOOK_SECRET")
                    .or_else(|_| std::env::var("RUST_WEBHOOK_SECRET"))
                    .ok();
                tokio::spawn(async move {
                    let mut req = client.post(&url).json(&payload);
                    if let Some(ref s) = secret {
                        req = req.header("X-Internal-Secret", s.clone())
                                 .header("X-Webhook-Secret", s.clone());
                    }
                    if let Err(e) = req.send().await {
                        warn!("Webhook delivery failed for {}: {}", url, e);
                    }
                });
            }
        }
        Err(e) => {
            let error_str = e.to_string();
            let status_str = if cancel_token.is_cancelled() {
                warn!("Job [{}] was cancelled during execution", job_id);
                let mut jobs = state.jobs.write().await;
                if let Some(record) = jobs.get_mut(&job_id) {
                    record.cancel();
                }
                "cancelled"
            } else {
                error!("Job [{}] failed: {}", job_id, e);
                let mut jobs = state.jobs.write().await;
                if let Some(record) = jobs.get_mut(&job_id) {
                    record.fail(error_str.clone());
                }
                "failed"
            };

            // Dispatch failure webhook notification
            if let Some(webhook_url) = &task.webhook_url {
                let client = reqwest::Client::builder()
                    .timeout(std::time::Duration::from_secs(10))
                    .build()
                    .unwrap_or_else(|_| reqwest::Client::new());
                let payload = serde_json::json!({
                    "job_id": job_id,
                    "status": status_str,
                    "error": error_str,
                    "error_message": error_str,
                    "timestamp": chrono::Utc::now().to_rfc3339(),
                    "execution_duration_ms": duration_ms
                });
                let url = webhook_url.clone();
                let secret = std::env::var("INTERNAL_WEBHOOK_SECRET")
                    .or_else(|_| std::env::var("RUST_WEBHOOK_SECRET"))
                    .ok();
                tokio::spawn(async move {
                    let mut req = client.post(&url).json(&payload);
                    if let Some(ref s) = secret {
                        req = req.header("X-Internal-Secret", s.clone())
                                 .header("X-Webhook-Secret", s.clone());
                    }
                    if let Err(err) = req.send().await {
                        warn!("Failure webhook delivery failed for {}: {}", url, err);
                    }
                });
            }
        }
    }

    // Cleanup cancellation token and drop permit
    {
        let mut cancellations = state.cancellations.write().await;
        cancellations.remove(&job_id);
    }
    drop(permit);

    // Evict old terminal jobs from memory
    state.prune_jobs().await;
}

async fn execute_pipeline(
    state: &AppState,
    task: &VideoTask,
    cancel_token: &CancellationToken,
) -> reelforge_core::Result<Vec<reelforge_core::Clip>> {
    let job_id = task.job_id;

    reelforge_core::validate_path_security(&task.output_dir, "output_dir")?;

    // 1. Resolve source video file
    let source_path: PathBuf = if let Some(local_path) = &task.local_video_path {
        reelforge_core::validate_path_security(local_path, "local_video_path")?;
        let path = PathBuf::from(local_path);
        if !path.exists() {
            return Err(reelforge_core::CoreError::FileNotFound(path));
        }
        path
    } else if let Some(url) = &task.source_url {
        {
            let mut jobs = state.jobs.write().await;
            if let Some(record) = jobs.get_mut(&job_id) {
                record.transition_to(JobState::Downloading)?;
                record.set_progress(10);
            }
        }

        let raw_dir = if PathBuf::from("storage/media/raw").exists() {
            PathBuf::from("storage/media/raw")
        } else if PathBuf::from("../../storage/media/raw").exists() {
            PathBuf::from("../../storage/media/raw")
        } else {
            PathBuf::from("storage/media/raw")
        };
        tokio::fs::create_dir_all(&raw_dir).await?;

        let downloader = VideoDownloader::auto_detect().await?;
        let options = DownloaderOptions {
            output_dir: raw_dir,
            filename_stem: format!("source_{}", job_id),
            format_spec: None,
        };

        let state_clone = state.clone();
        let downloaded_file = downloader
            .download(url, options, Some(cancel_token.clone()), move |prog| {
                let scaled = (10.0 + prog.percent * 0.3) as u8;
                let state_c = state_clone.clone();
                tokio::spawn(async move {
                    let mut jobs = state_c.jobs.write().await;
                    if let Some(record) = jobs.get_mut(&job_id) {
                        record.set_progress(scaled);
                    }
                });
            })
            .await?;

        downloaded_file
    } else {
        return Err(reelforge_core::CoreError::ValidationError(
            "Neither source_url nor local_video_path was provided".to_string(),
        ));
    };

    if cancel_token.is_cancelled() {
        return Err(reelforge_core::CoreError::Cancelled("Cancelled before cutting".to_string()));
    }

    // 2. Transition to Analyzing
    {
        let mut jobs = state.jobs.write().await;
        if let Some(record) = jobs.get_mut(&job_id) {
            record.transition_to(JobState::Analyzing)?;
            record.set_progress(40);
        }
    }

    // 3. Transition to Cutting
    {
        let mut jobs = state.jobs.write().await;
        if let Some(record) = jobs.get_mut(&job_id) {
            record.transition_to(JobState::Cutting)?;
            record.set_progress(50);
        }
    }

    let state_clone = state.clone();
    let clips = VideoCutter::process_task(
        task,
        &source_path,
        Some(cancel_token.clone()),
        move |cut_pct| {
            let total_pct = 50 + ((cut_pct as f32 * 0.5) as u8);
            let state_c = state_clone.clone();
            tokio::spawn(async move {
                let mut jobs = state_c.jobs.write().await;
                if let Some(record) = jobs.get_mut(&job_id) {
                    record.set_progress(total_pct);
                }
            });
        },
    )
    .await?;

    Ok(clips)
}
