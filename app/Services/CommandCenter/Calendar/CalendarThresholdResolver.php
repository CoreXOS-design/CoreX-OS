<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarThresholdResolver
{
    /**
     * Resolve the current urgency colour for an event.
     *
     * @param array|null $overrides  Optional per-event threshold overrides
     *                               (keys: green_days, amber_days, red_days).
     *                               Only threshold day numbers are overridden;
     *                               show_days, is_active, visibility, and
     *                               notifications still come from class config.
     * @return 'red'|'amber'|'green'|null   null = don't show on calendar yet
     */
    public function resolve(?int $agencyId, string $eventClass, ?Carbon $eventDate, ?array $overrides = null): ?string
    {
        if (!$eventDate) {
            return null;
        }

        $config = CalendarEventClassSetting::forAgencyAndClass($agencyId, $eventClass);

        if (!$config || !$config->is_active) {
            return null;
        }

        $daysUntil = (int) now()->startOfDay()->diffInDays($eventDate->copy()->startOfDay(), false);

        // Overdue is always red.
        if ($daysUntil < 0) {
            return 'red';
        }

        // Apply per-event overrides for threshold day numbers only.
        $redDays   = $overrides['red_days']   ?? $config->red_days;
        $amberDays = $overrides['amber_days'] ?? $config->amber_days;
        $greenDays = $overrides['green_days'] ?? $config->green_days;

        if ($daysUntil <= $redDays) {
            return 'red';
        }

        if ($daysUntil <= $amberDays) {
            return 'amber';
        }

        if ($daysUntil <= $greenDays) {
            return 'green';
        }

        // Beyond green threshold — render as neutral (no RAG urgency).
        // show_days is now used only for notification/digest surfacing, not calendar render.
        return 'neutral';
    }

    /**
     * Resolve the colour for a CalendarEvent model directly.
     * Extracts per-event threshold overrides where the source row carries them.
     */
    public function resolveForEvent(CalendarEvent $event): ?string
    {
        $config = CalendarEventClassSetting::forAgencyAndClass($event->agency_id, $event->category ?? '');
        if (!$config || !$config->is_active) {
            return null;
        }

        // Informational events (leave, birthdays, holidays, time-blocks, and any
        // event the user marked "No feedback needed") — always neutral, no RAG
        // progression, never red/overdue. Reads the EFFECTIVE per-event nature
        // (metadata override ?? class default), so a per-event choice is honoured.
        if ($event->isInformational()) {
            return 'neutral';
        }

        // Completed/dismissed events are done — never show as red urgency
        $status = $event->status ?? 'pending';
        if (in_array($status, ['completed', 'dismissed'])) {
            return 'neutral';
        }

        $overrides = $this->extractOverrides($event);

        return $this->resolve(
            $event->agency_id,
            $event->category ?? '',
            $event->event_date ? Carbon::parse($event->event_date) : null,
            $overrides,
        );
    }

    /**
     * Extract per-event threshold overrides from the source row.
     * Currently only deal_step_deadline (per-step RAG columns on
     * deal_step_instances). Returns null when no overrides apply or
     * all override columns are NULL on the source row.
     */
    private function extractOverrides(CalendarEvent $event): ?array
    {
        if ($event->category !== 'deal_step_deadline') {
            return null;
        }
        if (!$event->source_id) {
            return null;
        }

        $row = DB::table('deal_step_instances')
            ->where('id', $event->source_id)
            ->select('rag_green_days', 'rag_amber_days', 'rag_red_days')
            ->first();

        if (!$row) {
            return null;
        }

        $overrides = [];
        if (!is_null($row->rag_green_days)) $overrides['green_days'] = (int) $row->rag_green_days;
        if (!is_null($row->rag_amber_days)) $overrides['amber_days'] = (int) $row->rag_amber_days;
        if (!is_null($row->rag_red_days))   $overrides['red_days']   = (int) $row->rag_red_days;

        return empty($overrides) ? null : $overrides;
    }
}
