<?php

namespace App\Http\Controllers\TitleDeeds\Concerns;

use App\Exceptions\TitleDeedFormException;
use App\Services\TitleDeeds\TitleDeedFormGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/title-deed-register.md section 14.1 - every state-changing form in the module claims its one-time token before it
 * acts. A second submit of the same rendered form (Back, double click, refresh-resend, a retry on a slow line) is answered with
 * a plain "already done" and sends the person to the screen they were on; nothing is repeated.
 */
trait GuardsTitleDeedForms
{
    /**
     * Claim the request's `_form_token` for ($purpose, $deedId). Returns null when the claim succeeded (go ahead), otherwise the
     * redirect to send the user to.
     */
    protected function claimForm(Request $request, string $purpose, ?int $deedId, string $backUrl): ?RedirectResponse
    {
        try {
            app(TitleDeedFormGuard::class)->claim($request->input('_form_token'), $request->user(), $purpose, $deedId);

            return null;
        } catch (TitleDeedFormException $e) {
            return redirect($backUrl)->with($e->kind === TitleDeedFormException::ALREADY ? 'info' : 'error', $e->getMessage());
        }
    }

    /** Where a row action on the list sends the user back to: the list exactly as they had it (same filters, same page). */
    protected function returnTo(Request $request, string $fallback): string
    {
        $to = (string) $request->input('return_to', '');
        $listPath = route('title-deeds.index', [], false);

        // Only ever back into this module's own list - never an address someone typed into a hidden field.
        if ($to !== '' && str_starts_with($to, $listPath) && ! str_contains($to, '//') && ! str_contains($to, "\n")) {
            return $to;
        }

        return $fallback;
    }
}
