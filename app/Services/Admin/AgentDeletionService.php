<?php

namespace App\Services\Admin;

use App\Models\Communications\CommsAccessAuditLog;
use App\Models\Property;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentDeletionService
{
    /**
     * Count records that would be reassigned/removed if this agent were deleted.
     *
     * @return array{
     *     properties_primary:int,
     *     properties_secondary:int,
     *     contacts:int,
     *     calendar_events:int,
     *     command_tasks:int,
     *     deals:int,
     *     has_any:bool,
     *     has_deals:bool
     * }
     *
     * `has_any` covers properties/contacts/events/tasks (the records gated by
     * the reassignment dropdown). Deals are tracked separately via `deals` /
     * `has_deals` because they have their own leave-or-move control.
     */
    public function preview(User $user): array
    {
        $primary   = DB::table('properties')->whereNull('deleted_at')->where('agent_id', $user->id)->count();
        $secondary = DB::table('properties')->whereNull('deleted_at')->where('pp_second_agent_id', $user->id)->count();
        $contacts  = DB::table('contacts')->whereNull('deleted_at')->where('created_by_user_id', $user->id)->count();
        $events    = DB::table('calendar_events')->whereNull('deleted_at')->where('user_id', $user->id)->count();
        $tasks     = DB::table('command_tasks')->whereNull('deleted_at')->where('assigned_to', $user->id)->count();

        // Distinct deals this agent owns across both deal systems (v1 register +
        // v2 pipeline) — as agent on the deal, or as the named branch manager.
        $dealsV1 = DB::table('deals')->whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->where('managed_by_user_id', $user->id)
                  ->orWhereIn('id', DB::table('deal_user')->select('deal_id')->where('user_id', $user->id));
            })->count();

        $dealsV2 = DB::table('deals_v2')->whereNull('deleted_at')
            ->where(function ($q) use ($user) {
                $q->where('listing_agent_id', $user->id)
                  ->orWhere('selling_agent_id', $user->id)
                  ->orWhereIn('id', DB::table('deal_v2_agents')->select('deal_id')->where('user_id', $user->id));
            })->count();

        $deals = $dealsV1 + $dealsV2;

        return [
            'properties_primary'   => $primary,
            'properties_secondary' => $secondary,
            'contacts'             => $contacts,
            'calendar_events'      => $events,
            'command_tasks'        => $tasks,
            'deals'                => $deals,
            'has_any'              => ($primary + $secondary + $contacts + $events + $tasks) > 0,
            'has_deals'            => $deals > 0,
        ];
    }

    /**
     * Point the departing agent's QR slug at a live agent.
     *
     * The slug stays on $source (audit anchor); scans now resolve through
     * the reroute pointer to $target. Mandatory on every agent delete so no
     * printed QR code ever dead-ends. Chained automatically if $target later
     * leaves too (see User::resolveByQrSlug).
     *
     * Spec: .ai/specs/agent-qr-onboarding.md
     */
    public function setQrReroute(User $source, User $target, int $actorId): void
    {
        DB::table('users')->where('id', $source->id)->update([
            'qr_reroute_user_id' => $target->id,
            'updated_at'         => now(),
        ]);

        Log::info('agent.qr_rerouted', [
            'actor_user_id'   => $actorId,
            'source_user_id'  => $source->id,
            'source_qr_slug'  => $source->qr_code_slug,
            'target_user_id'  => $target->id,
            'target_user_name' => $target->name,
        ]);
    }

    /**
     * Bulk reassign properties + contacts from $source to $target,
     * soft-delete calendar events + tasks owned by $source.
     *
     * Secondary handling:
     *   'promote' = where source is primary AND a different secondary exists,
     *               promote the secondary to primary and clear the secondary slot.
     *               Otherwise reassign primary to target.
     *   'replace' = always set primary to target; secondary slot unchanged
     *               (except where source itself is the secondary, that slot becomes target).
     *
     * Returns the counts that were actually changed.
     *
     * @return array{
     *     properties_primary:int,
     *     properties_secondary:int,
     *     contacts:int,
     *     calendar_events:int,
     *     command_tasks:int
     * }
     */
    public function reassignAndCleanup(User $source, User $target, string $secondaryHandling, int $actorId): array
    {
        // AT-321 — these are raw DB::table('properties') writes that bypass the
        // Eloquent observer. The unbypassable audit trigger records each agent_id
        // change; stamp a clear source so those rows read "agent-merge …" and carry
        // the acting user, never a blank/"System".
        $actorName = optional(User::find($actorId))->name ?? ("User #{$actorId}");
        \App\Support\Audit\PropertyAuditContext::setSource(
            "agent-merge: {$source->name} (#{$source->id}) → {$target->name} (#{$target->id}) by {$actorName}",
            'system'
        );

        return DB::transaction(function () use ($source, $target, $secondaryHandling, $actorId) {
            $now = now();

            $primaryChanged = 0;

            if ($secondaryHandling === 'promote') {
                // 1a. Properties where source is primary AND a different non-null secondary exists:
                //     promote the secondary to primary, clear the secondary slot.
                $promoted = DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->whereNotNull('pp_second_agent_id')
                    ->where('pp_second_agent_id', '!=', $source->id)
                    ->get(['id', 'pp_second_agent_id']);

                foreach ($promoted as $row) {
                    DB::table('properties')->where('id', $row->id)->update([
                        'agent_id'           => $row->pp_second_agent_id,
                        'pp_second_agent_id' => null,
                        'updated_at'         => $now,
                    ]);
                    $primaryChanged++;
                }

                // 1b. Remaining properties where source is still primary (no secondary to promote):
                //     reassign to target.
                $primaryChanged += DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            } else {
                // 'replace' — always set primary to target.
                $primaryChanged = DB::table('properties')
                    ->whereNull('deleted_at')
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            }

            // 2. Properties where source is the secondary agent → set secondary to target,
            //    unless that would duplicate the existing primary (then null it instead).
            $secondaryRows = DB::table('properties')
                ->whereNull('deleted_at')
                ->where('pp_second_agent_id', $source->id)
                ->get(['id', 'agent_id']);

            $secondaryChanged = 0;
            foreach ($secondaryRows as $row) {
                $newSecondary = ((int) $row->agent_id === (int) $target->id) ? null : $target->id;
                DB::table('properties')->where('id', $row->id)->update([
                    'pp_second_agent_id' => $newSecondary,
                    'updated_at'         => $now,
                ]);
                $secondaryChanged++;
            }

            // 3. Contacts — reassign created_by_user_id.
            $contactsChanged = DB::table('contacts')
                ->whereNull('deleted_at')
                ->where('created_by_user_id', $source->id)
                ->update(['created_by_user_id' => $target->id, 'updated_at' => $now]);

            // 4. Calendar events owned by source → soft delete.
            $eventsDeleted = DB::table('calendar_events')
                ->whereNull('deleted_at')
                ->where('user_id', $source->id)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            // 5. Command tasks assigned to source → soft delete.
            $tasksDeleted = DB::table('command_tasks')
                ->whereNull('deleted_at')
                ->where('assigned_to', $source->id)
                ->update(['deleted_at' => $now, 'updated_at' => $now]);

            $counts = [
                'properties_primary'   => $primaryChanged,
                'properties_secondary' => $secondaryChanged,
                'contacts'             => $contactsChanged,
                'calendar_events'      => $eventsDeleted,
                'command_tasks'        => $tasksDeleted,
            ];

            // No general activity_log table exists in this DB; write a structured log line.
            Log::info('agent.deleted_with_reassignment', [
                'actor_user_id'      => $actorId,
                'source_user_id'     => $source->id,
                'source_user_name'   => $source->name,
                'target_user_id'     => $target->id,
                'target_user_name'   => $target->name,
                'secondary_handling' => $secondaryHandling,
                'counts'             => $counts,
            ]);

            return $counts;
        });
    }

    /**
     * AT-118 Flow B — offboarding transfer. Moves the departing agent's LIVE
     * working set to the successor and LEAVES the historic/financial record with
     * the departed agent. Deals + commissions are NOT touched here (they stay).
     *
     * Transfers to $target:
     *   - Properties: ON-MARKET only (status NOT in Property::OFF_MARKET_STATUSES).
     *     Sold / transferred / withdrawn / expired / let / draft / archived stay
     *     attributed to the departed agent (their track record).
     *   - Contacts: agent_id + second_agent_id + created_by_user_id (so the contact
     *     surface — $contact->agent ?? $contact->createdBy — shows the successor).
     *   - FICA: requested_by + agent_verified_by (the agent-side fields).
     *   - communications.owner_user_id (the AT-118 gate re-points automatically).
     * Calendar events + tasks owned by the source are soft-deleted (existing
     * offboarding behaviour). Logs an immutable ownership_transfer audit event.
     *
     * @return array<string,int>
     */
    public function transferForOffboarding(User $source, User $target, string $secondaryHandling, int $actorId): array
    {
        if ((int) $source->id === (int) $target->id) {
            return ['skipped_same_user' => 1];
        }

        $counts = DB::transaction(function () use ($source, $target, $secondaryHandling) {
            $now      = now();
            $offMkt   = Property::OFF_MARKET_STATUSES;

            // ── Properties — ON-MARKET only (status-based split) ──
            $primaryChanged = 0;
            if ($secondaryHandling === 'promote') {
                $promoted = DB::table('properties')->whereNull('deleted_at')
                    ->whereNotIn('status', $offMkt)
                    ->where('agent_id', $source->id)
                    ->whereNotNull('pp_second_agent_id')
                    ->where('pp_second_agent_id', '!=', $source->id)
                    ->get(['id', 'pp_second_agent_id']);
                foreach ($promoted as $row) {
                    DB::table('properties')->where('id', $row->id)->update([
                        'agent_id' => $row->pp_second_agent_id, 'pp_second_agent_id' => null, 'updated_at' => $now,
                    ]);
                    $primaryChanged++;
                }
                $primaryChanged += DB::table('properties')->whereNull('deleted_at')
                    ->whereNotIn('status', $offMkt)
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            } else {
                $primaryChanged = DB::table('properties')->whereNull('deleted_at')
                    ->whereNotIn('status', $offMkt)
                    ->where('agent_id', $source->id)
                    ->update(['agent_id' => $target->id, 'updated_at' => $now]);
            }

            $secondaryChanged = 0;
            foreach (DB::table('properties')->whereNull('deleted_at')
                        ->whereNotIn('status', $offMkt)
                        ->where('pp_second_agent_id', $source->id)
                        ->get(['id', 'agent_id']) as $row) {
                $newSecondary = ((int) $row->agent_id === (int) $target->id) ? null : $target->id;
                DB::table('properties')->where('id', $row->id)
                    ->update(['pp_second_agent_id' => $newSecondary, 'updated_at' => $now]);
                $secondaryChanged++;
            }

            // Properties left with the departed agent (sold/historic) — for the audit detail.
            $historicLeft = DB::table('properties')->whereNull('deleted_at')
                ->whereIn('status', $offMkt)
                ->where(fn ($q) => $q->where('agent_id', $source->id)->orWhere('pp_second_agent_id', $source->id))
                ->count();

            // ── Contacts — current operational agent + capture fields → successor ──
            $contactsPrimary = DB::table('contacts')->whereNull('deleted_at')
                ->where('agent_id', $source->id)->update(['agent_id' => $target->id, 'updated_at' => $now]);
            $contactsSecond = DB::table('contacts')->whereNull('deleted_at')
                ->where('second_agent_id', $source->id)->update(['second_agent_id' => $target->id, 'updated_at' => $now]);
            $contactsCreated = DB::table('contacts')->whereNull('deleted_at')
                ->where('created_by_user_id', $source->id)->update(['created_by_user_id' => $target->id, 'updated_at' => $now]);

            // ── FICA — agent-side fields → successor (compliance-officer verify fields stay) ──
            $ficaRequested = 0;
            $ficaAgentVerified = 0;
            if (Schema::hasTable('fica_submissions')) {
                $ficaRequested = DB::table('fica_submissions')
                    ->where('requested_by', $source->id)->update(['requested_by' => $target->id, 'updated_at' => $now]);
                if (Schema::hasColumn('fica_submissions', 'agent_verified_by')) {
                    $ficaAgentVerified = DB::table('fica_submissions')
                        ->where('agent_verified_by', $source->id)->update(['agent_verified_by' => $target->id, 'updated_at' => $now]);
                }
            }

            // ── Communications — owner re-points → the AT-118 gate follows automatically ──
            $commsOwner = DB::table('communications')
                ->where('owner_user_id', $source->id)->update(['owner_user_id' => $target->id, 'updated_at' => $now]);

            // ── Calendar + tasks — soft-delete (existing offboarding behaviour) ──
            $eventsDeleted = DB::table('calendar_events')->whereNull('deleted_at')
                ->where('user_id', $source->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $tasksDeleted = DB::table('command_tasks')->whereNull('deleted_at')
                ->where('assigned_to', $source->id)->update(['deleted_at' => $now, 'updated_at' => $now]);

            return [
                'properties_primary'    => $primaryChanged,
                'properties_secondary'  => $secondaryChanged,
                'properties_historic_left' => $historicLeft,
                'contacts_agent'        => $contactsPrimary,
                'contacts_second_agent' => $contactsSecond,
                'contacts_created_by'   => $contactsCreated,
                'fica_requested_by'     => $ficaRequested,
                'fica_agent_verified_by' => $ficaAgentVerified,
                'communications_owner'  => $commsOwner,
                'calendar_events'       => $eventsDeleted,
                'command_tasks'         => $tasksDeleted,
            ];
        });

        // Immutable POPIA record of the transfer (req 1). Deals/commissions intentionally absent.
        CommsAccessAuditLog::record(CommsAccessAuditLog::EVENT_OWNERSHIP_TRANSFER, [
            'agency_id'       => $source->agency_id,
            'actor_user_id'   => $actorId,
            'subject_user_id' => $source->id,
            'detail'          => [
                'departed_user_id'  => $source->id,
                'departed_user'     => $source->name,
                'successor_user_id' => $target->id,
                'successor_user'    => $target->name,
                'secondary_handling' => $secondaryHandling,
                'transferred'       => $counts,
                'stays_with_departed' => ['deal_register', 'commissions', 'sold_historic_stock'],
            ],
        ]);

        Log::info('agent.offboarding_transfer', [
            'actor_user_id'  => $actorId,
            'source_user_id' => $source->id,
            'target_user_id' => $target->id,
            'counts'         => $counts,
        ]);

        return $counts;
    }

    /**
     * Move every deal-ownership and commission/settlement linkage from
     * $source to $target, across both deal systems (v1 register + v2 pipeline).
     *
     * Used to consolidate duplicate agent accounts: all deals, commission
     * pools, and settlement allocations owed to the departing agent move to
     * the surviving agent so nothing is orphaned under the deleted user.
     *
     * What MOVES (ownership + money):
     *   - deal_user / deal_v2_agents           (agent role on each deal)
     *   - deal_settlements / deal_v2_settlements (commission split overrides)
     *   - deals.managed_by_user_id              (named branch manager)
     *   - deals_v2.listing_agent_id / selling_agent_id
     *
     * What is REBUILT (not moved row-by-row):
     *   - deal_money_lines                      (v1 commission projection,
     *     regenerated from the moved deal_user/deal_settlements — see below)
     *
     * What is LEFT UNTOUCHED (audit / historical actor fields — rewriting them
     * would corrupt the record of who did what):
     *   - deal_logs.actor_user_id, deals.link_reviewed_by_user_id,
     *     deal_step_instances.completed_by_id, deal_step_documents.uploaded_by_id,
     *     deal_activity_log.user_id, *.created_by_id, lost-deal actor fields.
     *
     * Tables with a unique(deal_id, user_id, side) constraint are deduplicated:
     * if $target already holds the exact slot, $source's duplicate row is
     * removed (soft-deleted where the table supports it) rather than moved, so
     * the unique key never clashes and commission is never double-counted.
     *
     * `deal_money_lines` (the materialized v1 commission projection) is NOT
     * re-pointed row-by-row — it is rebuilt from the moved deal_user /
     * deal_settlements via DealMoneyLineRebuilder. The observers that normally
     * trigger that rebuild are bypassed by the raw writes here, and a naive
     * re-point would double-count commission on the dedup path, so we drive the
     * canonical rebuilder directly instead.
     *
     * @return array<string,int> rows changed per table.
     */
    public function reassignDeals(User $source, User $target, int $actorId): array
    {
        // Merging an account into itself is a no-op — and would be destructive
        // (every slot would "collide" with itself and get dropped).
        if ((int) $source->id === (int) $target->id) {
            return ['skipped_same_user' => 1];
        }

        return DB::transaction(function () use ($source, $target, $actorId) {
            $now = now();

            // v1 deals whose derived commission projection must be rebuilt after
            // the move — captured BEFORE we touch any rows. Covers deals the agent
            // is on as agent, or has a settlement / money-line for.
            $v1DealIds = DB::table('deal_user')->where('user_id', $source->id)->pluck('deal_id')
                ->merge(DB::table('deal_settlements')->where('user_id', $source->id)->pluck('deal_id'))
                ->merge(DB::table('deal_money_lines')->where('user_id', $source->id)->pluck('deal_id'))
                ->unique()
                ->values();

            $counts = [
                'deal_user'           => $this->moveAgentSlots('deal_user', $source, $target, $now),
                'deal_settlements'    => $this->moveAgentSlots('deal_settlements', $source, $target, $now),
                'deal_v2_agents'      => $this->moveAgentSlots('deal_v2_agents', $source, $target, $now),
                'deal_v2_settlements' => $this->moveAgentSlots('deal_v2_settlements', $source, $target, $now),
                'deals_managed_by'    => DB::table('deals')
                    ->where('managed_by_user_id', $source->id)
                    ->update(['managed_by_user_id' => $target->id, 'updated_at' => $now]),
                'deals_v2_listing'    => DB::table('deals_v2')
                    ->where('listing_agent_id', $source->id)
                    ->update(['listing_agent_id' => $target->id, 'updated_at' => $now]),
                'deals_v2_selling'    => DB::table('deals_v2')
                    ->where('selling_agent_id', $source->id)
                    ->update(['selling_agent_id' => $target->id, 'updated_at' => $now]),
            ];

            // Rebuild deal_money_lines from the now-moved deal_user/deal_settlements
            // so the projection matches reality (and any dedup-dropped duplicate is
            // collapsed rather than left double-counted).
            $rebuilt = 0;
            foreach ($v1DealIds as $dealId) {
                DealMoneyLineRebuilder::rebuildDealId((int) $dealId);
                $rebuilt++;
            }
            $counts['deal_money_lines_rebuilt'] = $rebuilt;

            Log::info('agent.deals_reassigned', [
                'actor_user_id'    => $actorId,
                'source_user_id'   => $source->id,
                'source_user_name' => $source->name,
                'target_user_id'   => $target->id,
                'target_user_name' => $target->name,
                'counts'           => $counts,
            ]);

            return $counts;
        });
    }

    /**
     * Move rows on a table keyed by unique(deal_id, user_id, side) from $source
     * to $target. Where $target already holds the same (deal_id, side) slot, the
     * $source row is dropped instead of moved (soft-deleted if the table has a
     * deleted_at column, hard-deleted otherwise) to avoid a unique-key clash and
     * double-counted commission.
     */
    private function moveAgentSlots(string $table, User $source, User $target, $now): int
    {
        $softDeletes = Schema::hasColumn($table, 'deleted_at');

        $sourceRows = DB::table($table)
            ->where('user_id', $source->id)
            ->when($softDeletes, fn ($q) => $q->whereNull('deleted_at'))
            ->get(['id', 'deal_id', 'side']);

        $changed = 0;

        foreach ($sourceRows as $row) {
            // The unique key (deal_id, user_id, side) spans ALL rows — including
            // soft-deleted ones — so the collision check must NOT filter by
            // deleted_at, or moving onto a soft-deleted target slot would throw a
            // duplicate-key error mid-transaction.
            $collision = DB::table($table)
                ->where('deal_id', $row->deal_id)
                ->where('side', $row->side)
                ->where('user_id', $target->id)
                ->exists();

            if ($collision) {
                if ($softDeletes) {
                    DB::table($table)->where('id', $row->id)
                        ->update(['deleted_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table($table)->where('id', $row->id)->delete();
                }
            } else {
                DB::table($table)->where('id', $row->id)
                    ->update(['user_id' => $target->id, 'updated_at' => $now]);
            }

            $changed++;
        }

        return $changed;
    }
}
