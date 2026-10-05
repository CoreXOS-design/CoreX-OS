<?php

namespace App\Services\Platform;

use App\Events\Platform\AgencyTimelineMilestoneCompleted;
use App\Events\Platform\AgencyTimelineStarted;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineDefaultItem;
use App\Models\Platform\AgencyTimelineEvent;
use App\Models\Platform\AgencyTimelineItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that changes an agency timeline goes through here so that every
 * change is recorded (agency_timeline_events). Spec: agency-timeline-and-platform-esign.md §7.
 */
class AgencyTimelineService
{
    /** @var string[] merge fields usable in timeline text */
    public const MERGE_FIELDS = ['agency_name', 'start_date', 'go_live_date', 'billing_start_date'];

    // ── Start ──────────────────────────────────────────────────────────────

    /**
     * Snapshot the current defaults onto a new timeline for $agency.
     * One timeline per agency: a second start throws.
     *
     * @param  array<int,string>  $dateOverrides  default-item id => Y-m-d, set on the Start screen
     *                                            (replaces the offset-computed date for that step)
     */
    public function start(Agency $agency, CarbonInterface $startDate, ?int $userId = null, array $dateOverrides = []): AgencyTimeline
    {
        if (AgencyTimeline::where('agency_id', $agency->id)->exists()) {
            throw new \DomainException('This agency already has a timeline.');
        }

        $timeline = DB::transaction(function () use ($agency, $startDate, $userId, $dateOverrides) {
            $timeline = AgencyTimeline::create([
                'agency_id'  => $agency->id,
                'token'      => Str::random(48),
                'start_date' => $startDate->toDateString(),
                'status'     => AgencyTimeline::STATUS_RUNNING,
                'started_by' => $userId,
            ]);

            $defaults = AgencyTimelineDefaultItem::orderBy('kind')->orderBy('sort_order')->orderBy('id')->get();
            foreach ($defaults as $d) {
                AgencyTimelineItem::create([
                    'timeline_id'           => $timeline->id,
                    'kind'                  => $d->kind,
                    'title'                 => $d->title,
                    'body'                  => $d->body,
                    'sort_order'            => $d->sort_order,
                    'offset_days'           => $d->offset_days,
                    'due_date'              => $d->kind !== 'milestone' ? null
                        : ($dateOverrides[$d->id] ?? ($d->offset_days !== null
                            ? Carbon::parse($startDate)->addDays($d->offset_days)->toDateString() : null)),
                    'is_public'             => $d->is_public,
                    'is_go_live'            => $d->is_go_live,
                    'auto_complete_trigger' => $d->auto_complete_trigger,
                    'source_default_id'     => $d->id,
                ]);
            }

            $this->log($timeline, null, 'started', 'Timeline started with start date ' . $startDate->format('j M Y'), null, ['start_date' => $startDate->toDateString()], $userId);

            return $timeline;
        });

        event(new AgencyTimelineStarted($agency->id, $timeline->id, $userId));

        return $timeline;
    }

    /** Preview the dates a start would produce (used by the Start screen). */
    public function previewDates(CarbonInterface $startDate): array
    {
        return AgencyTimelineDefaultItem::where('kind', 'milestone')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($d) => [
                'id'     => $d->id,
                'offset' => (int) $d->offset_days,
                'title' => $d->title,
                'date'  => Carbon::parse($startDate)->addDays((int) $d->offset_days),
                'live'  => (bool) $d->is_go_live,
            ])->all();
    }

    // ── Item changes (each one recorded) ──────────────────────────────────

    public function addCustomItem(AgencyTimeline $timeline, array $data, ?int $userId): AgencyTimelineItem
    {
        $kind = $data['kind'];
        $max = (int) AgencyTimelineItem::where('timeline_id', $timeline->id)->where('kind', $kind)->max('sort_order');

        $item = AgencyTimelineItem::create([
            'timeline_id' => $timeline->id,
            'kind'        => $kind,
            'title'       => $data['title'],
            'body'        => $data['body'] ?? null,
            'due_date'    => $kind === 'milestone' ? ($data['due_date'] ?? null) : null,
            'is_public'   => (bool) ($data['is_public'] ?? true),
            'is_custom'   => true,
            'sort_order'  => $max + 10,
        ]);

        $this->log($timeline, $item->id, 'item_added', 'Added custom ' . $kind . ': ' . $item->title, null, $this->snapshot($item), $userId);

        return $item;
    }

    public function updateItem(AgencyTimelineItem $item, array $data, ?int $userId): AgencyTimelineItem
    {
        $before = $this->snapshot($item);
        $item->fill(array_intersect_key($data, array_flip(['title', 'body', 'due_date', 'is_public'])));
        if (!$item->isMilestone()) {
            $item->due_date = null;
        }
        $dateMoved = $item->isDirty('due_date');
        $dirty = array_keys($item->getDirty());
        if (!$dirty) {
            return $item;
        }
        $item->save();

        $summary = $dateMoved
            ? 'Moved "' . $item->title . '" from ' . ($before['due_date'] ?? 'no date') . ' to ' . ($item->due_date?->toDateString() ?? 'no date')
            : 'Edited "' . $item->title . '" (' . implode(', ', $dirty) . ')';
        $this->log($item->timeline, $item->id, $dateMoved ? 'date_moved' : 'item_edited', $summary, $before, $this->snapshot($item), $userId);

        return $item;
    }

    public function setStatus(AgencyTimelineItem $item, string $status, ?int $userId, string $source = 'manual'): AgencyTimelineItem
    {
        if (!in_array($status, ['pending', 'done', 'skipped'], true) || $item->status === $status) {
            return $item;
        }
        $before = ['status' => $item->status];
        $item->status = $status;
        $item->completed_at = $status === 'pending' ? null : now();
        $item->completed_by = $status === 'pending' ? null : $userId;
        $item->completed_source = $status === 'pending' ? null : $source;
        $item->save();

        $verb = ['pending' => 'Reopened', 'done' => 'Completed', 'skipped' => 'Skipped'][$status];
        $this->log($item->timeline, $item->id, 'status_' . $status, $verb . ' "' . $item->title . '"' . ($source !== 'manual' ? ' (automatic: ' . $source . ')' : ''), $before, ['status' => $status], $userId, $source);

        if ($status === 'done') {
            event(new AgencyTimelineMilestoneCompleted($item->timeline->agency_id, $item->timeline_id, $item->id, $source, $userId));
        }

        return $item;
    }

    public function archiveItem(AgencyTimelineItem $item, ?int $userId): void
    {
        $item->delete();
        $this->log($item->timeline, $item->id, 'item_archived', 'Archived "' . $item->title . '"', null, null, $userId);
    }

    public function restoreItem(AgencyTimelineItem $item, ?int $userId): void
    {
        $item->restore();
        $this->log($item->timeline, $item->id, 'item_restored', 'Restored "' . $item->title . '"', null, null, $userId);
    }

    /** Move an item one place up/down among items of its own kind. */
    public function move(AgencyTimelineItem $item, string $direction, ?int $userId): void
    {
        $siblings = AgencyTimelineItem::where('timeline_id', $item->timeline_id)->where('kind', $item->kind)
            ->orderBy('sort_order')->orderBy('id')->get()->values();
        $i = $siblings->search(fn ($s) => $s->id === $item->id);
        $j = $direction === 'up' ? $i - 1 : $i + 1;
        if ($i === false || !isset($siblings[$j])) {
            return;
        }
        $a = $siblings[$i];
        $b = $siblings[$j];
        // Equal sort_orders would make a swap a no-op, so renumber the whole set first.
        foreach ($siblings as $n => $s) {
            $s->sort_order = ($n + 1) * 10;
        }
        [$a->sort_order, $b->sort_order] = [$b->sort_order, $a->sort_order];
        $siblings->each->save();
        $this->log($item->timeline, $item->id, 'reordered', 'Moved "' . $item->title . '" ' . $direction, null, null, $userId);
    }

    /**
     * Change the start date. When $shift is true every not-yet-done dated
     * milestone moves by the same number of days (default behaviour in the UI).
     */
    public function changeStartDate(AgencyTimeline $timeline, CarbonInterface $new, bool $shift, ?int $userId): void
    {
        $old = Carbon::parse($timeline->start_date);
        $days = (int) $old->diffInDays($new, false);
        if ($days === 0) {
            return;
        }
        DB::transaction(function () use ($timeline, $new, $shift, $days, $old, $userId) {
            $timeline->update(['start_date' => $new->toDateString()]);
            $moved = 0;
            if ($shift) {
                AgencyTimelineItem::where('timeline_id', $timeline->id)->where('kind', 'milestone')
                    ->where('status', 'pending')->whereNotNull('due_date')->get()
                    ->each(function ($m) use ($days, &$moved) {
                        $m->update(['due_date' => $m->due_date->copy()->addDays($days)->toDateString()]);
                        $moved++;
                    });
            }
            $this->log($timeline, null, 'start_date_changed',
                'Start date changed from ' . $old->format('j M Y') . ' to ' . $new->format('j M Y') . ($shift ? " ({$moved} open dates shifted by {$days} days)" : ' (dates not shifted)'),
                ['start_date' => $old->toDateString()], ['start_date' => $new->toDateString(), 'shifted' => $moved], $userId);
        });
    }

    /** Put every milestone that came from a default back to start_date + its offset. */
    public function resetDates(AgencyTimeline $timeline, ?int $userId): int
    {
        $n = 0;
        AgencyTimelineItem::where('timeline_id', $timeline->id)->where('kind', 'milestone')->whereNotNull('offset_days')->get()
            ->each(function ($m) use ($timeline, &$n) {
                $due = Carbon::parse($timeline->start_date)->addDays($m->offset_days)->toDateString();
                if (!$m->due_date || $m->due_date->toDateString() !== $due) {
                    $m->update(['due_date' => $due]);
                    $n++;
                }
            });
        $this->log($timeline, null, 'dates_reset', "Reset {$n} dates to the defaults", null, null, $userId);

        return $n;
    }

    public function setLinkEnabled(AgencyTimeline $timeline, bool $enabled, ?int $userId): void
    {
        $timeline->update(['public_link_enabled' => $enabled]);
        $this->log($timeline, null, $enabled ? 'link_enabled' : 'link_disabled', 'Public link ' . ($enabled ? 'enabled' : 'disabled'), null, null, $userId);
    }

    public function regenerateToken(AgencyTimeline $timeline, ?int $userId): void
    {
        $timeline->update(['token' => Str::random(48)]);
        $this->log($timeline, null, 'link_regenerated', 'Public link regenerated (the old link no longer works)', null, null, $userId);
    }

    public function markLive(AgencyTimeline $timeline, ?int $userId): void
    {
        $timeline->update(['status' => AgencyTimeline::STATUS_LIVE, 'live_at' => now()]);
        $this->log($timeline, null, 'marked_live', 'Agency marked live', null, null, $userId);
    }

    public function setPaused(AgencyTimeline $timeline, bool $paused, ?int $userId): void
    {
        $timeline->update(['status' => $paused ? AgencyTimeline::STATUS_PAUSED : AgencyTimeline::STATUS_RUNNING, 'live_at' => null]);
        $this->log($timeline, null, $paused ? 'paused' : 'resumed', $paused ? 'Timeline paused' : 'Timeline resumed', null, null, $userId);
    }

    // ── Agreement (platform e-sign document) ───────────────────────────────

    /** Link (or unlink with null) the platform e-sign document that is this agency's agreement. */
    public function linkAgreement(AgencyTimeline $timeline, ?int $templateId, ?int $userId): void
    {
        $before = $timeline->agreement_template_id;
        $timeline->update(['agreement_template_id' => $templateId]);
        $this->log($timeline, null, 'agreement_linked', $templateId ? 'Linked e-sign document #' . $templateId : 'Unlinked the e-sign agreement',
            ['agreement_template_id' => $before], ['agreement_template_id' => $templateId], $userId);
        $this->syncAgreement($timeline);
    }

    /**
     * If the linked e-sign document is fully signed, fire AgencyContractSigned so the
     * `contract_signed` steps tick. Idempotent (listener only touches pending items),
     * and called whenever a timeline is read, so no hook inside the e-sign is needed.
     */
    public function syncAgreement(AgencyTimeline $timeline): void
    {
        if (!$timeline->agreement_template_id) {
            return;
        }
        $signed = \App\Models\Docuperfect\SignatureTemplate::withoutGlobalScopes()
            ->where('id', $timeline->agreement_template_id)
            ->where('status', \App\Models\Docuperfect\SignatureTemplate::STATUS_COMPLETED)
            ->exists();
        if ($signed) {
            event(new \App\Events\Platform\AgencyContractSigned($timeline->agency_id, (int) $timeline->agreement_template_id, null));
        }
    }

    // ── Reading ───────────────────────────────────────────────────────────

    /** Display state of one milestone relative to $today. */
    public function state(AgencyTimelineItem $m, CarbonInterface $today): string
    {
        if ($m->status === 'done') {
            return 'done';
        }
        if ($m->status === 'skipped') {
            return 'skipped';
        }
        if ($m->due_date && $m->due_date->lt($today->copy()->startOfDay())) {
            return 'overdue';
        }

        return 'upcoming';
    }

    public function daysOverdue(AgencyTimelineItem $m, CarbonInterface $today): int
    {
        return $m->due_date && $m->status === 'pending' && $m->due_date->lt($today->copy()->startOfDay())
            ? (int) $m->due_date->diffInDays($today->copy()->startOfDay()) : 0;
    }

    /**
     * Planned vs expected go-live. An open milestone that is late pushes the
     * expected live date out by the worst number of days late (Johan, 2026-10-05:
     * "overdue … push the live date further on"). Only milestones due on or before
     * the planned go-live count; the go-live item itself is never its own slip.
     *
     * @return array{planned:?Carbon, expected:?Carbon, slip_days:int}
     */
    public function goLive(AgencyTimeline $timeline, ?CarbonInterface $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $items = AgencyTimelineItem::where('timeline_id', $timeline->id)->where('kind', 'milestone')->get();
        $live = $items->firstWhere('is_go_live', true);
        if (!$live || !$live->due_date) {
            return ['planned' => null, 'expected' => null, 'slip_days' => 0];
        }

        $slip = $items->filter(fn ($m) => !$m->is_go_live && $m->due_date && $m->due_date->lte($live->due_date))
            ->map(fn ($m) => $this->daysOverdue($m, $today))->max() ?? 0;
        $planned = $live->due_date->copy();

        return ['planned' => $planned, 'expected' => $planned->copy()->addDays($slip), 'slip_days' => (int) $slip];
    }

    /** Replace {{merge fields}} in timeline text. Unknown fields are left visible, never silently blank. */
    public function mergeText(?string $text, AgencyTimeline $timeline, ?array $goLive = null): string
    {
        $goLive ??= $this->goLive($timeline);
        $live = ($goLive['expected'] ?? $goLive['planned']);
        $map = [
            'agency_name'        => $timeline->agency?->name ?? 'your agency',
            'start_date'         => Carbon::parse($timeline->start_date)->format('j F Y'),
            'go_live_date'       => $live ? $live->format('j F Y') : 'the go-live date',
            'billing_start_date' => $live ? $live->copy()->addDay()->format('j F Y') : 'the day after go-live',
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $map[$m[1]] ?? $m[0], (string) $text);
    }

    // ── Recording ─────────────────────────────────────────────────────────

    public function log(AgencyTimeline $timeline, ?int $itemId, string $event, string $summary, ?array $before, ?array $after, ?int $userId, string $source = 'manual'): void
    {
        AgencyTimelineEvent::create([
            'timeline_id'   => $timeline->id,
            'item_id'       => $itemId,
            'event'         => $event,
            'summary'       => Str::limit($summary, 490, ''),
            'before'        => $before,
            'after'         => $after,
            'actor_user_id' => $userId,
            'source'        => $source,
        ]);
    }

    private function snapshot(AgencyTimelineItem $i): array
    {
        return [
            'title' => $i->title, 'body' => $i->body,
            'due_date' => $i->due_date?->toDateString(), 'is_public' => $i->is_public,
        ];
    }
}
