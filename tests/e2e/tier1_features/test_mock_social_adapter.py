#!/usr/bin/env python3
"""
Tier 1 Feature Tests: Pluggable Mock Social Publisher Adapter
Tests MockSocialAdapter against SocialPublisherAdapterInterface contract for
TikTok, Instagram Reels, and YouTube Shorts.
"""

import time
import uuid
import unittest

from tests.e2e.common.contracts import ContractValidator
from tests.e2e.common.mock_server import MockSocialAdapter


class TestMockSocialAdapterFeatures(unittest.TestCase):
    """Verifies MockSocialAdapter compliance with SocialPublisherAdapterInterface."""

    def test_1_adapter_platform_identity(self):
        """Feature Test 1: getPlatform() returns valid identifiers for all target platforms."""
        platforms = ["tiktok", "instagram", "youtube"]
        for p in platforms:
            adapter = MockSocialAdapter(platform=p)
            self.assertEqual(adapter.get_platform(), p)

    def test_2_mock_publish_success(self):
        """Feature Test 2: publish() returns valid PublishResult with simulated post ID."""
        adapter = MockSocialAdapter(platform="tiktok")
        clip_id = str(uuid.uuid4())
        account_id = str(uuid.uuid4())

        result = adapter.publish(clip_id, account_id, options={"caption": "E2E Test Clip"})
        ContractValidator.validate_mock_social_publish_result(result)
        self.assertTrue(result["success"])
        self.assertEqual(result["platform"], "tiktok")
        self.assertTrue(result["platform_post_id"].startswith("tiktok_post_"))
        self.assertIsNotNone(result["published_at"])
        self.assertIsNone(result["error"])

    def test_3_mock_account_validation(self):
        """Feature Test 3: validateAccount() correctly reports active and revoked accounts."""
        adapter = MockSocialAdapter(platform="instagram")
        account_id = str(uuid.uuid4())

        self.assertTrue(adapter.validate_account(account_id, is_active=True))
        self.assertFalse(adapter.validate_account(account_id, is_active=False))

    def test_4_mock_token_refresh(self):
        """Feature Test 4: refreshToken() updates simulated OAuth credentials without pipeline failure."""
        adapter = MockSocialAdapter(platform="youtube")
        account_id = str(uuid.uuid4())

        self.assertTrue(adapter.refresh_token(account_id, force_failure=False))
        self.assertFalse(adapter.refresh_token(account_id, force_failure=True))

    def test_5_mock_simulation_flags(self):
        """Feature Test 5: Simulation flags trigger deterministic latency and simulated failure modes."""
        # Test simulated delay
        adapter_with_delay = MockSocialAdapter(platform="tiktok", simulate_delay_ms=100)
        t0 = time.time()
        res_delay = adapter_with_delay.publish("clip-1", "acc-1")
        elapsed = time.time() - t0
        self.assertTrue(res_delay["success"])
        self.assertGreaterEqual(elapsed, 0.08)

        # Test simulated failure
        adapter = MockSocialAdapter(platform="tiktok")
        res_failure = adapter.publish("clip-1", "acc-1", simulate_failure=True)
        ContractValidator.validate_mock_social_publish_result(res_failure)
        self.assertFalse(res_failure["success"])
        self.assertIn("MockSimulatedFailure", res_failure["error"])


if __name__ == "__main__":
    unittest.main()
