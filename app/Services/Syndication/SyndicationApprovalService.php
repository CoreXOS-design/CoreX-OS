<?php

declare(strict_types=1);

namespace App\Services\Syndication;

use App\Events\Property\SyndicationApprovalRequested;
use App\Events\Property\SyndicationApprovalRevoked;
use App\Events\Property\SyndicationApproved;
use App\Events\Property\SyndicationRejected;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Compliance\MarketingReadinessService;
use Illuminate\Support\Facades\DB;

/**
 * Layer 3 of the market gate — "even if compliance is done, a chosen person
 * must still approve this listing before it is syndicated."
 * Spec: .ai/specs/syndication-approval-gate.md
 *
 * Layer 1 = on-market (EnforcesMarketingReadiness::enforceListingNotDraft)
 * Layer 2 = compliance (MarketingReadinessService)
 * Layer 3 = THIS.
 *
 * Two facts drive everything:
 *   - the agency switch `syndication_approval_required` (off ⇒ every method
 *     here is inert and every surface renders nothing);
 *   - `properties.syndication_approved_at` — the durable approval stamp.
 *     Approval holds forever (spec D2), so the gate is a NULL check, never a
 *     status machine. NOTHING in CoreX may clear that stamp automatically;
 *     only revoke() does, and only from a human approver.
 */
class SyndicationApprovalService
{
    public const SETTING_REQUIRED  = 'syndication_approval_required';
    public const SETTING_APPROVERS = 'syndication_approver_user_ids';

    public function __construct(
        private MarketingReadinessService $readiness = new MarketingReadinessService(),
    ) {
    }

    // ── The gate ────────────────────────────────────────────────────────

    /** Is layer 3 switched on for this property's agency? */
    public function isRequired(Property $property): bool
    {
        return self::isRequiredForAgency((int) $property->agency_id);
    }

    public static function isRequiredForAgency(int $agencyId): bool
    {
        if ($agencyId <= 0) {
            return false;
        }

        return (bool) PerformanceSetting::get(self::SETTING_REQUIRED, 0, $agencyId);
    }

    /**
     * THE gate check. False ⇒ this listing may not be put onto any portal or
     * website. Inert (always true) when the agency has not switched layer 3 on.
     */
    public function isApproved(Property $property): bool
    {
        if (! $this->isRequired($property)) {
            return true;
        }

        return $property->syndication_approved_at !== null;
    }

    /**
     * The refusal every service chokepoint returns when this listing may not go
     * to a portal / website: null when it may. Same isApproved() as the
     * controllers' enforceSyndicationApproval() — one gate, no parallel rule.
     * Pure DB read (agency setting + the stamp on the row), never a portal call.
     *
     * @return array{success: false, message: string, approval_required: true}|null
     */
    public function refusalFor(Property $property, string $target = 'any website or portal'): ?array
    {
        if ($this->isApproved($property)) {
            return null;
        }

        $names = $this->approverNamesFor((int) $property->agency_id);
        $who   = empty($names) ? 'your agency admin' : implode(' or ', $names);

        return [
            'success'           => false,
            'approval_required' => true,
            'message'           => "Blocked: this listing must be approved by {$who} before it can go to {$target}.",
        ];
    }

    /**
     * Refusal for a push to a listing that MAY ALREADY BE LIVE on a portal
     * (price / status / field-edit resubmits, queued jobs). Spec: revoking an
     * approval leaves live listings alone — so an already-live listing keeps
     * receiving updates whether it was revoked, or is simply not yet stamped
     * (the grandfather job for a freshly switched-on agency has not run).
     * What still needs the approval is NEW publishing and returning an
     * off-market listing to market: no portal reference, or one the portal was
     * told to take off ('deactivated'), is NOT live and is refused as normal.
     *
     * Only Property24 / Private Property have a "live" notion; any other target
     * (websites) falls straight through to refusalFor().
     */
    public function refusalForUpdate(Property $property, string $target): ?array
    {
        if ($this->isApproved($property)) {
            return null;
        }

        $live = match ($target) {
            'Property24'       => $property->mayBeLiveOnP24(),
            'Private Property' => $property->mayBeLiveOnPp(),
            default            => false,
        };

        return $live ? null : $this->refusalFor($property, $target);
    }

    /**
     * May an agent raise a request right now? Requires compliance to be complete
     * (spec D5 — the button does not exist before that), no approval already in
     * place, and no request already pending.
     */
    public function canRequest(Property $property): bool
    {
        if (! $this->isRequired($property) || $this->isApproved($property)) {
            return false;
        }

        if (! $this->readiness->isMarketable($property)) {
            return false;
        }

        return $this->latestApproval($property)?->status !== PropertySyndicationApproval::STATUS_PENDING;
    }

    // ── Who may approve ─────────────────────────────────────────────────

    /** The agency's chosen approver ids. */
    public static function approverIdsFor(int $agencyId): array
    {
        if ($agencyId <= 0) {
            return [];
        }

        $raw = PerformanceSetting::get(self::SETTING_APPROVERS, [], $agencyId);

        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }

        return array_values(array_filter(array_map('intval', (array) $raw)));
    }

    /** Is this user one of the people the agency named? */
    public static function isChosenApprover(User $user): bool
    {
        $agencyId = (int) $user->effectiveAgencyId();

        return in_array((int) $user->id, self::approverIdsFor($agencyId), true);
    }

    /**
     * The standing fallback: an owner or agency admin may always approve, so an
     * agency whose chosen approver has left is never stuck with stock it cannot
     * market. Same doctrine as every other designated-person feature in CoreX.
     */
    public static function isFallbackApprover(User $user): bool
    {
        return $user->isOwnerRole() || in_array($user->role ?? '', ['admin', 'super_admin'], true);
    }

    /**
     * ONE rule, used by the queue filter, every button, and every write
     * endpoint — so list visibility and write authority can never disagree.
     *
     * Called with NO property it answers "is this person an approver at all?"
     * (the tile, the buttons). Called WITH one it answers "may they clear THIS
     * listing?" — and that second answer is narrowed by the same membership
     * tiers the queue renders with (spec §7.3), resolved through the one
     * shared resolver PropertySyndicationApproval::approvalScopeFor().
     *
     * WHY THE SCOPE NARROWING IS NOT OPTIONAL: without it a branch-limited
     * approver could POST /approve for a listing id in a branch the queue
     * correctly hides from them, and it was accepted (HTTP 200, listing
     * stamped). Authority must never be broader than visibility — CLAUDE.md
     * non-negotiable #8: direct-URL access by id is BLOCKED, not just
     * unlinked. Both halves now read the same rule, so they cannot drift.
     */
    public function canApprove(User $user, ?Property $property = null): bool
    {
        if (! self::isChosenApprover($user) && ! self::isFallbackApprover($user)) {
            return false;
        }

        if ($property === null) {
            return true;
        }

        // Cross-agency approval is never possible, whatever the roster says.
        if ((int) $property->agency_id !== (int) $user->effectiveAgencyId()) {
            return false;
        }

        // ONLY an explicit branch limit narrows an approver — deliberately not the
        // 'own' tier. Being named on the roster IS the grant of authority, and a
        // chosen approver is normally an ordinary agent (properties scope 'own')
        // whose whole job is clearing OTHER agents' listings. Narrowing 'own' here
        // would disable the feature for exactly the person it was built for.
        // Same reading as approvalScopeFor()'s own docblock: "a chosen approver is
        // agency-wide by definition… an explicit 'branch' scope still narrows them."
        if (PropertySyndicationApproval::approvalScopeFor($user) !== 'branch') {
            return true;
        }

        // Their branch only; NO branch resolvable ⇒ nothing (fail closed, never
        // fail open — the same posture as scopeVisibleTo()).
        return $property->branch_id !== null
            && (int) $user->effectiveBranchId() > 0
            && (int) $property->branch_id === (int) $user->effectiveBranchId();
    }

    // ── State for every UI surface ──────────────────────────────────────

    public function latestApproval(Property $property): ?PropertySyndicationApproval
    {
        return PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)
            ->orderByDesc('id')
            ->first();
    }

    public function stateFor(Property $property): SyndicationApprovalState
    {
        if (! $this->isRequired($property)) {
            return new SyndicationApprovalState(
                required: false,
                approved: true,
                badge: SyndicationApprovalState::BADGE_NONE,
            );
        }

        $latest     = $this->latestApproval($property);
        $approved   = $property->syndication_approved_at !== null;
        $compliance = $this->readiness->isMarketable($property);
        $pending    = $latest && $latest->status === PropertySyndicationApproval::STATUS_PENDING;

        $badge = match (true) {
            $approved   => SyndicationApprovalState::BADGE_APPROVED,
            $pending    => SyndicationApprovalState::BADGE_AWAITING,
            ! $compliance => SyndicationApprovalState::BADGE_NONE,
            $latest && $latest->status === PropertySyndicationApproval::STATUS_REJECTED
                        => SyndicationApprovalState::BADGE_REJECTED,
            default     => SyndicationApprovalState::BADGE_NEEDS,
        };

        return new SyndicationApprovalState(
            required: true,
            approved: $approved,
            badge: $badge,
            approvedAt: $property->syndication_approved_at,
            approvedByName: $property->syndicationApprovedBy?->name,
            pendingApprovalId: $pending ? (int) $latest->id : null,
            requestedAt: $pending ? $latest->requested_at : null,
            requestedByName: $pending ? $latest->requestedBy?->name : null,
            lastDecisionNote: $latest?->decision_note,
            canRequest: $this->canRequest($property),
            complianceComplete: $compliance,
            approverNames: $this->approverNamesFor((int) $property->agency_id),
        );
    }

    /** @return list<string> */
    public function approverNamesFor(int $agencyId): array
    {
        $ids = self::approverIdsFor($agencyId);

        if (empty($ids)) {
            return [];
        }

        return User::withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $ids)
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    // ── The four transitions ────────────────────────────────────────────

    /** Agent clicks "Send for approval". */
    public function request(Property $property, User $by, ?string $note = null): PropertySyndicationApproval
    {
        [$approval, $created] = DB::transaction(function () use ($property, $by, $note) {
            // Serialise on the property row so a double-click / second tab can
            // never create two pending rows: the loser sees the winner's row.
            Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();

            $existing = $this->latestApproval($property);
            if ($existing && $existing->status === PropertySyndicationApproval::STATUS_PENDING) {
                return [$existing, false];
            }

            return [PropertySyndicationApproval::create([
                'agency_id'            => $property->agency_id,
                'branch_id'            => $property->branch_id,
                'property_id'          => $property->id,
                'status'               => PropertySyndicationApproval::STATUS_PENDING,
                'requested_by_user_id' => $by->id,
                'requested_at'         => now(),
                'request_note'         => $note ?: null,
            ]), true];
        });

        if (! $created) {
            return $approval;
        }

        SyndicationApprovalRequested::dispatch(
            $property,
            (int) $approval->id,
            (int) $by->id,
            self::approverIdsFor((int) $property->agency_id),
        );

        return $approval;
    }

    /** The user who raised the currently pending request, or null when none is pending. */
    public function pendingRequesterId(Property $property): ?int
    {
        $pending = $this->latestApproval($property);

        return $pending && $pending->status === PropertySyndicationApproval::STATUS_PENDING
            ? (int) $pending->requested_by_user_id
            : null;
    }

    /** Agent cancels their own pending request. Returns false when nothing was pending. */
    public function cancel(Property $property, User $by): bool
    {
        return DB::transaction(function () use ($property, $by) {
            Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();

            $pending = $this->latestApproval($property);

            if (! $pending || $pending->status !== PropertySyndicationApproval::STATUS_PENDING) {
                return false;
            }

            $pending->update([
                'status'        => PropertySyndicationApproval::STATUS_WITHDRAWN,
                'decided_by_user_id' => $by->id,
                'decided_at'    => now(),
                'decision_note' => 'Request cancelled by the listing agent.',
            ]);

            return true;
        });
    }

    /** Approver approves — the stamp that unlocks every portal, permanently. */
    public function approve(Property $property, User $by, ?string $note = null): void
    {
        $approval = DB::transaction(function () use ($property, $by, $note) {
            // Lock + re-read the stamp: two approvers acting at once, or one on a
            // stale route-bound model, must not both write an approval row.
            $locked = Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();
            if ($locked && $locked->syndication_approved_at !== null) {
                return null;
            }

            $pending = $this->latestApproval($property);

            if ($pending && $pending->status === PropertySyndicationApproval::STATUS_PENDING) {
                $pending->update([
                    'status'             => PropertySyndicationApproval::STATUS_APPROVED,
                    'decided_by_user_id' => $by->id,
                    'decided_at'         => now(),
                    'decision_note'      => $note ?: null,
                ]);
            } else {
                // Approved directly off the property without a pending request
                // (an approver clearing a listing themselves). The trail still
                // gets its row — an approval with no record is not auditable.
                $pending = PropertySyndicationApproval::create([
                    'agency_id'            => $property->agency_id,
                    'branch_id'            => $property->branch_id,
                    'property_id'          => $property->id,
                    'status'               => PropertySyndicationApproval::STATUS_APPROVED,
                    'requested_by_user_id' => $property->agent_id ?: $by->id,
                    'requested_at'         => now(),
                    'decided_by_user_id'   => $by->id,
                    'decided_at'           => now(),
                    'decision_note'        => $note ?: 'Approved directly by the approver.',
                ]);
            }

            $property->forceFill([
                'syndication_approved_at'         => now(),
                'syndication_approved_by_user_id' => $by->id,
            ])->save();

            return $pending;
        });

        if ($approval) {
            SyndicationApproved::dispatch($property, (int) $approval->id, (int) $by->id);
        }
    }

    /** Approver rejects — the reason is mandatory and reaches the agent. */
    public function reject(Property $property, User $by, string $reason): bool
    {
        $approval = DB::transaction(function () use ($property, $by, $reason) {
            Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();

            $pending = $this->latestApproval($property);

            if (! $pending || $pending->status !== PropertySyndicationApproval::STATUS_PENDING) {
                return null;
            }

            $pending->update([
                'status'             => PropertySyndicationApproval::STATUS_REJECTED,
                'decided_by_user_id' => $by->id,
                'decided_at'         => now(),
                'decision_note'      => $reason,
            ]);

            return $pending;
        });

        if ($approval) {
            SyndicationRejected::dispatch($property, (int) $approval->id, (int) $by->id, $reason);
        }

        return $approval !== null;
    }

    /**
     * Approver revokes an approval already given. Clears the stamp so the
     * listing cannot go anywhere NEW, and deliberately does NOT deactivate
     * anything already live on a portal — that stays the separate, explicit
     * per-portal Deactivate action (spec §5.5).
     */
    public function revoke(Property $property, User $by, string $reason): void
    {
        $approval = DB::transaction(function () use ($property, $by, $reason) {
            $locked = Property::withoutGlobalScopes()->whereKey($property->id)->lockForUpdate()->first();
            if ($locked && $locked->syndication_approved_at === null) {
                return null; // already revoked by someone else
            }

            $row = PropertySyndicationApproval::create([
                'agency_id'            => $property->agency_id,
                'branch_id'            => $property->branch_id,
                'property_id'          => $property->id,
                'status'               => PropertySyndicationApproval::STATUS_WITHDRAWN,
                'requested_by_user_id' => $property->agent_id ?: $by->id,
                'requested_at'         => now(),
                'decided_by_user_id'   => $by->id,
                'decided_at'           => now(),
                'decision_note'        => $reason,
            ]);

            $property->forceFill([
                'syndication_approved_at'         => null,
                'syndication_approved_by_user_id' => null,
            ])->save();

            return $row;
        });

        if ($approval) {
            SyndicationApprovalRevoked::dispatch($property, (int) $approval->id, (int) $by->id, $reason);
        }
    }
}
