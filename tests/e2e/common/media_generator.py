#!/usr/bin/env python3
"""
Media generator utility for ReelForge E2E test suite.
Generates synthetic video fixtures, vertical reels, thumbnails, and boundary test files
using local FFmpeg binaries without external network downloads.
"""

import os
import subprocess
import tempfile
from pathlib import Path


class MediaGenerator:
    """Generates synthetic media fixtures for testing."""

    @staticmethod
    def generate_synthetic_video(
        output_path: str,
        duration: float = 10.0,
        width: int = 1920,
        height: int = 1080,
        fps: int = 30,
    ) -> str:
        """
        Generate a synthetic 16:9 test video with color pattern and 1kHz sine audio tone.
        Enforces -threads 1 to prevent host CPU starvation on single-core environments.
        """
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)

        cmd = [
            "ffmpeg",
            "-y",
            "-threads", "1",
            "-f", "lavfi",
            "-i", f"testsrc=duration={duration}:size={width}x{height}:rate={fps}",
            "-f", "lavfi",
            "-i", f"sine=frequency=1000:duration={duration}",
            "-c:v", "libx264",
            "-preset", "ultrafast",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac",
            "-b:a", "128k",
            "-shortest",
            str(output),
        ]

        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg synthetic video generation failed: {result.stderr}")

        return str(output)

    @staticmethod
    def generate_vertical_video(
        output_path: str,
        duration: float = 5.0,
        width: int = 1080,
        height: int = 1920,
        fps: int = 30,
    ) -> str:
        """Generate a native 9:16 vertical test video fixture."""
        return MediaGenerator.generate_synthetic_video(
            output_path, duration=duration, width=width, height=height, fps=fps
        )

    @staticmethod
    def generate_thumbnail_image(
        output_path: str,
        width: int = 1080,
        height: int = 1920,
        color: str = "blue",
    ) -> str:
        """Generate a synthetic JPEG thumbnail image."""
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)

        cmd = [
            "ffmpeg",
            "-y",
            "-threads", "1",
            "-f", "lavfi",
            "-i", f"color=c={color}:s={width}x{height}:d=0.1",
            "-frames:v", "1",
            "-q:v", "2",
            str(output),
        ]

        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg thumbnail generation failed: {result.stderr}")

        return str(output)

    @staticmethod
    def generate_zero_byte_file(output_path: str) -> str:
        """Create an empty zero-byte file for boundary testing."""
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_bytes(b"")
        return str(output)

    @staticmethod
    def generate_corrupt_video(output_path: str) -> str:
        """Create a file with corrupt MP4 headers for boundary testing."""
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)
        # Write corrupted header bytes that do not form a valid atom
        output.write_bytes(b"\x00\x00\x00\x20ftypBAD_ATOM_HEADER_FOR_TESTING_DATA_TRUNCATED")
        return str(output)

    @staticmethod
    def transcode_to_vertical_blur(
        input_path: str,
        output_path: str,
        start_time: float = 0.0,
        duration: float = 5.0,
    ) -> str:
        """
        Reference implementation of the 9:16 vertical blur-padding filtergraph:
        Splits source, scales background to 1080x1920 with boxblur, scales foreground
        to 1080 width preserving aspect ratio, overlays foreground centered on background.
        """
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)

        filter_complex = (
            "[0:v]split=2[bg][fg];"
            "[bg]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:5[blurred];"
            "[fg]scale=1080:-1:force_original_aspect_ratio=decrease[scaled];"
            "[blurred][scaled]overlay=(W-w)/2:(H-h)/2[v]"
        )

        cmd = [
            "ffmpeg",
            "-y",
            "-threads", "1",
            "-ss", str(start_time),
            "-t", str(duration),
            "-i", str(input_path),
            "-filter_complex", filter_complex,
            "-map", "[v]",
            "-map", "0:a?",
            "-c:v", "libx264",
            "-preset", "ultrafast",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac",
            "-b:a", "128k",
            str(output),
        ]

        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg vertical blur transcoding failed: {result.stderr}")

        return str(output)

    @staticmethod
    def transcode_with_filtergraph(
        input_path: str,
        output_path: str,
        filtergraph: str,
        start_time: float = 0.0,
        duration: float = 5.0,
    ) -> str:
        """Transcode video using a custom FFmpeg filtergraph."""
        output = Path(output_path)
        output.parent.mkdir(parents=True, exist_ok=True)

        cmd = [
            "ffmpeg",
            "-y",
            "-threads", "1",
            "-ss", str(start_time),
            "-t", str(duration),
            "-i", str(input_path),
            "-vf", filtergraph,
            "-c:v", "libx264",
            "-preset", "ultrafast",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac",
            "-b:a", "128k",
            str(output),
        ]

        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg custom filtergraph transcoding failed: {result.stderr}")

        return str(output)
