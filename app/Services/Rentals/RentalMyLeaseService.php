<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\Lease;

/**
 * AT-508 (P4) - the "My Lease" card for the mobile app: lease FACTS only (Johan, 25 Oct 2026 - no rent, no payments).
 * Built on RentalPortalOverviewService (the same record the web portal Home reads) so the app and the portal can never disagree.
 *
 *   tenant : sees the managing agent's name, phone and email (never a WhatsApp number) and NOTHING about the lessor - no name, contact or
 *            detail, and not even who gave a notice. The payload carries no owner field at all.
 *   owner  : as the portal today - also sees the tenant's name.
 * The notice period is the lease's OWN confirmed term (null when the lease does not state one or an agent has not confirmed it).
 */
class RentalMyLeaseService
{
    public function __construct(
        private readonly RentalPortalOverviewService $overview,
        private readonly LeaseNoticeTermsService $terms,
    ) {}

    /** @return array{leases: list<array<string,mixed>>} */
    public function forTenant(Contact $contact): array
    {
        $cards = [];
        foreach ($this->overview->forTenant($contact)['homes'] as $home) {
            $cards[] = $this->card($home, RentalPortalOverviewService::AUDIENCE_TENANT);
        }

        return ['leases' => $cards];
    }

    /** @return array{properties: list<array<string,mixed>>} one block per property the owner has; `lease` is null while the property has no lease */
    public function forLandlord(Contact $contact): array
    {
        $cards = [];
        foreach ($this->overview->forLandlord($contact)['homes'] as $home) {
            $cards[] = $this->card($home, RentalPortalOverviewService::AUDIENCE_LANDLORD)
                + ['tenant_names' => $home['tenant_names'] ?? null];
        }

        return ['properties' => $cards];
    }

    /** @param array<string,mixed> $home one block of RentalPortalOverviewService */
    private function card(array $home, string $audience): array
    {
        $tenancy = $home['tenancy'] ?? null;
        $lease = ! empty($home['lease_id']) ? Lease::withoutGlobalScopes()->find($home['lease_id']) : null;
        $agent = $home['contact']['agent'] ?? null;
        $office = $home['contact']['office'] ?? [];
        $next = $home['inspections']['upcoming'][0] ?? null;

        $tenant = $audience === RentalPortalOverviewService::AUDIENCE_TENANT;
        $notice = $tenancy['notice'] ?? null;
        $headline = $tenancy['headline'] ?? null;
        if ($tenant && $notice && ($notice['given_by'] ?? null) === Lease::NOTICE_BY_LANDLORD) {
            $headline = 'Notice has been given';   // the tenant is told a notice exists, never who gave it
        }

        return [
            'property' => $home['property'],
            'lease' => $tenancy ? [
                'id' => $home['lease_id'],
                'start_date' => $tenancy['start_date'],
                'end_date' => $tenancy['end_date'],
                'is_month_to_month' => $tenancy['is_month_to_month'],
                'days_left' => $tenancy['days_left'],
                'time_left' => $this->timeLeft($tenancy['days_left']),
            ] : null,
            'status' => $tenancy ? [
                'state' => $tenancy['state'],
                'headline' => $headline,
                'detail' => $tenancy['detail'],
                'in_renewal_window' => $tenancy['in_renewal_window'],
            ] : null,
            'notice_period' => $lease ? $this->noticePeriod($lease) : null,
            'notice' => $notice ? [
                'date' => $notice['date'],
                'move_out_date' => $notice['move_out_date'],
                'given_by' => $this->givenBy($notice['given_by'] ?? null, $audience),
            ] : null,
            'renewal' => $tenancy['renewal'] ?? null,
            'agent' => $agent ? ['name' => $agent['name'], 'phone' => $agent['phone'], 'email' => $agent['email']] : null,
            // only used when no agent qualifies: the branch / agency switchboard, so there is always somebody to call
            'office' => $agent ? null : ['phone' => $office['phone'] ?? null, 'email' => $office['email'] ?? null],
            'next_inspection' => $next ? ['id' => $next['id'], 'type' => $next['type'], 'type_label' => $next['type_label'], 'date' => $next['date'], 'time' => $next['time']] : null,
        ];
    }

    /** @return array{value:int,unit:string}|null */
    private function noticePeriod(Lease $lease): ?array
    {
        if (! $this->terms->isConfirmed($lease)) {
            return null;
        }
        $stored = $this->terms->forLease($lease);
        $value = $stored['notice_period'] ?? null;

        return $value ? ['value' => (int) $value, 'unit' => (string) ($stored['notice_period_unit'] ?: 'days')] : null;
    }

    private function givenBy(?string $by, string $audience): ?string
    {
        if ($by === null) {
            return null;
        }
        if ($audience === RentalPortalOverviewService::AUDIENCE_TENANT) {
            return $by === Lease::NOTICE_BY_TENANT ? 'you' : 'other_party';   // never "landlord"
        }

        return $by === Lease::NOTICE_BY_LANDLORD ? 'you' : 'tenant';
    }

    /** @return array{days:?int,text:?string} */
    private function timeLeft(?int $days): array
    {
        if ($days === null) {
            return ['days' => null, 'text' => null];
        }
        $text = match (true) {
            $days < 0 => 'Ended',
            $days === 0 => 'Ends today',
            $days === 1 => '1 day left',
            $days < 45 => "{$days} days left",
            default => (int) round($days / 30.4) . ' months left',
        };

        return ['days' => $days, 'text' => $text];
    }
}
