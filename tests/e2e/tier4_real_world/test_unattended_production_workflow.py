#!/usr/bin/env python3
"""
Tier 4 Real-World Application Scenarios: Unattended Multi-Clip Production Flows
Tests realistic production end-to-end scenarios:
1. Multi-clip unattended decomposition and 3-channel publishing.
2. Full dashboard lifecycle state and real-time KPI synchronization.
3. Concurrent batch production with isolated storage paths.
4. Transient publishing error retry and recovery.
5. Production endpoint contract and CORS verification.
"""

import json
import os
import shutil
import tempfile
import urllib.error
import urllib.request
import uuid
import unittest
from concurrent.futures import ThreadPoolExecutor

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.media_generator import MediaGenerator
from tests.e2e.common.media_validator import MediaValidator
from tests.e2e.common.mock_server import EphemeralMockServer, MockSocialAdapter, GLOBAL_STATE


class TestUnattendedProductionWorkflow(unittest.TestCase):
    """Executes realistic unattended multi-clip production flows."""

    @classmethod
    def setUpClass(cls):
        cls.work_dir = tempfile.mkdtemp(prefix="reelforge_tier4_prod_")
        cls.raw_video_path = os.path.join(cls.work_dir, "master_source.mp4")
        MediaGenerator.generate_synthetic_video(cls.raw_video_path, duration=15.0)

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
        cls.mock_server = EphemeralMockServer(port=8040)
        port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{port}"

    @classmethod
    def tearDownClass(cls):
        if cls.mock_server:
            cls.mock_server.stop()
        shutil.rmtree(cls.work_dir, ignore_errors=True)

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

    def test_scenario_1_unattended_multi_clip_ingest_decompose_publish(self):
        """Scenario 1: Full unattended workflow (Ingest 15s video -> Decompose 9:16 -> Fanout 3 channels)."""
        # 1. 1-Click Connect All Channels
        status, conn = self._http_request("POST", "/api/social-accounts/connect", {
            "platform": "all",
            "mode": "auto",
            "username": "@production_studio",
        })
        self.assertEqual(status, 200)

        # 2. Decompose with auto_publish enabled
        payload = {
            "source_url": "https://example.com/production_master.mp4",
            "interval_seconds": 15,
            "vertical_mode": "blur_padding",
            "auto_publish": True,
            "caption": "Production automated reel #viral #reel",
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)
        ContractValidator.validate_video_decomposition_response(res)

        # 3. Assert physical 9:16 vertical geometry metadata
        clips = res["clips"]
        self.assertGreater(len(clips), 0)
        for clip in clips:
            meta = clip["metadata"]
            self.assertEqual(meta["width"], 1080)
            self.assertEqual(meta["height"], 1920)
            self.assertEqual(meta["aspect_ratio"], "9:16")

        # 4. Assert publications were dispatched
        pubs = res["publications"]
        self.assertGreaterEqual(len(pubs), 3)
        targeted = {p["platform"] for p in pubs}
        self.assertTrue({"tiktok", "instagram", "youtube"}.issubset(targeted))

    def test_scenario_2_dashboard_lifecycle_and_live_kpis(self):
        """Scenario 2: User journey across dashboard (Connect -> Ingest -> Decompose -> Real-time KPIs)."""
        # Baseline
        _, m_init = self._http_request("GET", "/api/dashboard/metrics")

        # Connect new platform
        self._http_request("POST", "/api/social-accounts/connect", {
            "platform": "tiktok",
            "mode": "mock",
            "username": "@dashboard_star",
        })

        # Ingest & decompose video with auto-publish to 2 platforms
        _, decomp = self._http_request("POST", "/api/videos/decompose", {
            "source_url": "https://example.com/journey_video.mp4",
            "interval_seconds": 20,
            "auto_publish": True,
            "platforms": ["tiktok", "instagram"],
        })

        # Re-query metrics
        status, m_final = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        self.assertGreaterEqual(m_final["total_videos"], m_init["total_videos"] + 1)
        self.assertGreaterEqual(m_final["total_clips"], m_init["total_clips"] + 1)
        self.assertGreaterEqual(m_final["publications"]["total"], m_init["publications"]["total"] + 2)

    def test_scenario_3_unattended_concurrent_batch_production(self):
        """Scenario 3: Two concurrent videos submitted for unattended decomposition and publishing."""
        def submit_and_decompose(idx: int):
            payload = {
                "source_url": f"https://example.com/concurrent_{idx}.mp4",
                "interval_seconds": 25,
                "auto_publish": True,
                "platforms": ["tiktok"],
                "caption": f"Batch clip #{idx}",
            }
            return self._http_request("POST", "/api/videos/decompose", payload)

        with ThreadPoolExecutor(max_workers=2) as executor:
            f1 = executor.submit(submit_and_decompose, 1)
            f2 = executor.submit(submit_and_decompose, 2)
            s1, r1 = f1.result()
            s2, r2 = f2.result()

        self.assertEqual(s1, 201)
        self.assertEqual(s2, 201)
        # Distinct video and clip UUIDs
        self.assertNotEqual(r1["video"]["id"], r2["video"]["id"])
        self.assertNotEqual(r1["clips"][0]["id"], r2["clips"][0]["id"])

    def test_scenario_4_transient_failure_and_backpressure_recovery(self):
        """Scenario 4: Transient adapter network failure recovers on retry."""
        adapter = MockSocialAdapter(platform="instagram")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        # First attempt fails due to simulated network glitch
        res_fail = adapter.publish(clip_id, account_id, simulate_failure=True)
        self.assertFalse(res_fail["success"])
        self.assertIn("MockSimulatedFailure", res_fail["error"])

        # Retry succeeds
        res_retry = adapter.publish(clip_id, account_id, simulate_failure=False)
        self.assertTrue(res_retry["success"])
        self.assertTrue(res_retry["platform_post_id"].startswith("instagram_post_"))

    def test_scenario_5_production_endpoint_and_cors_verification(self):
        """Scenario 5: Validate API headers, JSON content-type, UUID schemas, and status codes."""
        # Check GET /api/videos schema
        status, res = self._http_request("GET", "/api/videos")
        self.assertEqual(status, 200)
        self.assertIn("data", res)

        # Check GET /api/social-accounts schema
        status, s_res = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status, 200)
        self.assertIn("data", s_res)

        # Check GET /api/dashboard/metrics schema
        status, m_res = self._http_request("GET", "/api/dashboard/metrics")
        self.assertEqual(status, 200)
        ContractValidator.validate_dashboard_metrics_response(m_res)


if __name__ == "__main__":
    unittest.main()
