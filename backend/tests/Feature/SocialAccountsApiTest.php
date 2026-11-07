<?php

namespace Tests\Feature;

use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\SocialAccountService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SocialAccountsApiTest extends TestCase
{
    public function test_get_social_accounts_returns_data_array(): void
    {
        $mockService = Mockery::mock(SocialAccountService::class);
        $this->app->instance(SocialAccountService::class, $mockService);

        $account = new SocialAccount([
            'platform' => 'tiktok',
            'platform_user_id' => 'tt_123',
            'platform_username' => '@creator',
        ]);
        $account->id = (string) Str::uuid();
        $account->created_at = Carbon::now();

        $mockService->shouldReceive('listAccounts')->once()->andReturn(new Collection([$account]));

        $response = $this->getJson('/api/social-accounts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'platform',
                        'platform_username',
                        'connected_at',
                        'is_mock',
                    ],
                ],
            ]);
    }

    public function test_connect_platform_generates_auth_url_and_state(): void
    {
        $response = $this->postJson('/api/social-accounts/connect/tiktok');

        $response->assertStatus(200)
            ->assertJsonStructure(['auth_url', 'state']);
    }

    public function test_connect_unsupported_platform_returns_400(): void
    {
        $response = $this->postJson('/api/social-accounts/connect/myspace');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Unsupported platform: myspace']);
    }

    public function test_mock_connect_provisions_test_account(): void
    {
        $mockService = Mockery::mock(SocialAccountService::class);
        $this->app->instance(SocialAccountService::class, $mockService);

        $fakeAccount = new SocialAccount([
            'platform' => 'tiktok',
            'platform_user_id' => 'mock_tiktok_12345678',
            'platform_username' => 'test_creator',
        ]);
        $fakeAccount->id = (string) Str::uuid();
        $fakeAccount->created_at = Carbon::now();

        $mockService->shouldReceive('createMockAccount')
            ->with('tiktok', 'test_creator', Mockery::any())
            ->once()
            ->andReturn($fakeAccount);

        $response = $this->postJson('/api/social-accounts/mock-connect', [
            'platform' => 'tiktok',
            'username' => 'test_creator',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'id' => $fakeAccount->id,
                'platform' => 'tiktok',
                'platform_username' => 'test_creator',
                'is_mock' => true,
            ]);
    }

    public function test_mock_connect_validation_failure_returns_422(): void
    {
        $response = $this->postJson('/api/social-accounts/mock-connect', [
            'platform' => 'invalid_platform',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platform', 'username']);
    }

    public function test_delete_social_account_returns_204(): void
    {
        $mockService = Mockery::mock(SocialAccountService::class);
        $this->app->instance(SocialAccountService::class, $mockService);

        $id = (string) Str::uuid();
        $mockService->shouldReceive('deleteAccount')->with($id)->once()->andReturn(true);

        $response = $this->deleteJson("/api/social-accounts/{$id}");
        $response->assertStatus(204);
    }
}
