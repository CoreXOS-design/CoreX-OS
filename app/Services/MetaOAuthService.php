<?php

namespace App\Services;

use App\Models\AgentSocialAccount;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MetaOAuthService
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v19.0';

    public function __construct(
        private Client $http = new Client(['timeout' => 30, 'connect_timeout' => 10]),
    ) {}

    /**
     * Build the Facebook OAuth URL for the given platform and user.
     * State encodes userId and platform so the callback knows what to store.
     */
    public function getAuthUrl(string $platform, int $userId): string
    {
        $scopes = match ($platform) {
            'instagram' => [
                'pages_show_list',
                'pages_read_engagement',
                'instagram_basic',
                'instagram_content_publish',
            ],
            default => [
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
                'read_insights',
            ],
        };

        $state = base64_encode(json_encode(['user_id' => $userId, 'platform' => $platform]));

        $params = http_build_query([
            'client_id'     => config('services.meta.app_id'),
            'redirect_uri'  => config('services.meta.redirect_uri'),
            'scope'         => implode(',', $scopes),
            'response_type' => 'code',
            'state'         => $state,
        ]);

        return 'https://www.facebook.com/v19.0/dialog/oauth?' . $params;
    }

    /**
     * Handle the OAuth callback: exchange code for token, list every Page the
     * user administers, and return them for the caller to choose from. Does
     * NOT persist anything — that's connectPage()'s job, once a page is picked.
     *
     * For 'instagram', only Pages with a linked Instagram Business Account are
     * returned (a Page without one can never be connected for that platform).
     */
    public function exchangeCodeForPages(string $code, string $state): array
    {
        $decoded  = json_decode(base64_decode($state), true);
        $userId   = (int) ($decoded['user_id'] ?? 0);
        $platform = (string) ($decoded['platform'] ?? 'facebook');

        if ($userId <= 0) {
            throw new \RuntimeException('Invalid OAuth state: missing user_id.');
        }

        // Exchange code for short-lived token
        $tokenResponse = $this->http->get(self::GRAPH_BASE . '/oauth/access_token', [
            'query' => [
                'client_id'     => config('services.meta.app_id'),
                'client_secret' => config('services.meta.app_secret'),
                'redirect_uri'  => config('services.meta.redirect_uri'),
                'code'          => $code,
            ],
        ]);

        $tokenData  = json_decode($tokenResponse->getBody()->getContents(), true);
        $shortToken = $tokenData['access_token'] ?? null;

        if (!$shortToken) {
            throw new \RuntimeException('Meta OAuth: no access_token in response.');
        }

        // Exchange for long-lived 60-day token
        $longToken = $this->getLongLivedToken($shortToken);

        // Fetch every Page the user administers
        $pagesResponse = $this->http->get(self::GRAPH_BASE . '/me/accounts', [
            'query' => [
                'access_token' => $longToken,
                'fields'       => 'id,name,access_token,instagram_business_account,picture',
            ],
        ]);

        $pages = json_decode($pagesResponse->getBody()->getContents(), true)['data'] ?? [];

        if (empty($pages)) {
            throw new \RuntimeException('No Facebook Pages found for this account. You must be an Admin of a Page.');
        }

        if ($platform === 'instagram') {
            $pages = array_values(array_filter($pages, fn ($p) => !empty($p['instagram_business_account'])));

            if (empty($pages)) {
                throw new \RuntimeException('None of your Facebook Pages have an Instagram Business Account linked. Connect Instagram to a Page in Facebook Settings first.');
            }
        }

        return [
            'user_id'  => $userId,
            'platform' => $platform,
            'pages'    => array_map(fn ($p) => [
                'id'                         => $p['id'],
                'name'                       => $p['name'],
                // Page access token (from /me/accounts) — required for posting to
                // Pages. The user long-lived token ($longToken) cannot post.
                'access_token'               => $p['access_token'],
                'instagram_business_account' => $p['instagram_business_account'] ?? null,
                'picture'                    => $p['picture']['data']['url'] ?? null,
            ], $pages),
        ];
    }

    /**
     * Persist the chosen Page (or its linked Instagram account) as the agent's
     * connected social account. $pages is the array returned by
     * exchangeCodeForPages() for the SAME callback — carries every Page's own
     * access token so no second Graph round-trip to /me/accounts is needed.
     */
    public function connectPage(int $userId, string $platform, string $pageId, array $pages): AgentSocialAccount
    {
        $page = collect($pages)->firstWhere('id', $pageId);

        if (!$page) {
            throw new \RuntimeException('That Page is no longer available. Please reconnect.');
        }

        $pageToken = $page['access_token'];

        if ($platform === 'instagram') {
            $igAccount = $page['instagram_business_account'] ?? null;
            if (!$igAccount) {
                throw new \RuntimeException('No Instagram Business Account linked to this Facebook Page.');
            }

            $igDetailsResponse = $this->http->get(self::GRAPH_BASE . '/' . $igAccount['id'], [
                'query' => ['access_token' => $pageToken, 'fields' => 'id,name,username'],
            ]);
            $igDetails = json_decode($igDetailsResponse->getBody()->getContents(), true);

            $connectedId   = $igDetails['id'];
            $connectedName = $igDetails['username'] ?? ($igDetails['name'] ?? 'Instagram Account');
        } else {
            $connectedId   = $page['id'];
            $connectedName = $page['name'];
        }

        // Calculate expiry (~60 days from now for long-lived tokens)
        $expiresAt = now()->addDays(60);

        // Upsert — restore soft-deleted if exists
        $existing = AgentSocialAccount::withTrashed()
            ->where('user_id', $userId)
            ->where('platform', $platform)
            ->first();

        if ($existing) {
            $existing->restore();
            $existing->update([
                'platform_page_id'   => $connectedId,
                'platform_page_name' => $connectedName,
                'access_token'       => $pageToken,
                'token_expires_at'   => $expiresAt,
                'is_active'          => true,
            ]);
            return $existing->fresh();
        }

        return AgentSocialAccount::create([
            'user_id'            => $userId,
            'platform'           => $platform,
            'platform_page_id'   => $connectedId,
            'platform_page_name' => $connectedName,
            'access_token'       => $pageToken,
            'token_expires_at'   => $expiresAt,
            'is_active'          => true,
        ]);
    }

    /**
     * Exchange a short-lived token for a 60-day long-lived token.
     */
    public function getLongLivedToken(string $shortToken): string
    {
        $response = $this->http->get(self::GRAPH_BASE . '/oauth/access_token', [
            'query' => [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => config('services.meta.app_id'),
                'client_secret'     => config('services.meta.app_secret'),
                'fb_exchange_token' => $shortToken,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Meta OAuth: failed to get long-lived token.');
        }

        return $data['access_token'];
    }

    /**
     * Refresh the token if it expires within 7 days.
     */
    public function refreshTokenIfNeeded(AgentSocialAccount $account): void
    {
        if ($account->token_expires_at === null) {
            return;
        }

        if ($account->token_expires_at->greaterThan(now()->addDays(7))) {
            return;
        }

        try {
            $newToken = $this->getLongLivedToken($account->access_token);
            $account->update([
                'access_token'     => $newToken,
                'token_expires_at' => now()->addDays(60),
            ]);
        } catch (\Throwable $e) {
            Log::error('MetaOAuthService: token refresh failed for account ' . $account->id . ': ' . $e->getMessage());
        }
    }
}
