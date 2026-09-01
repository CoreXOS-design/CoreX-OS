<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DevSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Version gate for the mobile app.
 *
 * DELIBERATELY UNAUTHENTICATED. The whole point of a forced-update gate is to
 * stop a build that can no longer talk to this API correctly, and such a build
 * may not be able to log in at all — so the check has to run before auth, on a
 * cold start, with no bearer token.
 *
 * The app compares its own build number against `min_build` and blocks itself
 * when it is lower. Nothing is enforced server-side: this is a UX gate, not a
 * security control. An attacker running a patched client is not the threat
 * model; an agent stranded on a build with a broken push pipeline is.
 *
 * Controlled entirely from DevSetting so the cutoff can be raised — or dropped
 * back to 0 in an emergency — without a deploy or an app release. A gate you
 * cannot switch off quickly is a way to brick the whole agent base.
 *
 *   DevSetting::set('mobile_min_build_android', '13');
 *   DevSetting::set('mobile_min_build_ios', '13');
 *   DevSetting::set('mobile_update_url_ios', 'https://apps.apple.com/za/app/corex-os/id123456789');
 *
 * Alongside the forced gate, an optional, dismissible "Update available"
 * notice — deliberately a SEPARATE dial from min_build. Announcing a release
 * and forcing one are different decisions; every release you tell people
 * about must not also be one you brick the old build for.
 *
 *   // Announce 1.1.0 (build 20) — dismissible notice, old builds keep working.
 *   DevSetting::set('mobile_latest_build_android', '20');
 *   DevSetting::set('mobile_latest_build_ios', '20');
 *   DevSetting::set('mobile_latest_version', '1.1.0');
 *
 *   // Escalate to a hard block only if that release genuinely must be adopted.
 *   DevSetting::set('mobile_min_build_android', '20');
 *
 *   // Emergency off-switch for either.
 *   DevSetting::set('mobile_latest_build_android', '0');
 *
 * Note DevSetting caches for an hour, so a change takes up to that long to
 * reach clients (DevSetting::set clears the key, so it is immediate in practice
 * on the writing node).
 */
class MobileAppConfigController extends Controller
{
    /**
     * Play listing is derivable from the package name, so it always has a default.
     *
     * PUBLIC because the Dev Settings screen has to mirror the "no url => both
     * dials forced to 0" rule when it validates. Re-deriving that default there
     * would let the two drift, and the operator would be told Android needs a url
     * it has always had.
     */
    public const DEFAULT_ANDROID_URL = 'https://play.google.com/store/apps/details?id=za.co.corex_mobile';

    /** Platforms this gate knows about. Anything else is never gated. */
    public const PLATFORMS = ['android', 'ios'];

    /**
     * The update url actually in force for a platform — stored value, or the
     * derivable Play default for Android. Empty string means "nowhere to send
     * them", which forces both dials off.
     */
    public static function effectiveUpdateUrl(string $platform): string
    {
        return trim((string) DevSetting::get(
            "mobile_update_url_{$platform}",
            $platform === 'android' ? self::DEFAULT_ANDROID_URL : ''
        ));
    }

    public function show(Request $request): JsonResponse
    {
        $platform = strtolower(trim((string) $request->query('platform', '')));

        // An unrecognised platform is never gated. A future client (or a web
        // build) asking this endpoint must not be locked out by a cutoff that
        // was never meant for it.
        if (! in_array($platform, self::PLATFORMS, true)) {
            return response()->json([
                'platform'         => $platform ?: null,
                'min_build'        => 0,
                'update_required'  => false,
                'update_url'       => null,
                'message'          => null,
                'latest_build'     => 0,
                'latest_version'   => null,
                'update_available' => false,
                'update_available_message' => null,
            ]);
        }

        $minBuild    = (int) DevSetting::get("mobile_min_build_{$platform}", '0');
        $latestBuild = (int) DevSetting::get("mobile_latest_build_{$platform}", '0');
        $updateUrl   = self::effectiveUpdateUrl($platform);

        // HARD SAFETY RULE: never gate — or announce an update for — a
        // platform we cannot send the user anywhere to update. Blocking (or
        // nagging) an agent behind an "Update now" button that goes nowhere
        // is strictly worse than staying quiet. In practice this only bites
        // iOS, where the App Store listing URL contains a numeric id we
        // cannot derive — so both dials stay inert until
        // mobile_update_url_ios is actually set.
        if ($updateUrl === '') {
            $minBuild    = 0;
            $latestBuild = 0;
        }

        $build = (int) $request->query('build', 0);

        return response()->json([
            'platform'  => $platform,
            'min_build' => $minBuild,
            // Computed server-side as well as client-side purely so this
            // endpoint is self-describing when curled during an incident.
            'update_required' => $minBuild > 0 && $build > 0 && $build < $minBuild,
            'update_url'      => $updateUrl ?: null,
            'message'         => trim((string) DevSetting::get('mobile_update_message', '')) ?: null,

            // Optional, dismissible notice — independent of the forced gate
            // above. latest_build = 0 means the notice is off, same
            // off-switch semantics as min_build = 0.
            'latest_build'   => $latestBuild,
            // Per-platform, falling back to the old global key so a value set
            // before this split keeps working mid-flight. Android build 29 and
            // iOS build 31 are different releases and cannot share one version
            // name — which is what the global key forced.
            'latest_version' => trim((string) DevSetting::get(
                "mobile_latest_version_{$platform}",
                trim((string) DevSetting::get('mobile_latest_version', ''))
            )) ?: null,
            // Computed server-side purely so this endpoint is self-describing
            // when curled during an incident — the app re-derives this
            // itself, since only the client knows for certain which build is
            // running.
            'update_available' => $latestBuild > 0 && $build > 0 && $build < $latestBuild,

            // Copy for the DISMISSIBLE notice. Kept strictly separate from
            // `message` above, which is the forced-gate wording ("no longer
            // supported") — showing that on a dialog the user can dismiss would
            // be a plain lie, and the kind that teaches agents to ignore the
            // real one. The app falls back to its own copy when this is null.
            'update_available_message' => trim((string) DevSetting::get('mobile_update_available_message', '')) ?: null,
        ]);
    }
}
