<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Communications\MailInterceptToggleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AT-URGENT-2026-09-09 (Johan, via conductor) — "for corex uses only". The
 * owner_only middleware on this controller's route is the ONLY enforcement
 * that matters — the email-setup screen also hides the control from anyone
 * who isn't isOwnerRole(), but that is a courtesy (an agency admin should
 * never see a control they cannot use), not the safety boundary. A crafted
 * request here from a non-super-admin is refused by the middleware before
 * this class runs at all.
 */
class MailInterceptToggleController extends Controller
{
    public function update(Request $request, MailInterceptToggleService $toggle)
    {
        $data = $request->validate([
            'direction' => 'required|in:intercept,send,clear',
            'reason' => 'required|string|max:1000',
        ]);

        $user = Auth::user();

        try {
            match ($data['direction']) {
                'intercept' => $toggle->forceIntercept($user, $data['reason']),
                'send' => $toggle->forceSend($user, $data['reason']),
                'clear' => $toggle->clearOverride($user, $data['reason']),
            };
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', 'Outbound mail interception setting updated.');
    }
}
