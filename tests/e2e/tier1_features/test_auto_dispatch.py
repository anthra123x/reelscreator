#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Auto-Dispatch (Autonomous Multi-Channel Social Publishing)
Tests auto_publish flag on decomposition, batch clip dispatching, active channels auto-discovery,
selective platform targeting, and structured audit event logging.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestAutoDispatchFeatures(unittest.TestCase):
    """Verifies autonomous social publishing dispatch logic and contracts."""

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
        cls.mock_server = EphemeralMockServer(port=8011)
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

    def test_1_auto_dispatch_flag_creates_publications(self):
        """Feature Test 1: auto_publish=true on decomposition automatically creates publications."""
        payload = {
            "source_url": "https://example.com/autodispatch_video.mp4",
            "interval_seconds": 30,
            "auto_publish": True,
            "caption": "ReelForge automated reel #fyp",
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)
        ContractValidator.validate_video_decomposition_response(res)

        pubs = res.get("publications", [])
        self.assertGreater(len(pubs), 0)
        self.assertIn(pubs[0]["status"], ("published", "pending", "queued"))
        self.assertEqual(pubs[0]["caption"], payload["caption"])

    def test_2_batch_clip_dispatching(self):
        """Feature Test 2: Decomposing into clips dispatches all clips across active channels."""
        # Create video
        _, vres = self._http_request("POST", "/api/videos", {"source_url": "https://example.com/batch.mp4"})
        vid = vres["id"]

        decomp_payload = {
            "interval_seconds": 20,
            "auto_publish": True,
            "platforms": ["tiktok", "instagram"],
        }
        status, res = self._http_request("POST", f"/api/videos/{vid}/decompose", decomp_payload)
        self.assertEqual(status, 200)

        pubs = res["publications"]
        self.assertGreaterEqual(len(pubs), 2)
        platforms_targeted = {p["platform"] for p in pubs}
        self.assertEqual(platforms_targeted, {"tiktok", "instagram"})

    def test_3_active_channels_discovery(self):
        """Feature Test 3: Omitting platforms parameter auto-discovers all active connected accounts."""
        _, vres = self._http_request("POST", "/api/videos", {"source_url": "https://example.com/discovery.mp4"})
        vid = vres["id"]

        status, res = self._http_request("POST", f"/api/videos/{vid}/decompose", {
            "auto_publish": True,
            # platforms intentionally omitted
        })
        self.assertEqual(status, 200)
        published_platforms = res.get("published_platforms", [])
        self.assertIn("tiktok", published_platforms)
        self.assertIn("instagram", published_platforms)
        self.assertIn("youtube", published_platforms)

    def test_4_selective_platforms_auto_dispatch(self):
        """Feature Test 4: Explicit platforms list targets only specified channels."""
        _, vres = self._http_request("POST", "/api/videos", {"source_url": "https://example.com/selective.mp4"})
        vid = vres["id"]

        status, res = self._http_request("POST", f"/api/videos/{vid}/decompose", {
            "auto_publish": True,
            "platforms": ["youtube"],
        })
        self.assertEqual(status, 200)
        self.assertEqual(res["published_platforms"], ["youtube"])
        for p in res["publications"]:
            self.assertEqual(p["platform"], "youtube")

    def test_5_auto_dispatch_audit_logging(self):
        """Feature Test 5: Publications are registered and queryable in publications index."""
        payload = {
            "source_url": "https://example.com/audit_test.mp4",
            "auto_publish": True,
            "platforms": ["tiktok"],
            "caption": "Audit log verification",
        }
        _, res = self._http_request("POST", "/api/videos/decompose", payload)
        pub_id = res["publications"][0]["id"]

        # Verify publication appears in GET /api/publications
        status, pubs_res = self._http_request("GET", "/api/publications")
        self.assertEqual(status, 200)
        pub_ids = [p["id"] for p in pubs_res.get("data", [])]
        self.assertIn(pub_id, pub_ids)


if __name__ == "__main__":
    unittest.main()
