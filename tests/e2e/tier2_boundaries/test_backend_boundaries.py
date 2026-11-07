#!/usr/bin/env python3
"""
Tier 2 Boundary & Negative Tests: Backend & Social Boundaries
Tests expired OAuth tokens, platform duration violations (e.g. YouTube Shorts 60s cap),
empty payload validation, 404 entity handling, and concurrency capacity bounds.
"""

import json
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.mock_server import EphemeralMockServer, MockSocialAdapter, GLOBAL_STATE


class TestBackendBoundaries(unittest.TestCase):
    """Verifies backend API boundaries, error handling, and platform constraints."""

    @classmethod
    def setUpClass(cls):
        cls.mock_server = EphemeralMockServer(port=8001)
        cls.port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{cls.port}"

    @classmethod
    def tearDownClass(cls):
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

    def test_1_expired_token_handling(self):
        """Boundary Test 1: Expired OAuth token simulation returns TokenExpired error."""
        adapter = MockSocialAdapter(platform="tiktok")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        res = adapter.publish(clip_id, account_id, simulate_token_expired=True)
        self.assertFalse(res["success"])
        self.assertIn("TokenExpiredException", res["error"])
        self.assertIsNone(res["platform_post_id"])

    def test_2_platform_duration_limit(self):
        """Boundary Test 2: Clips exceeding YouTube Shorts 60-second limit are rejected."""
        adapter = MockSocialAdapter(platform="youtube")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        # 75s clip exceeds 60s Shorts constraint
        res = adapter.publish(clip_id, account_id, duration_seconds=75.0)
        self.assertFalse(res["success"])
        self.assertIn("PlatformConstraintViolation", res["error"])
        self.assertIn("60.0s", res["error"])

        # 45s clip is within limit
        res_ok = adapter.publish(clip_id, account_id, duration_seconds=45.0)
        self.assertTrue(res_ok["success"])

    def test_3_empty_platform_list_rejected(self):
        """Boundary Test 3: Publishing request with empty platforms array returns 422."""
        clip_id = str(uuid.uuid4())
        payload = {"platforms": []}  # Invalid empty platforms
        status, data = self._http_request("POST", f"/api/clips/{clip_id}/publish", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", data)

    def test_4_nonexistent_entity_404(self):
        """Boundary Test 4: Querying nonexistent job or video ID returns 404 Not Found."""
        bogus_id = str(uuid.uuid4())
        status, data = self._http_request("GET", f"/api/v1/jobs/{bogus_id}")
        self.assertEqual(status, 404)
        self.assertIn("error", data)

        status_vid, data_vid = self._http_request("GET", f"/api/videos/{bogus_id}/status")
        self.assertEqual(status_vid, 404)
        self.assertIn("error", data_vid)

    def test_5_concurrency_capacity_bound(self):
        """Boundary Test 5: Axum job submission respects concurrency cap and handles saturation."""
        with GLOBAL_STATE.lock:
            GLOBAL_STATE.simulate_concurrency_rejection = True
            GLOBAL_STATE.active_jobs = 1
            GLOBAL_STATE.max_concurrent_jobs = 1

        payload = {
            "source_url": "https://example.com/overflow.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
        }
        status, data = self._http_request("POST", "/api/v1/jobs", payload)
        self.assertEqual(status, 429)
        self.assertIn("Concurrency limit", data.get("error", ""))

        # Reset flag
        with GLOBAL_STATE.lock:
            GLOBAL_STATE.simulate_concurrency_rejection = False
            GLOBAL_STATE.active_jobs = 0


if __name__ == "__main__":
    unittest.main()
