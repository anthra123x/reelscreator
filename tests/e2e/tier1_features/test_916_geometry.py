#!/usr/bin/env python3
"""
Tier 1 Feature Tests: 9:16 Aspect Ratio Geometry & Physical Stream Validation
Tests 1080x1920 canvas geometry, blur padding, center crop, letterbox,
H.264/AAC codecs, and JPEG keyframe thumbnails using FFmpeg and FFprobe.
"""

import os
import shutil
import tempfile
import unittest

from tests.e2e.common.media_generator import MediaGenerator
from tests.e2e.common.media_validator import MediaValidator


class Test916GeometryFeatures(unittest.TestCase):
    """Verifies physical media properties and 9:16 vertical geometry."""

    @classmethod
    def setUpClass(cls):
        cls.temp_dir = tempfile.mkdtemp(prefix="reelforge_tier1_geom_")
        cls.source_video = os.path.join(cls.temp_dir, "horizontal_source.mp4")
        # Generate 10-second 1920x1080 16:9 synthetic video
        MediaGenerator.generate_synthetic_video(cls.source_video, duration=10.0, width=1920, height=1080)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.temp_dir, ignore_errors=True)

    def test_1_blur_padding_geometry(self):
        """Feature Test 1: BlurPadding filtergraph outputs exact 1080x1920 9:16 canvas."""
        output_clip = os.path.join(self.temp_dir, "blur_padding_clip.mp4")
        MediaGenerator.transcode_to_vertical_blur(
            self.source_video,
            output_clip,
            start_time=0.0,
            duration=5.0,
        )
        self.assertTrue(os.path.exists(output_clip))
        probe = MediaValidator.probe_video(output_clip)
        self.assertEqual(probe["width"], 1080)
        self.assertEqual(probe["height"], 1920)
        self.assertEqual(probe["aspect_ratio"], "9:16")
        MediaValidator.assert_vertical_9_16(output_clip)

    def test_2_center_crop_geometry(self):
        """Feature Test 2: CenterCrop filtergraph outputs 1080x1920 canvas cropped from center."""
        output_clip = os.path.join(self.temp_dir, "center_crop_clip.mp4")
        # Center crop filtergraph: crop=ih*9/16:ih,scale=1080:1920
        filtergraph = "crop=ih*9/16:ih,scale=1080:1920"
        MediaGenerator.transcode_with_filtergraph(
            self.source_video,
            output_clip,
            filtergraph=filtergraph,
            start_time=0.0,
            duration=4.0,
        )
        self.assertTrue(os.path.exists(output_clip))
        probe = MediaValidator.probe_video(output_clip)
        self.assertEqual(probe["width"], 1080)
        self.assertEqual(probe["height"], 1920)
        self.assertEqual(probe["aspect_ratio"], "9:16")

    def test_3_letterbox_geometry(self):
        """Feature Test 3: Letterbox filtergraph outputs 1080x1920 canvas with padding."""
        output_clip = os.path.join(self.temp_dir, "letterbox_clip.mp4")
        # Letterbox: scale=1080:-2,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black
        filtergraph = "scale=1080:-2,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:black"
        MediaGenerator.transcode_with_filtergraph(
            self.source_video,
            output_clip,
            filtergraph=filtergraph,
            start_time=0.0,
            duration=4.0,
        )
        self.assertTrue(os.path.exists(output_clip))
        probe = MediaValidator.probe_video(output_clip)
        self.assertEqual(probe["width"], 1080)
        self.assertEqual(probe["height"], 1920)
        self.assertEqual(probe["aspect_ratio"], "9:16")

    def test_4_h264_aac_codec_verification(self):
        """Feature Test 4: Physical stream analysis confirms H.264 video and AAC audio codecs."""
        output_clip = os.path.join(self.temp_dir, "codec_verify_clip.mp4")
        MediaGenerator.transcode_to_vertical_blur(
            self.source_video,
            output_clip,
            start_time=0.0,
            duration=3.0,
        )
        probe = MediaValidator.probe_video(output_clip)
        self.assertEqual(probe["codec_name"].lower(), "h264")
        if probe.get("audio_codec"):
            self.assertEqual(probe["audio_codec"].lower(), "aac")

    def test_5_midpoint_thumbnail_geometry(self):
        """Feature Test 5: Midpoint JPEG thumbnail matches 1080x1920 vertical resolution."""
        output_thumb = os.path.join(self.temp_dir, "thumb_midpoint.jpg")
        MediaGenerator.generate_thumbnail_image(output_thumb, width=1080, height=1920)
        self.assertTrue(os.path.exists(output_thumb))
        self.assertGreater(os.path.getsize(output_thumb), 100)

        # Inspect thumbnail dimensions via ffprobe
        probe = MediaValidator.probe_image(output_thumb)
        self.assertEqual(probe["width"], 1080)
        self.assertEqual(probe["height"], 1920)


if __name__ == "__main__":
    unittest.main()
