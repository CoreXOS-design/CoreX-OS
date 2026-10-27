<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsParkReason;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsProspectEvent;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 - the ONLY writer of rts_prospects and rts_prospect_events (road-to-sold.md section 22).
 *
 *   place()        take a property for an agent (the allocation: one agent per property; null when another agent owns it)
 *   advance()      move to a later stage - forward only, once per (prospect, stage, subject); a lost deal resets explicitly
 *   applyOutcome() a call outcome sets the next action, the due date, the stage and (when the rules say so) parks
 *   park/unpark    dead stock with a reason and a revisit date; it comes back as a follow-up on that date
 *   release/close  the only ways an allocation ends
 *   dueFollowUps() prospects the agent should hear about today (a next action due, or a parked item whose date has come)
 *
 * Every method answers for the LIST OWNER (the agent an assistant acts for). Nothing here throws to the caller for an unknown
 * property: it answers null / false. Every state change writes one history line (append-only).
 */
class ProspectService
{
    /** next_action_key values that mean "nothing is scheduled". */
    public const NO_ACTION = ['none', 'dropped', Def::ACTION_NEVER_AGAIN];

    /** What a next action reads as on the page. */
    public const ACTION_LABELS = [
        Def::ACTION_AFTER_DAYS        => 'Call again',
        Def::ACTION_PICKED_DATE       => 'Follow up with the seller',
        Def::ACTION_EXPIRY_ELSE       => 'Follow up at the mandate expiry',
        Def::ACTION_EXPIRY_ELSE_DAYS  => 'Check the mandate expiry',
        Def::ACTION_AFTER_MONTHS      => 'Follow up with the seller',
        Def::ACTION_FIND_OWNER        => 'Find the owner',
        Def::ACTION_APPOINTMENT       => 'Do the evaluation',
        Def::ACTION_MOVE_TO_LIST      => 'Find the right contact',
    ];

    public function __construct(private readonly ParkReasonService $reasons)
    {
    }

    // ── reading ──────────────────────────────────────────────────────────

    private function base(int $agencyId, string $track)
    {
        return RtsProspect::withoutGlobalScope(AgencyScope::class)->where('rts_prospects.agency_id', $agencyId)->where('rts_prospects.track', $track);
    }

    /** The prospect row for a property, whoever owns it (null when there is none). */
    public function find(int $agencyId, int $trackedPropertyId, string $track = 'sales', bool $withTrashed = false): ?RtsProspect
    {
        $q = $this->base($agencyId, $track)->where('tracked_property_id', $trackedPropertyId);

        return ($withTrashed ? $q->withTrashed() : $q)->first();
    }

    /** The agent a property is allocated to right now (null = free). */
    public function ownerOf(int $agencyId, int $trackedPropertyId, string $track = 'sales'): ?int
    {
        $p = $this->find($agencyId, $trackedPropertyId, $track);

        return $p && $p->closed_at === null && $p->released_at === null ? (int) $p->user_id : null;
    }

    /**
     * Of these tracked properties, the ones allocated to a DIFFERENT agent than $agent (the never-offer-twice rule, 22.6).
     *
     * @param  int[] $trackedIds
     * @return int[]
     */
    public function ownedByOthers(User $agent, array $trackedIds, string $track = 'sales'): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', $trackedIds))));
        if ($agencyId <= 0 || $ids === []) {
            return [];
        }

        return $this->base($agencyId, $track)->owned()->whereIn('tracked_property_id', $ids)
            ->where('user_id', '!=', (int) $agent->ownershipUserId())
            ->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    // ── allocation ───────────────────────────────────────────────────────

    /**
     * Take a property for the agent. Returns the prospect, or null when another agent owns it (the caller then takes its next candidate).
     * Idempotent for the same agent. A property that was closed or released is free again and restarts at "found".
     *
     * @param array{prospecting_listing_id?:?int,property_id?:?int,selection?:?string,reason?:?string,reason_facts?:?array,stage?:string,actor?:?User} $attrs
     */
    public function place(User $agent, int $trackedPropertyId, string $track = 'sales', array $attrs = []): ?RtsProspect
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $ownerId = (int) $agent->ownershipUserId();
        if ($agencyId <= 0 || $trackedPropertyId <= 0 || $ownerId <= 0) {
            return null;
        }
        $stage = ProspectStages::exists((string) ($attrs['stage'] ?? '')) ? $attrs['stage'] : ProspectStages::FOUND;

        $attempt = function () use ($agencyId, $ownerId, $agent, $trackedPropertyId, $track, $attrs, $stage) {
            return DB::transaction(function () use ($agencyId, $ownerId, $agent, $trackedPropertyId, $track, $attrs, $stage) {
                $row = $this->base($agencyId, $track)->withTrashed()->where('tracked_property_id', $trackedPropertyId)->lockForUpdate()->first();

                if ($row && ! $row->trashed() && $row->closed_at === null && $row->released_at === null) {
                    if ((int) $row->user_id !== $ownerId) {
                        return null;   // somebody else's
                    }
                    $fill = array_filter([
                        'prospecting_listing_id' => $row->prospecting_listing_id ?: ($attrs['prospecting_listing_id'] ?? null),
                        'property_id'            => $row->property_id ?: ($attrs['property_id'] ?? null),
                    ]);
                    if ($fill !== []) {
                        $row->forceFill($fill)->save();
                    }

                    return $row;
                }

                $values = [
                    'agency_id' => $agencyId, 'track' => $track, 'user_id' => $ownerId, 'branch_id' => $agent->effectiveBranchId(),
                    'tracked_property_id' => $trackedPropertyId,
                    'prospecting_listing_id' => $attrs['prospecting_listing_id'] ?? null,
                    'property_id' => $attrs['property_id'] ?? null,
                    'stage' => $stage, 'stage_entered_at' => now(),
                    'selection' => $attrs['selection'] ?? null,
                    'reason' => isset($attrs['reason']) ? mb_substr((string) $attrs['reason'], 0, 255) : null,
                    'reason_facts' => $attrs['reason_facts'] ?? null,
                    'next_action_key' => ProspectStages::defaultNext($stage) ? 'stage' : null,
                    'next_action_label' => ProspectStages::defaultNext($stage),
                    'next_action_at' => null, 'tries' => 0, 'last_outcome_id' => null,
                    'parked_at' => null, 'parked_until' => null, 'park_reason_id' => null, 'park_reason_label' => null,
                    'park_note' => null, 'parked_from_stage' => null,
                    'closed_at' => null, 'closed_reason' => null, 'released_at' => null, 'released_by_user_id' => null,
                ];

                if ($row) {            // free again: restart the same row for the new agent
                    if ($row->trashed()) {
                        $row->restore();
                    }
                    $row->forceFill($values)->save();
                } else {
                    $row = RtsProspect::withoutAgencyStamping(fn () => RtsProspect::create($values));
                }
                $this->log($row, RtsProspectEvent::PLACED, null, $stage, $attrs['actor'] ?? null, ['note' => $attrs['selection'] ?? null]);

                return $row;
            });
        };

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            // two builds took the same property at the same moment: the other one won; answer from what is there now
            $row = $this->find($agencyId, $trackedPropertyId, $track);

            return $row && (int) $row->user_id === $ownerId && $row->closed_at === null && $row->released_at === null ? $row : null;
        }
    }

    // ── stages ───────────────────────────────────────────────────────────

    /**
     * Move to a later stage. Forward only; the same (stage, subject) twice writes nothing; an earlier stage is refused unless $allowBack
     * (a lost deal). Returns true when the prospect moved. A parked prospect that makes real progress is brought back first.
     *
     * @param array{event_class?:string,subject_type?:string,subject_id?:int,note?:string,next_at?:?CarbonInterface,next_label?:?string,meta?:array} $source
     */
    public function advance(RtsProspect $p, string $stage, ?User $actor = null, array $source = [], bool $allowBack = false): bool
    {
        if (! ProspectStages::exists($stage) || $p->closed_at !== null || $p->released_at !== null) {
            return false;
        }
        $from = (string) $p->stage;
        if (! $allowBack && ProspectStages::order($stage) <= ProspectStages::order($from)) {
            return false;
        }

        return (bool) DB::transaction(function () use ($p, $stage, $from, $actor, $source) {
            $p->refresh();
            try {
                $this->log($p, RtsProspectEvent::STAGE, $from, $stage, $actor, $source);
            } catch (UniqueConstraintViolationException) {
                return false;   // already recorded for this event
            }
            $label = array_key_exists('next_label', $source) ? $source['next_label'] : ProspectStages::defaultNext($stage);
            $p->forceFill([
                'stage' => $stage, 'stage_entered_at' => now(), 'tries' => 0,
                'next_action_key' => $label ? 'stage' : null, 'next_action_label' => $label,
                'next_action_at' => $source['next_at'] ?? null,
                'parked_at' => null, 'parked_until' => null, 'park_reason_id' => null, 'park_reason_label' => null,
                'park_note' => null, 'parked_from_stage' => null,
            ])->save();
            if ($stage === ProspectStages::REGISTERED) {
                $this->close($p, 'registered', $actor);
            }

            return true;
        });
    }

    /** Set (or clear) the next action by hand. */
    public function setNextAction(RtsProspect $p, ?string $label, ?CarbonInterface $at, ?User $actor = null, string $key = 'manual'): void
    {
        $p->forceFill([
            'next_action_key' => $label === null ? null : $key, 'next_action_label' => $label,
            'next_action_at' => $at ? Carbon::instance($at)->startOfDay() : null,
        ])->save();
        $this->log($p, RtsProspectEvent::NEXT_ACTION, $p->stage, $p->stage, $actor, ['note' => $label]);
    }

    // ── outcomes ─────────────────────────────────────────────────────────

    /**
     * A recorded call outcome decides what happens next (22.7): the next action and its date, the try counter, the stage, and a park when
     * the outcome (or its try limit) says so. Called by OutcomeService inside its transaction; $o is already saved.
     */
    public function applyOutcome(RtsProspect $p, RtsContactOutcome $o, Def $def, ?User $actor = null): void
    {
        $previousKey = $p->last_outcome_id ? RtsContactOutcome::withoutGlobalScopes()->whereKey($p->last_outcome_id)->value('outcome_key') : null;
        $tries = $def->max_tries ? (($previousKey === $def->key ? (int) $p->tries : 0) + 1) : 0;

        $key = (string) $o->next_action;
        $none = in_array($key, self::NO_ACTION, true);
        $p->forceFill([
            'last_outcome_id' => $o->id, 'tries' => $tries,
            'next_action_key' => $none ? null : $key,
            'next_action_label' => $none ? null : (self::ACTION_LABELS[$key] ?? $def->label),
            'next_action_at' => $none ? null : $o->next_action_at,
        ])->save();
        $this->log($p, RtsProspectEvent::OUTCOME, $p->stage, $p->stage, $actor, ['outcome_id' => $o->id, 'note' => $def->label]);

        if ($def->stage_after && ProspectStages::exists($def->stage_after)) {
            $this->advance($p, $def->stage_after, $actor, ['event_class' => 'outcome', 'subject_type' => 'rts_contact_outcome', 'subject_id' => $o->id, 'next_label' => $none ? null : ($p->next_action_label), 'next_at' => $o->next_action_at]);
            $p->refresh();
            // advance() sets the stage's default action; the outcome's own next action wins
            $p->forceFill([
                'next_action_key' => $none ? null : $key,
                'next_action_label' => $none ? null : (self::ACTION_LABELS[$key] ?? $def->label),
                'next_action_at' => $none ? null : $o->next_action_at,
            ])->save();
        }

        // park: at once (the outcome says so) or when the try limit is reached
        if ($def->park_reason_key && ! $def->max_tries) {
            $this->park($p, $def->park_reason_key, $o->next_action_at, null, $actor);
        } elseif ($def->max_tries && $tries >= (int) $def->max_tries) {
            if ($def->after_max_tries === Def::AFTER_PARK) {
                $this->park($p, $def->park_reason_key ?: 'other', null, $def->label . ' x' . $tries, $actor);
            } elseif ($def->after_max_tries === Def::AFTER_MANAGER_REPORT) {
                $p->forceFill(['next_action_key' => 'dropped', 'next_action_label' => null, 'next_action_at' => null])->save();
            }
        }
        if ($o->dropped) {
            $p->forceFill(['next_action_key' => 'dropped', 'next_action_label' => null, 'next_action_at' => null])->save();
        }
        if ($def->records_do_not_contact) {
            $this->close($p, 'do_not_contact', $actor);
        }
    }

    // ── parking, releasing, closing ──────────────────────────────────────

    /**
     * Set a prospect aside with a reason (a stable key from the agency's list, or a reason row) and a revisit date
     * (default: the reason's own interval). It returns on that date as a follow-up (dueFollowUps).
     */
    public function park(RtsProspect $p, string|RtsParkReason $reason, ?CarbonInterface $until = null, ?string $note = null, ?User $actor = null): RtsProspect
    {
        $r = $reason instanceof RtsParkReason ? $reason : $this->reasons->byKey((int) $p->agency_id, (string) $p->track, $reason);
        $days = (int) ($r?->default_revisit_days ?: 90);
        $date = $until ? Carbon::instance($until)->startOfDay() : now()->startOfDay()->addDays(max(1, $days));
        if ($date->lte(now()->startOfDay())) {
            $date = now()->startOfDay()->addDay();
        }

        $p->forceFill([
            'parked_at' => now(), 'parked_until' => $date, 'park_reason_id' => $r?->id, 'park_reason_label' => $r?->label ?? (is_string($reason) ? $reason : null),
            'park_note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'parked_from_stage' => $p->parked_from_stage ?: $p->stage,
            'next_action_key' => null, 'next_action_label' => null, 'next_action_at' => null,
        ])->save();
        $this->log($p, RtsProspectEvent::PARK, $p->stage, $p->stage, $actor, ['note' => $p->park_reason_label, 'meta' => ['until' => $date->toDateString()]]);

        return $p;
    }

    /** Bring a parked prospect back: it returns at the stage it left, with a follow-up due today. */
    public function unpark(RtsProspect $p, ?User $actor = null): RtsProspect
    {
        if ($p->parked_at === null) {
            return $p;
        }
        $p->forceFill([
            'parked_at' => null, 'parked_until' => null, 'park_reason_id' => null, 'park_reason_label' => null, 'park_note' => null,
            'parked_from_stage' => null,
            'next_action_key' => 'follow_up', 'next_action_label' => 'Follow up with the seller', 'next_action_at' => now()->startOfDay(),
        ])->save();
        $this->log($p, RtsProspectEvent::UNPARK, $p->stage, $p->stage, $actor);

        return $p;
    }

    /** End the allocation so the property can be offered again (a manager, or an agent who has gone). */
    public function release(RtsProspect $p, ?User $actor = null, ?string $note = null): RtsProspect
    {
        if ($p->released_at === null && $p->closed_at === null) {
            $p->forceFill(['released_at' => now(), 'released_by_user_id' => $actor?->id])->save();
            $this->log($p, RtsProspectEvent::RELEASE, $p->stage, $p->stage, $actor, ['note' => $note]);
        }

        return $p;
    }

    /** The prospect's story is over (registered, lost, do not contact). A property flagged do-not-contact stays closed for everyone. */
    public function close(RtsProspect $p, string $reason, ?User $actor = null): RtsProspect
    {
        if ($p->closed_at === null) {
            $p->forceFill(['closed_at' => now(), 'closed_reason' => mb_substr($reason, 0, 40), 'next_action_key' => null, 'next_action_label' => null, 'next_action_at' => null])->save();
            $this->log($p, RtsProspectEvent::CLOSE, $p->stage, $p->stage, $actor, ['note' => $reason]);
        }

        return $p;
    }

    // ── what is due ──────────────────────────────────────────────────────

    /**
     * Prospects of the agent that should come back on or before $day: a next action with a date that has arrived, or a parked prospect
     * whose revisit date has come. Oldest first.
     *
     * @return Collection<int,RtsProspect>
     */
    public function dueFollowUps(User $agent, CarbonInterface $day, string $track = 'sales'): Collection
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return collect();
        }
        $d = Carbon::instance($day)->toDateString();

        return $this->base($agencyId, $track)->owned()->where('user_id', (int) $agent->ownershipUserId())
            ->where(function ($w) use ($d) {
                $w->where(fn ($a) => $a->whereNull('parked_at')->whereNotNull('next_action_at')->whereDate('next_action_at', '<=', $d)
                        ->whereNotIn('next_action_key', ['dropped', 'none', Def::ACTION_NEVER_AGAIN]))
                  ->orWhere(fn ($b) => $b->whereNotNull('parked_at')->whereNotNull('parked_until')->whereDate('parked_until', '<=', $d));
            })
            ->orderByRaw('COALESCE(parked_until, next_action_at)')->orderBy('id')->get();
    }

    // ── history ──────────────────────────────────────────────────────────

    /**
     * One history line. Throws UniqueConstraintViolationException for a (prospect, kind, stage, subject) already written - the caller
     * treats that as "already done".
     *
     * @param array{event_class?:?string,subject_type?:?string,subject_id?:?int,note?:?string,outcome_id?:?int,meta?:?array} $x
     */
    private function log(RtsProspect $p, string $kind, ?string $from, ?string $to, ?User $actor, array $x = []): void
    {
        RtsProspectEvent::withoutAgencyStamping(fn () => RtsProspectEvent::create([
            'agency_id' => $p->agency_id, 'track' => $p->track, 'prospect_id' => $p->id, 'user_id' => $p->user_id,
            'actor_user_id' => $actor?->id, 'occurred_at' => now(), 'kind' => $kind, 'from_stage' => $from, 'to_stage' => $to,
            'outcome_id' => $x['outcome_id'] ?? null, 'event_class' => isset($x['event_class']) ? mb_substr((string) $x['event_class'], 0, 190) : null,
            'subject_type' => $x['subject_type'] ?? null, 'subject_id' => $x['subject_id'] ?? null,
            'note' => isset($x['note']) ? mb_substr((string) $x['note'], 0, 255) : null, 'meta' => $x['meta'] ?? null,
        ]));
    }
}
