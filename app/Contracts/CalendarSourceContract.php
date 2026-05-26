<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * A calendar source service that produces calendar events for a domain.
 *
 * Phase 1 source services implement this. The reconciliation command
 * iterates registered sources nightly and ensures calendar_events stays
 * in sync with their output.
 */
interface CalendarSourceContract
{
    /**
     * Return all calendar events this source currently produces.
     *
     * Each array MUST contain: event_type, category, title, event_date,
     * source_type, source_id, agency_id, branch_id, user_id.
     *
     * The reconciliation command treats (source_type, source_id, category)
     * as the unique key when reconciling.
     */
    public function syncAll(): Collection;

    /**
     * Human-readable name for logging.
     */
    public function name(): string;
}
