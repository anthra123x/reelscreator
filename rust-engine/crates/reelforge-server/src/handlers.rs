use axum::{
    extract::{Path, State},
    http::StatusCode,
    response::{IntoResponse, Json},
};
use reelforge_core::{
    validate_path_security, AspectRatio, CutStrategy, JobStatusRecord, TimeRange, VerticalMode,
    VideoTask,
};
use reelforge_cutter::VideoCutter;
use serde::{Deserialize, Serialize};
use std::path::PathBuf;
use tokio_util::sync::CancellationToken;
use uuid::Uuid;

use crate::state::AppState;
use crate::worker::run_job_pipeline;

#[derive(Serialize)]
pub struct ConcurrencyInfo {
    pub active_jobs: usize,
    pub max_concurrent_jobs: usize,
    pub available_permits: usize,
}

#[derive(Serialize)]
pub struct HealthResponse {
    pub status: String,
    pub version: String,
    pub concurrency: ConcurrencyInfo,
    pub uptime_seconds: u64,
}

pub async fn health_handler(State(state): State<AppState>) -> impl IntoResponse {
    let resp = HealthResponse {
        status: "healthy".to_string(),
        version: "0.1.0".to_string(),
        concurrency: ConcurrencyInfo {
            active_jobs: state.active_jobs_count(),
            max_concurrent_jobs: state.max_concurrent_jobs,
            available_permits: state.available_permits(),
        },
        uptime_seconds: state.uptime_seconds(),
    };
    Json(resp)
}

#[derive(Deserialize)]
pub struct CreateJobRequest {
    pub job_id: Option<Uuid>,
    pub source_url: Option<String>,
    pub local_video_path: Option<String>,
    pub strategy: Option<CutStrategy>,
    pub interval_seconds: Option<f64>,
    pub max_duration_seconds: Option<f64>,
    pub aspect_ratio: Option<AspectRatio>,
    pub vertical_mode: Option<VerticalMode>,
    pub output_dir: Option<String>,
    pub webhook_url: Option<String>,
    pub custom_ranges: Option<Vec<TimeRange>>,
}

#[derive(Serialize)]
pub struct CreateJobResponse {
    pub job_id: Uuid,
    pub status: String,
    pub message: String,
}

pub async fn create_job_handler(
    State(state): State<AppState>,
    Json(payload): Json<CreateJobRequest>,
) -> Result<(StatusCode, Json<CreateJobResponse>), (StatusCode, Json<serde_json::Value>)> {
    let job_id = payload.job_id.unwrap_or_else(Uuid::new_v4);

    let output_dir = payload
        .output_dir
        .unwrap_or_else(|| format!("media/clips/{}", job_id));

    let task = VideoTask {
        job_id,
        source_url: payload.source_url,
        local_video_path: payload.local_video_path,
        strategy: payload.strategy.unwrap_or_default(),
        interval_seconds: payload.interval_seconds.unwrap_or(30.0),
        max_duration_seconds: payload.max_duration_seconds.unwrap_or(60.0),
        aspect_ratio: payload.aspect_ratio.unwrap_or_default(),
        vertical_mode: payload.vertical_mode.unwrap_or_default(),
        output_dir,
        webhook_url: payload.webhook_url,
        custom_ranges: payload.custom_ranges,
        created_at: chrono::Utc::now(),
    };

    if let Err(e) = task.validate() {
        return Err((
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "error": e.to_string() })),
        ));
    }

    let cancel_token = CancellationToken::new();

    // Register job in memory
    {
        let mut jobs = state.jobs.write().await;
        jobs.insert(job_id, JobStatusRecord::new(job_id));

        let mut cancellations = state.cancellations.write().await;
        cancellations.insert(job_id, cancel_token.clone());
    }

    // Spawn async background processing
    let state_clone = state.clone();
    tokio::spawn(async move {
        run_job_pipeline(state_clone, task, cancel_token).await;
    });

    Ok((
        StatusCode::ACCEPTED,
        Json(CreateJobResponse {
            job_id,
            status: "queued".to_string(),
            message: "Job accepted and queued for processing".to_string(),
        }),
    ))
}

pub async fn get_job_handler(
    State(state): State<AppState>,
    Path(job_id): Path<Uuid>,
) -> Result<Json<JobStatusRecord>, (StatusCode, Json<serde_json::Value>)> {
    let jobs = state.jobs.read().await;
    if let Some(record) = jobs.get(&job_id) {
        Ok(Json(record.clone()))
    } else {
        Err((
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({ "error": format!("Job '{}' not found", job_id) })),
        ))
    }
}

#[derive(Serialize)]
pub struct CancelJobResponse {
    pub job_id: Uuid,
    pub status: String,
    pub message: String,
}

pub async fn cancel_job_handler(
    State(state): State<AppState>,
    Path(job_id): Path<Uuid>,
) -> Result<Json<CancelJobResponse>, (StatusCode, Json<serde_json::Value>)> {
    let mut jobs = state.jobs.write().await;
    if let Some(record) = jobs.get_mut(&job_id) {
        if record.status.is_terminal() {
            return Err((
                StatusCode::BAD_REQUEST,
                Json(serde_json::json!({
                    "error": format!("Cannot cancel job in terminal state '{:?}'", record.status)
                })),
            ));
        }

        record.cancel();

        // Trigger cancellation token
        let cancellations = state.cancellations.read().await;
        if let Some(token) = cancellations.get(&job_id) {
            token.cancel();
        }

        Ok(Json(CancelJobResponse {
            job_id,
            status: "cancelled".to_string(),
            message: "Job successfully cancelled".to_string(),
        }))
    } else {
        Err((
            StatusCode::NOT_FOUND,
            Json(serde_json::json!({ "error": format!("Job '{}' not found", job_id) })),
        ))
    }
}

#[derive(Deserialize)]
pub struct DirectCutRequest {
    pub source_path: String,
    pub output_dir: String,
    pub ranges: Vec<TimeRange>,
    pub vertical_mode: Option<VerticalMode>,
    pub aspect_ratio: Option<AspectRatio>,
}

#[derive(Serialize)]
pub struct DirectCutResponse {
    pub clips: Vec<reelforge_core::Clip>,
}

pub async fn direct_cut_handler(
    State(state): State<AppState>,
    Json(payload): Json<DirectCutRequest>,
) -> Result<Json<DirectCutResponse>, (StatusCode, Json<serde_json::Value>)> {
    if payload.output_dir.trim().is_empty() {
        return Err((
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "error": "output_dir cannot be empty" })),
        ));
    }

    validate_path_security(&payload.output_dir, "output_dir").map_err(|e| {
        (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "error": e.to_string() })),
        )
    })?;

    validate_path_security(&payload.source_path, "source_path").map_err(|e| {
        (
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "error": e.to_string() })),
        )
    })?;

    let output_dir = PathBuf::from(&payload.output_dir);
    let source = PathBuf::from(&payload.source_path);
    if !source.exists() {
        return Err((
            StatusCode::BAD_REQUEST,
            Json(serde_json::json!({ "error": "Source video file not found" })),
        ));
    }

    // Acquire concurrency permit before processing cuts
    let _permit = state.semaphore.acquire().await.map_err(|e| {
        (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({ "error": format!("Failed to acquire concurrency permit: {}", e) })),
        )
    })?;

    tokio::fs::create_dir_all(&output_dir).await.map_err(|e| {
        (
            StatusCode::INTERNAL_SERVER_ERROR,
            Json(serde_json::json!({ "error": e.to_string() })),
        )
    })?;

    let vertical_mode = payload.vertical_mode.unwrap_or_default();
    let aspect_ratio = payload.aspect_ratio.unwrap_or_default();
    let dimensions = aspect_ratio.target_dimensions();

    let mut clips = Vec::new();
    for (idx, range) in payload.ranges.iter().enumerate() {
        let clip_path = output_dir.join(format!("direct_clip_{:03}.mp4", idx + 1));
        let thumb_path = output_dir.join(format!("direct_thumb_{:03}.jpg", idx + 1));

        let clip = VideoCutter::slice_clip(
            &source,
            &clip_path,
            &thumb_path,
            range,
            dimensions,
            vertical_mode,
            None,
        )
        .await
        .map_err(|e| {
            (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(serde_json::json!({ "error": e.to_string() })),
            )
        })?;

        clips.push(clip);
    }

    Ok(Json(DirectCutResponse { clips }))
}
