#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Dashboard Metrics (Unified KPI Aggregation)
Tests totalVideos (GET /api/videos), totalClips (GET /api/clips),
connectedAccounts (GET /api/social-accounts), publications (GET /api/publications),
and consolidated dashboard metrics (GET /api/dashboard/metrics).
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestDashboardMetricsFeatures(unittest.TestCase):
    """Verifies dashboard metrics aggregation endpoints and real-time state."""

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
        cls.mock_server = EphemeralMockServer(port=8013)
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
            with urllib.request.urlopen(req, timeout=5.0) as resp:
                body = resp.read().decode("utf-8")
                return resp.status, json.loads(body) if body else {}
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8")
            return e.code, json.loads(body) if body else {}

    def test_1_dashboard_total_videos_metric(self):
        """Feature Test 1: GET /api/videos returns list and total count of ingested videos."""
        status, res = self._http_request("GET", "/api/videos")
        self.assertEqual(status, 200)
        self.assertIn("data", res)
        self.assertIn("total", res)
        self.assertIsInstance(res["data"], list)
        self.assertEqual(res["total"], len(res["data"]))

    def test_2_dashboard_total_clips_metric(self):
        """Feature Test 2: GET /api/clips returns list and total count of generated 9:16 reels."""
        status, res = self._http_request("GET", "/api/clips")
        self.assertEqual(status, 200)
        self.assertIn("data", res)
        self.assertIn("total", res)
        self.assertIsInstance(res["data"], list)

    def test_3_dashboard_connected_accounts_metric(self):
        """Feature Test 3: GET /api/social-accounts returns connected accounts list for dashboard card."""
        status, res = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status, 200)
        self.assertIn("data", res)
        accounts = res["data"]
        self.assertGreater(len(accounts), 0)
        platforms = [a["platform"] for a in accounts]
        self.assertTrue(any(p in platforms for p in ("tiktok", "instagram", "youtube")))

    def test_4_dashboard_publications_metrics(self):
        """Feature Test 4: GET /api/publications returns publications collection for activity metrics."""
        status, res = self._http_request("GET", "/api/publications")
        self.assertEqual(status, 200)
        self.assertIn("data", res)
        self.assertIn("total", res)
        self.assertIsInstance(res["data"], list)

    def test_5_dashboard_aggregated_metrics_endpoint(self):
        """Feature Test 5: GET /api/dashboard/metrics returns consolidated KPI object for Angular dashboard."""
        status, res = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        ContractValidator.validate_dashboard_metrics_response(res)
        self.assertGreaterEqual(res["total_videos"], 0)
        self.assertGreaterEqual(res["total_clips"], 0)
        self.assertGreaterEqual(res["connected_accounts"], 0)
        self.assertGreaterEqual(res["publications"]["total"], 0)
        self.assertGreaterEqual(res["publications"]["published"], 0)
        self.assertGreaterEqual(res["publications"]["pending"], 0)


if __name__ == "__main__":
    unittest.main()
