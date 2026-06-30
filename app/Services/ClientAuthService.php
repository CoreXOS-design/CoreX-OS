<?php

namespace App\Services;

use App\Mail\ClientAuthOtpMail;
use App\Models\Agency;
use App\Models\ClientAccessLog;
use App\Models\ClientSigninAttempt;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Otp;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\ContactScope;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Core service for the mobile Client Portal sign-in flow.
 *
 * Spec: .ai/specs/client-auth.md
 *
 * IMPORTANT — Multi-tenancy:
 * This is the ONLY sanctioned site in request-path code that bypasses
 * AgencyScope + ContactScope. The bypass is constrained to identifier
 * lookup and fake-email collision checks. Once an agency is chosen,
 * every downstream read is scoped normally.
 */
class ClientAuthService
{
    /**
     * Look up which agencies a given email/phone identifier appears on.
     * SANCTIONED cross-agency read.
     *
     * @return array{agencies: array<int, array{id:int,name:string}>, contacts: \Illuminate\Support\Collection}
     */
    public function findContactsByIdentifierAcrossAgencies(string $identifier): array
    {
        $needle = strtolower(trim($identifier));

        // Cross-agency, cross-scope lookup. The single sanctioned bypass.
        $contacts = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->whereRaw('LOWER(email) = ?', [$needle])
            ->orWhereHas('clientUser', function ($q) use ($needle) {
                $q->whereRaw('LOWER(email) = ?', [$needle]);
            })
            ->get();

        $agencyIds = $contacts->pluck('agency_id')->filter()->unique()->values();

        $agencies = $agencyIds->isEmpty()
            ? collect()
            : Agency::query()
                ->withoutGlobalScope(AgencyScope::class)
                ->whereIn('id', $agencyIds)
                ->get(['id', 'name', 'slug']);

        return [
            'agencies' => $agencies->map(fn ($a) => [
                'id'   => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
            ])->values()->all(),
            'contacts' => $contacts,
        ];
    }

    /**
     * Find or create the ClientUser for an email. Links any matching
     * Contact rows whose client_user_id is null.
     */
    public function findOrCreateClientUser(string $email): ClientUser
    {
        $email = strtolower(trim($email));

        $clientUser = ClientUser::where('email', $email)->first();

        // Pre-fetch matching contacts so we can stamp the origin agency on
        // first creation (earliest contact's agency = origin).
        $matches = $this->findContactsByIdentifierAcrossAgencies($email);

        if (!$clientUser) {
            $originAgencyId = $matches['contacts']
                ->sortBy('id')
                ->pluck('agency_id')
                ->filter()
                ->first();

            $clientUser = ClientUser::create([
                'email' => $email,
                'created_by_agency_id' => $originAgencyId,
            ]);
        } elseif (empty($clientUser->created_by_agency_id)) {
            // Backfill origin if missing (legacy rows).
            $originAgencyId = $matches['contacts']
                ->sortBy('id')
                ->pluck('agency_id')
                ->filter()
                ->first();
            if ($originAgencyId) {
                $clientUser->forceFill(['created_by_agency_id' => $originAgencyId])->save();
            }
        }

        // Lazy-link any matching contacts (across all agencies).
        foreach ($matches['contacts'] as $contact) {
            if (!$contact->client_user_id) {
                $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();
            }
        }

        return $clientUser;
    }

    /**
     * Generate a unique fake login email for a contact under @corexclient.co.za.
     * SANCTIONED cross-agency uniqueness check.
     */
    public function generateFakeLoginEmail(Contact $contact): string
    {
        $domain = config('clientauth.fake_email_domain', 'corexclient.co.za');

        $base = $this->slugifyForEmail(
            $contact->first_name
                ?: ($contact->full_name ?? null)
                ?: 'client'
        );
        if ($base === '') {
            $base = 'client';
        }

        $candidate = "{$base}@{$domain}";
        if (!$this->isClientEmailTaken($candidate)) {
            return $candidate;
        }

        for ($i = 1; $i <= 9999; $i++) {
            $candidate = "{$base}{$i}@{$domain}";
            if (!$this->isClientEmailTaken($candidate)) {
                return $candidate;
            }
        }

        return $base . Str::lower(Str::random(4)) . '@' . $domain;
    }

    public function isClientEmailTaken(string $email): bool
    {
        $needle = strtolower(trim($email));

        if (ClientUser::where('email', $needle)->exists()) {
            return true;
        }

        // Also collide if a real Contact email already uses it (in any agency).
        return Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->whereRaw('LOWER(email) = ?', [$needle])
            ->exists();
    }

    private function slugifyForEmail(string $value): string
    {
        $ascii = Str::ascii($value);
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $ascii));
    }

    /**
     * Issue an OTP to the given email. Always sends if rate-limit allows,
     * regardless of whether the email is matched (to avoid enumeration).
     *
     * AT-130: delegates to the canonical {@see OtpService} — this consumer
     * declares its destination (the client email), subject (the ClientUser),
     * Mailable (ClientAuthOtpMail, for byte-identical existing emails), the
     * fake-@corexclient.co.za delivery skip, and its audit sink
     * (ClientAccessLog 'otp_sent', logged only when a ClientUser exists, as
     * before). Behaviour is identical to the pre-AT-130 inline implementation.
     */
    public function issueOtp(string $email, string $purpose, Request $request): Otp
    {
        $email      = strtolower(trim($email));
        $clientUser = ClientUser::where('email', $email)->first();
        $fakeDomain = config('clientauth.fake_email_domain', 'corexclient.co.za');
        $expiresMin = (int) config('clientauth.otp.expires_minutes', 10);

        return app(OtpService::class)->issue($purpose, $email, [
            'subject'         => $clientUser,
            'channel'         => 'email',
            'ip'              => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'expires_minutes' => $expiresMin,
            // Fake @corexclient.co.za logins are not deliverable mailboxes.
            'deliver'         => !str_ends_with($email, '@' . $fakeDomain),
            'mail'            => fn (string $code) => new ClientAuthOtpMail($code, $expiresMin),
            // Preserve the legacy columns exactly (client_user_id + email) so
            // the ClientUser->otps() relation and existing rows are unchanged.
            'attributes'      => ['client_user_id' => $clientUser?->id, 'email' => $email],
            // Consumer-provided audit sink: still ClientAccessLog, still only
            // when a ClientUser exists. Verify-side auditing stays at the
            // controller ('otp_verified', richer context) — untouched here.
            'audit'           => function (string $event, ?Otp $otp, array $context) use ($clientUser, $request, $purpose) {
                if ($event === 'otp_issued' && $clientUser) {
                    $this->log($clientUser, null, null, 'otp_sent', $request, ['purpose' => $purpose]);
                }
            },
        ]);
    }

    /**
     * Verify a submitted OTP code for an email.
     *
     * AT-130: delegates to the canonical {@see OtpService} (verify-by
     * destination, where destination = the client email). Identical semantics
     * to the previous inline implementation: latest unused/unexpired/matching
     * purpose, single-use, attempts increment on miss.
     */
    public function verifyOtp(string $email, string $code, string $purpose, Request $request): ?Otp
    {
        $email = strtolower(trim($email));

        return app(OtpService::class)->verify($purpose, $email, $code);
    }

    /**
     * Persist an event in client_access_logs.
     */
    public function log(
        ?ClientUser $clientUser,
        ?int $agencyId,
        ?int $contactId,
        string $event,
        Request $request,
        array $meta = [],
        ?string $deviceName = null
    ): ClientAccessLog {
        return ClientAccessLog::create([
            'client_user_id' => $clientUser?->id,
            'agency_id'      => $agencyId,
            'contact_id'     => $contactId,
            'event'          => $event,
            'meta'           => $meta ?: null,
            'ip'             => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
            'device_name'    => $deviceName,
        ]);
    }

    public function recordAttempt(string $identifier, bool $matched, int $agencyCount, Request $request): void
    {
        ClientSigninAttempt::create([
            'identifier'   => strtolower(trim($identifier)),
            'matched'      => $matched,
            'agency_count' => $agencyCount,
            'ip'           => $request->ip(),
            'user_agent'   => substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    /**
     * Resolve which agencies a ClientUser may operate in.
     *
     * @return array<int, array{id:int,name:string,slug:?string,is_preferred:bool,is_locked:bool}>
     */
    public function agenciesFor(ClientUser $clientUser): array
    {
        $contacts = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('client_user_id', $clientUser->id)
            ->get(['id', 'agency_id']);

        $agencyIds = $contacts->pluck('agency_id')->filter()->unique();
        if ($agencyIds->isEmpty()) {
            return [];
        }

        $agencies = Agency::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $agencyIds)
            ->get(['id', 'name', 'slug']);

        return $agencies->map(fn ($a) => [
            'id'           => $a->id,
            'name'         => $a->name,
            'slug'         => $a->slug,
            'is_preferred' => $clientUser->preferred_agency_id === $a->id,
            'is_locked'    => $clientUser->locked_to_agency_id === $a->id,
        ])->values()->all();
    }

    /**
     * Resolve the contact row this client should operate as in the given agency.
     */
    public function contactForAgency(ClientUser $clientUser, int $agencyId): ?Contact
    {
        return Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('client_user_id', $clientUser->id)
            ->where('agency_id', $agencyId)
            ->first();
    }

    public function issueSanctumToken(ClientUser $clientUser, string $deviceName, ?Carbon $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays((int) config('clientauth.token.expires_in_days', 30));

        $token = $clientUser->createToken(
            $deviceName ?: config('clientauth.token.name_default', 'CoreX Client App'),
            [config('clientauth.token.ability', 'client')],
            $expiresAt
        );

        return $token->plainTextToken;
    }

    public function issueActivationToken(ClientUser $clientUser): string
    {
        $token = $clientUser->createToken(
            'client-activation',
            ['client-activation'],
            now()->addMinutes((int) config('clientauth.activation_token_minutes', 15))
        );

        return $token->plainTextToken;
    }
}
