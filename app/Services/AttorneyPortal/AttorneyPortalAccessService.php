<?php

namespace App\Services\AttorneyPortal;

use App\Mail\AttorneyPortalInviteMail;
use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSession;
use App\Models\AttorneyPortalSetting;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Otp\OtpService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * .ai/specs/attorney-portal.md §4 + §10 - invite / resend / revoke an attorney's portal access, and the link + emailed
 * one-time-code sign-in. One access per supplier contact. Everything is keyed by an EXPLICIT agency: the portal has no
 * signed-in staff user, so the global AgencyScope is bypassed on purpose and replaced by these filters.
 */
class AttorneyPortalAccessService
{
    public function __construct(private OtpService $otp) {}

    // ── the agent's side ────────────────────────────────────────────────────

    /**
     * Create the access for a supplier contact, or rotate its link (a resend). Mails the link.
     *
     * @return array{access:AttorneyPortalAccess, url:string, resent:bool}
     * @throws DomainException with a plain sentence the agent can read
     */
    public function invite(AgencyServiceProviderContact $contact, User $actor): array
    {
        $agencyId = (int) $contact->agency_id;
        if ($agencyId !== (int) $actor->effectiveAgencyId()) {
            throw new DomainException('That contact belongs to another agency.');
        }
        $settings = AttorneyPortalSetting::forAgency($agencyId);
        if (! $settings->isEnabled()) {
            throw new DomainException('The attorney portal is switched off for your agency.');
        }
        $email = $this->cleanEmail($contact->email);
        if (! $email) {
            throw new DomainException('This contact has no email address. Add one first.');
        }
        if (! $contact->is_active) {
            throw new DomainException('This contact is not active.');
        }

        $token  = Str::random(48);
        $access = AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('service_provider_contact_id', $contact->id)->first();
        $resent = $access !== null;

        $fields = [
            'service_provider_id' => $contact->service_provider_id,
            'email'               => $email,
            'token_hash'          => AttorneyPortalAccess::hashToken($token),
            'invited_by_id'       => $actor->id,
            'invited_at'          => now(),
            'expires_at'          => now()->addDays($settings->linkValidDays()),
            'revoked_at'          => null,
            'revoked_by_id'       => null,
        ];
        if ($access) {
            $access->forceFill($fields)->save();
            $this->endSessions($access);
        } else {
            $access = new AttorneyPortalAccess(['agency_id' => $agencyId, 'service_provider_contact_id' => $contact->id] + $fields);
            $access->save();
        }
        $access->load(['supplierContact', 'firm']);

        $url = route('attorney.link', ['token' => $token]);
        AttorneyPortalLogger::record($access, $resent ? 'resent' : 'invited', ['meta' => ['by_user_id' => $actor->id]]);

        Mail::to($email)->send(new AttorneyPortalInviteMail(
            recipientName: $access->displayName(),
            agency: Agency::withoutGlobalScopes()->find($agencyId),
            url: $url,
            validDays: $settings->linkValidDays(),
        ));

        return ['access' => $access, 'url' => $url, 'resent' => $resent];
    }

    public function revoke(AttorneyPortalAccess $access, User $actor): void
    {
        if ((int) $access->agency_id !== (int) $actor->effectiveAgencyId()) {
            throw new DomainException('That access belongs to another agency.');
        }
        if ($access->revoked_at === null) {
            $access->forceFill(['revoked_at' => now(), 'revoked_by_id' => $actor->id])->save();
            AttorneyPortalLogger::record($access, 'revoked', ['meta' => ['by_user_id' => $actor->id]]);
        }
        $this->endSessions($access);
    }

    /**
     * The "Portal access" panel on a deal: one row per attorney the deal names (transferring, bond), with what the agent
     * needs to act on - who, whether they can be sent a link, and the state of any link already sent.
     *
     * @return list<array<string,mixed>>
     */
    public function panelFor(\App\Models\Deal $deal): array
    {
        $agencyId = (int) $deal->agency_id;
        $slots = [
            ['transfer_attorney', 'Transferring attorney', $deal->attorney_provider_id, $deal->attorney_contact_id],
            ['bond_attorney', 'Bond attorney', $deal->bond_attorney_provider_id, $deal->bond_attorney_contact_id],
        ];

        $rows = [];
        foreach ($slots as [$role, $label, $firmId, $contactId]) {
            if (! $firmId) {
                continue;
            }
            $firm    = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->find($firmId);
            $contact = $contactId ? AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $agencyId)->find($contactId) : null;
            $access  = $contact
                ? AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('service_provider_contact_id', $contact->id)->first()
                : null;

            $lastDownload = $access
                ? \App\Models\AttorneyPortalLog::where('access_id', $access->id)->where('deal_id', $deal->id)
                    ->whereIn('event', ['document_downloaded', 'document_viewed'])->max('created_at')
                : null;

            $rows[] = [
                'role'          => $role,
                'label'         => $label,
                'firm'          => $firm?->name,
                'person'        => $contact ? (trim((string) ($contact->attorney_name ?: $contact->contact_person)) ?: null) : null,
                'contact'       => $contact,
                'has_email'     => $contact && $this->cleanEmail($contact->email) !== null,
                'access'        => $access,
                'status'        => $access?->status(),
                'last_login'    => $access?->last_login_at,
                'last_download' => $lastDownload ? Carbon::parse($lastDownload) : null,
            ];
        }

        return $rows;
    }

    // ── the attorney's side ─────────────────────────────────────────────────

    /** The access behind an emailed link, or null when it is unknown, expired, revoked or the agency switch is off. */
    public function accessForLink(string $token): ?AttorneyPortalAccess
    {
        if (strlen($token) < 20) {
            return null;
        }
        $access = AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)
            ->where('token_hash', AttorneyPortalAccess::hashToken($token))->first();

        return $this->usable($access) ? $access : null;
    }

    /** Email a one-time code to the supplier contact's address on file. Returns null when sent, or 'cooldown'/'hourly'. */
    public function sendCode(AttorneyPortalAccess $access, Request $request): ?string
    {
        $blocked = $this->otp->throttle('attorney_portal', $access->email, [
            'cooldown_secs' => config('attorney-portal.otp.cooldown_secs'),
            'hourly_limit'  => config('attorney-portal.otp.hourly_limit'),
        ]);
        if ($blocked) {
            AttorneyPortalLogger::record($access, 'code_failed', ['meta' => ['reason' => 'throttled_' . $blocked]], $request);

            return $blocked;
        }

        $agency = Agency::withoutGlobalScopes()->find($access->agency_id);
        $this->otp->issue($this->purpose($access), $access->email, [
            'ip'              => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'expires_minutes' => config('attorney-portal.otp.expires_minutes'),
            'mail'            => fn (string $code) => new OtpMail($code, (int) config('attorney-portal.otp.expires_minutes'), 'Your code to open your deals', null, $agency),
        ]);
        AttorneyPortalLogger::record($access, 'code_sent', [], $request);

        return null;
    }

    /** Check the code; on success start a session and return its raw cookie token. */
    public function verifyCode(AttorneyPortalAccess $access, string $code, Request $request): ?string
    {
        $otp = $this->otp->verify($this->purpose($access), $access->email, trim($code));
        if (! $otp) {
            AttorneyPortalLogger::record($access, 'code_failed', ['meta' => ['reason' => 'wrong_or_expired']], $request);

            return null;
        }

        $raw = Str::random(64);
        AttorneyPortalSession::create([
            'access_id'  => $access->id,
            'token_hash' => AttorneyPortalAccess::hashToken($raw),
            'expires_at' => $this->sessionEnd($access),
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
        $access->forceFill([
            'last_login_at' => now(), 'last_login_ip' => $request->ip(), 'login_count' => $access->login_count + 1,
        ])->save();
        AttorneyPortalLogger::record($access, 'signed_in', [], $request);

        return $raw;
    }

    /** The live session behind a cookie token, with its access still usable - or null. */
    public function sessionFor(?string $rawToken): ?AttorneyPortalSession
    {
        if (! $rawToken) {
            return null;
        }
        $session = AttorneyPortalSession::where('token_hash', AttorneyPortalAccess::hashToken($rawToken))->first();
        if (! $session || ! $session->isLive()) {
            return null;
        }
        $access = AttorneyPortalAccess::withoutGlobalScope(AgencyScope::class)->find($session->access_id);
        if (! $this->usable($access)) {
            return null;
        }
        $session->setRelation('access', $access);

        return $session;
    }

    public function signOut(AttorneyPortalSession $session, Request $request): void
    {
        $session->forceFill(['revoked_at' => now()])->save();
        AttorneyPortalLogger::record($session->access, 'signed_out', [], $request);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function usable(?AttorneyPortalAccess $access): bool
    {
        if (! $access || ! $access->isLive()) {
            return false;
        }
        if (! AttorneyPortalSetting::forAgency((int) $access->agency_id)->isEnabled()) {
            return false;
        }
        $contact = AgencyServiceProviderContact::withoutGlobalScopes()->where('agency_id', $access->agency_id)->find($access->service_provider_contact_id);

        return $contact !== null && $contact->is_active && ! $contact->trashed();
    }

    /** A session never outlives the link it came from. */
    private function sessionEnd(AttorneyPortalAccess $access): Carbon
    {
        $end = now()->addHours((int) config('attorney-portal.session_hours', 12));

        return $access->expires_at && $access->expires_at->lt($end) ? $access->expires_at->copy() : $end;
    }

    private function endSessions(AttorneyPortalAccess $access): void
    {
        AttorneyPortalSession::where('access_id', $access->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    private function purpose(AttorneyPortalAccess $access): string
    {
        return 'attorney_portal:' . $access->id;
    }

    private function cleanEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
