#!/usr/bin/env python3
"""
Ephemeral mock server and reference test harness for ReelForge.
Implements the Axum HTTP API and Laravel REST API specifications from PROJECT.md.
Used for offline contract verification and deterministic E2E simulation.
"""

import json
import threading
import time
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any, Dict, List, Optional
from urllib.parse import parse_qs, urlparse


class ReelForgeMockState:
    """Thread-safe in-memory state for mock services."""

    def __init__(self):
        self.lock = threading.Lock()
        self.jobs: Dict[str, Dict[str, Any]] = {}
        self.videos: Dict[str, Dict[str, Any]] = {}
        self.clips: Dict[str, List[Dict[str, Any]]] = {}
        self.social_accounts: List[Dict[str, Any]] = [
            {
                "id": str(uuid.uuid4()),
                "platform": "tiktok",
                "platform_username": "@test_creator",
                "connected_at": "2026-11-01T12:00:00Z",
                "is_mock": True,
                "token_expired": False,
            },
            {
                "id": str(uuid.uuid4()),
                "platform": "instagram",
                "platform_username": "@reels_creator",
                "connected_at": "2026-11-02T12:00:00Z",
                "is_mock": True,
                "token_expired": False,
            },
            {
                "id": str(uuid.uuid4()),
                "platform": "youtube",
                "platform_username": "@shorts_creator",
                "connected_at": "2026-11-03T12:00:00Z",
                "is_mock": True,
                "token_expired": False,
            },
        ]
        self.publications: Dict[str, Dict[str, Any]] = {}
        self.active_jobs = 0
        self.max_concurrent_jobs = 1
        self.start_time = time.time()
        # Simulation flags
        self.simulate_error: Optional[str] = None
        self.simulate_concurrency_rejection: bool = False
        self.nonexistent_clips: set = set()


GLOBAL_STATE = ReelForgeMockState()


class MockSocialAdapter:
    """Python reference implementation of SocialPublisherAdapterInterface and MockSocialAdapter."""

    def __init__(self, platform: str, simulate_delay_ms: int = 0):
        self.platform = platform
        self.simulate_delay_ms = simulate_delay_ms

    def get_platform(self) -> str:
        return self.platform

    def publish(
        self,
        clip_id: str,
        account_id: str,
        options: Optional[Dict[str, Any]] = None,
        simulate_failure: bool = False,
        simulate_token_expired: bool = False,
        duration_seconds: float = 30.0,
    ) -> Dict[str, Any]:
        """Simulate publishing a clip to social platform."""
        if self.simulate_delay_ms > 0:
            time.sleep(self.simulate_delay_ms / 1000.0)

        # Constraint check: YouTube Shorts limit is 60 seconds
        if self.platform == "youtube" and duration_seconds > 60.0:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "PlatformConstraintViolation: YouTube Shorts maximum duration is 60.0s",
            }

        if simulate_token_expired:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "TokenExpiredException: OAuth access token has expired",
            }

        if simulate_failure:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "MockSimulatedFailure: Simulated API rate limit or outage",
            }

        post_id = f"{self.platform}_post_{uuid.uuid4().hex[:12]}"
        now = datetime.now(timezone.utc).isoformat()
        return {
            "success": True,
            "platform": self.platform,
            "platform_post_id": post_id,
            "published_at": now,
            "error": None,
        }

    def refresh_token(self, account_id: str, force_failure: bool = False) -> bool:
        """Simulate OAuth token refresh."""
        return not force_failure

    def validate_account(self, account_id: str, is_active: bool = True) -> bool:
        """Simulate account credential validity check."""
        return is_active


class ReelForgeRequestHandler(BaseHTTPRequestHandler):
    """Handles HTTP requests matching the Axum and Laravel contracts."""

    def log_message(self, format: str, *args: Any) -> None:
        """Silence standard console logging unless in debug."""
        pass

    def _send_json(self, status: int, data: Any) -> None:
        payload = json.dumps(data).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def _read_json(self) -> Dict[str, Any]:
        length = int(self.headers.get("Content-Length", 0))
        if length == 0:
            return {}
        body = self.rfile.read(length)
        return json.loads(body.decode("utf-8"))

    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path

        # 1. Axum GET /health
        if path == "/health":
            uptime = int(time.time() - GLOBAL_STATE.start_time)
            with GLOBAL_STATE.lock:
                available = max(0, GLOBAL_STATE.max_concurrent_jobs - GLOBAL_STATE.active_jobs)
                resp = {
                    "status": "healthy",
                    "version": "0.1.0",
                    "concurrency": {
                        "active_jobs": GLOBAL_STATE.active_jobs,
                        "max_concurrent_jobs": GLOBAL_STATE.max_concurrent_jobs,
                        "available_permits": available,
                    },
                    "uptime_seconds": uptime,
                }
            self._send_json(200, resp)
            return

        # 2. Axum GET /api/v1/jobs/:id
        if path.startswith("/api/v1/jobs/"):
            job_id = path.split("/")[-1]
            try:
                uuid.UUID(job_id)
            except ValueError:
                self._send_json(400, {"error": "Invalid job UUID parameter", "job_id": job_id})
                return
            with GLOBAL_STATE.lock:
                job = GLOBAL_STATE.jobs.get(job_id)
            if not job:
                self._send_json(404, {"error": "Job not found", "job_id": job_id})
                return
            self._send_json(200, job)
            return

        # 3. Laravel GET /api/videos/:id/status
        if path.startswith("/api/videos/") and path.endswith("/status"):
            parts = path.split("/")
            video_id = parts[3]
            with GLOBAL_STATE.lock:
                video = GLOBAL_STATE.videos.get(video_id)
            if not video:
                self._send_json(404, {"error": "Video not found", "id": video_id})
                return
            self._send_json(200, {
                "id": video_id,
                "status": video.get("status", "processing"),
                "progress_percent": video.get("progress_percent", 50),
                "error_message": video.get("error_message"),
            })
            return

        # 4. Laravel GET /api/videos/:id/clips
        if path.startswith("/api/videos/") and path.endswith("/clips"):
            parts = path.split("/")
            video_id = parts[3]
            with GLOBAL_STATE.lock:
                clips = GLOBAL_STATE.clips.get(video_id, [])
            self._send_json(200, {"data": clips})
            return

        # 5. Laravel GET /api/social-accounts
        if path == "/api/social-accounts":
            with GLOBAL_STATE.lock:
                accounts = list(GLOBAL_STATE.social_accounts)
            self._send_json(200, {"data": accounts})
            return

        self._send_json(404, {"error": "Endpoint not found", "path": path})

    def do_POST(self) -> None:
        parsed = urlparse(self.path)
        path = parsed.path

        try:
            body = self._read_json()
        except Exception as e:
            self._send_json(400, {"error": f"Invalid JSON payload: {str(e)}"})
            return

        # 1. Axum POST /api/v1/jobs
        if path == "/api/v1/jobs":
            job_id = body.get("job_id") or str(uuid.uuid4())

            # Validate input boundaries
            if not body.get("source_url") and not body.get("local_video_path"):
                self._send_json(422, {"error": "Must provide source_url or local_video_path"})
                return

            if body.get("source_url") and not body.get("source_url").startswith(("http://", "https://")):
                self._send_json(422, {"error": "Malformed source_url: invalid protocol"})
                return

            # Validate intervals and durations
            interval = body.get("interval_seconds")
            if interval is not None:
                try:
                    if float(interval) <= 0:
                        self._send_json(400, {"error": "interval_seconds must be positive"})
                        return
                except (ValueError, TypeError):
                    self._send_json(400, {"error": "interval_seconds must be numeric"})
                    return

            max_dur = body.get("max_duration_seconds")
            if max_dur is not None:
                try:
                    if float(max_dur) <= 0:
                        self._send_json(400, {"error": "max_duration_seconds must be positive"})
                        return
                except (ValueError, TypeError):
                    self._send_json(400, {"error": "max_duration_seconds must be numeric"})
                    return

            # Validate custom ranges
            custom_ranges = body.get("custom_ranges")
            if custom_ranges:
                for r in custom_ranges:
                    try:
                        st = float(r.get("start", 0))
                        en = float(r.get("end", 0))
                        if st < 0 or en <= st:
                            self._send_json(400, {"error": f"Invalid custom range [{st}, {en}]"})
                            return
                    except (ValueError, TypeError):
                        self._send_json(400, {"error": "custom_ranges must be numeric"})
                        return

            with GLOBAL_STATE.lock:
                if GLOBAL_STATE.simulate_concurrency_rejection and GLOBAL_STATE.active_jobs >= GLOBAL_STATE.max_concurrent_jobs:
                    self._send_json(429, {"error": "Concurrency limit reached: worker busy"})
                    return

                # Create job
                output_dir = body.get("output_dir", f"media/clips/{job_id}")
                clip_id = str(uuid.uuid4())
                thumb_id = str(uuid.uuid4())

                GLOBAL_STATE.jobs[job_id] = {
                    "job_id": job_id,
                    "status": "completed",
                    "progress_percent": 100,
                    "clips": [
                        {
                            "clip_id": clip_id,
                            "file_path": f"{output_dir}/clip_001.mp4",
                            "thumbnail_path": f"media/thumbnails/{job_id}/thumb_001.jpg",
                            "start_time": 0.0,
                            "end_time": float(body.get("interval_seconds", 30)),
                            "duration": float(body.get("interval_seconds", 30)),
                            "width": 1080,
                            "height": 1920,
                        }
                    ],
                    "error_message": None,
                    "execution_duration_ms": 1250,
                }

            self._send_json(202, {
                "job_id": job_id,
                "status": "queued",
                "message": "Job accepted and queued for processing",
            })
            return

        # 2. Laravel POST /api/videos
        if path == "/api/videos":
            source_url = body.get("source_url")
            local_path = body.get("local_video_path")
            if not source_url and not local_path:
                self._send_json(422, {"error": "source_url or local_video_path is required"})
                return

            if source_url and not source_url.startswith(("http://", "https://")):
                self._send_json(422, {"error": "Invalid source_url format"})
                return

            video_id = str(uuid.uuid4())
            now = datetime.now(timezone.utc).isoformat()
            with GLOBAL_STATE.lock:
                GLOBAL_STATE.videos[video_id] = {
                    "id": video_id,
                    "source_url": source_url or f"local://{local_path}",
                    "status": "processing",
                    "progress_percent": 0,
                    "created_at": now,
                    "error_message": None,
                }
                # Pre-populate clips
                clip_id = str(uuid.uuid4())
                GLOBAL_STATE.clips[video_id] = [
                    {
                        "id": clip_id,
                        "video_id": video_id,
                        "file_url": f"/storage/media/clips/{video_id}/clip_001.mp4",
                        "thumbnail_url": f"/storage/media/thumbnails/{video_id}/thumb_001.jpg",
                        "duration": float(body.get("interval_seconds", 30.0)),
                        "start_time": 0.0,
                        "end_time": float(body.get("interval_seconds", 30.0)),
                        "status": "ready",
                    }
                ]

            self._send_json(201, {
                "id": video_id,
                "source_url": source_url or f"local://{local_path}",
                "status": "pending",
                "created_at": now,
            })
            return

        # 3. Laravel POST /api/clips/:id/publish
        if path.startswith("/api/clips/") and path.endswith("/publish"):
            parts = path.split("/")
            clip_id = parts[3]
            try:
                uuid.UUID(clip_id)
            except ValueError:
                self._send_json(404, {"error": "Invalid or non-existent clip UUID in path", "id": clip_id})
                return
            if clip_id in GLOBAL_STATE.nonexistent_clips:
                self._send_json(404, {"error": "Clip not found", "id": clip_id})
                return
            platforms = body.get("platforms", [])
            if not platforms:
                self._send_json(422, {"error": "platforms array cannot be empty"})
                return

            pub_ids = []
            with GLOBAL_STATE.lock:
                for p in platforms:
                    pid = str(uuid.uuid4())
                    pub_ids.append(pid)
                    GLOBAL_STATE.publications[pid] = {
                        "id": pid,
                        "clip_id": clip_id,
                        "platform": p,
                        "status": "queued",
                        "caption": body.get("caption", ""),
                        "scheduled_at": body.get("scheduled_at"),
                    }

            self._send_json(202, {
                "publication_ids": pub_ids,
                "status": "queued",
            })
            return

        # 4. Laravel POST /api/social-accounts/mock-connect
        if path == "/api/social-accounts/mock-connect":
            platform = body.get("platform")
            username = body.get("username", "test_creator")
            if not platform or platform not in ("tiktok", "instagram", "youtube"):
                self._send_json(422, {"error": "Invalid or missing platform. Must be tiktok, instagram, or youtube"})
                return

            account_id = str(uuid.uuid4())
            new_acc = {
                "id": account_id,
                "platform": platform,
                "platform_username": username,
                "connected_at": datetime.now(timezone.utc).isoformat(),
                "is_mock": True,
                "token_expired": False,
            }
            with GLOBAL_STATE.lock:
                GLOBAL_STATE.social_accounts.append(new_acc)

            self._send_json(201, new_acc)
            return

        self._send_json(404, {"error": "Endpoint not found", "path": path})


class EphemeralMockServer:
    """Manages lifecycle of ephemeral mock server in a background thread."""

    def __init__(self, port: int = 3000):
        self.port = port
        self.server: Optional[HTTPServer] = None
        self.thread: Optional[threading.Thread] = None

    def start(self) -> int:
        """Start HTTP server. Tries requested port, falls back to dynamic port if busy."""
        try:
            self.server = HTTPServer(("127.0.0.1", self.port), ReelForgeRequestHandler)
        except OSError:
            # Fall back to ephemeral OS port
            self.server = HTTPServer(("127.0.0.1", 0), ReelForgeRequestHandler)
            self.port = self.server.server_port

        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        return self.port

    def stop(self) -> None:
        """Shut down the server."""
        if self.server:
            self.server.shutdown()
            self.server.server_close()
            self.server = None
