#!/usr/bin/env python3
"""
Tier 2 Boundary Tests: Duration & Platform Limit Constraints
Tests YouTube Shorts <=60s limit, Instagram Reels <=90s limit, TikTok limit,
minimum clip duration boundary (<1s), and accepted duration boundaries.
"""

import uuid
import unittest

from tests.e2e.common.mock_server import MockSocialAdapter


class TestDurationAndPlatformLimits(unittest.TestCase):
    """Verifies duration limits and platform constraint violations across all channels."""

    def test_1_youtube_shorts_60s_cap(self):
        """Boundary Test 1: Clips > 60.0s are rejected for YouTube Shorts with PlatformConstraintViolation."""
        adapter = MockSocialAdapter(platform="youtube")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        # 60.1s exceeds cap
        res = adapter.publish(clip_id, account_id, duration_seconds=60.1)
        self.assertFalse(res["success"])
        self.assertIn("PlatformConstraintViolation", res["error"])
        self.assertIn("60.0s", res["error"])

    def test_2_instagram_reels_90s_cap(self):
        """Boundary Test 2: Clips > 90.0s are rejected for Instagram Reels with PlatformConstraintViolation."""
        adapter = MockSocialAdapter(platform="instagram")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        # 95.0s exceeds Instagram Reels 90s cap
        res = adapter.publish(clip_id, account_id, duration_seconds=95.0)
        self.assertFalse(res["success"])
        self.assertIn("PlatformConstraintViolation", res["error"])
        self.assertIn("90.0s", res["error"])

    def test_3_tiktok_duration_limit(self):
        """Boundary Test 3: Clips exceeding TikTok max duration (>180s) are rejected."""
        adapter = MockSocialAdapter(platform="tiktok")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        res = adapter.publish(clip_id, account_id, duration_seconds=200.0)
        self.assertFalse(res["success"])
        self.assertIn("PlatformConstraintViolation", res["error"])

    def test_4_minimum_clip_duration_bound(self):
        """Boundary Test 4: Sub-second clips (< 1.0s) are rejected as sub-minimum duration."""
        adapter = MockSocialAdapter(platform="tiktok")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        res = adapter.publish(clip_id, account_id, duration_seconds=0.5)
        self.assertFalse(res["success"])
        self.assertIn("PlatformConstraintViolation", res["error"])
        self.assertIn("1.0s", res["error"])

    def test_5_duration_within_bounds_accepted(self):
        """Boundary Test 5: Clips within valid duration limits are accepted across all platforms."""
        yt_adapter = MockSocialAdapter(platform="youtube")
        ig_adapter = MockSocialAdapter(platform="instagram")
        tt_adapter = MockSocialAdapter(platform="tiktok")

        clip_id = str(uuid.uuid4())
        acc_id = str(uuid.uuid4())

        yt_res = yt_adapter.publish(clip_id, acc_id, duration_seconds=58.0)
        self.assertTrue(yt_res["success"])

        ig_res = ig_adapter.publish(clip_id, acc_id, duration_seconds=88.0)
        self.assertTrue(ig_res["success"])

        tt_res = tt_adapter.publish(clip_id, acc_id, duration_seconds=150.0)
        self.assertTrue(tt_res["success"])


if __name__ == "__main__":
    unittest.main()
