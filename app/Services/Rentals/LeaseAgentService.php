<?php

declare(strict_types=1);

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §17 — the ONE place that knows who a lease's two agents are, who they default to, who may be
 * chosen, and how a change is made and logged.
 *
 * Johan, 8 Oct 2026: "on lease know who the rental agent is for the owner and tenant — it does not mean that if retha is
 * advertising the property that its her tenant… retha is the listing agent, maggie is the tenant agent. but I would still
 * on lease show the agents as such and it can be changed if need be. and that selection is who is shown on the tenant and
 * owner links."
 *
 *   OWNER'S agent  — landlord side. Default: the property's primary agent (the one who lists / advertises it).
 *   TENANT'S agent — tenant side. Default: the agent who sent out the rental application that led to the lease, else the
 *                    agent who processed / approved it, else whoever created the lease, else the owner's agent.
 *
 * "Agent" is any ACTIVE user of the lease's own agency (never another agency's, never someone who left or was
 * deactivated). Every lookup here pins the agency explicitly; nothing relies on a global scope.
 */
final class LeaseAgentService
{
    public const SIDE_OWNER = 'owner';
    public const SIDE_TENANT = 'tenant';
    public const SIDES = [self::SIDE_OWNER, self::SIDE_TENANT];

    /** Which default rule picked an agent — reported by the back-fill, stored in the lease_created event. */
    public const RULE_PROPERTY_AGENT = 'property_agent';
    public const RULE_APPLICATION_SENDER = 'application_sender';
    public const RULE_APPLICATION_APPROVER = 'application_approver';
    public const RULE_LEASE_CREATOR = 'lease_creator';
    public const RULE_OWNER_AGENT = 'owner_agent';
    public const RULE_PREVIOUS_TERM = 'previous_term';
    public const RULE_NONE = 'none';

    private const COLUMNS = [
        self::SIDE_OWNER => 'owner_agent_user_id',
        self::SIDE_TENANT => 'tenant_agent_user_id',
    ];

    public static function column(string $side): string
    {
        return self::COLUMNS[$side];
    }

    public static function sideLabel(string $side): string
    {
        return $side === self::SIDE_OWNER ? "Owner's agent" : "Tenant's agent";
    }

    // ── Who may be chosen ─────────────────────────────────────────────────────────────────────

    /** Whether $userId is an active (not deactivated, not removed) user of $agencyId. No global scope involved. */
    public function isActiveAgent(?int $userId, int $agencyId): bool
    {
        if (! $userId) {
            return false;
        }

        return User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereKey($userId)
            ->exists();
    }

    /** $userId when it is an active agent of $agencyId, else null — the shape every default rule below wants. */
    private function activeOrNull(mixed $userId, int $agencyId): ?int
    {
        $id = is_numeric($userId) ? (int) $userId : 0;

        return $id > 0 && $this->isActiveAgent($id, $agencyId) ? $id : null;
    }

    /**
     * The people a screen may offer for this lease: the active users of the lease's agency, those of the lease's own
     * branch first. Branch-aware like the rest of leases: the BranchScope still applies, so a user confined to one
     * branch by the agency's Split Branches setting is only offered that branch's people. Only the agency scope is
     * lifted — the lease's agency is pinned explicitly instead, so an owner-role user working across agencies still gets
     * THIS lease's people.
     *
     * @return Collection<int, array{id:int,name:string,branch_id:?int,in_branch:bool}>
     */
    public function selectableAgents(int $agencyId, ?int $leaseBranchId = null): Collection
    {
        return User::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('users.agency_id', $agencyId)
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.branch_id'])
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => trim((string) $u->name) !== '' ? trim((string) $u->name) : 'User #' . $u->id,
                'branch_id' => $u->branch_id !== null ? (int) $u->branch_id : null,
                'in_branch' => $leaseBranchId !== null && (int) $u->branch_id === (int) $leaseBranchId,
            ])
            ->sortBy(fn (array $a) => [$a['in_branch'] ? 0 : 1, mb_strtolower($a['name'])])
            ->values();
    }

    /** Whether the user is on the list selectableAgents() would offer — the server-side twin of every dropdown. */
    public function isSelectable(int $userId, int $agencyId): bool
    {
        return User::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->where('users.agency_id', $agencyId)
            ->where('users.is_active', true)
            ->whereKey($userId)
            ->exists();
    }

    // ── The defaults ──────────────────────────────────────────────────────────────────────────

    /**
     * Defaults for a lease about to be created. $application is the rental application the lease comes from (if any);
     * $creatorId is whoever is capturing it.
     *
     * @return array{owner:array{id:?int,rule:string},tenant:array{id:?int,rule:string}}
     */
    public function defaultsForNewLease(Property $property, ?RentalApplication $application, ?int $creatorId): array
    {
        $agencyId = (int) $property->agency_id;
        $creator = $this->activeOrNull($creatorId, $agencyId);

        return $this->defaultsFor($agencyId, $property->agent_id, $application, $creator);
    }

    /**
     * What the same rules say for a lease that already exists (the back-fill, and the fallback for a lease whose columns
     * were never filled): the property it is on, the application it came from, and the person who created it.
     *
     * @return array{owner:array{id:?int,rule:string},tenant:array{id:?int,rule:string}}
     */
    public function defaultsForExistingLease(Lease $lease): array
    {
        $agencyId = (int) $lease->agency_id;
        $propertyAgent = Property::withoutGlobalScopes()->withTrashed()->whereKey($lease->property_id)->value('agent_id');
        $application = $lease->rental_application_id
            ? RentalApplication::withoutGlobalScopes()->withTrashed()->find($lease->rental_application_id)
            : null;

        return $this->defaultsFor($agencyId, $propertyAgent, $application, $this->activeOrNull($lease->created_by_user_id, $agencyId));
    }

    /**
     * @return array{owner:array{id:?int,rule:string},tenant:array{id:?int,rule:string}}
     */
    public function defaultsFor(int $agencyId, mixed $propertyAgentId, ?RentalApplication $application, ?int $creatorId): array
    {
        $creatorId = $this->activeOrNull($creatorId, $agencyId);

        // Owner's agent: the property's primary agent; when it has none (or they left), whoever captured the lease.
        $owner = ['id' => null, 'rule' => self::RULE_NONE];
        if (($id = $this->activeOrNull($propertyAgentId, $agencyId)) !== null) {
            $owner = ['id' => $id, 'rule' => self::RULE_PROPERTY_AGENT];
        } elseif ($creatorId !== null) {
            $owner = ['id' => $creatorId, 'rule' => self::RULE_LEASE_CREATOR];
        }

        // Tenant's agent: who sent the application → who processed / approved it → who created the lease → the owner's agent.
        $tenant = ['id' => null, 'rule' => self::RULE_NONE];
        if ($application && ($id = $this->activeOrNull($application->created_by_user_id, $agencyId)) !== null) {
            $tenant = ['id' => $id, 'rule' => self::RULE_APPLICATION_SENDER];
        } elseif ($application && ($id = $this->activeOrNull($this->applicationProcessorId($application), $agencyId)) !== null) {
            $tenant = ['id' => $id, 'rule' => self::RULE_APPLICATION_APPROVER];
        } elseif ($creatorId !== null) {
            $tenant = ['id' => $creatorId, 'rule' => self::RULE_LEASE_CREATOR];
        } elseif ($owner['id'] !== null) {
            $tenant = ['id' => $owner['id'], 'rule' => self::RULE_OWNER_AGENT];
        }

        return ['owner' => $owner, 'tenant' => $tenant];
    }

    /**
     * Who processed the application: whoever recorded its approval (the latest approval, so an override counts), else
     * whoever made its latest recorded status change. The invite itself is not a status change, so the sender is the
     * application's creator (see resolve()), not found here.
     */
    private function applicationProcessorId(RentalApplication $application): ?int
    {
        $base = RentalApplicationStatusHistory::withoutGlobalScopes()
            ->where('rental_application_id', $application->id)
            ->whereNotNull('changed_by_user_id');

        $approved = (clone $base)->where('to_status', 'approved')->orderByDesc('id')->value('changed_by_user_id');
        if ($approved) {
            return (int) $approved;
        }

        $any = (clone $base)->orderByDesc('id')->value('changed_by_user_id');

        return $any ? (int) $any : null;
    }

    // ── What a lease has ──────────────────────────────────────────────────────────────────────

    /**
     * The agent ids to use for $lease right now, each side: the stored one, else — for a lease whose agents were never
     * filled in (created before this existed and not yet back-filled) — the default rules' answer. Not validated for
     * "still active": callers that must only name a working agent (the portal) check that themselves.
     *
     * @return array{owner:?int,tenant:?int}
     */
    public function effectiveIds(Lease $lease): array
    {
        $owner = $lease->owner_agent_user_id !== null ? (int) $lease->owner_agent_user_id : null;
        $tenant = $lease->tenant_agent_user_id !== null ? (int) $lease->tenant_agent_user_id : null;

        if ($owner === null || $tenant === null) {
            $defaults = $this->defaultsForExistingLease($lease);
            $owner ??= $defaults['owner']['id'];
            $tenant ??= $defaults['tenant']['id'];
        }

        return ['owner' => $owner, 'tenant' => $tenant];
    }

    /**
     * The ACTIVE user responsible for one side of a lease: the lease's own agent for that side, else the property's agent
     * (anyone no longer an active user of the agency is skipped). The single rule behind "who to call" on the portal and
     * "who is told" when the owner acts on a work order. Null when nobody qualifies (the branch alone has no user).
     *
     * @param 'owner'|'tenant' $side
     */
    public function responsibleUser(?Lease $lease, Property $property, int $agencyId, string $side): ?User
    {
        $leaseAgentId = $lease ? $this->effectiveIds($lease)[$side] : null;
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
                return $user;
            }
        }

        return null;
    }

    /**
     * Who must hear about a NEW fault (Johan, 8 Oct 2026): the lease's tenant-side AND owner-side agents (de-duplicated when it
     * is one person); with no lease agent, the property's agent; with none of those, the branch's manager, then its office
     * admin, then the agency's admin - so a fault is never reported to nobody. Inactive / removed users are skipped throughout.
     *
     * @return Collection<int, User>
     */
    public function faultRecipients(?Lease $lease, Property $property, int $agencyId): Collection
    {
        $active = fn ($id) => $id ? User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', true)->find($id) : null;

        $people = collect();
        if ($lease) {
            foreach ($this->effectiveIds($lease) as $id) {
                if ($u = $active($id)) {
                    $people->push($u);
                }
            }
        }
        if ($people->isEmpty() && ($u = $active($property->agent_id))) {
            $people->push($u);
        }
        if ($people->isEmpty()) {
            $branchId = $lease?->branch_id ?: $property->branch_id;
            foreach (['branch_manager', 'office_admin', 'admin'] as $role) {
                $q = User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', true)->where('role', $role);
                if ($role !== 'admin' && $branchId) {
                    $q->where('branch_id', $branchId);
                }
                if ($found = $q->orderBy('id')->first()) {
                    $people->push($found);
                    break;
                }
            }
        }

        return $people->unique('id')->values();
    }

    // ── Changing them ─────────────────────────────────────────────────────────────────────────

    /**
     * Set the owner's and/or the tenant's agent on a lease and log each change (who, from, to, when) in the lease
     * history. $changes holds only the sides to set: ['owner' => userId, 'tenant' => userId]. A side whose value is
     * unchanged writes nothing and logs nothing. Returns the sides that actually changed.
     *
     * @param array<string,int|string|null> $changes
     * @return list<string>
     *
     * @throws ValidationException a person who is not an active user of the lease's agency, or no one at all
     */
    public function assign(Lease $lease, array $changes, ?User $actor): array
    {
        $agencyId = (int) $lease->agency_id;
        $wanted = [];

        foreach (self::SIDES as $side) {
            if (! array_key_exists($side, $changes)) {
                continue;
            }
            $value = $changes[$side];
            $userId = is_numeric($value) ? (int) $value : 0;
            if ($userId <= 0 || ! $this->isSelectable($userId, $agencyId)) {
                throw ValidationException::withMessages([
                    $side . '_agent_user_id' => 'Choose ' . strtolower(self::sideLabel($side)) . ' from the list.',
                ]);
            }
            $wanted[$side] = $userId;
        }

        return DB::transaction(function () use ($lease, $wanted, $actor) {
            $locked = Lease::withoutGlobalScopes()->lockForUpdate()->findOrFail($lease->id);
            $changed = [];

            foreach ($wanted as $side => $userId) {
                $column = self::column($side);
                $from = $locked->{$column} !== null ? (int) $locked->{$column} : null;
                if ($from === $userId) {
                    continue;
                }

                $locked->{$column} = $userId;
                $changed[] = $side;

                $fromName = $this->nameOf($from);
                $toName = $this->nameOf($userId);
                LeaseEvent::create([
                    'lease_id' => $locked->id,
                    'event_type' => LeaseEvent::TYPE_LEASE_AGENT_CHANGED,
                    'description' => self::sideLabel($side) . ' changed: ' . ($fromName ?? 'no one') . ' → ' . $toName,
                    'actor_user_id' => $actor?->id,
                    'metadata' => [
                        'side' => $side,
                        'from_user_id' => $from,
                        'from_name' => $fromName,
                        'to_user_id' => $userId,
                        'to_name' => $toName,
                    ],
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
            }

            if ($changed !== []) {
                $locked->save();
                // The caller's instance shows the new values without a reload.
                foreach ($changed as $side) {
                    $lease->setAttribute(self::column($side), $locked->{self::column($side)});
                }
            }

            return $changed;
        });
    }

    private function nameOf(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        $name = User::withoutGlobalScopes()->withTrashed()->whereKey($userId)->value('name');

        return $name !== null && trim((string) $name) !== '' ? trim((string) $name) : 'User #' . $userId;
    }
}
