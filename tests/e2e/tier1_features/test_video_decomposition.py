#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Video Decomposition (Autonomous 9:16 Pipeline)
Tests POST /api/videos/{id}/decompose, POST /api/videos/decompose,
serverless fallback metadata, and clip lifecycle reconciliation.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestVideoDecompositionFeatures(unittest.TestCase):
    """Verifies autonomous 9:16 video decomposition endpoints and contracts."""

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
        cls.mock_server = EphemeralMockServer(port=8010)
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

    def test_1_decompose_existing_video_endpoint(self):
        """Feature Test 1: POST /api/videos/{id}/decompose generates 9:16 clips and returns 200 OK."""
        # Create a video first
        video_payload = {"source_url": "https://example.com/raw_source.mp4"}
        status, video_res = self._http_request("POST", "/api/videos", video_payload)
        self.assertEqual(status, 201)
        video_id = video_res["id"]

        # Trigger decomposition
        decomp_payload = {
            "interval_seconds": 30,
            "vertical_mode": "blur_padding",
            "auto_publish": False,
        }
        status, decomp_res = self._http_request("POST", f"/api/videos/{video_id}/decompose", decomp_payload)
        self.assertEqual(status, 200)
        ContractValidator.validate_video_decomposition_response(decomp_res)
        self.assertEqual(decomp_res["video"]["id"], video_id)
        self.assertEqual(decomp_res["status"], "ready")
        self.assertGreater(len(decomp_res["clips"]), 0)

        clip = decomp_res["clips"][0]
        self.assertEqual(clip["metadata"]["aspect_ratio"], "9:16")
        self.assertEqual(clip["metadata"]["width"], 1080)
        self.assertEqual(clip["metadata"]["height"], 1920)

    def test_2_decompose_direct_ingest_endpoint(self):
        """Feature Test 2: POST /api/videos/decompose accepts source URL and returns 201 Created with clips."""
        payload = {
            "source_url": "https://example.com/podcast_interview.mp4",
            "interval_seconds": 25,
            "vertical_mode": "center_crop",
            "auto_publish": False,
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)
        ContractValidator.validate_video_decomposition_response(res)
        self.assertIn("id", res["video"])
        self.assertEqual(res["status"], "ready")
        self.assertGreater(len(res["clips"]), 0)

        clip = res["clips"][0]
        self.assertEqual(clip["metadata"]["vertical_mode"], "center_crop")
        self.assertEqual(clip["duration"], 25.0)

    def test_3_decompose_custom_interval(self):
        """Feature Test 3: Decomposing with custom interval (15s) sets exact clip duration."""
        payload = {
            "source_url": "https://example.com/quick_tips.mp4",
            "interval_seconds": 15,
            "vertical_mode": "letterbox",
            "auto_publish": False,
        }
        status, res = self._http_request("POST", "/api/videos/decompose", payload)
        self.assertEqual(status, 201)
        self.assertEqual(res["clips"][0]["duration"], 15.0)
        self.assertEqual(res["clips"][0]["end_time"] - res["clips"][0]["start_time"], 15.0)

    def test_4_serverless_decomposition_fallback(self):
        """Feature Test 4: Serverless decomposition fallback provides valid 9:16 metadata and codec specs."""
        video_payload = {"source_url": "https://example.com/serverless_fallback.mp4"}
        status, video_res = self._http_request("POST", "/api/videos", video_payload)
        video_id = video_res["id"]

        status, res = self._http_request("POST", f"/api/videos/{video_id}/decompose", {
            "vertical_mode": "blur_padding",
        })
        self.assertEqual(status, 200)
        meta = res["clips"][0]["metadata"]
        self.assertEqual(meta["width"], 1080)
        self.assertEqual(meta["height"], 1920)
        self.assertEqual(meta["aspect_ratio"], "9:16")
        self.assertEqual(meta["video_codec"], "h264")
        self.assertEqual(meta["audio_codec"], "aac")

    def test_5_decompose_reconciliation_and_status(self):
        """Feature Test 5: Querying video and clips endpoints confirms status transitioned to ready."""
        payload = {"source_url": "https://example.com/reconcile.mp4"}
        _, res = self._http_request("POST", "/api/videos/decompose", payload)
        video_id = res["video"]["id"]

        # Check video status endpoint
        status, stat_res = self._http_request("GET", f"/api/videos/{video_id}/status")
        self.assertEqual(status, 200)
        self.assertEqual(stat_res["status"], "ready")
        self.assertEqual(stat_res["progress_percent"], 100)

        # Check clips index for this video
        status, clips_res = self._http_request("GET", f"/api/videos/{video_id}/clips")
        self.assertEqual(status, 200)
        self.assertGreater(len(clips_res["data"]), 0)


if __name__ == "__main__":
    unittest.main()
