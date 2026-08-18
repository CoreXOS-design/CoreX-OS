<?php

namespace App\Http\Controllers;

use App\Exceptions\AiCopyUnavailableException;
use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Models\PropertyMarketingPost;
use App\Services\MarketingCopyService;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PropertyMarketingController extends Controller
{
    use Concerns\EnforcesMarketingReadiness;
    public function __construct(
        private MarketingCopyService  $copyService,
        private MetaOAuthService      $oauthService,
        private MetaPublishingService $publishingService,
    ) {}

    /**
     * Marketing hub for a property: connected accounts + ad builder + post history.
     */
    public function index(Property $property)
    {
        $this->authorizeProperty($property);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $socialAccounts = AgentSocialAccount::where('user_id', $user->id)
            ->active()
            ->get()
            ->keyBy('platform');

        $posts = PropertyMarketingPost::where('property_id', $property->id)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        // Agency-wide custom templates (AgencyScope keeps other agencies out).
        // No user_id/is_global OR-clause — that leaked global templates across
        // agencies via operator precedence. Spec ad-manager.md §5.
        $templates = PropertyAdTemplate::orderByDesc('updated_at')->get();

        return view('marketing.hub', compact('property', 'socialAccounts', 'posts', 'templates'));
    }

    /**
     * Generate AI ad copy for the given platform.
     */
    public function generateCopy(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'platform' => 'required|in:facebook,instagram',
            'emojis'   => 'sometimes|boolean',
        ]);

        try {
            $copy = $this->copyService->generateAdCopy($property, $data['platform'], (bool) ($data['emojis'] ?? false));
            return response()->json(['ok' => true, 'copy' => $copy]);
        } catch (AiCopyUnavailableException $e) {
            // Expected, user-facing state (no key / disabled / budget) — show the message.
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::generateCopy failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => "Couldn't generate copy right now. Please try again."], 500);
        }
    }

    /**
     * Publish to one or more platforms.
     */
    public function publish(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);
        $this->enforceMarketingReadiness($property);

        $validated = $request->validate([
            'platforms'  => 'required|array|min:1',
            'platforms.*'=> 'in:facebook,instagram',
            'copy'       => 'required|string|max:5000',
            'image_urls' => 'nullable|array',
            'image_urls.*' => 'string|url',
        ]);

        /** @var \App\Models\User $user */
        $user      = auth()->user();
        $copy      = $validated['copy'];
        $imageUrls = $validated['image_urls'] ?? [];
        $results   = [];

        foreach ($validated['platforms'] as $platform) {
            $account = AgentSocialAccount::where('user_id', $user->id)
                ->where('platform', $platform)
                ->active()
                ->first();

            if (!$account) {
                $results[$platform] = ['ok' => false, 'error' => 'No connected ' . ucfirst($platform) . ' account found.'];
                continue;
            }

            // Create a draft post record first
            $post = PropertyMarketingPost::create([
                'property_id' => $property->id,
                'user_id'     => $user->id,
                'platform'    => $platform,
                'ad_copy'     => $copy,
                'image_urls'  => $imageUrls,
                'status'      => 'draft',
            ]);

            try {
                if ($platform === 'facebook') {
                    $platformPostId = $this->publishingService->publishToFacebook($account, $copy, $imageUrls);
                } else {
                    $platformPostId = $this->publishingService->publishToInstagram($account, $copy, $imageUrls);
                }

                $post->update([
                    'platform_post_id' => $platformPostId,
                    'status'           => 'published',
                    'published_at'     => now(),
                ]);

                $results[$platform] = ['ok' => true, 'post_id' => $post->id, 'platform_post_id' => $platformPostId];
            } catch (\Throwable $e) {
                $post->update(['status' => 'failed']);
                $results[$platform] = ['ok' => false, 'error' => $e->getMessage()];
                Log::error('PropertyMarketingController::publish failed for ' . $platform . ': ' . $e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'results' => $results]);
    }

    /**
     * Sync insights for a single post.
     */
    public function syncInsights(PropertyMarketingPost $post): JsonResponse
    {
        if ((int) $post->user_id !== (int) auth()->id()) {
            abort(403);
        }

        try {
            $metrics = $this->publishingService->fetchPostInsights($post);

            $post->update(array_merge($metrics, ['last_synced_at' => now()]));

            return response()->json(['ok' => true, 'metrics' => $metrics]);
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::syncInsights failed for post ' . $post->id . ': ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Redirect the agent to Meta OAuth.
     */
    public function oauthRedirect(Request $request)
    {
        $platform = $request->validate([
            'platform' => 'required|in:facebook,instagram',
        ])['platform'];

        $url = $this->oauthService->getAuthUrl($platform, auth()->id());

        return redirect($url);
    }

    /**
     * Handle the OAuth callback from Meta. If the account only administers one
     * Page, connect it immediately (unchanged single-page UX). If there is
     * more than one, stash the list in the session and show a picker so the
     * agent chooses the right agency Page instead of always getting the first
     * one Facebook happens to return.
     */
    public function oauthCallback(Request $request)
    {
        $code  = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return redirect()->route('agent.portal')
                ->with('error', 'Meta OAuth was cancelled or failed.');
        }

        try {
            // A code obtained via the Facebook JS SDK's config_id login
            // (FB.login) is NOT tied to a redirect_uri and must be exchanged
            // with an empty one — see exchangeCodeForPages()'s docblock.
            $redirectUri = $request->query('flow') === 'js' ? '' : null;
            $pagesData   = $this->oauthService->exchangeCodeForPages($code, $state, $redirectUri);

            if ((int) $pagesData['user_id'] !== (int) auth()->id()) {
                return redirect()->route('agent.portal')
                    ->with('error', 'Meta OAuth session mismatch. Please try connecting again.');
            }

            if (count($pagesData['pages']) === 1) {
                $this->oauthService->connectPage(
                    auth()->id(),
                    $pagesData['platform'],
                    $pagesData['pages'][0]['id'],
                    $pagesData['pages'],
                );

                return redirect()->route('agent.portal')
                    ->with('success', 'Social account connected successfully.');
            }

            session([
                'meta_oauth_platform' => $pagesData['platform'],
                'meta_oauth_pages'    => $pagesData['pages'],
            ]);

            return redirect()->route('corex.social.oauth.choose-page');
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::oauthCallback failed: ' . $e->getMessage());
            return redirect()->route('agent.portal')
                ->with('error', 'Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Show the "which Page do you want to connect?" picker — only reached
     * when the agent administers more than one Facebook Page.
     */
    public function oauthChoosePageForm()
    {
        $platform = session('meta_oauth_platform');
        $pages    = session('meta_oauth_pages');

        if (!$platform || !$pages) {
            return redirect()->route('agent.portal')
                ->with('error', 'Your Meta connection session expired. Please connect again.');
        }

        return view('marketing.social-choose-page', compact('platform', 'pages'));
    }

    /**
     * Persist the Page the agent picked from oauthChoosePageForm().
     */
    public function oauthChoosePage(Request $request)
    {
        $pageId = $request->validate(['page_id' => 'required|string'])['page_id'];

        $platform = session('meta_oauth_platform');
        $pages    = session('meta_oauth_pages');

        if (!$platform || !$pages) {
            return redirect()->route('agent.portal')
                ->with('error', 'Your Meta connection session expired. Please connect again.');
        }

        try {
            $this->oauthService->connectPage(auth()->id(), $platform, $pageId, $pages);
            session()->forget(['meta_oauth_platform', 'meta_oauth_pages']);

            return redirect()->route('agent.portal')
                ->with('success', 'Social account connected successfully.');
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::oauthChoosePage failed: ' . $e->getMessage());
            return redirect()->route('agent.portal')
                ->with('error', 'Connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Disconnect (soft-delete) a social account.
     */
    public function disconnectAccount(Request $request): JsonResponse
    {
        $platform = $request->validate([
            'platform' => 'required|in:facebook,instagram',
        ])['platform'];

        $account = AgentSocialAccount::where('user_id', auth()->id())
            ->where('platform', $platform)
            ->active()
            ->first();

        if (!$account) {
            return response()->json(['ok' => false, 'error' => 'Account not found.'], 404);
        }

        $account->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Accept a base64-encoded PNG from the Ad Builder and store it,
     * returning a public URL to use as a marketing image.
     */
    public function uploadTemplateImage(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|string']);

        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $request->input('image'));
        $bytes  = base64_decode($base64);

        if (!$bytes) {
            return response()->json(['ok' => false, 'error' => 'Invalid image data.'], 422);
        }

        $filename = 'marketing-exports/' . uniqid('tpl_') . '.png';
        Storage::disk('public')->put($filename, $bytes);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($filename)]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function authorizeProperty(Property $property): void
    {
        /** @var \App\Models\User $user */
        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own'    && (int) $property->agent_id === (int) $user->id) return;

        abort(403);
    }
}
