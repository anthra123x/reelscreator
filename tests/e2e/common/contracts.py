#!/usr/bin/env python3
"""
Formal interface contract validators for ReelForge.
Validates requests and responses against specifications defined in PROJECT.md.
"""

import uuid
from typing import Any, Dict, List, Optional


class ContractValidationError(AssertionError):
    """Raised when an API payload violates the interface contract."""
    pass


class ContractValidator:
    """Validates payloads against ReelForge interface specifications."""

    @staticmethod
    def validate_uuid(value: Any, field_name: str = "id") -> str:
        """Validate that value is a valid UUID string."""
        if not isinstance(value, str):
            raise ContractValidationError(f"Field '{field_name}' must be a string, got {type(value)}")
        try:
            uuid.UUID(value)
            return value
        except ValueError:
            raise ContractValidationError(f"Field '{field_name}' is not a valid UUID: {value}")

    @staticmethod
    def validate_axum_health_response(data: Dict[str, Any]) -> None:
        """Validate Axum GET /health contract."""
        if not isinstance(data, dict):
            raise ContractValidationError("Health response must be a JSON object")

        if data.get("status") != "healthy":
            raise ContractValidationError(f"Expected status 'healthy', got '{data.get('status')}'")

        if "version" not in data or not isinstance(data["version"], str):
            raise ContractValidationError("Field 'version' must be a non-empty string")

        concurrency = data.get("concurrency")
        if not isinstance(concurrency, dict):
            raise ContractValidationError("Field 'concurrency' must be an object")

        for key in ["active_jobs", "max_concurrent_jobs", "available_permits"]:
            if key not in concurrency or not isinstance(concurrency[key], int):
                raise ContractValidationError(f"Concurrency field '{key}' must be an integer")

        if "uptime_seconds" not in data or not isinstance(data["uptime_seconds"], (int, float)):
            raise ContractValidationError("Field 'uptime_seconds' must be a numeric value")

    @staticmethod
    def validate_axum_job_request(data: Dict[str, Any]) -> None:
        """Validate Axum POST /api/v1/jobs request contract."""
        ContractValidator.validate_uuid(data.get("job_id"), "job_id")

        has_url = data.get("source_url") is not None
        has_path = data.get("local_video_path") is not None
        if not (has_url or has_path):
            raise ContractValidationError("Job request must specify either 'source_url' or 'local_video_path'")

        strategy = data.get("strategy")
        valid_strategies = ["fixed_interval", "scene_detection", "silence_detection", "manual_timestamps"]
        if strategy not in valid_strategies:
            raise ContractValidationError(f"Strategy '{strategy}' must be one of {valid_strategies}")

        aspect_ratio = data.get("aspect_ratio", "9:16")
        if aspect_ratio != "9:16":
            raise ContractValidationError(f"Aspect ratio must be '9:16', got '{aspect_ratio}'")

        mode = data.get("vertical_mode", "blur_padding")
        valid_modes = ["blur_padding", "center_crop", "letterbox"]
        if mode not in valid_modes:
            raise ContractValidationError(f"vertical_mode '{mode}' must be one of {valid_modes}")

    @staticmethod
    def validate_axum_job_accepted_response(data: Dict[str, Any]) -> None:
        """Validate Axum POST /api/v1/jobs 202 Accepted response contract."""
        ContractValidator.validate_uuid(data.get("job_id"), "job_id")
        if data.get("status") != "queued":
            raise ContractValidationError(f"Expected status 'queued', got '{data.get('status')}'")
        if not data.get("message"):
            raise ContractValidationError("Accepted response must contain a non-empty message")

    @staticmethod
    def validate_axum_job_completed_response(data: Dict[str, Any]) -> None:
        """Validate Axum GET /api/v1/jobs/:id completed response contract."""
        ContractValidator.validate_uuid(data.get("job_id"), "job_id")
        if data.get("status") != "completed":
            raise ContractValidationError(f"Expected status 'completed', got '{data.get('status')}'")

        if data.get("progress_percent") != 100:
            raise ContractValidationError(f"Expected progress_percent 100, got {data.get('progress_percent')}")

        clips = data.get("clips")
        if not isinstance(clips, list) or len(clips) == 0:
            raise ContractValidationError("Completed job must contain a non-empty 'clips' array")

        for i, clip in enumerate(clips):
            ContractValidator.validate_uuid(clip.get("clip_id"), f"clips[{i}].clip_id")
            if not clip.get("file_path"):
                raise ContractValidationError(f"clips[{i}] missing file_path")
            if not clip.get("thumbnail_path"):
                raise ContractValidationError(f"clips[{i}] missing thumbnail_path")
            if clip.get("width") != 1080 or clip.get("height") != 1920:
                raise ContractValidationError(
                    f"clips[{i}] must be 1080x1920, got {clip.get('width')}x{clip.get('height')}"
                )
            if float(clip.get("duration", 0)) <= 0:
                raise ContractValidationError(f"clips[{i}] duration must be positive")

    @staticmethod
    def validate_laravel_video_created_response(data: Dict[str, Any]) -> None:
        """Validate Laravel POST /api/videos 201 Created response contract."""
        ContractValidator.validate_uuid(data.get("id"), "id")
        if data.get("status") not in ("pending", "processing"):
            raise ContractValidationError(f"Initial video status must be 'pending' or 'processing', got '{data.get('status')}'")
        if not data.get("created_at"):
            raise ContractValidationError("Created video response missing 'created_at'")

    @staticmethod
    def validate_laravel_clips_response(data: Dict[str, Any]) -> None:
        """Validate Laravel GET /api/videos/:id/clips response contract."""
        clips = data.get("data")
        if not isinstance(clips, list):
            raise ContractValidationError("Clips response must have a 'data' array")

        for i, clip in enumerate(clips):
            ContractValidator.validate_uuid(clip.get("id"), f"data[{i}].id")
            ContractValidator.validate_uuid(clip.get("video_id"), f"data[{i}].video_id")
            if not clip.get("file_url"):
                raise ContractValidationError(f"data[{i}] missing file_url")
            if not clip.get("thumbnail_url"):
                raise ContractValidationError(f"data[{i}] missing thumbnail_url")
            if float(clip.get("duration", 0)) <= 0:
                raise ContractValidationError(f"data[{i}] duration must be positive")

    @staticmethod
    def validate_laravel_publish_accepted_response(data: Dict[str, Any]) -> None:
        """Validate Laravel POST /api/clips/:id/publish 202 Accepted response contract."""
        pub_ids = data.get("publication_ids")
        if not isinstance(pub_ids, list) or len(pub_ids) == 0:
            raise ContractValidationError("Publish response must contain non-empty 'publication_ids' array")
        for i, pid in enumerate(pub_ids):
            ContractValidator.validate_uuid(pid, f"publication_ids[{i}]")
        if data.get("status") != "queued":
            raise ContractValidationError(f"Expected status 'queued', got '{data.get('status')}'")

    @staticmethod
    def validate_mock_social_publish_result(result: Dict[str, Any]) -> None:
        """Validate MockSocialAdapter publish result contract."""
        if not isinstance(result, dict):
            raise ContractValidationError("PublishResult must be an object")
        if not isinstance(result.get("success"), bool):
            raise ContractValidationError("PublishResult 'success' must be a boolean")
        if result.get("success"):
            if not result.get("platform_post_id"):
                raise ContractValidationError("Successful publication must contain 'platform_post_id'")
            if not result.get("published_at"):
                raise ContractValidationError("Successful publication must contain 'published_at'")
        else:
            if not result.get("error"):
                raise ContractValidationError("Failed publication must contain 'error' message")

    @staticmethod
    def validate_video_decomposition_response(data: Dict[str, Any]) -> None:
        """Validate POST /api/videos/{id}/decompose or POST /api/videos/decompose contract."""
        if not isinstance(data, dict):
            raise ContractValidationError("Decomposition response must be a JSON object")

        if not data.get("message"):
            raise ContractValidationError("Decomposition response missing 'message'")

        video = data.get("video")
        if not isinstance(video, dict):
            raise ContractValidationError("Decomposition response missing 'video' object")
        ContractValidator.validate_uuid(video.get("id"), "video.id")

        clips = data.get("clips")
        if not isinstance(clips, list) or len(clips) == 0:
            raise ContractValidationError("Decomposition response must contain a non-empty 'clips' array")

        for i, clip in enumerate(clips):
            ContractValidator.validate_uuid(clip.get("id"), f"clips[{i}].id")
            if "start_time" not in clip or "end_time" not in clip:
                raise ContractValidationError(f"clips[{i}] must specify start_time and end_time")
            if float(clip.get("duration", 0)) <= 0:
                raise ContractValidationError(f"clips[{i}] duration must be positive")

            meta = clip.get("metadata", {})
            if meta.get("width") != 1080 or meta.get("height") != 1920:
                raise ContractValidationError(
                    f"clips[{i}].metadata must specify 1080x1920, got {meta.get('width')}x{meta.get('height')}"
                )
            if meta.get("aspect_ratio") != "9:16":
                raise ContractValidationError(
                    f"clips[{i}].metadata must specify aspect_ratio '9:16', got '{meta.get('aspect_ratio')}'"
                )

        publications = data.get("publications", [])
        if not isinstance(publications, list):
            raise ContractValidationError("Field 'publications' must be a list")
        for j, pub in enumerate(publications):
            ContractValidator.validate_uuid(pub.get("id"), f"publications[{j}].id")
            ContractValidator.validate_uuid(pub.get("clip_id"), f"publications[{j}].clip_id")
            if pub.get("platform") not in ("tiktok", "instagram", "youtube"):
                raise ContractValidationError(f"Invalid platform in publication: {pub.get('platform')}")

    @staticmethod
    def validate_social_accounts_connect_response(data: Dict[str, Any]) -> None:
        """Validate POST /api/social-accounts/connect contract."""
        if not isinstance(data, dict):
            raise ContractValidationError("Connect response must be a JSON object")

        if not data.get("message"):
            raise ContractValidationError("Connect response missing 'message'")

        connected = data.get("connected")
        if not isinstance(connected, list) or len(connected) == 0:
            raise ContractValidationError("Field 'connected' must be a non-empty list of connected accounts")

        for i, acc in enumerate(connected):
            ContractValidator.validate_uuid(acc.get("id"), f"connected[{i}].id")
            if acc.get("platform") not in ("tiktok", "instagram", "youtube"):
                raise ContractValidationError(f"connected[{i}] invalid platform '{acc.get('platform')}'")
            if not acc.get("platform_username"):
                raise ContractValidationError(f"connected[{i}] missing platform_username")
            if not isinstance(acc.get("is_mock"), bool):
                raise ContractValidationError(f"connected[{i}] 'is_mock' must be boolean")
            if acc.get("status") != "connected":
                raise ContractValidationError(f"connected[{i}] status must be 'connected'")

    @staticmethod
    def validate_dashboard_metrics_response(data: Dict[str, Any]) -> None:
        """Validate aggregated dashboard metrics contract."""
        if not isinstance(data, dict):
            raise ContractValidationError("Dashboard metrics must be a JSON object")

        for key in ["total_videos", "total_clips", "connected_accounts"]:
            if key not in data or not isinstance(data[key], int) or data[key] < 0:
                raise ContractValidationError(f"Dashboard metric '{key}' must be a non-negative integer")

        pubs = data.get("publications")
        if not isinstance(pubs, dict):
            raise ContractValidationError("Dashboard metrics missing 'publications' object")

        for pub_key in ["total", "published", "pending"]:
            if pub_key not in pubs or not isinstance(pubs[pub_key], int) or pubs[pub_key] < 0:
                raise ContractValidationError(f"Publication metric '{pub_key}' must be a non-negative integer")

