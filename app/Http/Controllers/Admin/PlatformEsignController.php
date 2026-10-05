<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PlatformEsignMode;
use Illuminate\Http\Request;

/**
 * AT-447 — Dev Settings → Platform E-Sign.
 *
 * Opens the normal e-sign (DocuPerfect — template creator, wizard, signing) in
 * Platform E-Sign mode, where everything belongs to CoreX and no agency. See
 * App\Support\PlatformEsignMode. Owner-only (route is owner_only AND re-checked).
 */
class PlatformEsignController extends Controller
{
    public function enter(Request $request)
    {
        abort_unless($request->user()?->isOwnerRole(), 403);

        PlatformEsignMode::enter();

        return redirect()->route('docuperfect.dashboard');
    }

    public function exit(Request $request)
    {
        abort_unless($request->user()?->isOwnerRole(), 403);

        PlatformEsignMode::leave();

        return redirect()->route('admin.dev-settings.index')->with('success', 'Left Platform E-Sign.');
    }
}
