<?php

namespace App\Http\Controllers;

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

        $templates = PropertyAdTemplate::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('is_global', true);
        })->orderByDesc('updated_at')->get();

        return view('marketing.hub', compact('property', 'socialAccounts', 'posts', 'templates'));
    }

    /**
     * Generate AI ad copy for the given platform.
     */
    public function generateCopy(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($property);

        $platform = $request->validate([
            'platform' => 'required|in:facebook,instagram',
        ])['platform'];

        try {
            $copy = $this->copyService->generateAdCopy($property, $platform);
            return response()->json(['ok' => true, 'copy' => $copy]);
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::generateCopy failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
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
     * Handle the OAuth callback from Meta.
     */
    public function oauthCallback(Request $request)
    {
        $code  = $request->query('code');
        $state = $request->query('state');

        if (!$code || !$state) {
            return redirect()->route('corex.settings', ['tab' => 'user'])
                ->with('error', 'Meta OAuth was cancelled or failed.');
        }

        try {
            $this->oauthService->handleCallback($code, $state);
            return redirect()->route('corex.settings', ['tab' => 'user'])
                ->with('success', 'Social account connected successfully.');
        } catch (\Throwable $e) {
            Log::error('PropertyMarketingController::oauthCallback failed: ' . $e->getMessage());
            return redirect()->route('corex.settings', ['tab' => 'user'])
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
