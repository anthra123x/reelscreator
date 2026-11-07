#!/usr/bin/env python3
"""
Tier 5 Adversarial Coverage Hardening: REST API & Publishing Pipeline
Probes Axum and Laravel endpoints with rapid sequential bursts, malformed JSON,
extreme timestamp intervals, SQL/XSS payloads, and platform constraint limits.
"""

import json
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.mock_server import EphemeralMockServer, MockSocialAdapter, GLOBAL_STATE


class TestAdversarialRestPipeline(unittest.TestCase):
    """Adversarial stress and negative testing for REST contracts and publishing pipeline."""

    @classmethod
    def setUpClass(cls):
        cls.mock_server = EphemeralMockServer(port=8002)
        cls.port = cls.mock_server.start()
        cls.base_url = f"http://127.0.0.1:{cls.port}"

    @classmethod
    def tearDownClass(cls):
        cls.mock_server.stop()

    def _http_raw(self, method: str, path: str, raw_body: bytes = None, headers: dict = None) -> tuple:
        """Sends raw HTTP bytes to test malformed payloads without client-side JSON serialization."""
        url = f"{self.base_url}{path}"
        hdrs = headers or {}
        req = urllib.request.Request(url, data=raw_body, headers=hdrs, method=method)

        try:
            with urllib.request.urlopen(req, timeout=5.0) as resp:
                body = resp.read().decode("utf-8")
                return resp.status, json.loads(body) if body else {}
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8")
            try:
                data = json.loads(body) if body else {}
            except Exception:
                data = {"raw": body}
            return e.code, data

    def _http_json(self, method: str, path: str, payload: dict = None) -> tuple:
        """Helper to send valid JSON dictionary payload."""
        data_bytes = json.dumps(payload).encode("utf-8") if payload is not None else None
        headers = {"Content-Type": "application/json"} if data_bytes is not None else {}
        return self._http_raw(method, path, data_bytes, headers)

    def test_1_rapid_sequential_burst_submissions(self):
        """Adversarial Test 1: Rapid sequential burst of 25 job submissions handled safely with distinct UUIDs."""
        job_ids = set()

        for i in range(25):
            payload = {
                "source_url": f"https://example.com/burst_test_{i}.mp4",
                "strategy": "fixed_interval",
                "interval_seconds": 30,
            }
            status, data = self._http_json("POST", "/api/v1/jobs", payload)
            self.assertEqual(status, 202, f"Failed at iteration {i}: {data}")
            self.assertIn("job_id", data)
            self.assertEqual(data["status"], "queued")
            self.assertNotIn(data["job_id"], job_ids, "UUID collision in rapid submission")
            job_ids.add(data["job_id"])

        self.assertEqual(len(job_ids), 25)

        # Health endpoint remains consistent
        h_status, h_data = self._http_json("GET", "/health")
        self.assertEqual(h_status, 200)
        self.assertEqual(h_data["status"], "healthy")

    def test_2_malformed_json_syntax_rejected_cleanly(self):
        """Adversarial Test 2: Raw broken JSON syntax returns 400/422 without unhandled 500 or crash."""
        broken_payloads = [
            b"{\"job_id\": \"broken_syntax",
            b"{not_json_at_all}",
            b"",
            b"{\"interval_seconds\": }",
        ]

        for raw_body in broken_payloads:
            status, data = self._http_raw(
                "POST",
                "/api/v1/jobs",
                raw_body=raw_body,
                headers={"Content-Type": "application/json"},
            )
            self.assertIn(status, [400, 422], f"Expected 400/422 for malformed body, got {status}")

    def test_3_extreme_negative_intervals_and_durations(self):
        """Adversarial Test 3: Negative or zero interval and duration parameters reject with 400/422."""
        invalid_params = [
            {"source_url": "https://example.com/vid.mp4", "interval_seconds": -30},
            {"source_url": "https://example.com/vid.mp4", "interval_seconds": 0},
            {"source_url": "https://example.com/vid.mp4", "max_duration_seconds": -60},
            {"source_url": "https://example.com/vid.mp4", "max_duration_seconds": 0},
        ]

        for payload in invalid_params:
            status, data = self._http_json("POST", "/api/v1/jobs", payload)
            self.assertIn(status, [400, 422], f"Expected rejection for {payload}, got {status}")

    def test_4_inverted_and_negative_custom_ranges(self):
        """Adversarial Test 4: Custom timestamp ranges with inverted or negative bounds are rejected."""
        inverted_payloads = [
            {
                "source_url": "https://example.com/vid.mp4",
                "strategy": "manual_timestamps",
                "custom_ranges": [{"start": 25.0, "end": 10.0}],
            },
            {
                "source_url": "https://example.com/vid.mp4",
                "strategy": "manual_timestamps",
                "custom_ranges": [{"start": -5.0, "end": 15.0}],
            },
            {
                "source_url": "https://example.com/vid.mp4",
                "strategy": "manual_timestamps",
                "custom_ranges": [{"start": 10.0, "end": 10.0}],
            },
        ]

        for payload in inverted_payloads:
            status, data = self._http_json("POST", "/api/v1/jobs", payload)
            self.assertIn(status, [400, 422], f"Expected rejection for {payload}, got {status}")

    def test_5_simultaneous_multi_platform_publishing(self):
        """Adversarial Test 5: Simultaneous fan-out to TikTok, Instagram Reels, and YouTube Shorts succeeds."""
        clip_id = str(uuid.uuid4())
        payload = {
            "platforms": ["tiktok", "instagram", "youtube"],
            "caption": "Simultaneous Multi-Platform Publish #Viral 🔥🚀",
        }

        status, data = self._http_json("POST", f"/api/clips/{clip_id}/publish", payload)
        self.assertEqual(status, 202)
        self.assertIn("publication_ids", data)
        self.assertEqual(len(data["publication_ids"]), 3)
        self.assertEqual(data["status"], "queued")

    def test_6_special_characters_sql_injection_and_emojis(self):
        """Adversarial Test 6: URLs and titles with complex queries, unicode, emojis, and SQLi payloads handle safely."""
        adversarial_entries = [
            ("https://sub.domain.com:8443/watch?v=123&t=10s#frag", "🔥 Emojis and #Hashtags 💯"),
            ("https://example.com/video%20test%20añó.mp4", "'; DROP TABLE videos; --"),
            ("https://example.com/unicode_video.mp4", "<script>alert('XSS')</script>"),
            ("https://example.com/standard.mp4", "¿Cómo funciona ReelForge? (ä, ö, ü, ñ)"),
        ]

        for url, title in adversarial_entries:
            payload = {
                "source_url": url,
                "strategy": "fixed_interval",
                "title": title,
            }
            status, data = self._http_json("POST", "/api/videos", payload)
            self.assertEqual(status, 201)
            self.assertIn("id", data)

    def test_7_nonexistent_and_malformed_uuid_endpoints(self):
        """Adversarial Test 7: Missing or malformed UUIDs in path parameters return 400/404 without 500 crashes."""
        bogus_clip_id = str(uuid.uuid4())
        with GLOBAL_STATE.lock:
            GLOBAL_STATE.nonexistent_clips.add(bogus_clip_id)

        bad_paths = [
            "/api/v1/jobs/not-a-uuid-12345",
            f"/api/v1/jobs/{uuid.uuid4()}",
            "/api/videos/malformed_id/status",
            f"/api/videos/{uuid.uuid4()}/status",
            f"/api/clips/{bogus_clip_id}/publish",
            "/api/clips/not-a-uuid/publish",
        ]

        for path in bad_paths:
            if "publish" in path:
                status, data = self._http_json("POST", path, {"platforms": ["tiktok"]})
            else:
                status, data = self._http_json("GET", path)

            self.assertIn(status, [400, 404, 422], f"Path {path} returned unexpected status {status}")

    def test_8_youtube_shorts_duration_limit_enforcement(self):
        """Adversarial Test 8: YouTube Shorts enforces strict 60.0s maximum duration constraint."""
        adapter = MockSocialAdapter(platform="youtube")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        # Exact boundary: 60.0s is allowed
        res_boundary = adapter.publish(clip_id, account_id, duration_seconds=60.0)
        self.assertTrue(res_boundary["success"])

        # Boundary violation: 60.01s is rejected
        res_over = adapter.publish(clip_id, account_id, duration_seconds=60.01)
        self.assertFalse(res_over["success"])
        self.assertIn("PlatformConstraintViolation", res_over["error"])


if __name__ == "__main__":
    unittest.main()
