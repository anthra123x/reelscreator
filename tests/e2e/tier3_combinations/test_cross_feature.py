#!/usr/bin/env python3
"""
Tier 3 Cross-Feature Integration Tests: Multi-Module Combinations
Tests multi-stage workflows across boundaries: video slicing + vertical transformation + thumbnailing,
multi-platform publishing fan-out, Laravel-to-Axum pipeline orchestration,
metadata editing with scheduled publication, and mock account connect-and-publish flows.
"""

import json
import os
import shutil
import tempfile
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.media_generator import MediaGenerator
from tests.e2e.common.media_validator import MediaValidator
from tests.e2e.common.mock_server import EphemeralMockServer, MockSocialAdapter


class TestCrossFeatureCombinations(unittest.TestCase):
    """Verifies cross-feature interactions and inter-module contracts."""

    @classmethod
    def setUpClass(cls):
        cls.temp_dir = tempfile.mkdtemp(prefix="reelforge_tier3_")
        cls.synthetic_video = os.path.join(cls.temp_dir, "raw_16_9_input.mp4")
        MediaGenerator.generate_synthetic_video(cls.synthetic_video, duration=10.0)

        cls.mock_server = EphemeralMockServer(port=8002)
        cls.port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{cls.port}"

    @classmethod
    def tearDownClass(cls):
        cls.mock_server.stop()
        shutil.rmtree(cls.temp_dir, ignore_errors=True)

    def _http_request(self, method: str, path: str, payload: dict = None) -> tuple:
        url = f"{self.base_url}{path}"
        data = json.dumps(payload).encode("utf-8") if payload else None
        headers = {"Content-Type": "application/json"} if payload else {}
        req = urllib.request.Request(url, data=data, headers=headers, method=method)

        with urllib.request.urlopen(req, timeout=5.0) as resp:
            body = resp.read().decode("utf-8")
            return resp.status, json.loads(body) if body else {}

    def test_1_slicing_vertical_thumbnail_combo(self):
        """Combination Test 1: Slicing + 9:16 vertical blur + JPEG thumbnail extraction."""
        out_clip = os.path.join(self.temp_dir, "combo_clip_001.mp4")
        out_thumb = os.path.join(self.temp_dir, "combo_thumb_001.jpg")

        # 1. Transcode 0-5s segment to vertical 9:16 with blur background
        MediaGenerator.transcode_to_vertical_blur(
            self.synthetic_video,
            out_clip,
            start_time=0.0,
            duration=5.0,
        )

        # 2. Extract midpoint JPEG thumbnail
        MediaGenerator.generate_thumbnail_image(out_thumb, width=1080, height=1920)

        # 3. Verify media properties
        self.assertTrue(os.path.exists(out_clip))
        self.assertTrue(os.path.exists(out_thumb))
        MediaValidator.assert_vertical_9_16(out_clip)
        MediaValidator.assert_video_codec(out_clip, "h264")
        MediaValidator.assert_audio_codec(out_clip, "aac")
        MediaValidator.assert_duration_within(out_clip, 5.0, tolerance=0.5)
        MediaValidator.assert_valid_jpeg(out_thumb, expected_width=1080, expected_height=1920)

    def test_2_multi_platform_publishing_fanout(self):
        """Combination Test 2: Publishing single clip to TikTok, IG Reels, and YouTube Shorts simultaneously."""
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())
        platforms = ["tiktok", "instagram", "youtube"]

        publish_results = {}
        for p in platforms:
            adapter = MockSocialAdapter(platform=p)
            res = adapter.publish(clip_id, account_id, options={"caption": "Multi-platform reel #viral"})
            ContractValidator.validate_mock_social_publish_result(res)
            publish_results[p] = res

        # All 3 platforms must succeed with distinct IDs
        self.assertEqual(len(publish_results), 3)
        post_ids = [res["platform_post_id"] for res in publish_results.values()]
        self.assertEqual(len(set(post_ids)), 3, "Each platform publication must generate a unique post ID")

        for p in platforms:
            self.assertTrue(publish_results[p]["success"])
            self.assertTrue(publish_results[p]["platform_post_id"].startswith(f"{p}_post_"))

    def test_3_laravel_to_axum_pipeline_contract(self):
        """Combination Test 3: Laravel Video Ingest -> Axum Job Dispatch -> Clips Registration."""
        # 1. Submit video to Laravel
        status_vid, vid_data = self._http_request("POST", "/api/videos", {
            "source_url": "https://example.com/podcast_episode_1.mp4",
            "interval_seconds": 30,
        })
        self.assertEqual(status_vid, 201)
        video_id = vid_data["id"]

        # 2. Simulate dispatch to Axum engine
        axum_job_id = str(uuid.uuid4())
        status_job, job_data = self._http_request("POST", "/api/v1/jobs", {
            "job_id": axum_job_id,
            "source_url": "https://example.com/podcast_episode_1.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
            "aspect_ratio": "9:16",
            "vertical_mode": "blur_padding",
        })
        self.assertEqual(status_job, 202)
        self.assertEqual(job_data["status"], "queued")

        # 3. Retrieve Axum completed job
        status_axum, axum_res = self._http_request("GET", f"/api/v1/jobs/{axum_job_id}")
        self.assertEqual(status_axum, 200)
        self.assertEqual(axum_res["status"], "completed")
        self.assertGreater(len(axum_res["clips"]), 0)

        # 4. Verify Laravel clip query returns registered clips
        status_clips, clips_data = self._http_request("GET", f"/api/videos/{video_id}/clips")
        self.assertEqual(status_clips, 200)
        self.assertGreater(len(clips_data["data"]), 0)
        self.assertEqual(clips_data["data"][0]["video_id"], video_id)

    def test_4_clip_metadata_and_scheduling(self):
        """Combination Test 4: Clip metadata custom caption paired with scheduled publishing."""
        clip_id = str(uuid.uuid4())
        scheduled_time = "2026-11-10T18:00:00Z"
        caption = "Catch this highlight! #reel #shorts #trending"

        payload = {
            "platforms": ["tiktok", "instagram"],
            "caption": caption,
            "scheduled_at": scheduled_time,
        }
        status, data = self._http_request("POST", f"/api/clips/{clip_id}/publish", payload)
        self.assertEqual(status, 202)
        self.assertEqual(len(data["publication_ids"]), 2)
        self.assertEqual(data["status"], "queued")

    def test_5_mock_connect_and_publish_flow(self):
        """Combination Test 5: Mock OAuth Connect -> Account Persistence -> Publish Clip."""
        # 1. Connect new mock account
        connect_payload = {
            "platform": "tiktok",
            "username": "super_creator",
        }
        status_conn, conn_data = self._http_request("POST", "/api/social-accounts/mock-connect", connect_payload)
        self.assertEqual(status_conn, 201)
        account_id = conn_data["id"]
        self.assertEqual(conn_data["platform_username"], "super_creator")
        self.assertTrue(conn_data["is_mock"])

        # 2. Check accounts list includes new account
        status_list, list_data = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status_list, 200)
        usernames = [a["platform_username"] for a in list_data["data"]]
        self.assertIn("super_creator", usernames)

        # 3. Publish clip to newly connected account
        clip_id = str(uuid.uuid4())
        adapter = MockSocialAdapter(platform="tiktok")
        res_pub = adapter.publish(clip_id, account_id, options={"caption": "First clip on new account"})
        self.assertTrue(res_pub["success"])
        self.assertTrue(res_pub["platform_post_id"].startswith("tiktok_post_"))


if __name__ == "__main__":
    unittest.main()
