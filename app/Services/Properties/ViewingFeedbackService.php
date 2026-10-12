<?php

namespace App\Services\Properties;

use App\Models\CommandCenter\AgencyFeedbackOption;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE one WRITE path for viewing feedback (R1, R3, R4, R7, R8, R9, R10). Reads go through PropertyViewings.
 * Spec: .ai/specs/calendar-viewing-feedback.md
 *
 *  - canEdit(): permission `viewing_feedback_edit.view` with the Role Manager scope own / branch / all
 *    (own = appointments the user CREATED; branch = their branch, i.e. branch managers; all = admins).
 *    Never a hardcoded role name. Enforced by the controller on every write route.
 *  - save(): ONE row per (appointment, property). It finds the existing row whatever form or kind wrote it
 *    (R10 - a re-save never inserts a duplicate and never hits the unique key), skips a property the agent did
 *    not touch (R7), never overwrites the original capturer / capture time (R4) - an edit stamps
 *    last_edited_by/at - and logs every changed field (old -> new) to calendar_event_feedback_log.
 *  - archive() / restore(): soft delete, restorable, logged (R8). No hard delete anywhere.
 *  - archiveForRemovedProperties(): when a property is taken off an appointment its feedback is archived (R9).
 */
class ViewingFeedbackService
{
    public const PERMISSION = 'viewing_feedback_edit.view';
    public const MODULE = 'viewing_feedback_edit';

    public const FIELD_LABELS = [
        'viewing_status'  => 'Viewing status',
        'outcome'         => 'Outcome',
        'concerns'        => 'Concerns',
        'seller_comment'  => 'Seller comment',
        'internal_comment' => 'Internal comment',
        'next_action'     => 'Next action',
    ];

    /** Can this user capture / edit / archive / restore feedback on this viewing appointment? */
    public function canEdit(User $user, CalendarEvent $event): bool
    {
        if (! in_array($event->category, PropertyViewings::VIEWING_CATEGORIES, true)) {
            return false;
        }
        if (! $user->hasPermission(self::PERMISSION)) {
            return false;
        }
        $scope = PermissionService::mutationScope($user, self::MODULE);
        if ($scope === null) {
            return false;
        }
        // Never across agencies, whatever the scope says.
        $agencyId = $user->effectiveAgencyId();
        if ($event->agency_id && $agencyId && (int) $event->agency_id !== (int) $agencyId) {
            return false;
        }
        // The creator can always edit (own is the narrowest grant; branch / all include it).
        if ($event->created_by_id && (int) $event->created_by_id === (int) $user->id) {
            return true;
        }

        return match ($scope) {
            'all'    => true,
            'branch' => $event->branch_id !== null && $user->branch_id !== null && (int) $event->branch_id === (int) $user->branch_id,
            default  => false,
        };
    }

    /**
     * Save the rows an agent submitted from the feedback form.
     *
     * @param  array<int,array<string,mixed>> $rows each: property_id, viewing_status?, outcome_id?, concern_ids?,
     *                                              seller_visible_notes?, internal_notes?, next_action_notes?
     * @return array{touched: array<int,int>, skipped: array<int,int>, saved: array<int,int>}
     *         touched = property ids whose feedback was created or changed; saved = property id => feedback id
     */
    public function save(CalendarEvent $event, User $user, array $rows): array
    {
        $touched = [];
        $skipped = [];
        $saved = [];
        $buyerContactId = $this->eventBuyerContactId($event);
        $options = AgencyFeedbackOption::withoutGlobalScopes()->pluck('label', 'id');

        DB::transaction(function () use ($event, $user, $rows, $buyerContactId, $options, &$touched, &$skipped, &$saved) {
            foreach ($rows as $row) {
                $propertyId = (int) ($row['property_id'] ?? 0);
                if ($propertyId <= 0) {
                    continue;
                }
                $in = $this->normaliseInput($row);
                $existing = $this->findExisting($event, $propertyId, $buyerContactId);

                // R7: a property the agent did not touch is never saved as a row.
                if ($existing === null && $this->isBlank($in)) {
                    $skipped[] = $propertyId;
                    continue;
                }

                if ($existing === null) {
                    $fb = CalendarEventFeedback::withoutGlobalScopes()->create([
                        'calendar_event_id'    => $event->id,
                        'contact_id'           => $buyerContactId,
                        'property_id'          => $propertyId,
                        'feedback_kind'        => 'viewing',
                        'visibility'           => 'public_to_seller',
                        'viewing_status'       => $in['viewing_status'],
                        'outcome_option_id'    => $in['outcome_id'],
                        'concern_option_ids'   => $in['concern_ids'],
                        'seller_visible_notes' => $in['seller_comment'],
                        'internal_notes'       => $in['internal_comment'],
                        'next_action_notes'    => $in['next_action'],
                        'captured_by_user_id'  => $user->id,
                        'captured_at'          => now(),
                        'agency_id'            => $event->agency_id,
                        'branch_id'            => $event->branch_id,
                    ]);
                    $this->logFields($fb, $user, 'created', $this->currentValues($fb, $options, true), $options);
                    $touched[$propertyId] = $propertyId;
                    $saved[$propertyId] = (int) $fb->id;
                    continue;
                }

                $wasArchived = $existing->trashed();
                $before = $this->currentValues($existing, $options);
                if ($wasArchived) {
                    $existing->restore();
                    $existing->archived_by_user_id = null;
                    $existing->archive_reason = null;
                    $this->logRow($existing, $user, 'restored', null, null, null, 'restored by saving feedback');
                }
                $existing->fill([
                    'viewing_status'       => $in['viewing_status'],
                    'outcome_option_id'    => $in['outcome_id'],
                    'concern_option_ids'   => $in['concern_ids'],
                    'seller_visible_notes' => $in['seller_comment'],
                    'internal_notes'       => $in['internal_comment'],
                    'next_action_notes'    => $in['next_action'],
                ]);
                // Rows written by the old forms get the real kind / visibility and the property they belong to.
                if ($existing->feedback_kind !== 'viewing') {
                    $existing->feedback_kind = 'viewing';
                }
                if ($existing->visibility !== 'public_to_seller') {
                    $existing->visibility = 'public_to_seller';
                }
                if ($existing->property_id === null) {
                    $existing->property_id = $propertyId;
                }
                $after = $this->currentValues($existing, $options, false, $in);
                $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));

                if ($changed === [] && ! $wasArchived && ! $existing->isDirty()) {
                    $saved[$propertyId] = (int) $existing->id;
                    continue; // an unchanged re-save writes nothing and logs nothing
                }
                if ($changed !== [] || $wasArchived) {
                    // R4: the original capturer and capture time stay; only "last edited" moves.
                    $existing->last_edited_by_user_id = $user->id;
                    $existing->last_edited_at = now();
                }
                $existing->save();
                foreach ($changed as $field) {
                    $this->logRow($existing, $user, 'edited', $field, $before[$field] ?? null, $after[$field] ?? null);
                }
                if ($changed !== [] || $wasArchived) {
                    $touched[$propertyId] = $propertyId;
                }
                $saved[$propertyId] = (int) $existing->id;
            }
        });

        return ['touched' => array_values($touched), 'skipped' => $skipped, 'saved' => $saved];
    }

    /** R8: soft delete, restorable, logged. */
    public function archive(CalendarEventFeedback $fb, User $user, string $reason = 'archived by agent'): void
    {
        if ($fb->trashed()) {
            return;
        }
        DB::transaction(function () use ($fb, $user, $reason) {
            $fb->archived_by_user_id = $user->id;
            $fb->archive_reason = mb_substr($reason, 0, 60);
            $fb->save();
            $fb->delete();
            $this->logRow($fb, $user, 'archived', null, null, null, $reason);
        });
    }

    public function restore(CalendarEventFeedback $fb, User $user): void
    {
        if (! $fb->trashed()) {
            return;
        }
        DB::transaction(function () use ($fb, $user) {
            $fb->restore();
            $fb->archived_by_user_id = null;
            $fb->archive_reason = null;
            $fb->last_edited_by_user_id = $user->id;
            $fb->last_edited_at = now();
            $fb->save();
            $this->logRow($fb, $user, 'restored', null, null, null, null);
        });
    }

    /** R9: a property taken off the appointment stops counting - its feedback is archived, never deleted. */
    public function archiveForRemovedProperties(CalendarEvent $event, array $removedPropertyIds, ?User $user = null): int
    {
        $removedPropertyIds = array_values(array_unique(array_map('intval', $removedPropertyIds)));
        if ($removedPropertyIds === []) {
            return 0;
        }
        $n = 0;
        $rows = CalendarEventFeedback::withoutGlobalScopes()
            ->where('calendar_event_id', $event->id)
            ->whereIn('property_id', $removedPropertyIds)
            ->get();
        foreach ($rows as $fb) {
            DB::transaction(function () use ($fb, $user) {
                $fb->archived_by_user_id = $user?->id;
                $fb->archive_reason = 'property removed from appointment';
                $fb->save();
                $fb->delete();
                $this->logRow($fb, $user, 'archived', null, null, null, 'property removed from appointment');
            });
            $n++;
        }

        return $n;
    }

    /**
     * R2: what the appointment panel shows, per property: status, outcome, concern ticks, seller comment, internal
     * comment, who captured + when, who last edited + when; archived rows (for editors) and the change history.
     * Agent-side only - this carries internal comments.
     *
     * @return array<string,mixed>
     */
    public function panelData(CalendarEvent $event, User $user): array
    {
        $canEdit = $this->canEdit($user, $event);
        $propertyIds = DB::table('calendar_event_links')
            ->where('calendar_event_id', $event->id)
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('role', 'subject_property')
            ->whereNull('deleted_at')
            ->pluck('linkable_id')->map(fn ($i) => (int) $i)->all();
        $properties = Property::withoutGlobalScopes()->whereIn('id', $propertyIds)->get();

        $rows = CalendarEventFeedback::withoutGlobalScopes()->withTrashed()
            ->where('calendar_event_id', $event->id)->get();
        $log = DB::table('calendar_event_feedback_log')->where('calendar_event_id', $event->id)->orderByDesc('id')->limit(60)->get();

        $options = AgencyFeedbackOption::withoutGlobalScopes()->pluck('label', 'id');
        $userIds = $rows->pluck('captured_by_user_id')->merge($rows->pluck('last_edited_by_user_id'))->merge($log->pluck('user_id'))->unique()->filter();
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->pluck('name', 'id');

        $single = count($propertyIds) === 1;
        $out = [];
        foreach ($properties as $p) {
            $mine = $rows->filter(fn ($r) => (int) $r->property_id === (int) $p->id || ($single && $r->property_id === null));
            $live = $mine->filter(fn ($r) => ! $r->trashed())->map(fn ($r) => PropertyViewings::capture($r))
                ->reject(fn ($c) => $c['blank'])->sortBy('id');
            $archived = $mine->filter(fn ($r) => $r->trashed())->map(fn ($r) => PropertyViewings::capture($r));
            $status = $live->isEmpty() ? PropertyViewings::STATUS_VIEWED : $live->sortByDesc(fn ($c) => [$c['sort_key'], $c['id']])->first()['status'];

            $out[] = [
                'property_id'    => (int) $p->id,
                'label'          => method_exists($p, 'buildDisplayAddress') ? $p->buildDisplayAddress() : ($p->title ?? "Property #{$p->id}"),
                'viewing_status' => $status,
                'status_label'   => PropertyViewings::STATUS_LABELS[$status],
                'captures'       => $live->map(fn ($c) => $this->times(PropertyViewings::present($c, $options, $users)))->values()->all(),
                'archived'       => $canEdit
                    ? $archived->map(fn ($c) => $this->times(PropertyViewings::present($c, $options, $users)) + [
                        'archived_by' => $rows->firstWhere('id', $c['id'])?->archived_by_user_id
                            ? $users->get($rows->firstWhere('id', $c['id'])->archived_by_user_id) ?? null : null,
                    ])->values()->all()
                    : [],
                'history'        => $log->filter(fn ($l) => (int) $l->property_id === (int) $p->id)->take(25)->map(fn ($l) => [
                    'action'    => $l->action,
                    'field'     => $l->field ? (self::FIELD_LABELS[$l->field] ?? $l->field) : null,
                    'old'       => $l->old_value,
                    'new'       => $l->new_value,
                    'note'      => $l->note,
                    'by'        => $l->user_id ? ($users->get($l->user_id) ?? null) : null,
                    'when'      => \Carbon\Carbon::parse($l->created_at)->format('j M Y, H:i'),
                ])->values()->all(),
            ];
        }

        return ['can_edit' => $canEdit, 'properties' => $out];
    }

    // ── internals ────────────────────────────────────────────────────────

    /** Dates as display strings for the JSON the appointment panel and form consume. */
    private function times(array $d): array
    {
        foreach (['captured_at', 'last_edited_at'] as $k) {
            $d[$k] = $d[$k] ? \Carbon\Carbon::parse($d[$k])->format('j M Y, H:i') : null;
        }

        return $d;
    }

    /** @return array{viewing_status:string,outcome_id:?int,concern_ids:array<int,int>,seller_comment:?string,internal_comment:?string,next_action:?string} */
    private function normaliseInput(array $row): array
    {
        $status = $row['viewing_status'] ?? PropertyViewings::STATUS_VIEWED;
        if (! in_array($status, PropertyViewings::STATUSES, true)) {
            $status = PropertyViewings::STATUS_VIEWED;
        }
        $trim = function ($v) {
            $v = trim((string) ($v ?? ''));

            return $v === '' ? null : $v;
        };
        $concerns = collect($row['concern_ids'] ?? [])->filter()->map(fn ($i) => (int) $i)->unique()->sort()->values()->all();

        return [
            'viewing_status'   => $status,
            'outcome_id'       => ! empty($row['outcome_id']) ? (int) $row['outcome_id'] : null,
            'concern_ids'      => $concerns,
            'seller_comment'   => $trim($row['seller_visible_notes'] ?? null),
            'internal_comment' => $trim($row['internal_notes'] ?? null),
            'next_action'      => $trim($row['next_action_notes'] ?? null),
        ];
    }

    private function isBlank(array $in): bool
    {
        return $in['viewing_status'] === PropertyViewings::STATUS_VIEWED && $in['outcome_id'] === null && $in['concern_ids'] === []
            && $in['seller_comment'] === null && $in['internal_comment'] === null && $in['next_action'] === null;
    }

    /**
     * Find the row for (appointment, property) whatever form / kind wrote it. Live rows win over archived ones;
     * among live rows the one for the appointment's buyer wins, else the latest.
     */
    private function findExisting(CalendarEvent $event, int $propertyId, ?int $buyerContactId): ?CalendarEventFeedback
    {
        $linkedCount = DB::table('calendar_event_links')->where('calendar_event_id', $event->id)
            ->where('linkable_type', 'App\\Models\\Property')->where('role', 'subject_property')->whereNull('deleted_at')->count();

        $rows = CalendarEventFeedback::withoutGlobalScopes()->withTrashed()
            ->where('calendar_event_id', $event->id)
            ->where(function ($q) use ($propertyId, $linkedCount) {
                $q->where('property_id', $propertyId);
                if ($linkedCount <= 1) {
                    $q->orWhereNull('property_id'); // an old single-property row that never named its property
                }
            })
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $live = $rows->reject(fn ($r) => $r->trashed());
        $pool = $live->isNotEmpty() ? $live : $rows;

        return $pool->sortByDesc(fn ($r) => [
            $buyerContactId && (int) $r->contact_id === $buyerContactId ? 1 : 0,
            $r->property_id !== null ? 1 : 0,
            $r->id,
        ])->first();
    }

    private function eventBuyerContactId(CalendarEvent $event): ?int
    {
        if ($event->contact_id) {
            return (int) $event->contact_id;
        }
        $id = DB::table('calendar_event_links')
            ->where('calendar_event_id', $event->id)
            ->where('linkable_type', 'App\\Models\\Contact')
            ->whereIn('role', ['buyer_contact', 'attendee'])
            ->whereNull('deleted_at')
            ->value('linkable_id');

        return $id ? (int) $id : null;
    }

    /**
     * The loggable view of a row: field key => human value (null when empty).
     *
     * @param array|null $override  when given, take the incoming values (used to diff before the save)
     * @return array<string,?string>
     */
    private function currentValues(CalendarEventFeedback $fb, Collection $options, bool $nonEmptyOnly = false, ?array $override = null): array
    {
        if ($override !== null) {
            $status = $override['viewing_status'];
            $outcome = $override['outcome_id'];
            $concerns = $override['concern_ids'];
            $seller = $override['seller_comment'];
            $internal = $override['internal_comment'];
            $next = $override['next_action'];
        } else {
            $status = in_array($fb->viewing_status, PropertyViewings::STATUSES, true) ? $fb->viewing_status : PropertyViewings::STATUS_VIEWED;
            $outcome = $fb->outcome_option_id ? (int) $fb->outcome_option_id : null;
            $concerns = collect($fb->concern_option_ids ?? [])->filter()->map(fn ($i) => (int) $i)->unique()->sort()->values()->all();
            $s = trim((string) $fb->seller_visible_notes);
            $i = trim((string) $fb->internal_notes);
            $n = trim((string) $fb->next_action_notes);
            $seller = $s !== '' ? $s : null;
            $internal = $i !== '' ? $i : null;
            $next = $n !== '' ? $n : null;
        }

        $vals = [
            'viewing_status'   => PropertyViewings::STATUS_LABELS[$status],
            'outcome'          => $outcome ? ($options->get($outcome) ?? ('#' . $outcome)) : null,
            'concerns'         => $concerns ? collect($concerns)->map(fn ($id) => $options->get($id) ?? ('#' . $id))->implode(', ') : null,
            'seller_comment'   => $seller,
            'internal_comment' => $internal,
            'next_action'      => $next,
        ];
        // A brand-new "Viewed" status is the default and not worth a log line on its own.
        if ($nonEmptyOnly && $status === PropertyViewings::STATUS_VIEWED) {
            unset($vals['viewing_status']);
        }

        return $nonEmptyOnly ? array_filter($vals, fn ($v) => $v !== null) : $vals;
    }

    private function logFields(CalendarEventFeedback $fb, ?User $user, string $action, array $values, Collection $options): void
    {
        foreach ($values as $field => $value) {
            $this->logRow($fb, $user, $action, $field, null, $value);
        }
    }

    private function logRow(CalendarEventFeedback $fb, ?User $user, string $action, ?string $field, ?string $old, ?string $new, ?string $note = null): void
    {
        DB::table('calendar_event_feedback_log')->insert([
            'feedback_id'       => $fb->id,
            'calendar_event_id' => $fb->calendar_event_id,
            'property_id'       => $fb->property_id,
            'contact_id'        => $fb->contact_id,
            'agency_id'         => $fb->agency_id,
            'action'            => $action,
            'field'             => $field,
            'old_value'         => $old,
            'new_value'         => $new,
            'note'              => $note !== null ? mb_substr($note, 0, 120) : null,
            'user_id'           => $user?->id,
            'created_at'        => now(),
        ]);
    }
}
