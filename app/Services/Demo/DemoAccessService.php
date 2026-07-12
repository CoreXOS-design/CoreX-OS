<?php

namespace App\Services\Demo;

use App\Events\Demo\DemoAccessExpired;
use App\Events\Demo\DemoAccessFirstLogin;
use App\Events\Demo\DemoAccessGranted;
use App\Events\Demo\DemoAccessRevoked;
use App\Events\Demo\DemoTncAccepted;
use App\Models\DemoAccessGrant;
use App\Models\DemoPageView;
use App\Models\DemoSession;
use App\Models\DemoTncAcceptance;
use App\Models\DemoTncVersion;
use App\Models\DevSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The grant lifecycle. Runs on PRIMARY only.
 *
 * Spec: .ai/specs/demo-access-control.md §6
 *
 * One home for issue / verify / accept / revoke / archive, so the API
 * (DemoAccessApiController) and the admin UI (DemoAccessController) cannot drift
 * apart on the rules. Every status decision routes through
 * DemoAccessGrant::status() — nothing here re-implements it.
 */
class DemoAccessService
{
    /** Default trial length. Declared at the call site, per the DevSetting pattern. */
    public const DEFAULT_EXPIRY_HOURS = 72;

    public static function defaultExpiryHours(): int
    {
        $hours = (int) DevSetting::get('demo_default_expiry_hours', self::DEFAULT_EXPIRY_HOURS);

        return $hours > 0 ? $hours : self::DEFAULT_EXPIRY_HOURS;
    }

    /**
     * Issue a grant. Returns [$grant, $plaintextCode].
     *
     * The plaintext code exists exactly here, in the emailed copy, and in the
     * one-time confirmation screen. It is NEVER persisted and NEVER logged.
     *
     * expires_at is deliberately left NULL — the clock starts at first login,
     * not at issue. A prospect who opens the mail four days later still gets
     * their full trial.
     */
    public function issue(array $data, int $issuedByUserId): array
    {
        $code = DemoAccessGrant::mintCode();

        $grant = DemoAccessGrant::create([
            'company_name'      => trim($data['company_name']),
            'contact_email'     => strtolower(trim($data['contact_email'])),
            'contact_name'      => isset($data['contact_name']) ? trim((string) $data['contact_name']) ?: null : null,
            'contact_id'        => $data['contact_id'] ?? null,
            'credential_hash'   => DemoAccessGrant::hashCode($code),
            // COPIED, not referenced. Changing the default setting later must not
            // retroactively shorten a trial we already sold.
            'expiry_hours'      => (int) ($data['expiry_hours'] ?? self::defaultExpiryHours()),
            'issued_by_user_id' => $issuedByUserId,
            'notes'             => isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
        ]);

        // The listener queues the email — from PRIMARY's mailer. Never from demo,
        // whose mailer is Mailpit and would swallow it silently.
        //
        // The plaintext rides on the event because this is the only moment it
        // exists (the DB holds bcrypt(code) alone). The event redacts it from its
        // audit payload — see DemoAccessGranted::payloadSnapshot().
        DemoAccessGranted::dispatch($grant, $code);

        return [$grant, $code];
    }

    /**
     * Verify an emailed credential and open a session.
     *
     * @return array{ok:bool, status:string, message:?string, grant:?DemoAccessGrant, session:?DemoSession}
     */
    public function verify(string $email, string $code, ?string $ip, ?string $userAgent): array
    {
        $grant = DemoAccessGrant::where('contact_email', strtolower(trim($email)))
            ->orderByDesc('id')       // most recent grant for this address wins
            ->first();

        // Same response for "no such grant" and "wrong code" — a different message
        // for each would let anyone enumerate which companies are evaluating CoreX.
        if (! $grant || ! $grant->verifyCode($code)) {
            return $this->fail('invalid', 'That email and access code do not match. Check the code in your invitation email.');
        }

        if (! $grant->isUsable()) {
            if ($grant->status() === DemoAccessGrant::STATUS_EXPIRED) {
                DemoAccessExpired::dispatch($grant);
            }

            return $this->fail($grant->status(), $this->blockedMessage($grant), $grant);
        }

        // Start the clock — atomically. Only the winner announces first login.
        if ($grant->first_login_at === null && $grant->stampFirstLogin()) {
            DemoAccessFirstLogin::dispatch($grant);
        }

        $session = DemoSession::create([
            'demo_access_grant_id' => $grant->id,
            'session_token'        => (string) Str::uuid(),
            'started_at'           => Carbon::now(),
            'last_seen_at'         => Carbon::now(),
            'ip_address'           => $ip,
            'user_agent'           => $userAgent ? Str::limit($userAgent, 250, '') : null,
        ]);

        return [
            'ok'      => true,
            'status'  => $grant->fresh()->status(),
            'message' => null,
            'grant'   => $grant->fresh(),
            'session' => $session,
        ];
    }

    /**
     * Re-check an established session. Called by the demo gate on every request
     * (cached 60s on the demo side) — this round trip is what makes revoke bite.
     */
    public function checkSession(string $token): array
    {
        $session = DemoSession::with('grant')->where('session_token', $token)->first();

        if (! $session || ! $session->grant) {
            return $this->fail('invalid', 'Your demo session is no longer valid. Please sign in again.');
        }

        $grant = $session->grant;

        if (! $grant->isUsable()) {
            if ($grant->status() === DemoAccessGrant::STATUS_EXPIRED) {
                DemoAccessExpired::dispatch($grant);
            }

            return $this->fail($grant->status(), $this->blockedMessage($grant), $grant, $session);
        }

        $session->touchSeen();

        return [
            'ok'      => true,
            'status'  => $grant->status(),
            'message' => null,
            'grant'   => $grant,
            'session' => $session,
        ];
    }

    /**
     * Record clickwrap acceptance of the CURRENT T&C version.
     *
     * firstOrCreate against the (grant, version) UNIQUE index — a double-click or
     * a retried request produces ONE acceptance row, not two.
     */
    public function acceptTnc(DemoAccessGrant $grant, ?string $ip, ?string $userAgent): ?DemoTncAcceptance
    {
        $version = DemoTncVersion::current();

        if (! $version) {
            return null;   // caller fails closed
        }

        $acceptance = DemoTncAcceptance::firstOrCreate(
            [
                'demo_access_grant_id' => $grant->id,
                'demo_tnc_version_id'  => $version->id,
            ],
            [
                'accepted_at' => Carbon::now(),
                'ip_address'  => $ip,
                'user_agent'  => $userAgent ? Str::limit($userAgent, 250, '') : null,
            ]
        );

        if ($acceptance->wasRecentlyCreated) {
            DemoTncAccepted::dispatch($grant, $version);
        }

        return $acceptance;
    }

    /**
     * Log a page view against a session.
     *
     * FAILS OPEN by contract: an unknown token is silently dropped, never an
     * error. A demo page must not break because telemetry has drifted.
     */
    public function recordPageView(string $sessionToken, string $path, ?string $routeName, ?string $title): ?DemoPageView
    {
        $session = DemoSession::where('session_token', $sessionToken)->first();

        if (! $session) {
            return null;
        }

        $session->touchSeen();

        return DemoPageView::create([
            'demo_session_id' => $session->id,
            'path'            => Str::limit($path, 250, ''),
            'route_name'      => $routeName ? Str::limit($routeName, 250, '') : null,
            'title'           => $title ? Str::limit($title, 250, '') : null,
            'viewed_at'       => Carbon::now(),
        ]);
    }

    /** Revoke. Bites within the gate cache TTL (≤60s) — not instantly. */
    public function revoke(DemoAccessGrant $grant, int $byUserId): DemoAccessGrant
    {
        if ($grant->revoked_at === null) {
            $grant->forceFill([
                'revoked_at'         => Carbon::now(),
                'revoked_by_user_id' => $byUserId,
            ])->save();

            DemoAccessRevoked::dispatch($grant);
        }

        return $grant;
    }

    /**
     * "Delete" = archive. The row STAYS (non-negotiable #1).
     * SELECT COUNT(*) on demo_access_grants must never decrease — grants are
     * legal evidence of who accepted what, and when.
     */
    public function archive(DemoAccessGrant $grant): DemoAccessGrant
    {
        if ($grant->archived_at === null) {
            $grant->forceFill(['archived_at' => Carbon::now()])->save();
        }

        return $grant;
    }

    public function restore(DemoAccessGrant $grant): DemoAccessGrant
    {
        $grant->forceFill(['archived_at' => null])->save();

        return $grant;
    }

    // ---- Internals ---------------------------------------------------------

    /** Plain-English, no jargon, tells the prospect what to do next. */
    private function blockedMessage(DemoAccessGrant $grant): string
    {
        return match ($grant->status()) {
            DemoAccessGrant::STATUS_EXPIRED => $grant->expires_at
                ? 'Your demo access expired on ' . $grant->expires_at->format('j F Y') . '. Contact us for a new invitation.'
                : 'Your demo access has expired. Contact us for a new invitation.',
            DemoAccessGrant::STATUS_REVOKED  => 'Your demo access has been withdrawn. Please contact us.',
            DemoAccessGrant::STATUS_ARCHIVED => 'That email and access code do not match. Check the code in your invitation email.',
            default                          => 'Your demo access is not currently active. Please contact us.',
        };
    }

    private function fail(string $status, string $message, ?DemoAccessGrant $grant = null, ?DemoSession $session = null): array
    {
        return [
            'ok'      => false,
            'status'  => $status,
            'message' => $message,
            'grant'   => $grant,
            'session' => $session,
        ];
    }
}
