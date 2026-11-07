#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Modular Laravel REST API
Tests HTTP contracts for /api/videos, /api/videos/:id/status, /api/videos/:id/clips,
/api/clips/:id/publish, and /api/social-accounts.
"""

import json
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer


class TestLaravelApiFeatures(unittest.TestCase):
    """Verifies Laravel REST endpoints against PROJECT.md specifications."""

    @classmethod
    def setUpClass(cls):
        # Probe if live Laravel backend is running on port 8000
        cls.base_url = "http://127.0.0.1:8000"
        cls.mock_server = None

        try:
            req = urllib.request.Request(f"{cls.base_url}/api/social-accounts")
            with urllib.request.urlopen(req, timeout=0.5) as resp:
                if resp.status == 200:
                    cls.is_live = True
                    return
        except Exception:
            cls.is_live = False

        # Launch ephemeral mock server implementing the Laravel contracts
        cls.mock_server = EphemeralMockServer(port=8000)
        port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{port}"

    @classmethod
    def tearDownClass(cls):
        if cls.mock_server:
            cls.mock_server.stop()

    def _http_request(self, method: str, path: str, payload: dict = None) -> tuple:
        """Helper to send HTTP request and return (status_code, response_dict)."""
        url = f"{self.base_url}{path}"
        data = json.dumps(payload).encode("utf-8") if payload else None
        headers = {"Content-Type": "application/json"} if payload else {}
        req = urllib.request.Request(url, data=data, headers=headers, method=method)

        try:
            with urllib.request.urlopen(req, timeout=3.0) as resp:
                body = resp.read().decode("utf-8")
                return resp.status, json.loads(body) if body else {}
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8")
            return e.code, json.loads(body) if body else {}

    def test_1_video_submission(self):
        """Feature Test 1: POST /api/videos accepts URL and returns 201 Created with UUID."""
        payload = {
            "source_url": "https://example.com/raw_video.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
            "vertical_mode": "blur_padding",
        }
        status, data = self._http_request("POST", "/api/videos", payload)
        self.assertEqual(status, 201)
        ContractValidator.validate_laravel_video_created_response(data)
        self.assertIn("id", data)
        self.assertEqual(data["source_url"], payload["source_url"])

    def test_2_video_status_polling(self):
        """Feature Test 2: GET /api/videos/:id/status returns 200 OK with progress metrics."""
        # Create video first
        payload = {"source_url": "https://example.com/status_test.mp4"}
        _, created = self._http_request("POST", "/api/videos", payload)
        video_id = created["id"]

        status, data = self._http_request("GET", f"/api/videos/{video_id}/status")
        self.assertEqual(status, 200)
        self.assertEqual(data["id"], video_id)
        self.assertIn(data["status"], ("pending", "processing", "completed", "failed"))
        self.assertGreaterEqual(data["progress_percent"], 0)

    def test_3_video_clips_retrieval(self):
        """Feature Test 3: GET /api/videos/:id/clips returns array of generated clips."""
        payload = {"source_url": "https://example.com/clips_test.mp4"}
        _, created = self._http_request("POST", "/api/videos", payload)
        video_id = created["id"]

        status, data = self._http_request("GET", f"/api/videos/{video_id}/clips")
        self.assertEqual(status, 200)
        ContractValidator.validate_laravel_clips_response(data)
        clips = data["data"]
        self.assertGreater(len(clips), 0)
        clip = clips[0]
        self.assertEqual(clip["video_id"], video_id)
        self.assertTrue(clip["file_url"].endswith(".mp4"))
        self.assertTrue(clip["thumbnail_url"].endswith(".jpg"))

    def test_4_clip_publishing_dispatch(self):
        """Feature Test 4: POST /api/clips/:id/publish queues publication and returns 202 Accepted."""
        clip_id = str(uuid.uuid4())
        payload = {
            "platforms": ["tiktok", "instagram"],
            "caption": "Check out this reel! #viral",
            "scheduled_at": None,
        }
        status, data = self._http_request("POST", f"/api/clips/{clip_id}/publish", payload)
        self.assertEqual(status, 202)
        ContractValidator.validate_laravel_publish_accepted_response(data)
        self.assertEqual(len(data["publication_ids"]), 2)
        self.assertEqual(data["status"], "queued")

    def test_5_social_accounts_listing(self):
        """Feature Test 5: GET /api/social-accounts returns registered platforms and mock badges."""
        status, data = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status, 200)
        self.assertIn("data", data)
        accounts = data["data"]
        self.assertGreater(len(accounts), 0)

        platforms = [acc["platform"] for acc in accounts]
        self.assertIn("tiktok", platforms)
        self.assertIn("instagram", platforms)
        self.assertIn("youtube", platforms)

        for acc in accounts:
            ContractValidator.validate_uuid(acc["id"], "account_id")
            self.assertTrue(acc.get("is_mock", False))


if __name__ == "__main__":
    unittest.main()
