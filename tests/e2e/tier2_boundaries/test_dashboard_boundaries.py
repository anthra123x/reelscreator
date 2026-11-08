#!/usr/bin/env python3
"""
Tier 2 Boundary Tests: Dashboard Metrics Boundaries
Tests empty state metrics, query parameter sanitization, metric updates after creation,
rapid polling consistency, and extreme boundary limits.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest
from concurrent.futures import ThreadPoolExecutor

from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestDashboardBoundaries(unittest.TestCase):
    """Verifies edge cases and boundary conditions for dashboard metrics."""

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
        cls.mock_server = EphemeralMockServer(port=8022)
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

    def test_1_dashboard_empty_state(self):
        """Boundary Test 1: Metrics return valid zero or positive numbers without null or NaN."""
        status, res = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        self.assertGreaterEqual(res["total_videos"], 0)
        self.assertGreaterEqual(res["total_clips"], 0)
        self.assertGreaterEqual(res["connected_accounts"], 0)
        self.assertGreaterEqual(res["publications"]["total"], 0)

    def test_2_dashboard_query_params_sanitization(self):
        """Boundary Test 2: Unrecognized query parameters do not cause 500 errors."""
        status, res = self._http_request("GET", "/api/dashboard/metrics?filter=all&evil=<script>&sort=desc")
        self.assertEqual(status, 200)
        self.assertIn("total_videos", res)

    def test_3_metrics_after_entity_creation(self):
        """Boundary Test 3: Creating a video and clips immediately reflects in metrics count."""
        # Read baseline
        _, initial = self._http_request("GET", "/api/dashboard/metrics")
        init_videos = initial["total_videos"]

        # Ingest video
        payload = {"source_url": "https://example.com/metric_inc.mp4"}
        self._http_request("POST", "/api/videos", payload)

        # Re-query
        status, updated = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        self.assertGreaterEqual(updated["total_videos"], init_videos + 1)

    def test_4_rapid_polling_consistency(self):
        """Boundary Test 4: Rapid concurrent polling does not produce race conditions or exceptions."""
        def poll():
            return self._http_request("GET", "/api/dashboard/metrics")

        with ThreadPoolExecutor(max_workers=5) as executor:
            futures = [executor.submit(poll) for _ in range(15)]
            results = [f.result() for f in futures]

        for status, data in results:
            self.assertEqual(status, 200)
            self.assertIn("total_videos", data)

    def test_5_pagination_limit_boundaries(self):
        """Boundary Test 5: Querying lists with extreme pagination parameters handles safely."""
        status, res = self._http_request("GET", "/api/videos?limit=1000&page=1")
        self.assertEqual(status, 200)
        self.assertIn("data", res)


if __name__ == "__main__":
    unittest.main()
