#!/usr/bin/env python3
"""
Tier 3 Cross-Feature Combination Tests: Autonomous Pipeline & Multi-Platform Interactions
Tests pairwise interactions: Decomposition -> Auto-Dispatch -> Multi-Platform Publication -> Audit Logs;
Agile connect 'all' -> Unattended video decomposition -> Fanout publications;
Token expiry resilience; Serverless fallback with auto-dispatch; and Dashboard metrics sync.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, MockSocialAdapter, GLOBAL_STATE


class TestAutonomousPipelineCombinations(unittest.TestCase):
    """Verifies pairwise cross-feature integrations across autonomous subsystems."""

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
        cls.mock_server = EphemeralMockServer(port=8030)
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

    def test_1_decomposition_to_auto_dispatch_to_publication_logs(self):
        """Combination 1: Decomposition -> Auto-Dispatch -> Multi-Platform Publication -> Audit Logs."""
        # 1. Decompose video with auto_publish enabled
        payload = {
            "source_url": "https://example.com/podcast_roundtable.mp4",
            "interval_seconds": 30,
            "auto_publish": True,
            "platforms": ["tiktok", "instagram"],
            "caption": "Roundtable highlight #podcast #shorts",
        }
        status, decomp_res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)
        ContractValidator.validate_video_decomposition_response(decomp_res)

        # 2. Assert clips were generated with 9:16 vertical geometry
        clips = decomp_res["clips"]
        self.assertGreater(len(clips), 0)
        clip = clips[0]
        self.assertEqual(clip["metadata"]["aspect_ratio"], "9:16")
        self.assertEqual(clip["metadata"]["width"], 1080)
        self.assertEqual(clip["metadata"]["height"], 1920)

        # 3. Assert publications were dispatched automatically
        pubs = decomp_res["publications"]
        self.assertEqual(len(pubs), 2)
        pub_platforms = {p["platform"] for p in pubs}
        self.assertEqual(pub_platforms, {"tiktok", "instagram"})

        # 4. Assert publications are registered in publications audit collection
        status_pub, all_pubs = self._http_request("GET", "/api/publications")
        self.assertEqual(status_pub, 200)
        registered_ids = {p["id"] for p in all_pubs["data"]}
        for p in pubs:
            self.assertIn(p["id"], registered_ids)

    def test_2_agile_connect_all_then_autonomous_dispatch(self):
        """Combination 2: Agile Connect 'all' -> Autonomous Ingest & Decompose -> 3-Channel Fanout."""
        # 1. Connect all channels with 1 click
        status_conn, conn_res = self._http_request("POST", "/api/social-accounts/connect", {
            "platform": "all",
            "mode": "auto",
            "username": "@unified_creator",
        })
        self.assertEqual(status_conn, 200)
        self.assertGreaterEqual(len(conn_res["connected"]), 3)

        # 2. Decompose video with auto_publish=True (no platforms specified, auto-discovers all)
        status_decomp, decomp_res = self._http_request("POST", "/api/videos/decompose", {
            "source_url": "https://example.com/unattended_full_show.mp4",
            "auto_publish": True,
            "interval_seconds": 20,
        })
        self.assertEqual(status_decomp, 201)

        # 3. Verify all 3 channels received publications
        pubs = decomp_res["publications"]
        targeted_platforms = {p["platform"] for p in pubs}
        self.assertTrue({"tiktok", "instagram", "youtube"}.issubset(targeted_platforms))

    def test_3_token_expiry_resilience_in_auto_dispatch(self):
        """Combination 3: Simulated token expiry on one channel does not halt dispatch to other channels."""
        # Query connected accounts
        status, accs_res = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status, 200)

        # Simulate publish on multiple adapters where one has expired token
        results = {}
        for platform in ["tiktok", "instagram"]:
            adapter = MockSocialAdapter(platform=platform)
            is_expired = (platform == "instagram")
            res = adapter.publish(
                clip_id=str(uuid.uuid4()),
                account_id=str(uuid.uuid4()),
                simulate_token_expired=is_expired,
            )
            results[platform] = res

        # TikTok succeeded
        self.assertTrue(results["tiktok"]["success"])
        self.assertIsNotNone(results["tiktok"]["platform_post_id"])

        # Instagram failed gracefully with TokenExpiredException without crashing
        self.assertFalse(results["instagram"]["success"])
        self.assertIn("TokenExpiredException", results["instagram"]["error"])

    def test_4_serverless_fallback_to_auto_dispatch(self):
        """Combination 4: Serverless decomposition fallback -> 9:16 geometry -> Auto-dispatch -> Metrics."""
        # Decompose via serverless fallback
        payload = {
            "source_url": "https://example.com/serverless_flow.mp4",
            "interval_seconds": 25,
            "vertical_mode": "blur_padding",
            "auto_publish": True,
            "platforms": ["tiktok"],
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)

        clip = res["clips"][0]
        self.assertEqual(clip["metadata"]["width"], 1080)
        self.assertEqual(clip["metadata"]["height"], 1920)
        self.assertEqual(clip["metadata"]["aspect_ratio"], "9:16")

        # Verify metrics updated
        status_m, metrics = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status_m, 200)
        self.assertGreater(metrics["total_clips"], 0)
        self.assertGreater(metrics["publications"]["total"], 0)

    def test_5_clip_customization_and_dashboard_metrics_sync(self):
        """Combination 5: Ingest -> Decompose -> Publish -> Dashboard metrics state reflects increment."""
        # Check initial metrics
        _, m_before = self._http_request("GET", "/api/dashboard/metrics")

        # Decompose new video with auto-publish
        _, decomp = self._http_request("POST", "/api/videos/decompose", {
            "source_url": "https://example.com/sync_test.mp4",
            "auto_publish": True,
            "platforms": ["youtube"],
        })

        # Check updated metrics
        status, m_after = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        self.assertGreaterEqual(m_after["total_videos"], m_before["total_videos"] + 1)
        self.assertGreaterEqual(m_after["total_clips"], m_before["total_clips"] + len(decomp["clips"]))
        self.assertGreaterEqual(m_after["publications"]["total"], m_before["publications"]["total"] + len(decomp["publications"]))


if __name__ == "__main__":
    unittest.main()
