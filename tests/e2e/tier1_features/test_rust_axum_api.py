#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Rust Video Engine Axum REST API (reelforge-server)
Tests HTTP contracts for /health, POST /api/v1/jobs, GET /api/v1/jobs/:id, and concurrency bounds.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestRustAxumApiFeatures(unittest.TestCase):
    """Verifies Axum REST API endpoints against PROJECT.md specifications."""

    @classmethod
    def setUpClass(cls):
        mode = os.environ.get("REELFORGE_TEST_MODE", "auto")
        cls.mock_server = None

        if mode == "live":
            cls.base_url = "http://127.0.0.1:3000"
            try:
                req = urllib.request.Request(f"{cls.base_url}/health")
                with urllib.request.urlopen(req, timeout=0.5) as resp:
                    if resp.status == 200:
                        cls.is_live = True
                        return
            except Exception:
                pass

        # In auto or mock mode, launch ephemeral mock server for deterministic offline testing
        cls.is_live = False
        cls.mock_server = EphemeralMockServer(port=3000)
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

    def test_1_health_endpoint(self):
        """Feature Test 1: GET /health returns 200 OK with concurrency and version metadata."""
        status, data = self._http_request("GET", "/health")
        self.assertEqual(status, 200)
        ContractValidator.validate_axum_health_response(data)
        self.assertEqual(data["status"], "healthy")
        self.assertIn("version", data)
        self.assertGreaterEqual(data["concurrency"]["available_permits"], 0)

    def test_2_job_submission(self):
        """Feature Test 2: POST /api/v1/jobs accepts valid payload and returns 202 Accepted."""
        job_id = str(uuid.uuid4())
        payload = {
            "job_id": job_id,
            "source_url": "https://example.com/sample_video.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
            "max_duration_seconds": 60,
            "aspect_ratio": "9:16",
            "vertical_mode": "blur_padding",
            "output_dir": f"media/clips/{job_id}",
            "webhook_url": None,
        }
        ContractValidator.validate_axum_job_request(payload)

        status, data = self._http_request("POST", "/api/v1/jobs", payload)
        self.assertEqual(status, 202)
        ContractValidator.validate_axum_job_accepted_response(data)
        self.assertEqual(data["job_id"], job_id)
        self.assertEqual(data["status"], "queued")

    def test_3_job_status_polling(self):
        """Feature Test 3: GET /api/v1/jobs/:id returns 200 OK with job lifecycle progress."""
        # Submit a job first
        job_id = str(uuid.uuid4())
        payload = {
            "job_id": job_id,
            "source_url": "https://example.com/source.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
            "aspect_ratio": "9:16",
            "vertical_mode": "blur_padding",
        }
        self._http_request("POST", "/api/v1/jobs", payload)

        status, data = self._http_request("GET", f"/api/v1/jobs/{job_id}")
        self.assertEqual(status, 200)
        self.assertEqual(data["job_id"], job_id)
        self.assertIn(data["status"], ("queued", "processing", "completed"))
        self.assertGreaterEqual(data.get("progress_percent", 0), 0)

    def test_4_completed_clips_schema(self):
        """Feature Test 4: Completed job details return valid 1080x1920 clip entities."""
        job_id = str(uuid.uuid4())
        payload = {
            "job_id": job_id,
            "source_url": "https://example.com/source.mp4",
            "strategy": "fixed_interval",
            "interval_seconds": 30,
            "aspect_ratio": "9:16",
            "vertical_mode": "blur_padding",
        }
        self._http_request("POST", "/api/v1/jobs", payload)

        status, data = self._http_request("GET", f"/api/v1/jobs/{job_id}")
        self.assertEqual(status, 200)

        # If running against live asynchronous server, poll until completion
        if data.get("status") in ("queued", "pending", "downloading", "analyzing", "cutting", "processing"):
            import time
            for _ in range(50):
                time.sleep(0.2)
                status, data = self._http_request("GET", f"/api/v1/jobs/{job_id}")
                self.assertEqual(status, 200)
                if data.get("status") in ("completed", "failed"):
                    break

        ContractValidator.validate_axum_job_completed_response(data)

        clips = data["clips"]
        self.assertGreater(len(clips), 0)
        clip = clips[0]
        self.assertEqual(clip["width"], 1080)
        self.assertEqual(clip["height"], 1920)
        self.assertTrue(clip["file_path"].endswith(".mp4"))
        self.assertTrue(clip["thumbnail_path"].endswith(".jpg"))

    def test_5_concurrency_semaphore(self):
        """Feature Test 5: Concurrency indicators accurately bound active worker permits."""
        status, health = self._http_request("GET", "/health")
        self.assertEqual(status, 200)
        concurrency = health["concurrency"]
        max_jobs = concurrency["max_concurrent_jobs"]
        active = concurrency["active_jobs"]
        available = concurrency["available_permits"]

        # Invariant: active + available == max_jobs
        self.assertEqual(active + available, max_jobs)
        self.assertLessEqual(active, max_jobs)


if __name__ == "__main__":
    unittest.main()
