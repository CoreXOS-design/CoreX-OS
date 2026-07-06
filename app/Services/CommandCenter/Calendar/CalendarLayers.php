<?php

namespace App\Services\CommandCenter\Calendar;

use App\Models\AgencyContactSettings;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\User;

/**
 * AT-164 Gate 6 — the layer-toggle taxonomy. ONE classifier shared by the grid
 * (data-layer tags for instant client-side show/hide), the Deck's Notifications
 * tile (server-side filter), and the persistence endpoint. Layers derive from
 * event_type; appointments (occupies_time=true) are their own layer regardless of
 * type; birthdays / leave / office-closure fall under Personal (off by default).
 */
class CalendarLayers
{
    /** Ordered UI layers (key + plain-English label — STANDARDS F.8). */
    public const LAYERS = [
        ['key' => 'appointments', 'label' => 'Appointments'],
        ['key' => 'deal',         'label' => 'Deals'],
        ['key' => 'document',     'label' => 'Documents'],
        ['key' => 'lease',        'label' => 'Rent & Lease'],
        ['key' => 'property',     'label' => 'Listings'],
        ['key' => 'people',       'label' => 'People'],
        ['key' => 'compliance',   'label' => 'Compliance'],
        ['key' => 'payroll',      'label' => 'Payroll'],
        ['key' => 'recurring',    'label' => 'Recurring'],
        ['key' => 'personal',     'label' => 'Personal'],
    ];

    /** Deadline categories that belong to the Personal layer (off by default). */
    public const PERSONAL_CATEGORIES = [
        'agent_birthday', 'contact_birthday', 'leave_annual', 'leave_sick', 'office_closure',
    ];

    private const TYPE_LAYERS = ['deal', 'document', 'lease', 'property', 'people', 'compliance', 'payroll', 'recurring'];

    /** All valid layer keys. */
    public static function allKeys(): array
    {
        return array_column(self::LAYERS, 'key');
    }

    /** The layer an event belongs to. Appointment species → 'appointments'. */
    public static function layerFor($event, bool $isAppointment): string
    {
        if ($isAppointment) {
            return 'appointments';
        }
        if (in_array($event->category, self::PERSONAL_CATEGORIES, true)) {
            return 'personal';
        }
        return self::layerForType((string) ($event->event_type ?? ''));
    }

    /** The layer for a bare event_type (used for aggregate deadline groups). */
    public static function layerForType(string $type): string
    {
        return in_array($type, self::TYPE_LAYERS, true) ? $type : 'appointments';
    }

    /**
     * The active layer set for a user: an explicit request override → the per-user
     * saved set → the agency default. Sanitised to known keys.
     *
     * @param  string[]|null  $requestLayers
     * @return string[]
     */
    public static function resolveActive(User $user, ?array $requestLayers = null): array
    {
        if (is_array($requestLayers) && ! empty($requestLayers)) {
            $active = $requestLayers;
        } else {
            $pref = CalendarUserPreference::where('user_id', $user->id)->value('calendar_layers');
            if (is_array($pref)) {
                $active = $pref;
            } else {
                $active = AgencyContactSettings::forAgency($user->effectiveAgencyId() ?? 1)->calendarDefaultLayers();
            }
        }

        $active = array_values(array_intersect(array_map('strval', $active), self::allKeys()));
        return $active;
    }

    /** Sanitise a layer set to known keys WITHOUT persisting (used by the explicit-save path). */
    public static function clean(array $layers): array
    {
        return array_values(array_intersect(array_map('strval', $layers), self::allKeys()));
    }

    /** Persist a user's active layer set (sanitised). Returns the stored set. */
    public static function save(User $user, array $layers): array
    {
        $clean = self::clean($layers);
        $pref = CalendarUserPreference::firstOrNew(['user_id' => $user->id]);
        $pref->calendar_layers = $clean;
        $pref->save();

        return $clean;
    }
}
