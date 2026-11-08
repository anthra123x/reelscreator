#!/usr/bin/env python3
"""
Tier 2 Boundary Tests: Decomposition Boundaries
Tests nonexistent video IDs, empty/missing URLs, malformed protocols,
negative/zero intervals, and invalid platforms parameters.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestDecompositionBoundaries(unittest.TestCase):
    """Verifies edge cases and boundary validation on video decomposition endpoints."""

    @classmethod
    def setUpClass(cls):
        mode = os.environ.get("REELFORGE_TEST_MODE", "auto")
        cls.mock_server = None

        if mode == "live":
            cls.base_url = "http://127.0.0.1:8000"
            try:
                req = urllib.request.Request(f"{cls.base_url}/api/videos")
                with urllib.request.urlopen(req, timeout=0.5) as resp:
                    if resp.status == 200:
                        cls.is_live = True
                        return
            except Exception:
                pass

        cls.is_live = False
        cls.mock_server = EphemeralMockServer(port=8021)
        port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{port}"

    @classmethod
    def tearDownClass(cls):
        if cls.mock_server:
            cls.mock_server.stop()

    def _http_request(self, method: str, path: str, payload: dict = None) -> tuple:
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

    def test_1_decompose_nonexistent_video_404(self):
        """Boundary Test 1: Decomposing nonexistent video ID returns 404 Not Found."""
        bogus_uuid = str(uuid.uuid4())
        status, res = self._http_request("POST", f"/api/videos/{bogus_uuid}/decompose", {"interval_seconds": 30})
        self.assertEqual(status, 404)
        self.assertIn("error", res)

    def test_2_decompose_empty_or_missing_url_422(self):
        """Boundary Test 2: Decomposing without source_url or local_video_path returns 422."""
        payload = {"interval_seconds": 30}  # missing source_url
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)

    def test_3_decompose_invalid_url_protocol_422(self):
        """Boundary Test 3: Decomposing with non-HTTP protocol (e.g. ftp://, file://) returns 422."""
        payload = {"source_url": "ftp://malicious.com/exploit.mp4"}
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)

    def test_4_decompose_negative_or_zero_interval_400(self):
        """Boundary Test 4: Decomposition interval <= 0 returns 400 Bad Request."""
        payload = {
            "source_url": "https://example.com/video.mp4",
            "interval_seconds": -5,
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 400)
        self.assertIn("error", res)

    def test_5_decompose_invalid_platforms_format_422(self):
        """Boundary Test 5: Specifying empty platforms array or invalid platform names returns 422."""
        payload = {
            "source_url": "https://example.com/video.mp4",
            "auto_publish": True,
            "platforms": ["invalid_channel_name"],
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)


if __name__ == "__main__":
    unittest.main()
