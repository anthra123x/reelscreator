#!/usr/bin/env bash
# ==============================================================================
# ReelForge - Local Development Environment Launcher
# ==============================================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

echo "=================================================================="
echo " Starting ReelForge Development Environment"
echo "=================================================================="

# Function to kill all spawned background processes on exit
cleanup() {
    echo ""
    echo "Stopping all ReelForge services..."
    if [[ -n "${RUST_PID:-}" ]] && kill -0 "$RUST_PID" 2>/dev/null; then
        kill "$RUST_PID" 2>/dev/null || true
    fi
    if [[ -n "${LARAVEL_PID:-}" ]] && kill -0 "$LARAVEL_PID" 2>/dev/null; then
        kill "$LARAVEL_PID" 2>/dev/null || true
    fi
    if [[ -n "${ANGULAR_PID:-}" ]] && kill -0 "$ANGULAR_PID" 2>/dev/null; then
        kill "$ANGULAR_PID" 2>/dev/null || true
    fi
    echo "All services stopped."
    exit 0
}

trap cleanup SIGINT SIGTERM EXIT

# Clean up any stale processes holding ports 3000, 8000, or 4200
echo "Verifying local ports (3000, 8000, 4200) are free..."
fuser -k 3000/tcp 2>/dev/null || true
fuser -k 8000/tcp 2>/dev/null || true
fuser -k 4200/tcp 2>/dev/null || true
sleep 1

# 1. Start Rust Engine (Axum) on port 3000
echo "[1/3] Starting Rust Video Processing Engine (Axum on http://127.0.0.1:3000)..."
cargo run --manifest-path "$ROOT_DIR/rust-engine/Cargo.toml" -p reelforge-server --bin reelforge-server &
RUST_PID=$!

# Wait briefly for Axum to bind
sleep 2

# 2. Start Laravel Backend on port 8000
echo "[2/3] Starting Laravel API Backend (http://127.0.0.1:8000)..."
(cd "$ROOT_DIR/backend" && php artisan serve --host=0.0.0.0 --port=8000) &
LARAVEL_PID=$!

# Wait briefly for Laravel to bind
sleep 1

# 3. Start Angular Frontend Dev Server on port 4200
echo "[3/3] Starting Angular Frontend UI (http://localhost:4200)..."
echo "=================================================================="
echo " ReelForge Services Ready:"
echo " - Frontend UI : http://localhost:4200"
echo " - Backend API : http://127.0.0.1:8000"
echo " - Rust Engine : http://127.0.0.1:3000"
echo " Press Ctrl+C in this terminal to gracefully stop all services."
echo "=================================================================="

(cd "$ROOT_DIR/frontend" && npm start -- --port 4200 --host 0.0.0.0) &
ANGULAR_PID=$!

wait

