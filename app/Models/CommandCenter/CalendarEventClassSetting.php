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
    ];

    public const NATURE_ACTIONABLE    = 'actionable';
    public const NATURE_INFORMATIONAL = 'informational';

    protected $casts = [
        'is_active'             => 'boolean',
        'daily_digest_enabled'  => 'boolean',
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
