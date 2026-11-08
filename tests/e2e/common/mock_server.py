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

        # Minimum duration check
        if duration_seconds < 1.0:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "PlatformConstraintViolation: Minimum clip duration is 1.0s",
            }

        # Constraint check: YouTube Shorts limit is 60 seconds
        if self.platform == "youtube" and duration_seconds > 60.0:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "PlatformConstraintViolation: YouTube Shorts maximum duration is 60.0s",
            }

        # Constraint check: Instagram Reels limit is 90 seconds
        if self.platform == "instagram" and duration_seconds > 90.0:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "PlatformConstraintViolation: Instagram Reels maximum duration is 90.0s",
            }

        # Constraint check: TikTok limit
        if self.platform == "tiktok" and duration_seconds > 180.0:
            return {
                "success": False,
                "platform": self.platform,
                "platform_post_id": None,
                "published_at": None,
                "error": "PlatformConstraintViolation: TikTok maximum duration is 180.0s",
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
            self._send_json(200, {"data": accounts, "total": len(accounts)})
            return

        # 6. Laravel GET /api/videos
        if path == "/api/videos":
            with GLOBAL_STATE.lock:
                video_list = list(GLOBAL_STATE.videos.values())
            self._send_json(200, {"data": video_list, "total": len(video_list)})
            return

        # 7. Laravel GET /api/clips
        if path == "/api/clips":
            with GLOBAL_STATE.lock:
                all_clips = [clip for sublist in GLOBAL_STATE.clips.values() for clip in sublist]
            self._send_json(200, {"data": all_clips, "total": len(all_clips)})
            return

        # 8. Laravel GET /api/publications
        if path == "/api/publications":
            with GLOBAL_STATE.lock:
                pubs = list(GLOBAL_STATE.publications.values())
            self._send_json(200, {"data": pubs, "total": len(pubs)})
            return

        # 9. Laravel GET /api/dashboard/metrics
        if path == "/api/dashboard/metrics":
            with GLOBAL_STATE.lock:
                total_videos = len(GLOBAL_STATE.videos)
                all_clips = [clip for sublist in GLOBAL_STATE.clips.values() for clip in sublist]
                total_clips = len(all_clips)
                active_accounts = len([a for a in GLOBAL_STATE.social_accounts if not a.get("token_expired")])
                pubs = list(GLOBAL_STATE.publications.values())
                total_pubs = len(pubs)
                published_count = len([p for p in pubs if p.get("status") == "published"])
                pending_count = len([p for p in pubs if p.get("status") in ("pending", "queued")])

            self._send_json(200, {
                "total_videos": total_videos,
                "total_clips": total_clips,
                "connected_accounts": active_accounts,
                "publications": {
                    "total": total_pubs,
                    "published": published_count,
                    "pending": pending_count,
                },
            })
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

        # 5. Laravel POST /api/social-accounts/connect (Agile connect endpoint)
        if path == "/api/social-accounts/connect":
            platform = body.get("platform")
            mode = body.get("mode", "auto")
            username = body.get("username", "reelforge_creator")
            access_token = body.get("access_token")

            valid_platforms = ("tiktok", "instagram", "youtube", "all")
            if not platform or platform not in valid_platforms:
                self._send_json(422, {"error": f"Invalid platform. Must be one of {valid_platforms}"})
                return

            valid_modes = ("auto", "env", "mock", "direct")
            if mode not in valid_modes:
                self._send_json(422, {"error": f"Invalid mode. Must be one of {valid_modes}"})
                return

            if mode == "direct" and not access_token:
                self._send_json(422, {"error": "Direct connection mode requires an access_token"})
                return

            target_platforms = ["tiktok", "instagram", "youtube"] if platform == "all" else [platform]
            connected_accounts = []

            with GLOBAL_STATE.lock:
                for plat in target_platforms:
                    # Check if account exists, update or append
                    existing = next((a for a in GLOBAL_STATE.social_accounts if a["platform"] == plat), None)
                    if existing:
                        existing["platform_username"] = username or existing["platform_username"]
                        existing["is_mock"] = (mode != "direct")
                        existing["token_expired"] = False
                        existing["connected_at"] = datetime.now(timezone.utc).isoformat()
                        existing["status"] = "connected"
                        connected_accounts.append(dict(existing))
                    else:
                        acc_id = str(uuid.uuid4())
                        acc = {
                            "id": acc_id,
                            "platform": plat,
                            "platform_username": username or f"@{plat}_creator",
                            "connected_at": datetime.now(timezone.utc).isoformat(),
                            "is_mock": (mode != "direct"),
                            "token_expired": False,
                            "status": "connected",
                        }
                        GLOBAL_STATE.social_accounts.append(acc)
                        connected_accounts.append(acc)

            self._send_json(200, {
                "message": "Social accounts connected successfully",
                "connected": connected_accounts,
            })
            return

        # 6. Laravel POST /api/videos/{id}/decompose
        if path.startswith("/api/videos/") and path.endswith("/decompose") and path != "/api/videos/decompose":
            parts = path.split("/")
            video_id = parts[3]
            try:
                uuid.UUID(video_id)
            except ValueError:
                self._send_json(404, {"error": "Invalid video UUID parameter", "id": video_id})
                return

            with GLOBAL_STATE.lock:
                video = GLOBAL_STATE.videos.get(video_id)
            if not video:
                self._send_json(404, {"error": "Video not found", "id": video_id})
                return

            interval = body.get("interval_seconds", 30)
            try:
                interval_float = float(interval)
                if interval_float <= 0:
                    self._send_json(400, {"error": "interval_seconds must be positive"})
                    return
            except (ValueError, TypeError):
                self._send_json(400, {"error": "interval_seconds must be numeric"})
                return

            platforms = body.get("platforms")
            if platforms is not None:
                if not isinstance(platforms, list) or len(platforms) == 0:
                    self._send_json(422, {"error": "platforms must be a non-empty list of platform names"})
                    return
                for p in platforms:
                    if p not in ("tiktok", "instagram", "youtube"):
                        self._send_json(422, {"error": f"Invalid platform '{p}' in platforms list"})
                        return

            auto_publish = body.get("auto_publish", False)
            caption = body.get("caption", "ReelForge auto-generated 9:16 reel")
            vertical_mode = body.get("vertical_mode", "blur_padding")

            clip_id = str(uuid.uuid4())
            new_clip = {
                "id": clip_id,
                "video_id": video_id,
                "start_time": 0.0,
                "end_time": interval_float,
                "duration": interval_float,
                "file_url": f"/storage/media/clips/{video_id}/clip_001.mp4",
                "thumbnail_url": f"/storage/media/thumbnails/{video_id}/thumb_001.jpg",
                "metadata": {
                    "width": 1080,
                    "height": 1920,
                    "aspect_ratio": "9:16",
                    "vertical_mode": vertical_mode,
                    "video_codec": "h264",
                    "audio_codec": "aac",
                },
                "status": "ready",
            }

            publications = []
            published_platforms = []
            with GLOBAL_STATE.lock:
                GLOBAL_STATE.clips.setdefault(video_id, []).append(new_clip)
                GLOBAL_STATE.videos[video_id]["status"] = "ready"
                GLOBAL_STATE.videos[video_id]["progress_percent"] = 100

                if auto_publish:
                    target_platforms = platforms if platforms else [
                        a["platform"] for a in GLOBAL_STATE.social_accounts if not a.get("token_expired")
                    ]
                    published_platforms = target_platforms
                    for plat in target_platforms:
                        pub_id = str(uuid.uuid4())
                        pub = {
                            "id": pub_id,
                            "clip_id": clip_id,
                            "platform": plat,
                            "status": "published",
                            "caption": caption,
                            "platform_post_id": f"mock_post_{uuid.uuid4().hex[:8]}",
                            "published_at": datetime.now(timezone.utc).isoformat(),
                        }
                        GLOBAL_STATE.publications[pub_id] = pub
                        publications.append(pub)

            self._send_json(200, {
                "message": "Video decomposed and reels dispatched for publication",
                "video": {"id": video_id, "status": "ready"},
                "clips": [new_clip],
                "publications": publications,
                "published_platforms": published_platforms,
                "status": "ready",
            })
            return

        # 7. Laravel POST /api/videos/decompose (Direct Ingest & Decompose endpoint)
        if path == "/api/videos/decompose":
            source_url = body.get("source_url")
            local_path = body.get("local_video_path")
            if not source_url and not local_path:
                self._send_json(422, {"error": "source_url or local_video_path is required"})
                return

            if source_url and not source_url.startswith(("http://", "https://")):
                self._send_json(422, {"error": "Invalid source_url format"})
                return

            interval = body.get("interval_seconds", 30)
            try:
                interval_float = float(interval)
                if interval_float <= 0:
                    self._send_json(400, {"error": "interval_seconds must be positive"})
                    return
            except (ValueError, TypeError):
                self._send_json(400, {"error": "interval_seconds must be numeric"})
                return

            platforms = body.get("platforms")
            if platforms is not None:
                if not isinstance(platforms, list) or len(platforms) == 0:
                    self._send_json(422, {"error": "platforms must be a non-empty list of platform names"})
                    return
                for p in platforms:
                    if p not in ("tiktok", "instagram", "youtube"):
                        self._send_json(422, {"error": f"Invalid platform '{p}' in platforms list"})
                        return

            video_id = str(uuid.uuid4())
            now = datetime.now(timezone.utc).isoformat()
            clip_id = str(uuid.uuid4())
            auto_publish = body.get("auto_publish", False)
            caption = body.get("caption", "ReelForge auto-generated 9:16 reel")
            vertical_mode = body.get("vertical_mode", "blur_padding")

            new_clip = {
                "id": clip_id,
                "video_id": video_id,
                "start_time": 0.0,
                "end_time": interval_float,
                "duration": interval_float,
                "file_url": f"/storage/media/clips/{video_id}/clip_001.mp4",
                "thumbnail_url": f"/storage/media/thumbnails/{video_id}/thumb_001.jpg",
                "metadata": {
                    "width": 1080,
                    "height": 1920,
                    "aspect_ratio": "9:16",
                    "vertical_mode": vertical_mode,
                    "video_codec": "h264",
                    "audio_codec": "aac",
                },
                "status": "ready",
            }

            publications = []
            published_platforms = []
            with GLOBAL_STATE.lock:
                GLOBAL_STATE.videos[video_id] = {
                    "id": video_id,
                    "source_url": source_url or f"local://{local_path}",
                    "status": "ready",
                    "progress_percent": 100,
                    "created_at": now,
                    "error_message": None,
                }
                GLOBAL_STATE.clips[video_id] = [new_clip]

                if auto_publish:
                    target_platforms = platforms if platforms else [
                        a["platform"] for a in GLOBAL_STATE.social_accounts if not a.get("token_expired")
                    ]
                    published_platforms = target_platforms
                    for plat in target_platforms:
                        pub_id = str(uuid.uuid4())
                        pub = {
                            "id": pub_id,
                            "clip_id": clip_id,
                            "platform": plat,
                            "status": "published",
                            "caption": caption,
                            "platform_post_id": f"mock_post_{uuid.uuid4().hex[:8]}",
                            "published_at": datetime.now(timezone.utc).isoformat(),
                        }
                        GLOBAL_STATE.publications[pub_id] = pub
                        publications.append(pub)

            self._send_json(201, {
                "message": "Video decomposed and reels dispatched for publication",
                "video": {"id": video_id, "status": "ready"},
                "clips": [new_clip],
                "publications": publications,
                "published_platforms": published_platforms,
                "status": "ready",
            })
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
