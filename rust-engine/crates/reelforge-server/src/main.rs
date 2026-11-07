use reelforge_server::{create_router, AppState};
use std::net::SocketAddr;
use tracing::info;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "info,reelforge_server=debug".into()),
        )
        .init();

    let max_concurrency: usize = std::env::var("REELFORGE_MAX_CONCURRENT_JOBS")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(1);

    let host = std::env::var("HOST").unwrap_or_else(|_| "0.0.0.0".to_string());
    let port: u16 = std::env::var("PORT")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(3000);

    let addr: SocketAddr = format!("{}:{}", host, port).parse()?;
    info!(
        "Starting ReelForge Server on {} (concurrency: {} permit)",
        addr, max_concurrency
    );

    let state = AppState::new(max_concurrency);
    let app = create_router(state);

    let listener = tokio::net::TcpListener::bind(&addr).await?;
    info!("ReelForge Axum API listening on http://{}", addr);

    axum::serve(listener, app).await?;
    Ok(())
}
