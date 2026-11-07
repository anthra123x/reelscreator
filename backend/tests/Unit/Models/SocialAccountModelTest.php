<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Modules\Publishing\Models\Publication;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Tests\TestCase;

class SocialAccountModelTest extends TestCase
{
    public function test_social_account_encrypts_tokens_at_rest_and_decrypts_on_access(): void
    {
        $rawAccessToken = 'tiktok_super_secret_access_token_12345';
        $rawRefreshToken = 'tiktok_refresh_token_67890';

        $account = new SocialAccount;
        $account->id = (string) Str::uuid();
        $account->user_id = (string) Str::uuid();
        $account->platform = SocialAccount::PLATFORM_TIKTOK;
        $account->platform_user_id = 'tt_user_999';
        $account->platform_username = '@reel_creator';
        $account->access_token = $rawAccessToken;
        $account->refresh_token = $rawRefreshToken;

        // Raw internal attribute must be encrypted (ciphertext payload)
        $rawAttributes = $account->getAttributes();
        $this->assertNotEquals($rawAccessToken, $rawAttributes['access_token']);
        $this->assertNotEquals($rawRefreshToken, $rawAttributes['refresh_token']);

        // Eloquent decrypted getter must return original plain secret
        $this->assertEquals($rawAccessToken, $account->access_token);
        $this->assertEquals($rawRefreshToken, $account->refresh_token);

        // Tokens must be hidden from array / JSON serialization
        $serialized = $account->toArray();
        $this->assertArrayNotHasKey('access_token', $serialized);
        $this->assertArrayNotHasKey('refresh_token', $serialized);
    }

    public function test_is_expired_detection(): void
    {
        $account = new SocialAccount;

        // No expires_at -> not expired
        $this->assertFalse($account->isExpired());

        // Future expires_at (2 hours ahead) -> not expired
        $account->expires_at = Carbon::now()->addHours(2);
        $this->assertFalse($account->isExpired());

        // Past expires_at -> expired
        $account->expires_at = Carbon::now()->subMinute();
        $this->assertTrue($account->isExpired());

        // Expiration in 2 minutes (threshold is 5 minutes) -> considered expired
        $account->expires_at = Carbon::now()->addMinutes(2);
        $this->assertTrue($account->isExpired());
    }

    public function test_is_mock_attribute_detection(): void
    {
        $mockAccount = new SocialAccount([
            'platform' => 'tiktok',
            'platform_user_id' => 'mock_tiktok_abc123',
        ]);
        $this->assertTrue($mockAccount->is_mock);

        config(['social.mock_mode' => false]);
        $realAccount = new SocialAccount([
            'platform' => 'tiktok',
            'platform_user_id' => 'real_user_87263',
        ]);
        $this->assertFalse($realAccount->is_mock);

        config(['social.mock_mode' => true]);
        $this->assertTrue($realAccount->is_mock);
    }

    public function test_relationships_definition(): void
    {
        $account = new SocialAccount;
        $this->assertInstanceOf(BelongsTo::class, $account->user());
        $this->assertInstanceOf(User::class, $account->user()->getRelated());

        $this->assertInstanceOf(HasMany::class, $account->publications());
        $this->assertInstanceOf(Publication::class, $account->publications()->getRelated());
    }
}
