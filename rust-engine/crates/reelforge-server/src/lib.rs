pub mod handlers;
pub mod routes;
pub mod state;
pub mod worker;

pub use routes::create_router;
pub use state::AppState;

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{Request, StatusCode};
    use reelforge_downloader::generate_synthetic_mp4;
    use serde_json::Value;
    use tower::ServiceExt;
    use uuid::Uuid;

    #[tokio::test]
    async fn test_health_endpoint() {
        let state = AppState::new(1);
        let app = create_router(state);

        let response = app
            .oneshot(
                Request::builder()
                    .uri("/health")
                    .body(Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();

        assert_eq!(response.status(), StatusCode::OK);
        let body = axum::body::to_bytes(response.into_body(), usize::MAX)
            .await
            .unwrap();
        let json: Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(json["status"], "healthy");
        assert_eq!(json["version"], "0.1.0");
        assert_eq!(json["concurrency"]["max_concurrent_jobs"], 1);
        assert_eq!(json["concurrency"]["available_permits"], 1);
        assert_eq!(json["concurrency"]["active_jobs"], 0);
    }

    #[tokio::test]
    async fn test_create_job_and_poll_status() {
        let state = AppState::new(1);
        let app = create_router(state.clone());

        let job_id = Uuid::new_v4();
        let payload = serde_json::json!({
            "job_id": job_id,
            "source_url": "test://fixture_sample",
            "strategy": "fixed_interval",
            "interval_seconds": 3.0,
            "max_duration_seconds": 6.0,
            "aspect_ratio": "9:16",
            "vertical_mode": "blur_padding"
        });

        let post_req = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(payload.to_string()))
            .unwrap();

        let response = app.oneshot(post_req).await.unwrap();
        assert_eq!(response.status(), StatusCode::ACCEPTED);

        let body = axum::body::to_bytes(response.into_body(), usize::MAX)
            .await
            .unwrap();
        let json: Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(json["job_id"], job_id.to_string());
        assert_eq!(json["status"], "queued");

        // Wait brief instant for worker progress
        tokio::time::sleep(tokio::time::Duration::from_millis(50)).await;

        let app2 = create_router(state.clone());
        let get_req = Request::builder()
            .uri(format!("/api/v1/jobs/{}", job_id))
            .body(Body::empty())
            .unwrap();

        let get_resp = app2.oneshot(get_req).await.unwrap();
        assert_eq!(get_resp.status(), StatusCode::OK);
    }

    #[tokio::test]
    async fn test_cancel_job() {
        let state = AppState::new(1);
        let app = create_router(state.clone());

        let job_id = Uuid::new_v4();
        let payload = serde_json::json!({
            "job_id": job_id,
            "source_url": "test://sample_cancel",
            "strategy": "fixed_interval",
            "interval_seconds": 10.0
        });

        let post_req = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(payload.to_string()))
            .unwrap();

        let _ = app.oneshot(post_req).await.unwrap();

        let app_cancel = create_router(state.clone());
        let cancel_req = Request::builder()
            .method("POST")
            .uri(format!("/api/v1/jobs/{}/cancel", job_id))
            .body(Body::empty())
            .unwrap();

        let cancel_resp = app_cancel.oneshot(cancel_req).await.unwrap();
        assert_eq!(cancel_resp.status(), StatusCode::OK);

        let app_poll = create_router(state.clone());
        let poll_req = Request::builder()
            .uri(format!("/api/v1/jobs/{}", job_id))
            .body(Body::empty())
            .unwrap();

        let poll_resp = app_poll.oneshot(poll_req).await.unwrap();
        assert_eq!(poll_resp.status(), StatusCode::OK);
        let body = axum::body::to_bytes(poll_resp.into_body(), usize::MAX)
            .await
            .unwrap();
        let json: Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(json["status"], "cancelled");
    }

    #[tokio::test]
    async fn test_direct_cut_endpoint() {
        let temp = tempfile::tempdir().unwrap();
        let source_file = temp.path().join("direct_source.mp4");
        generate_synthetic_mp4(&source_file, 4, 1280, 720).await.unwrap();

        let out_dir = temp.path().join("direct_out");
        let payload = serde_json::json!({
            "source_path": source_file.to_string_lossy(),
            "output_dir": out_dir.to_string_lossy(),
            "ranges": [
                { "start": 0.0, "end": 2.0 }
            ],
            "vertical_mode": "blur_padding",
            "aspect_ratio": "9:16"
        });

        let state = AppState::new(1);
        let app = create_router(state);

        let req = Request::builder()
            .method("POST")
            .uri("/api/v1/direct-cut")
            .header("Content-Type", "application/json")
            .body(Body::from(payload.to_string()))
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert_eq!(resp.status(), StatusCode::OK);

        let body = axum::body::to_bytes(resp.into_body(), usize::MAX)
            .await
            .unwrap();
        let json: Value = serde_json::from_slice(&body).unwrap();
        let clips = json["clips"].as_array().expect("Clips array expected");
        assert_eq!(clips.len(), 1);
        assert_eq!(clips[0]["width"], 1080);
        assert_eq!(clips[0]["height"], 1920);
    }

    #[tokio::test]
    async fn test_adversarial_malformed_json_body_rejected_cleanly() {
        let state = AppState::new(1);
        let app = create_router(state);

        let req = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from("{\"job_id\": \"not-closed-json-syntax"))
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert_eq!(resp.status(), StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn test_adversarial_invalid_types_rejected_cleanly() {
        let state = AppState::new(1);
        let app = create_router(state);

        let req = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "job_id": 999999,
                "interval_seconds": "invalid_number_type"
            }).to_string()))
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert!(resp.status().is_client_error());
    }

    #[tokio::test]
    async fn test_adversarial_negative_and_zero_interval_rejected() {
        let state = AppState::new(1);

        // Negative interval
        let app1 = create_router(state.clone());
        let req1 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "interval_seconds": -15.0
            }).to_string()))
            .unwrap();

        let resp1 = app1.oneshot(req1).await.unwrap();
        assert_eq!(resp1.status(), StatusCode::BAD_REQUEST);

        // Zero interval
        let app2 = create_router(state.clone());
        let req2 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "interval_seconds": 0.0
            }).to_string()))
            .unwrap();

        let resp2 = app2.oneshot(req2).await.unwrap();
        assert_eq!(resp2.status(), StatusCode::BAD_REQUEST);

        // Zero max_duration_seconds
        let app3 = create_router(state);
        let req3 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "max_duration_seconds": 0.0
            }).to_string()))
            .unwrap();

        let resp3 = app3.oneshot(req3).await.unwrap();
        assert_eq!(resp3.status(), StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn test_adversarial_inverted_and_negative_custom_ranges() {
        let state = AppState::new(1);

        // Inverted range [20.0, 5.0]
        let app1 = create_router(state.clone());
        let req1 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "strategy": "manual_timestamps",
                "custom_ranges": [{ "start": 20.0, "end": 5.0 }]
            }).to_string()))
            .unwrap();

        let resp1 = app1.oneshot(req1).await.unwrap();
        assert_eq!(resp1.status(), StatusCode::BAD_REQUEST);

        // Negative start range [-5.0, 10.0]
        let app2 = create_router(state.clone());
        let req2 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "strategy": "manual_timestamps",
                "custom_ranges": [{ "start": -5.0, "end": 10.0 }]
            }).to_string()))
            .unwrap();

        let resp2 = app2.oneshot(req2).await.unwrap();
        assert_eq!(resp2.status(), StatusCode::BAD_REQUEST);

        // Zero duration range [5.0, 5.0]
        let app3 = create_router(state);
        let req3 = Request::builder()
            .method("POST")
            .uri("/api/v1/jobs")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_url": "test://fixture",
                "strategy": "manual_timestamps",
                "custom_ranges": [{ "start": 5.0, "end": 5.0 }]
            }).to_string()))
            .unwrap();

        let resp3 = app3.oneshot(req3).await.unwrap();
        assert_eq!(resp3.status(), StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn test_adversarial_invalid_uuid_path_rejected() {
        let state = AppState::new(1);
        let app = create_router(state);

        let req = Request::builder()
            .uri("/api/v1/jobs/not-a-valid-uuid-12345")
            .body(Body::empty())
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert_eq!(resp.status(), StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn test_adversarial_cancel_nonexistent_job_returns_404() {
        let state = AppState::new(1);
        let app = create_router(state);

        let random_uuid = Uuid::new_v4();
        let req = Request::builder()
            .method("POST")
            .uri(format!("/api/v1/jobs/{}/cancel", random_uuid))
            .body(Body::empty())
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert_eq!(resp.status(), StatusCode::NOT_FOUND);
    }

    #[tokio::test]
    async fn test_adversarial_rapid_sequential_burst() {
        let state = AppState::new(1);

        for i in 0..25 {
            let app = create_router(state.clone());
            let job_id = Uuid::new_v4();
            let req = Request::builder()
                .method("POST")
                .uri("/api/v1/jobs")
                .header("Content-Type", "application/json")
                .body(Body::from(serde_json::json!({
                    "job_id": job_id,
                    "source_url": format!("test://burst_{}", i),
                    "strategy": "fixed_interval",
                    "interval_seconds": 10.0
                }).to_string()))
                .unwrap();

            let resp = app.oneshot(req).await.unwrap();
            assert_eq!(resp.status(), StatusCode::ACCEPTED);
        }

        // Concurrency metrics should remain bounded and consistent
        let app_health = create_router(state);
        let health_req = Request::builder()
            .uri("/health")
            .body(Body::empty())
            .unwrap();

        let health_resp = app_health.oneshot(health_req).await.unwrap();
        assert_eq!(health_resp.status(), StatusCode::OK);
        let body = axum::body::to_bytes(health_resp.into_body(), usize::MAX)
            .await
            .unwrap();
        let json: Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(json["concurrency"]["max_concurrent_jobs"], 1);
        let available = json["concurrency"]["available_permits"].as_u64().unwrap();
        assert!(available <= 1);
    }

    #[tokio::test]
    async fn test_adversarial_direct_cut_nonexistent_file() {
        let state = AppState::new(1);
        let app = create_router(state);

        let req = Request::builder()
            .method("POST")
            .uri("/api/v1/direct-cut")
            .header("Content-Type", "application/json")
            .body(Body::from(serde_json::json!({
                "source_path": "/tmp/definitely_does_not_exist_987654321.mp4",
                "output_dir": "/tmp/out_fail",
                "ranges": [{ "start": 0.0, "end": 2.0 }]
            }).to_string()))
            .unwrap();

        let resp = app.oneshot(req).await.unwrap();
        assert_eq!(resp.status(), StatusCode::BAD_REQUEST);
    }
}
