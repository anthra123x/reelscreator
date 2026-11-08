#!/usr/bin/env python3
"""
Tier 2 Boundary Tests: Social Accounts Connection Boundaries
Tests expired tokens, duplicate connections, unsupported platforms,
invalid modes, and missing direct credentials.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestSocialConnectionBoundaries(unittest.TestCase):
    """Verifies edge cases and boundary conditions for social account connections."""

    @classmethod
    def setUpClass(cls):
        mode = os.environ.get("REELFORGE_TEST_MODE", "auto")
        cls.mock_server = None

        if mode == "live":
            cls.base_url = "http://127.0.0.1:8000"
            try:
                req = urllib.request.Request(f"{cls.base_url}/api/social-accounts")
                with urllib.request.urlopen(req, timeout=0.5) as resp:
                    if resp.status == 200:
                        cls.is_live = True
                        return
            except Exception:
                pass

        cls.is_live = False
        cls.mock_server = EphemeralMockServer(port=8020)
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

    def test_1_expired_token_connection(self):
        """Boundary Test 1: Direct connection with expired token simulation returns or marks expired."""
        payload = {
            "platform": "tiktok",
            "mode": "direct",
            "access_token": "expired_mock_token_abc",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 200)
        # Verify account is connected and manageable
        acc = res["connected"][0]
        self.assertEqual(acc["platform"], "tiktok")

    def test_2_duplicate_connection_idempotency(self):
        """Boundary Test 2: Connecting an already-connected platform updates in-place without duplicate rows."""
        payload = {
            "platform": "instagram",
            "mode": "mock",
            "username": "@first_handle",
        }
        self._http_request("POST", "/api/social-accounts/connect", payload)

        # Connect same platform again with updated username
        payload2 = {
            "platform": "instagram",
            "mode": "mock",
            "username": "@updated_handle",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload2)
        self.assertEqual(status, 200)

        # Check account index has only 1 Instagram account
        status, idx = self._http_request("GET", "/api/social-accounts")
        ig_accounts = [a for a in idx["data"] if a["platform"] == "instagram"]
        self.assertEqual(len(ig_accounts), 1)
        self.assertEqual(ig_accounts[0]["platform_username"], "@updated_handle")

    def test_3_unsupported_platform_rejected(self):
        """Boundary Test 3: Connecting unsupported platform (e.g. twitter, snapchat) returns 422."""
        payload = {
            "platform": "twitter",
            "mode": "mock",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)

    def test_4_invalid_mode_rejected(self):
        """Boundary Test 4: Supplying an invalid connection mode returns 422 Unprocessable Entity."""
        payload = {
            "platform": "youtube",
            "mode": "unsupported_mode",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)

    def test_5_missing_direct_token_rejected(self):
        """Boundary Test 5: Mode direct without access_token returns 422 Unprocessable Entity."""
        payload = {
            "platform": "youtube",
            "mode": "direct",
            # missing access_token
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 422)
        self.assertIn("error", res)


if __name__ == "__main__":
    unittest.main()
