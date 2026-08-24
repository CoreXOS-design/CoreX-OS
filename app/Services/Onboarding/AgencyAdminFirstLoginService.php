<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Mail\AgencyOnboardingSetupMail;
use App\Models\AgencyOnboardingSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fires on a genuinely new agency Admin's first REAL credential-based login:
 * stamps first_login_at once, sends the deferred agency-onboarding-setup
 * email, and flashes the one-time welcome pop-up.
 *
 * Spec: .ai/specs/agency-admin-rule.md §R1b
 *
 * Deliberately NOT wired off the generic Illuminate\Auth\Events\Login event
 * (as it originally was). That event ALSO fires for
 * ImpersonateController::start()/stop() — Auth::login() always fires it —
 * so an owner impersonating a brand-new, still-pending-invite Admin
 * permanently consumed the Admin's first-login trigger: the mail sent from
 * the OWNER's action, the popup flags landed in the owner's (impersonating)
 * session, and the real Admin's later genuine first login silently did
 * nothing. Found in review 2026-08-12, before it shipped to anyone.
 *
 * Calling this explicitly from only the two genuine-login call sites —
 * AuthenticatedSessionController::store() and
 * AgencySetupGateController::login() — prevents that class of bug
 * structurally: impersonation code simply never calls it, rather than this
 * service trying to detect and exclude impersonation after the fact
 * (BUILD_STANDARD §3 — prevent, don't detect-and-suppress).
 */
class AgencyAdminFirstLoginService
{
    /**
     * @param bool $showWelcomePopup Pass false when the login itself already
     *   lands the user IN the onboarding wizard (AgencySetupGateController) —
     *   a "go start onboarding" pop-up on top of the wizard they're already
     *   on is redundant. The mail still sends either way so they have the
     *   link in their inbox for later.
     */
    public function handle(User $user, bool $showWelcomePopup = true): void
    {
        // Atomic compare-and-swap: only the request that actually flips
        // first_login_at from NULL proceeds. Without this, two near-
        // simultaneous logins (double-click, two tabs) could both read it as
        // null before either write commits, and both send the mail.
        //
        // Agent Activation Gate (.ai/specs/agent-activation-gate.md) — this is also
        // THE moment an agent activates: is_active flips in the same atomic write,
        // reusing this proven claim rather than a second racy read-then-write. A user
        // reaches here only after authenticate() already verified their real password
        // (never the unguessable invite placeholder), so this is a genuine first sign-in.
        $claimed = DB::table('users')
            ->where('id', $user->id)
            ->whereNull('first_login_at')
            ->update(['first_login_at' => now(), 'is_active' => true]);

        if ($claimed !== 1) {
            return; // not this account's first login, or a concurrent request already claimed it
        }

        $pendingSetup = AgencyOnboardingSetup::queryWithoutAgencyScope()
            ->where('admin_user_id', $user->id)
            ->whereNull('invite_email_sent_at')
            ->first();

        if (!$pendingSetup) {
            return;
        }

        // Same atomic claim on the setup row, so a first-login trigger racing
        // a manual Resend (or two near-simultaneous first logins) can't both
        // decide they're the one to send.
        $setupClaimed = DB::table('agency_onboarding_setups')
            ->where('id', $pendingSetup->id)
            ->whereNull('invite_email_sent_at')
            ->update(['invite_email_sent_at' => now()]);

        if ($setupClaimed !== 1) {
            return;
        }

        $this->sendMail($pendingSetup, $user->email);

        if ($showWelcomePopup) {
            // put(), NOT flash(): flash() survives exactly ONE subsequent
            // request, and the post-login redirect chain is TWO hops
            // (POST /login -> GET /dashboard, a redirect-only closure with no
            // view -> GET /corex, the actual rendered page). A flash set here
            // ages out before the popup partial ever renders — confirmed live
            // 2026-08-12. put() persists until the modal partial explicitly
            // forgets it right after rendering, so it still only shows once.
            session()->put('show_welcome_onboarding_popup', true);
            session()->put('welcome_onboarding_url', $pendingSetup->publicUrl());
        }
    }

    /**
     * Shared with AgencySetupProgressController::resend() — the manual resend
     * path intentionally does NOT go through handle()'s atomic claim (a
     * resend must work regardless of prior invite_email_sent_at state, and
     * itself decides when to stamp it), but both paths send the same mail
     * the same way and must fail the same way. Returns whether the send
     * succeeded, so callers that need to tell the user (e.g. resend()'s
     * success/error flash) can — a silent catch-and-log wasn't enough there.
     */
    public function sendMail(AgencyOnboardingSetup $setup, string $email): bool
    {
        try {
            Mail::mailer('corex')->to($email)->send(new AgencyOnboardingSetupMail($setup));
            return true;
        } catch (\Throwable $e) {
            // The setup record is already resumable from the tracking page,
            // so a mail hiccup must not block login or 500 the request. Log
            // and move on; the link can be re-sent from the owner tracking page.
            Log::error('Failed to send agency onboarding setup email.', [
                'setup_id' => $setup->id,
                'email'    => $email,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }
}
