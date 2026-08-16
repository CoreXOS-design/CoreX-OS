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
 * Note DevSetting caches for an hour, so a change takes up to that long to
 * reach clients (DevSetting::set clears the key, so it is immediate in practice
 * on the writing node).
 */
class MobileAppConfigController extends Controller
{
    /** Play listing is derivable from the package name, so it always has a default. */
    private const DEFAULT_ANDROID_URL = 'https://play.google.com/store/apps/details?id=za.co.corex_mobile';

    public function show(Request $request): JsonResponse
    {
        $platform = strtolower(trim((string) $request->query('platform', '')));

        // An unrecognised platform is never gated. A future client (or a web
        // build) asking this endpoint must not be locked out by a cutoff that
        // was never meant for it.
        if (! in_array($platform, ['android', 'ios'], true)) {
            return response()->json([
                'platform'        => $platform ?: null,
                'min_build'       => 0,
                'update_required' => false,
                'update_url'      => null,
                'message'         => null,
            ]);
        }

        $minBuild  = (int) DevSetting::get("mobile_min_build_{$platform}", '0');
        $updateUrl = trim((string) DevSetting::get(
            "mobile_update_url_{$platform}",
            $platform === 'android' ? self::DEFAULT_ANDROID_URL : ''
        ));

        // HARD SAFETY RULE: never gate a platform we cannot send the user
        // anywhere to update. Blocking an agent behind an "Update now" button
        // that goes nowhere is strictly worse than letting the old build run.
        // In practice this only bites iOS, where the App Store listing URL
        // contains a numeric id we cannot derive — so the iOS gate stays
        // inert until mobile_update_url_ios is actually set.
        if ($updateUrl === '') {
            $minBuild = 0;
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
        ]);
    }
}
