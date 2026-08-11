<?php

namespace App\Services\Performance\Providers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-B — appointments booked per agent (category 9): the actionable calendar
 * classes that occupy an agent's diary (viewings, presentations, evaluations,
 * meetings), as opposed to marker/reminder events.
 */
class AppointmentsProvider extends AbstractCountMetricProvider
{
    private const APPOINTMENT_CATEGORIES = ['viewing', 'listing_presentation', 'property_evaluation', 'meeting'];

    public function key(): string { return 'appointments'; }
    public function label(): string { return 'Appointments'; }
    protected function table(): string { return 'calendar_events'; }
    protected function userColumn(): string { return 'user_id'; }
    protected function periodColumn(): string { return 'event_date'; }
    protected function baseQuery(): Builder
    {
        return DB::table('calendar_events')->whereIn('category', self::APPOINTMENT_CATEGORIES);
    }
}
