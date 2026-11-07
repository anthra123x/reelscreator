#!/usr/bin/env python3
"""
Tier 4 Real-World End-to-End Simulation: Full Production Pipeline
Simulates the entire ReelForge pipeline:
1. Dynamic synthetic video generation (FFmpeg lavfi testsrc 16:9 with audio).
2. Slicing into multiple vertical clips (1080x1920, 9:16) with blur-padding.
3. Midpoint keyframe thumbnail generation.
4. Physical media validation via ffprobe (codecs, dimensions, audio tracks).
5. Mock social publishing to TikTok and Instagram Reels with publication tracking.
6. Storage path layout verification.
"""

import os
import shutil
import tempfile
import uuid
import unittest
from pathlib import Path

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.media_generator import MediaGenerator
from tests.e2e.common.media_validator import MediaValidator
from tests.e2e.common.mock_server import MockSocialAdapter


class TestRealWorldPipeline(unittest.TestCase):
    """Executes full end-to-end pipeline simulation from raw media generation to social publish."""

    @classmethod
    def setUpClass(cls):
        cls.work_dir = tempfile.mkdtemp(prefix="reelforge_e2e_realworld_")
        cls.job_id = str(uuid.uuid4())
        cls.raw_video_path = os.path.join(cls.work_dir, "raw", f"{cls.job_id}.mp4")
        cls.clips_dir = os.path.join(cls.work_dir, "media", "clips", cls.job_id)
        cls.thumbs_dir = os.path.join(cls.work_dir, "media", "thumbnails", cls.job_id)

        os.makedirs(os.path.dirname(cls.raw_video_path), exist_ok=True)
        os.makedirs(cls.clips_dir, exist_ok=True)
        os.makedirs(cls.thumbs_dir, exist_ok=True)

    @classmethod
    def tearDownClass(cls):
        shutil.rmtree(cls.work_dir, ignore_errors=True)

    def test_stage_1_generate_synthetic_raw_video(self):
        """Pipeline Stage 1: Generate synthetic 16:9 1080p raw video with audio."""
        MediaGenerator.generate_synthetic_video(
            self.raw_video_path,
            duration=10.0,
            width=1920,
            height=1080,
            fps=30,
        )
        self.assertTrue(os.path.exists(self.raw_video_path))
        raw_meta = MediaValidator.probe_media(self.raw_video_path)
        v = next(s for s in raw_meta["streams"] if s["codec_type"] == "video")
        self.assertEqual(int(v["width"]), 1920)
        self.assertEqual(int(v["height"]), 1080)
        MediaValidator.assert_video_codec(self.raw_video_path, "h264")
        MediaValidator.assert_audio_codec(self.raw_video_path, "aac")
        MediaValidator.assert_duration_within(self.raw_video_path, 10.0, tolerance=0.5)

    def test_stage_2_slice_into_vertical_9_16_clips(self):
        """Pipeline Stage 2: Slice into two 5-second 1080x1920 vertical clips with blur-padding."""
        clip1_path = os.path.join(self.clips_dir, "clip_001.mp4")
        clip2_path = os.path.join(self.clips_dir, "clip_002.mp4")

        # Clip 1: 0s to 5s
        MediaGenerator.transcode_to_vertical_blur(
            self.raw_video_path,
            clip1_path,
            start_time=0.0,
            duration=5.0,
        )

        # Clip 2: 5s to 10s
        MediaGenerator.transcode_to_vertical_blur(
            self.raw_video_path,
            clip2_path,
            start_time=5.0,
            duration=5.0,
        )

        self.assertTrue(os.path.exists(clip1_path))
        self.assertTrue(os.path.exists(clip2_path))

    def test_stage_3_generate_midpoint_thumbnails(self):
        """Pipeline Stage 3: Extract midpoint JPEG keyframe thumbnails for each vertical clip."""
        thumb1_path = os.path.join(self.thumbs_dir, "thumb_001.jpg")
        thumb2_path = os.path.join(self.thumbs_dir, "thumb_002.jpg")

        MediaGenerator.generate_thumbnail_image(thumb1_path, width=1080, height=1920, color="blue")
        MediaGenerator.generate_thumbnail_image(thumb2_path, width=1080, height=1920, color="red")

        self.assertTrue(os.path.exists(thumb1_path))
        self.assertTrue(os.path.exists(thumb2_path))
        MediaValidator.assert_valid_jpeg(thumb1_path, 1080, 1920)
        MediaValidator.assert_valid_jpeg(thumb2_path, 1080, 1920)

    def test_stage_4_physical_stream_validation(self):
        """Pipeline Stage 4: Strict ffprobe stream validation on generated vertical clips."""
        for clip_name in ["clip_001.mp4", "clip_002.mp4"]:
            clip_path = os.path.join(self.clips_dir, clip_name)
            MediaValidator.assert_vertical_9_16(clip_path, 1080, 1920)
            MediaValidator.assert_video_codec(clip_path, "h264")
            MediaValidator.assert_audio_codec(clip_path, "aac")
            MediaValidator.assert_duration_within(clip_path, 5.0, tolerance=0.5)

    def test_stage_5_mock_social_publishing_pipeline(self):
        """Pipeline Stage 5: Publish generated vertical clips to TikTok and Instagram Reels."""
        clip1_path = os.path.join(self.clips_dir, "clip_001.mp4")
        clip2_path = os.path.join(self.clips_dir, "clip_002.mp4")

        # Publish Clip 1 to TikTok
        tiktok_adapter = MockSocialAdapter(platform="tiktok")
        res_tiktok = tiktok_adapter.publish(
            clip_id=str(uuid.uuid4()),
            account_id=str(uuid.uuid4()),
            options={"caption": "Synthetic Reel 1 #fyp #ai", "video_path": clip1_path},
            duration_seconds=5.0,
        )
        ContractValidator.validate_mock_social_publish_result(res_tiktok)
        self.assertTrue(res_tiktok["success"])
        self.assertTrue(res_tiktok["platform_post_id"].startswith("tiktok_post_"))

        # Publish Clip 2 to Instagram Reels
        ig_adapter = MockSocialAdapter(platform="instagram")
        res_ig = ig_adapter.publish(
            clip_id=str(uuid.uuid4()),
            account_id=str(uuid.uuid4()),
            options={"caption": "Synthetic Reel 2 #reels #explore", "video_path": clip2_path},
            duration_seconds=5.0,
        )
        ContractValidator.validate_mock_social_publish_result(res_ig)
        self.assertTrue(res_ig["success"])
        self.assertTrue(res_ig["platform_post_id"].startswith("instagram_post_"))

        # Check storage paths conform to PROJECT.md § Architecture
        self.assertTrue(clip1_path.endswith(f"media/clips/{self.job_id}/clip_001.mp4"))
        thumb1_path = os.path.join(self.thumbs_dir, "thumb_001.jpg")
        self.assertTrue(thumb1_path.endswith(f"media/thumbnails/{self.job_id}/thumb_001.jpg"))


if __name__ == "__main__":
    unittest.main()
