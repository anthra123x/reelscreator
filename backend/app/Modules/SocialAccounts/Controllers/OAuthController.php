<?php

namespace App\Modules\SocialAccounts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use App\Modules\SocialAccounts\Services\SocialAccountService;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function __construct(
        protected SocialAccountService $accountService,
        protected TikTokOAuthService $tikTokOAuth,
        protected InstagramOAuthService $instagramOAuth,
        protected YouTubeOAuthService $youTubeOAuth
    ) {}

    /**
     * List connected social accounts.
     * GET /api/social-accounts
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $accounts = $this->accountService->listAccounts($userId);

        $data = $accounts->map(function (SocialAccount $account) {
            $isExpired = $account->isExpired();

            return [
                'id' => $account->id,
                'platform' => $account->platform,
                'platform_user_id' => $account->platform_user_id,
                'platform_username' => $account->platform_username,
                'connected_at' => $account->created_at?->toISOString() ?? now()->toISOString(),
                'expires_at' => $account->expires_at?->toISOString(),
                'is_mock' => $account->is_mock,
                'is_active' => ! $isExpired,
                'status' => $isExpired ? 'expired' : 'connected',
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Unified agile connect endpoint for social accounts.
     * POST /api/social-accounts/connect
     */
    public function simpleConnect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'platform' => ['required', 'string', 'in:tiktok,instagram,youtube,all'],
            'mode' => ['nullable', 'string', 'in:auto,env,mock,direct'],
            'username' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $platform = (string) $request->input('platform');
        $mode = (string) $request->input('mode', 'auto');
        $userId = $request->user()?->id;

        $options = [
            'username' => $request->input('username'),
            'access_token' => $request->input('access_token'),
            'refresh_token' => $request->input('refresh_token'),
        ];

        $accounts = $this->accountService->simpleConnect($platform, $mode, $options, $userId);

        $connectedData = $accounts->map(function (SocialAccount $account) {
            $isExpired = $account->isExpired();

            return [
                'id' => $account->id,
                'platform' => $account->platform,
                'platform_username' => $account->platform_username,
                'is_mock' => $account->is_mock,
                'status' => $isExpired ? 'expired' : 'connected',
                'is_active' => ! $isExpired,
                'connected_at' => $account->created_at?->toISOString() ?? now()->toISOString(),
            ];
        })->values();

        $response = [
            'message' => 'Social accounts connected successfully',
            'connected' => $connectedData,
        ];

        if ($connectedData->count() === 1) {
            $response['account'] = $connectedData->first();
        }

        return response()->json($response, 200);
    }

    /**
     * Initiate OAuth flow for a platform.
     * POST /api/social-accounts/connect/{platform}
     */
    public function connect(string $platform, Request $request): JsonResponse
    {
        if (! in_array($platform, SocialAccount::SUPPORTED_PLATFORMS, true)) {
            return response()->json(['message' => "Unsupported platform: {$platform}"], 400);
        }

        $inputClientId = $request->input('client_id');
        $inputClientSecret = $request->input('client_secret');

        // Check if real developer credentials are set in config or request
        $configKey = match ($platform) {
            SocialAccount::PLATFORM_TIKTOK => config('social.tiktok.client_key'),
            SocialAccount::PLATFORM_INSTAGRAM => config('social.instagram.client_id'),
            SocialAccount::PLATFORM_YOUTUBE => config('social.youtube.client_id'),
        };

        $effectiveClientId = $inputClientId ?: $configKey;
        $isConfigured = ! empty($effectiveClientId) && ! str_starts_with((string) $effectiveClientId, 'mock_');

        $state = Str::random(40);
        session(['oauth_state' => $state]);

        if ($inputClientId && $inputClientSecret) {
            Cache::put("oauth_creds_{$platform}_{$state}", [
                'client_id' => $inputClientId,
                'client_secret' => $inputClientSecret,
            ], now()->addHour());
        }

        $authUrl = match ($platform) {
            SocialAccount::PLATFORM_TIKTOK => $this->tikTokOAuth->getAuthorizationUrl($state, null, $effectiveClientId),
            SocialAccount::PLATFORM_INSTAGRAM => $this->instagramOAuth->getAuthorizationUrl($state, $effectiveClientId),
            SocialAccount::PLATFORM_YOUTUBE => $this->youTubeOAuth->getAuthorizationUrl($state, $effectiveClientId),
        };

        $redirectUri = match ($platform) {
            SocialAccount::PLATFORM_TIKTOK => config('social.tiktok.redirect_uri'),
            SocialAccount::PLATFORM_INSTAGRAM => config('social.instagram.redirect_uri'),
            SocialAccount::PLATFORM_YOUTUBE => config('social.youtube.redirect_uri'),
        };

        return response()->json([
            'auth_url' => $authUrl,
            'state' => $state,
            'configured' => $isConfigured,
            'redirect_uri' => $redirectUri,
            'platform' => $platform,
            'message' => $isConfigured ? null : "Para autenticación OAuth con {$platform} se requiere registrar las credenciales de tu aplicación (Client ID y Client Secret).",
        ], 200);
    }

    /**
     * Handle OAuth callback redirect.
     * GET /api/social-accounts/callback/{platform}
     */
    public function callback(string $platform, Request $request): JsonResponse|RedirectResponse
    {
        if (! in_array($platform, SocialAccount::SUPPORTED_PLATFORMS, true)) {
            return response()->json(['message' => "Unsupported platform: {$platform}"], 400);
        }

        if ($request->has('error')) {
            $errorMessage = (string) ($request->query('error_description') ?: $request->query('error'));
            if (! $request->wantsJson()) {
                $frontendUrl = env('FRONTEND_URL', 'https://reelscreator-beta.vercel.app');

                return redirect("{$frontendUrl}/social-accounts?error=".urlencode($errorMessage));
            }

            return response()->json(['message' => 'OAuth provider error', 'error' => $errorMessage], 400);
        }

        $code = $request->query('code');
        if (! $code) {
            return response()->json(['message' => 'Missing authorization code'], 400);
        }

        $state = (string) $request->query('state');
        $creds = $state ? Cache::get("oauth_creds_{$platform}_{$state}", []) : [];
        $customClientId = $creds['client_id'] ?? null;
        $customClientSecret = $creds['client_secret'] ?? null;

        $tokenData = match ($platform) {
            SocialAccount::PLATFORM_TIKTOK => $this->tikTokOAuth->exchangeCode($code, null, $customClientId, $customClientSecret),
            SocialAccount::PLATFORM_INSTAGRAM => $this->instagramOAuth->exchangeCode($code, $customClientId, $customClientSecret),
            SocialAccount::PLATFORM_YOUTUBE => $this->youTubeOAuth->exchangeCode($code, $customClientId, $customClientSecret),
        };

        $account = $this->accountService->saveOAuthAccount($platform, $tokenData, $request->user()?->id);

        if (! $request->wantsJson()) {
            $frontendUrl = env('FRONTEND_URL', 'https://reelscreator-beta.vercel.app');

            return redirect("{$frontendUrl}/social-accounts?connected={$platform}");
        }

        return response()->json([
            'id' => $account->id,
            'platform' => $account->platform,
            'platform_username' => $account->platform_username,
            'connected_at' => $account->created_at?->toISOString() ?? now()->toISOString(),
            'is_mock' => $account->is_mock,
        ]);
    }

    /**
     * Instantly provisions a mock account for offline testing without credentials.
     * POST /api/social-accounts/mock-connect
     */
    public function mockConnect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'platform' => ['required', 'string', 'in:tiktok,instagram,youtube'],
            'username' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $platform = $request->input('platform');
        $username = $request->input('username');
        $userId = $request->user()?->id;

        $account = $this->accountService->createMockAccount($platform, $username, $userId);

        return response()->json([
            'id' => $account->id,
            'platform' => $account->platform,
            'platform_username' => $account->platform_username,
            'connected_at' => $account->created_at?->toISOString() ?? now()->toISOString(),
            'is_mock' => true,
        ], 201);
    }

    /**
     * Disconnect and remove linked social account.
     * DELETE /api/social-accounts/{id}
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $deleted = $this->accountService->deleteAccount($id);
        if (! $deleted) {
            return response()->json(['message' => 'Social account not found'], 404);
        }

        return response()->noContent();
    }
}
