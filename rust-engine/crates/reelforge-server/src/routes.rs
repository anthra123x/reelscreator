use axum::{
    routing::{get, post},
    Router,
};
use tower_http::cors::{Any, CorsLayer};
use tower_http::trace::TraceLayer;

use crate::handlers::{
    cancel_job_handler, create_job_handler, direct_cut_handler, get_job_handler, health_handler,
};
use crate::state::AppState;

pub fn create_router(state: AppState) -> Router {
    let cors = CorsLayer::new()
        .allow_origin(Any)
        .allow_methods(Any)
        .allow_headers(Any);

    Router::new()
        .route("/health", get(health_handler))
        .route("/api/v1/jobs", post(create_job_handler))
        .route("/api/v1/jobs/{id}", get(get_job_handler))
        .route("/api/v1/jobs/{id}/cancel", post(cancel_job_handler))
        .route("/api/v1/direct-cut", post(direct_cut_handler))
        .layer(cors)
        .layer(TraceLayer::new_for_http())
        .with_state(state)
}
