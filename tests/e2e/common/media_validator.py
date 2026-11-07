#!/usr/bin/env python3
"""
Media validator utility for ReelForge E2E test suite.
Validates physical video stream properties, codecs, dimensions, and audio tracks via ffprobe.
"""

import json
import subprocess
from pathlib import Path
from typing import Any, Dict, Optional


class MediaValidator:
    """Probes and verifies media file properties via ffprobe."""

    @staticmethod
    def probe_media(file_path: str) -> Dict[str, Any]:
        """Run ffprobe on target file and return structured JSON metadata."""
        path = Path(file_path)
        if not path.exists():
            raise FileNotFoundError(f"Media file does not exist: {file_path}")

        cmd = [
            "ffprobe",
            "-v", "error",
            "-print_format", "json",
            "-show_format",
            "-show_streams",
            str(path),
        ]

        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"ffprobe execution failed on {file_path}: {result.stderr}")

        return json.loads(result.stdout)

    @staticmethod
    def assert_vertical_9_16(file_path: str, expected_width: int = 1080, expected_height: int = 1920) -> None:
        """Assert that media has vertical 9:16 aspect ratio with exact width and height."""
        meta = MediaValidator.probe_media(file_path)
        video_streams = [s for s in meta.get("streams", []) if s.get("codec_type") == "video"]
        if not video_streams:
            raise AssertionError(f"No video stream found in {file_path}")

        v = video_streams[0]
        actual_width = int(v.get("width", 0))
        actual_height = int(v.get("height", 0))

        if actual_width != expected_width or actual_height != expected_height:
            raise AssertionError(
                f"Expected dimensions {expected_width}x{expected_height} (9:16), "
                f"got {actual_width}x{actual_height} in {file_path}"
            )

    @staticmethod
    def assert_video_codec(file_path: str, expected_codec: str = "h264") -> None:
        """Assert that primary video stream matches expected codec."""
        meta = MediaValidator.probe_media(file_path)
        video_streams = [s for s in meta.get("streams", []) if s.get("codec_type") == "video"]
        if not video_streams:
            raise AssertionError(f"No video stream found in {file_path}")

        actual_codec = video_streams[0].get("codec_name", "")
        if actual_codec.lower() != expected_codec.lower():
            raise AssertionError(
                f"Expected video codec {expected_codec}, got {actual_codec} in {file_path}"
            )

    @staticmethod
    def assert_audio_codec(file_path: str, expected_codec: str = "aac") -> None:
        """Assert that primary audio stream matches expected codec."""
        meta = MediaValidator.probe_media(file_path)
        audio_streams = [s for s in meta.get("streams", []) if s.get("codec_type") == "audio"]
        if not audio_streams:
            raise AssertionError(f"No audio stream found in {file_path}")

        actual_codec = audio_streams[0].get("codec_name", "")
        if actual_codec.lower() != expected_codec.lower():
            raise AssertionError(
                f"Expected audio codec {expected_codec}, got {actual_codec} in {file_path}"
            )

    @staticmethod
    def assert_duration_within(file_path: str, expected_seconds: float, tolerance: float = 0.5) -> None:
        """Assert that duration is within tolerance of expected duration."""
        meta = MediaValidator.probe_media(file_path)
        fmt = meta.get("format", {})
        actual_duration = float(fmt.get("duration", 0.0))

        diff = abs(actual_duration - expected_seconds)
        if diff > tolerance:
            raise AssertionError(
                f"Expected duration {expected_seconds}s (+/- {tolerance}s), "
                f"got {actual_duration}s in {file_path} (diff: {diff:.3f}s)"
            )

    @staticmethod
    def assert_valid_jpeg(file_path: str, expected_width: int = 1080, expected_height: int = 1920) -> None:
        """Assert that file is a readable JPEG image with valid dimensions."""
        path = Path(file_path)
        if not path.exists() or path.stat().st_size == 0:
            raise AssertionError(f"Thumbnail file missing or empty: {file_path}")

        meta = MediaValidator.probe_media(file_path)
        video_streams = [s for s in meta.get("streams", []) if s.get("codec_type") == "video"]
        if not video_streams:
            raise AssertionError(f"No image stream found in thumbnail: {file_path}")

        img = video_streams[0]
        codec = img.get("codec_name", "")
        if codec.lower() not in ("mjpeg", "jpeg"):
            raise AssertionError(f"Expected MJPEG/JPEG codec for thumbnail, got {codec}")

        actual_width = int(img.get("width", 0))
        actual_height = int(img.get("height", 0))
        if actual_width != expected_width or actual_height != expected_height:
            raise AssertionError(
                f"Expected thumbnail dimensions {expected_width}x{expected_height}, "
                f"got {actual_width}x{actual_height}"
            )
