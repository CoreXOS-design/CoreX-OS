<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CalendarEventClassSetting extends Model
{
    use BelongsToAgency;

    protected $table = 'calendar_event_class_settings';

    protected $fillable = [
        'agency_id', 'event_class', 'is_active', 'event_nature',
        'green_days', 'amber_days', 'red_days', 'show_days',
        'green_visibility', 'amber_visibility', 'red_visibility',
        'green_notifications', 'amber_notifications', 'red_notifications',
        'daily_digest_enabled', 'daily_digest_roles',
        'label', 'description',
        // Added by migration 2026_05_05_000019 but never added to $fillable,
        // so CalendarEventClassSeeder's updateOrCreate silently dropped it
        // (mass-assignment) — every class defaulted to false and the
        // migration's "viewing => true" was unreproducible on a fresh seed.
        'allow_multiple_properties',
        // Added by migration 2026_05_06_000001 (actor_role +
        // completion_behaviour). Same migration-before-seeder defect as
        // allow_multiple_properties: the migration's per-class UPDATE is a
        // no-op on a fresh DB (class rows don't exist yet) and these were
        // never in $fillable, so every class fell back to the column
        // defaults ('neither' / 'freeform'). With 'viewing' stuck on
        // 'freeform' the panel never offered "Capture Feedback to
        // Complete". CalendarEventClassSeeder now reasserts the map.
        'actor_role', 'completion_behaviour',
        // occupies_time (2026_07_02_000001) — explicit appointment flag,
        // decoupled from actor_role. true = occupies a slot (conflicts);
        // false = marker/reminder (never conflicts).
        'occupies_time',
        // CAL-7 Class 6 — every migration column that the app is meant to
        // set must appear here. Previously omitted columns silently
        // dropped their mass-assigned values (Model::create([col=>X])
        // -> row got the DB default). The regression test at
        // tests/Feature/Calendar/CalendarEventClassSettingFillableTest.php
        // compares this list against Schema::getColumnListing and fails
        // CI on any divergence — so the next contributor adding a
        // migration is forced to update both sides.
        //   feedback_mode  — 2026_05_11_094044
        //   buyer_facing   — 2026_05_05_000021
        'feedback_mode',
        'buyer_facing',
    ];

    public const NATURE_ACTIONABLE    = 'actionable';
    public const NATURE_INFORMATIONAL = 'informational';

    protected $casts = [
        'is_active'             => 'boolean',
        'daily_digest_enabled'  => 'boolean',
        'allow_multiple_properties' => 'boolean',
        'occupies_time'         => 'boolean',
        'green_days'            => 'integer',
        'amber_days'            => 'integer',
        'red_days'              => 'integer',
        'show_days'             => 'integer',
        'green_visibility'      => 'array',
        'amber_visibility'      => 'array',
        'red_visibility'        => 'array',
        'green_notifications'   => 'array',
        'amber_notifications'   => 'array',
        'red_notifications'     => 'array',
        'daily_digest_roles'    => 'array',
    ];

    /**
     * Resolve the effective config for an agency + event_class.
     * Returns the agency-specific row if it exists, otherwise the global
     * default (agency_id IS NULL).
     *
     * Bypasses the BelongsToAgency global scope to allow fallback to
     * global defaults (NULL agency_id rows).
     */
    public static function forAgencyAndClass(?int $agencyId, string $eventClass): ?self
    {
        $query = self::withoutGlobalScopes()
            ->where('event_class', $eventClass);

        if ($agencyId !== null) {
            $agencyRow = (clone $query)->where('agency_id', $agencyId)->first();
            if ($agencyRow) return $agencyRow;
        }

        return $query->whereNull('agency_id')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get visibility roles for a given colour.
     */
    public function visibilityFor(string $colour): array
    {
        return $this->{$colour . '_visibility'} ?? [];
    }

    /**
     * Get notification routing for a given colour.
     */
    public function notificationsFor(string $colour): array
    {
        return $this->{$colour . '_notifications'} ?? [];
    }
}
