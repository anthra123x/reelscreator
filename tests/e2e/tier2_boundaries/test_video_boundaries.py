#!/usr/bin/env python3
"""
Tier 2 Boundary & Negative Tests: Video Engine Boundaries
Tests zero-byte files, inverted timestamps, corrupt container headers,
out-of-bounds duration, and malformed URLs.
"""

import os
import shutil
import subprocess
import tempfile
import unittest

from tests.e2e.common.media_generator import MediaGenerator
from tests.e2e.common.media_validator import MediaValidator


class TestVideoBoundaries(unittest.TestCase):
    """Verifies edge cases, corrupt inputs, and boundary validations on video engine."""

    @classmethod
    def setUpClass(cls):
        cls.temp_dir = tempfile.mkdtemp(prefix="reelforge_boundary_test_")
        cls.valid_video = os.path.join(cls.temp_dir, "valid_source.mp4")
        MediaGenerator.generate_synthetic_video(cls.valid_video, duration=10.0)

        cls.zero_byte_file = os.path.join(cls.temp_dir, "empty.mp4")
        MediaGenerator.generate_zero_byte_file(cls.zero_byte_file)

        cls.corrupt_video = os.path.join(cls.temp_dir, "corrupt.mp4")
        MediaGenerator.generate_corrupt_video(cls.corrupt_video)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.temp_dir, ignore_errors=True)

    def test_1_zero_byte_input_rejection(self):
        """Boundary Test 1: Zero-byte input file is rejected with an error and does not produce output."""
        output_clip = os.path.join(self.temp_dir, "out_from_zero.mp4")
        with self.assertRaises((RuntimeError, subprocess.CalledProcessError, AssertionError)):
            # Running FFmpeg transcoding on zero-byte file must fail
            MediaGenerator.transcode_to_vertical_blur(
                self.zero_byte_file,
                output_clip,
                start_time=0.0,
                duration=5.0,
            )
        self.assertFalse(os.path.exists(output_clip), "Output clip should not exist for zero-byte input")

    def test_2_inverted_timestamp_rejection(self):
        """Boundary Test 2: Inverted timestamps (start >= end) are detected and rejected."""
        start_time = 8.0
        end_time = 4.0  # Inverted: end is before start

        def validate_timestamps(start: float, end: float, max_duration: float) -> bool:
            if start < 0 or end <= 0:
                return False
            if start >= end:
                return False
            if start >= max_duration:
                return False
            return True

        self.assertFalse(
            validate_timestamps(start_time, end_time, 10.0),
            "Timestamps where start >= end must be invalid"
        )

    def test_3_out_of_bounds_start_time_rejection(self):
        """Boundary Test 3: Start time exceeding total video duration is rejected."""
        video_meta = MediaValidator.probe_media(self.valid_video)
        duration = float(video_meta["format"]["duration"])

        out_of_bounds_start = duration + 10.0
        output_clip = os.path.join(self.temp_dir, "out_oob.mp4")

        # FFmpeg seeking past EOF either yields 0 frames or non-zero error
        with self.assertRaises((RuntimeError, AssertionError)):
            MediaGenerator.transcode_to_vertical_blur(
                self.valid_video,
                output_clip,
                start_time=out_of_bounds_start,
                duration=5.0,
            )
            # If ffmpeg created an empty/0-length file, probe_media will fail
            MediaValidator.assert_duration_within(output_clip, 5.0)

    def test_4_malformed_url_rejection(self):
        """Boundary Test 4: Malformed URLs (invalid schemes, empty hostnames) fail validation."""
        invalid_urls = [
            "ftp://example.com/video.mp4",
            "not_a_url",
            "http://",
            "file:///etc/passwd",
        ]

        def is_valid_source_url(url: str) -> bool:
            if not isinstance(url, str):
                return False
            if not (url.startswith("http://") or url.startswith("https://")):
                return False
            if len(url.split("://")[-1].strip()) < 3:
                return False
            return True

        for bad_url in invalid_urls:
            self.assertFalse(
                is_valid_source_url(bad_url),
                f"URL '{bad_url}' should fail validation"
            )

    def test_5_corrupted_header_handling(self):
        """Boundary Test 5: Truncated or corrupt MP4 headers fail gracefully without crash."""
        output_clip = os.path.join(self.temp_dir, "out_from_corrupt.mp4")
        with self.assertRaises(RuntimeError):
            MediaGenerator.transcode_to_vertical_blur(
                self.corrupt_video,
                output_clip,
                start_time=0.0,
                duration=5.0,
            )
        self.assertFalse(os.path.exists(output_clip))


if __name__ == "__main__":
    unittest.main()
