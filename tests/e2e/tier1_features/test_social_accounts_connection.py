#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Social Accounts Connection (Agile 1-Click & Multi-Mode Connect)
Tests POST /api/social-accounts/connect with auto, env, mock, direct modes,
1-click "all" platform connection, and accounts index querying.
"""

import json
import os
import urllib.error
import urllib.request
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import EphemeralMockServer, GLOBAL_STATE


class TestSocialAccountsConnectionFeatures(unittest.TestCase):
    """Verifies simplified agile social accounts connection contracts."""

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
        cls.mock_server = EphemeralMockServer(port=8012)
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

    def test_1_connect_single_platform_mock(self):
        """Feature Test 1: POST /api/social-accounts/connect connects single platform in mock mode."""
        payload = {
            "platform": "tiktok",
            "mode": "mock",
            "username": "@reels_star",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 200)
        ContractValidator.validate_social_accounts_connect_response(res)

        connected = res["connected"]
        self.assertEqual(len(connected), 1)
        self.assertEqual(connected[0]["platform"], "tiktok")
        self.assertEqual(connected[0]["platform_username"], "@reels_star")
        self.assertTrue(connected[0]["is_mock"])
        self.assertEqual(connected[0]["status"], "connected")

    def test_2_connect_all_platforms_quick_connect(self):
        """Feature Test 2: POST /api/social-accounts/connect with platform='all' connects all 3 channels in 1 call."""
        payload = {
            "platform": "all",
            "mode": "auto",
            "username": "@creator_studio",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 200)
        ContractValidator.validate_social_accounts_connect_response(res)

        connected = res["connected"]
        self.assertGreaterEqual(len(connected), 3)
        platforms = {acc["platform"] for acc in connected}
        self.assertTrue({"tiktok", "instagram", "youtube"}.issubset(platforms))

    def test_3_connect_env_credentials_mode(self):
        """Feature Test 3: POST /api/social-accounts/connect with mode='env' connects platform."""
        payload = {
            "platform": "instagram",
            "mode": "env",
            "username": "@insta_influencer",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 200)
        ContractValidator.validate_social_accounts_connect_response(res)
        self.assertEqual(res["connected"][0]["platform"], "instagram")

    def test_4_connect_direct_token_mode(self):
        """Feature Test 4: POST /api/social-accounts/connect with mode='direct' stores live token credentials."""
        payload = {
            "platform": "youtube",
            "mode": "direct",
            "username": "@yt_shorts_pro",
            "access_token": "ya29.live_token_simulated_for_testing_123456",
        }
        status, res = self._http_request("POST", "/api/social-accounts/connect", payload)
        self.assertEqual(status, 200)
        ContractValidator.validate_social_accounts_connect_response(res)
        acc = res["connected"][0]
        self.assertEqual(acc["platform"], "youtube")
        self.assertFalse(acc["is_mock"])

    def test_5_social_accounts_index_retrieval(self):
        """Feature Test 5: GET /api/social-accounts returns registered accounts with statuses."""
        status, res = self._http_request("GET", "/api/social-accounts")
        self.assertEqual(status, 200)
        self.assertIn("data", res)
        accounts = res["data"]
        self.assertGreaterEqual(len(accounts), 3)
        for acc in accounts:
            ContractValidator.validate_uuid(acc["id"], "account.id")
            self.assertIn(acc["platform"], ("tiktok", "instagram", "youtube"))
            self.assertIn("platform_username", acc)


if __name__ == "__main__":
    unittest.main()
