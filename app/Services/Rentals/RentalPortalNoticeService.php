<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * .ai/specs/lease-notices.md §5.1/§8 - the tenant's and the owner's "Notices" tab: give notice, and see where the notices they gave
 * stand. A tenant gives notice only on a lease they are a tenant on; an owner only on a lease at one of their own properties
 * (RentalPortalScopeService decides which). Giving a notice sends nothing to anyone but the agents (LeaseNoticeService::give).
 * A person sees the notices their OWN side gave (with where each stands), and - once an agent has ACCEPTED it - a notice the OTHER side gave
 * (dates only: the reason is between that person and their agent). Breach notices and notices to vacate are in Documents.
 */
class RentalPortalNoticeService
{
    public const ROLE_TENANT = 'tenant';
    public const ROLE_LANDLORD = 'landlord';

    public function __construct(private readonly RentalPortalScopeService $scope, private readonly LeaseNoticeService $notices)
    {
    }

    /** The running leases this person may give notice on. */
    public function leasesFor(string $role, Contact $contact): Collection
    {
        $leases = $role === self::ROLE_TENANT ? $this->scope->tenantLeases($contact) : $this->scope->landlordLeases($contact);

        return $leases->filter(fn (Lease $l) => $l->status === Lease::STATUS_ACTIVE)->values();
    }

    /** @return array{leases:list<array<string,mixed>>,notices:list<array<string,mixed>>,from_other_party:list<array<string,mixed>>} */
    public function overview(string $role, Contact $contact): array
    {
        $allLeases = $role === self::ROLE_TENANT ? $this->scope->tenantLeases($contact) : $this->scope->landlordLeases($contact);
        $leaseIds = $allLeases->pluck('id')->all();
        $properties = \App\Models\Property::withoutGlobalScopes()->where('agency_id', $contact->agency_id)->whereIn('id', $allLeases->pluck('property_id')->filter()->all())->get()->keyBy('id');
        $address = fn (Lease $l) => $properties->get($l->property_id)?->buildDisplayAddress() ?: ('Lease #' . $l->id);
        $byId = $allLeases->keyBy('id');

        $leases = $this->leasesFor($role, $contact)->map(fn (Lease $l) => [
            'id' => $l->id, 'address' => $address($l), 'end_date' => $l->end_date?->toDateString(), 'is_month_to_month' => (bool) $l->is_month_to_month,
            'has_notice' => $l->hasActiveNotice(),
            'waiting' => LeaseNotice::withoutGlobalScopes()->where('lease_id', $l->id)->where('given_by', $role)->where('status', LeaseNotice::STATUS_RECEIVED)->whereNull('deleted_at')->exists(),
        ])->values()->all();

        $notices = LeaseNotice::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')->where('given_by', $role)->whereIn('lease_id', $leaseIds)
            ->orderByDesc('id')->get()
            ->map(fn (LeaseNotice $n) => $this->shape($n, $byId->get($n->lease_id) ? $address($byId->get($n->lease_id)) : ('Lease #' . $n->lease_id)))->values()->all();

        $other = $role === self::ROLE_TENANT ? self::ROLE_LANDLORD : self::ROLE_TENANT;
        $fromOther = LeaseNotice::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')->where('given_by', $other)->where('status', LeaseNotice::STATUS_ACCEPTED)->whereIn('lease_id', $leaseIds)
            ->orderByDesc('id')->get()
            ->map(fn (LeaseNotice $n) => [
                'id' => $n->id, 'lease_id' => $n->lease_id, 'address' => $byId->get($n->lease_id) ? $address($byId->get($n->lease_id)) : ('Lease #' . $n->lease_id),
                'given_by_label' => $other === self::ROLE_LANDLORD ? 'Your landlord' : 'Your tenant',
                'notice_date' => $n->notice_date->toDateString(), 'end_date' => $n->effectiveEndDate()->toDateString(),
                'status_line' => ($other === self::ROLE_LANDLORD ? 'Your landlord' : 'Your tenant') . ' gave notice on ' . $n->notice_date->format('j F Y') . '. The lease ends on ' . $n->effectiveEndDate()->format('j F Y') . '.',
            ])->values()->all();

        return ['leases' => $leases, 'notices' => $notices, 'from_other_party' => $fromOther];
    }

    /**
     * Give notice from the portal.
     *
     * @param array{lease_id:int,notice_date:string,intended_end_date:string,reason?:?string} $data
     * @throws \Illuminate\Validation\ValidationException
     */
    public function give(string $role, Contact $contact, array $data, ?UploadedFile $file): LeaseNotice
    {
        $lease = $this->leasesFor($role, $contact)->firstWhere('id', (int) $data['lease_id']);
        if (! $lease) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lease_id' => 'Choose the lease you are giving notice on.']);
        }

        return $this->notices->give($lease, $role, $data, LeaseNotice::VIA_PORTAL, $contact, null, $file);
    }

    /** What the portal person reads: where it stands, in plain words. The agent's internal note on an accepted-as-given notice is never shown. */
    private function shape(LeaseNotice $n, string $address): array
    {
        [$state, $line] = match ($n->status) {
            LeaseNotice::STATUS_RECEIVED => ['with_agent', 'With your agent for review. Nothing has been sent to anyone else.'],
            LeaseNotice::STATUS_ACCEPTED => ['accepted', 'Accepted - your lease ends on ' . $n->effectiveEndDate()->format('j F Y') . '.'
                . ($n->decision_kind === LeaseNotice::DECISION_CORRECTED ? ' Your agent changed the date you gave (' . $n->intended_end_date->format('j F Y') . ')' . ($n->decision_note ? ': ' . $n->decision_note : '.') : '')],
            LeaseNotice::STATUS_REJECTED => ['rejected', 'Not accepted' . ($n->decision_note ? ': ' . $n->decision_note : '.') . ' Please contact your agent, or give notice again.'],
            default => ['withdrawn', 'Withdrawn by your agent. Please contact them if this is not what you expected.'],
        };

        return [
            'id' => $n->id, 'lease_id' => $n->lease_id, 'address' => $address,
            'notice_date' => $n->notice_date->toDateString(), 'intended_end_date' => $n->intended_end_date->toDateString(),
            'accepted_end_date' => $n->accepted_end_date?->toDateString(), 'reason' => $n->reason, 'has_attachment' => $n->hasAttachment(),
            'state' => $state, 'status_label' => $n->statusLabel(), 'status_line' => $line, 'via' => $n->via,
        ];
    }
}
