<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdStudio\VideoAdPostService;
use App\Services\MetaOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AT-527 (spec .ai/specs/training-videos.md 9.22) - connect the CoreX Facebook page from Marketing -> Facebook page. System Owner only. The owner signs in on
 * Facebook's OWN login screen (MetaOAuthService, the same flow agents use); CoreX never sees or types a password or token. The return trip uses the callback
 * that is already registered with the Meta app (corex.social.oauth.callback) with context=platform, so no new redirect address is needed.
 */
class VideoAdFacebookController extends Controller
{
    private const SESSION_NONCE = 'video_ads_fb_nonce';
    private const SESSION_PAGES = 'video_ads_fb_pages';

    public function __construct(private readonly MetaOAuthService $oauth, private readonly VideoAdPostService $posts)
    {
    }

    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function index()
    {
        $this->assertOwner();

        return view('admin.video-ads.facebook', [
            'account' => $this->posts->account(), 'dryRun' => $this->posts->isDryRun(),
            'metaConfigured' => (string) config('services.meta.app_id') !== '',
            'redirectUri' => (string) config('services.meta.redirect_uri'),
            'neededRedirect' => url('/corex/social/oauth/callback'),
        ]);
    }

    public function connect(Request $request)
    {
        $this->assertOwner();
        if ((string) config('services.meta.app_id') === '') {
            return back()->withErrors(['facebook' => 'The Meta app is not set up on this server (META_APP_ID).']);
        }
        $nonce = Str::random(32);
        $request->session()->put(self::SESSION_NONCE, $nonce);

        return redirect()->away($this->oauth->getAuthUrl('facebook', (int) Auth::id(), 'platform', $nonce));
    }

    /** Called by the registered OAuth callback when the state says context=platform. */
    public function afterLogin(array $pagesData)
    {
        $this->assertOwner();
        $nonce = session()->pull(self::SESSION_NONCE);
        if (! $nonce || ! hash_equals((string) $nonce, (string) ($pagesData['nonce'] ?? ''))) {
            return redirect()->route('admin.video-ads.facebook')->withErrors(['facebook' => 'The Facebook sign-in could not be checked. Please press Connect again.']);
        }
        if (count($pagesData['pages']) === 1) {
            $account = $this->oauth->connectPage((int) Auth::id(), 'facebook', $pagesData['pages'][0]['id'], $pagesData['pages'], true);

            return redirect()->route('admin.video-ads.facebook')->with('status', 'Connected to "' . $account->platform_page_name . '".');
        }
        session([self::SESSION_PAGES => $pagesData['pages']]);

        return redirect()->route('admin.video-ads.facebook.choose-page');
    }

    public function choosePageForm()
    {
        $this->assertOwner();
        $pages = session(self::SESSION_PAGES);
        if (! $pages) {
            return redirect()->route('admin.video-ads.facebook')->withErrors(['facebook' => 'Your Facebook session expired. Please connect again.']);
        }

        return view('admin.video-ads.facebook-choose', ['pages' => $pages]);
    }

    public function choosePage(Request $request)
    {
        $this->assertOwner();
        $pageId = $request->validate(['page_id' => 'required|string'])['page_id'];
        $pages = session(self::SESSION_PAGES);
        if (! $pages) {
            return redirect()->route('admin.video-ads.facebook')->withErrors(['facebook' => 'Your Facebook session expired. Please connect again.']);
        }
        try {
            $account = $this->oauth->connectPage((int) Auth::id(), 'facebook', $pageId, $pages, true);
        } catch (\Throwable $e) {
            return redirect()->route('admin.video-ads.facebook')->withErrors(['facebook' => $e->getMessage()]);
        }
        session()->forget(self::SESSION_PAGES);

        return redirect()->route('admin.video-ads.facebook')->with('status', 'Connected to "' . $account->platform_page_name . '".');
    }

    public function disconnect()
    {
        $this->assertOwner();
        if ($account = $this->posts->account()) {
            $account->forceFill(['is_active' => false])->save();
            $account->delete();   // soft delete: scheduled posts for it will fail with a plain reason instead of posting
        }

        return redirect()->route('admin.video-ads.facebook')->with('status', 'The CoreX Facebook page was disconnected.');
    }
}
