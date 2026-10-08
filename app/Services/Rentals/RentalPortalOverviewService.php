<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * .ai/specs/rental-portal-access.md §20 — the portal HOME for a tenant and an owner: who to call, when the inspections
 * are, and where the lease stands. One block per property (tenant: each property they rent; owner: each property they own).
 *
 * Read-only, built from records the agency already keeps; nothing is stored. Every lookup goes through
 * RentalPortalScopeService (the caller's OWN leases / properties / inspections), and the few direct reads here (the agent,
 * the branch, the agency, the property) pin agency_id and deleted_at explicitly — a portal request has no staff user, so
 * global scopes are stripped, exactly like the scope service.
 *
 * Nothing here is agency-specific: the office details are whatever the lease's own branch / agency carry, the renewal window
 * and the notice period are the agency's own lease settings, and the wording is neutral.
 */
class RentalPortalOverviewService
{
    public const AUDIENCE_TENANT = 'tenant';
    public const AUDIENCE_LANDLORD = 'landlord';

    // Where the tenancy stands, in the order they are decided (the first that fits wins).
    public const STATE_NOTICE_GIVEN = 'notice_given';
    public const STATE_RENEWED = 'renewed';
    public const STATE_MONTH_TO_MONTH = 'month_to_month';
    public const STATE_ENDED = 'ended';
    public const STATE_RENEWAL_WINDOW = 'renewal_window';
    public const STATE_UPCOMING = 'upcoming';
    public const STATE_RUNNING = 'running';

    /** Past inspections shown on the home; the rest are counted, not listed. */
    public const PAST_INSPECTIONS_SHOWN = 10;

    public function __construct(private readonly RentalPortalScopeService $scope)
    {
    }

    // ── Entry points ─────────────────────────────────────────────────────────────────────────

    /** @return array{role:string,homes:array<int,array<string,mixed>>} */
    public function forTenant(Contact $contact): array
    {
        $leases = $this->scope->tenantLeases($contact);
        $inspections = $this->scope->tenantInspections($contact);
        $properties = $this->propertiesById((int) $contact->agency_id, $leases->pluck('property_id')->all());

        $homes = [];
        foreach ($leases->groupBy('property_id') as $propertyId => $propertyLeases) {
            $property = $properties->get((int) $propertyId);
            $lease = $this->currentLease($propertyLeases);
            if (! $property || ! $lease) {
                continue;
            }
            $homes[] = $this->home(self::AUDIENCE_TENANT, $contact, $property, $lease, $inspections->where('property_id', $property->id));
        }

        return ['role' => self::AUDIENCE_TENANT, 'homes' => $homes];
    }

    /** @return array{role:string,homes:array<int,array<string,mixed>>,decisions_waiting:int} */
    public function forLandlord(Contact $contact): array
    {
        $properties = $this->scope->landlordProperties($contact);
        $leases = $this->scope->landlordLeases($contact);
        $liveLeaseIds = $leases->pluck('id')->all();
        // An inspection belongs to a lease; when that lease is archived (invisible to the portal) its inspections go with it.
        $inspections = $this->scope->landlordInspections($contact)->filter(fn ($i) => in_array($i->lease_id, $liveLeaseIds, true));

        $homes = [];
        foreach ($properties as $property) {
            $lease = $this->currentLease($leases->where('property_id', $property->id));
            $homes[] = $this->home(self::AUDIENCE_LANDLORD, $contact, $property, $lease, $inspections->where('property_id', $property->id));
        }

        return [
            'role' => self::AUDIENCE_LANDLORD,
            'homes' => $homes,
            'decisions_waiting' => $this->scope->landlordPendingFaultReports($contact)->count()
                + $this->scope->landlordPendingWorkOrders($contact)->count()
                + $this->scope->landlordPendingVariations($contact)->count(),
        ];
    }

    // ── One property ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int,RentalInspection>  $inspections  already limited to what the portal may show (see RentalPortalScopeService)
     * @return array<string,mixed>
     */
    private function home(string $audience, Contact $contact, Property $property, ?Lease $lease, Collection $inspections): array
    {
        $agencyId = (int) $contact->agency_id;

        return [
            'property' => ['id' => $property->id, 'address' => $property->buildDisplayAddress()],
            'lease_id' => $lease?->id,
            'tenant_names' => $audience === self::AUDIENCE_LANDLORD && $lease && $lease->status === Lease::STATUS_ACTIVE ? $lease->tenantNames() : null,
            'tenancy' => $lease ? $this->tenancy($lease, $audience) : null,
            // §21 — the FAQ worked out from the lease's own notice / cancellation terms; empty when the lease has none.
            'faq' => $lease ? app(RentalPortalFaqService::class)->forLease($lease, $audience) : [],
            'contact' => $this->agentContact($lease, $property, $agencyId, $audience),
            'inspections' => $this->inspectionLists($inspections),
        ];
    }

    // ── 1. Who to call ───────────────────────────────────────────────────────────────────────

    /**
     * Who to call: the lease's OWN agent for this side — the tenant portal shows the TENANT'S agent, the owner portal the
     * OWNER'S agent (leases.md §17, Johan 8 Oct 2026) — else the property's agent, else the branch alone. A lease whose
     * agents were never filled in gets the same default rules' answer (LeaseAgentService::effectiveIds), so an old lease
     * and a new one read the same way. Anyone who is no longer an active user of this agency is skipped. The office block
     * (agency + branch) is always returned, so there is somebody to call even when no agent qualifies.
     *
     * @return array{agent:?array<string,mixed>,office:array<string,mixed>}
     */
    public function agentContact(?Lease $lease, Property $property, int $agencyId, string $audience = self::AUDIENCE_TENANT): array
    {
        $side = $audience === self::AUDIENCE_LANDLORD ? LeaseAgentService::SIDE_OWNER : LeaseAgentService::SIDE_TENANT;
        $leaseAgentId = $lease ? app(LeaseAgentService::class)->effectiveIds($lease)[$side] : null;

        $agent = null;
        foreach ([$leaseAgentId, $property->agent_id] as $userId) {
            if (! $userId) {
                continue;
            }
            $user = User::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->find($userId);
            if ($user) {
                $agent = [
                    'name' => trim((string) $user->name),
                    'designation' => $user->designation ?: null,
                    'phone' => $user->cell ?: ($user->phone ?: null),
                    // The outward-facing address, never the login (User::outwardEmail — CLAUDE.md, public-facing surfaces).
                    'email' => $user->outward_email ?: null,
                ];
                break;
            }
        }

        return ['agent' => $agent, 'office' => $this->office($lease?->branch_id ?: $property->branch_id, $agencyId)];
    }

    /** @return array{agency:?string,branch:?string,phone:?string,email:?string,address:?string} */
    private function office(?int $branchId, int $agencyId): array
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        $branch = $branchId
            ? Branch::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($branchId)
            : null;

        return [
            'agency' => $agency ? ($agency->trading_name ?: $agency->name) : null,
            'branch' => $branch?->name,
            // The branch's own details first; the agency's when the branch has none.
            'phone' => $branch?->phone ?: ($agency?->phone ?: null),
            'email' => $branch?->email ?: ($agency?->email ?: null),
            'address' => $branch?->address ?: ($agency?->address ?: null),
        ];
    }

    // ── 3. Lease end and renewal ─────────────────────────────────────────────────────────────

    /**
     * Where this lease stands, in plain words, from the lease record. `state` is the machine key, `headline` / `detail` are
     * the wording. `notice_period_days` is the agency's own setting (what a tenant must give) — kept in the payload for the mobile app; the web Home no longer
     * prints it (§21: the FAQ answers from the lease's own terms instead).
     *
     * @return array<string,mixed>
     */
    public function tenancy(Lease $lease, string $audience): array
    {
        $today = now()->startOfDay();
        $agencyId = (int) $lease->agency_id;
        $noticeDays = LeaseSetting::tenantNoticePeriodDaysFor($agencyId);
        $windowDays = LeaseSetting::expiryNoticeWindowDaysFor($agencyId);

        $start = $lease->start_date;
        $end = $lease->end_date;
        $daysLeft = $end ? (int) $today->diffInDays($end->copy()->startOfDay(), false) : null;
        $isActive = $lease->status === Lease::STATUS_ACTIVE;
        $inWindow = $isActive && $end && $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= $windowDays;

        $notice = null;
        $renewal = null;

        if ($lease->hasActiveNotice()) {
            $state = self::STATE_NOTICE_GIVEN;
            $notice = [
                'date' => $lease->notice_date->toDateString(),
                'given_by' => $lease->notice_given_by,
                'move_out_date' => $lease->move_out_date?->toDateString(),
            ];
            $by = match (true) {
                $lease->notice_given_by === Lease::NOTICE_BY_TENANT => $audience === self::AUDIENCE_TENANT ? 'You have given notice' : 'The tenant has given notice',
                $lease->notice_given_by === Lease::NOTICE_BY_LANDLORD => $audience === self::AUDIENCE_LANDLORD ? 'Notice has been given by you' : 'The landlord has given notice',
                default => 'Notice has been given',
            };
            $headline = $by;
            $detail = 'Notice given ' . $this->day($lease->notice_date) . ($lease->move_out_date ? ' · moving out ' . $this->day($lease->move_out_date) : '');
        } elseif ($lease->renewed_lease_id) {
            $state = self::STATE_RENEWED;
            $next = Lease::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($lease->renewed_lease_id);
            if ($next && $next->status !== Lease::STATUS_CANCELLED) {
                $renewal = ['start_date' => $next->start_date?->toDateString(), 'end_date' => $next->end_date?->toDateString()];
            }
            $headline = 'Lease renewed';
            $detail = $renewal && $next->start_date && $next->end_date
                ? 'New term ' . $this->day($next->start_date) . ' – ' . $this->day($next->end_date)
                : ($end ? 'Current term ends ' . $this->day($end) : null);
        } elseif ($lease->is_month_to_month) {
            $state = self::STATE_MONTH_TO_MONTH;
            $headline = 'Month-to-month';
            $detail = $end ? 'Original term ended ' . $this->day($end) : null;
        } elseif ($lease->status === Lease::STATUS_EXPIRED || ($end && $daysLeft !== null && $daysLeft < 0)) {
            $state = self::STATE_ENDED;
            $headline = 'Lease ended';
            $detail = $end ? 'Ended ' . $this->day($end) : null;
        } elseif ($inWindow) {
            $state = self::STATE_RENEWAL_WINDOW;
            $headline = 'Renewal window';
            $detail = 'Ends ' . $this->day($end) . ' · ' . $this->daysPhrase($daysLeft);
        } elseif ($start && $start->copy()->startOfDay()->gt($today)) {
            $state = self::STATE_UPCOMING;
            $headline = 'Starts ' . $this->day($start);
            $detail = $end ? 'Ends ' . $this->day($end) : null;
        } else {
            $state = self::STATE_RUNNING;
            $headline = 'Lease running';
            $detail = $end ? 'Ends ' . $this->day($end) . ($daysLeft !== null && $daysLeft >= 0 ? ' · ' . $this->daysPhrase($daysLeft) : '') : null;
        }

        return [
            'state' => $state,
            'headline' => $headline,
            'detail' => $detail,
            'start_date' => $start?->toDateString(),
            'end_date' => $end?->toDateString(),
            'days_left' => $daysLeft,
            'notice_period_days' => $noticeDays,
            'in_renewal_window' => $inWindow,
            'is_month_to_month' => (bool) $lease->is_month_to_month,
            'notice' => $notice,
            'renewal' => $renewal,
        ];
    }

    /**
     * The one lease to describe for a property: the one in force (newest first), else the most recent one that ran its
     * course. A draft, a cancelled or an archived lease is never the answer.
     *
     * @param  Collection<int,Lease>  $leases  the person's own live leases on ONE property
     */
    private function currentLease(Collection $leases): ?Lease
    {
        $active = $leases->where('status', Lease::STATUS_ACTIVE)->sortByDesc('id')->first();
        if ($active) {
            return $active;
        }

        return $leases->where('status', Lease::STATUS_EXPIRED)->sortByDesc(fn (Lease $l) => $l->end_date?->timestamp ?? 0)->first();
    }

    // ── 2. Inspection dates ──────────────────────────────────────────────────────────────────

    /**
     * Upcoming booked dates (type + date + time only) and past sent inspections (type + date + status). Never a report link
     * here — the report is opened from Documents, under that area's own rule.
     *
     * @param  Collection<int,RentalInspection>  $inspections
     * @return array{upcoming:array<int,array<string,mixed>>,past:array<int,array<string,mixed>>,past_total:int}
     */
    private function inspectionLists(Collection $inspections): array
    {
        $rows = $inspections->map(fn (RentalInspection $i) => $this->inspectionRow($i))->filter()->values();
        $upcoming = $rows->where('when', 'upcoming')->sortBy('date_sort')->values();
        $past = $rows->where('when', 'past')->sortByDesc('date_sort')->values();

        return [
            'upcoming' => $upcoming->map(fn ($r) => collect($r)->except('date_sort')->all())->all(),
            'past' => $past->take(self::PAST_INSPECTIONS_SHOWN)->map(fn ($r) => collect($r)->except('date_sort')->all())->all(),
            'past_total' => $past->count(),
        ];
    }

    /**
     * The API / page row for one inspection the portal may show, or null when it may not be shown at all. `status` is the
     * real status for a sent inspection and "scheduled" for a booked date — a draft or in-progress state is never exposed.
     *
     * @return array<string,mixed>|null
     */
    public function inspectionRow(RentalInspection $inspection): ?array
    {
        $state = $this->scope->inspectionPortalState($inspection);
        if ($state === null) {
            return null;
        }

        $upcoming = $state === RentalPortalScopeService::INSPECTION_SCHEDULED;
        $date = $upcoming
            ? $inspection->scheduled_for
            : ($inspection->completed_at ?? $inspection->scheduled_for ?? $inspection->created_at);

        return [
            'id' => $inspection->id,
            'type' => $inspection->type,
            'type_label' => RentalInspection::typeName((string) $inspection->type),
            'when' => $upcoming ? 'upcoming' : 'past',
            'status' => $upcoming ? 'scheduled' : $inspection->status,
            'status_label' => $upcoming ? 'Scheduled' : ($inspection->status === RentalInspection::STATUS_COMPLETED ? 'Completed' : 'Report sent'),
            'date' => $date?->toDateString(),
            'time' => $upcoming && $inspection->scheduled_time ? substr((string) $inspection->scheduled_time, 0, 5) : null,
            'completed_at' => $inspection->completed_at?->toIso8601String(),
            'date_sort' => $date?->timestamp ?? 0,
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return Collection<int,Property> */
    private function propertiesById(int $agencyId, array $ids): Collection
    {
        return Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('id', array_filter($ids))
            ->get()
            ->keyBy('id');
    }

    private function day(\Carbon\CarbonInterface $date): string
    {
        return $date->format('j M Y');
    }

    private function daysPhrase(int $days): string
    {
        return match (true) {
            $days === 0 => 'ends today',
            $days === 1 => '1 day left',
            default => $days . ' days left',
        };
    }
}
