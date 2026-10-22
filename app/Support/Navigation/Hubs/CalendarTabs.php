<?php

namespace App\Support\Navigation\Hubs;

use App\Models\CommandCenter\CalendarEventInvitation;
use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/dashboard-menu-tabs.md - Dashboard > Calendar: Calendar | Invitations. The pending-invitation count that sat on the old
 * Invitations item sits on its tab and on the Calendar item.
 */
final class CalendarTabs extends TabHub
{
    public static function label(): string
    {
        return 'Calendar';
    }

    public static function tabs(): array
    {
        return [
            'calendar' => ['label' => 'Calendar', 'route' => 'command-center.calendar', 'old' => null,
                'feature' => null, 'permissions' => ['command_center.calendar.view']],
            // the old Invitations item checked nothing - copied as-is
            'invitations' => ['label' => 'Invitations', 'route' => 'command-center.calendar.invitations', 'match' => ['command-center.calendar.invitations*'], 'old' => 'Invitations',
                'feature' => null, 'permissions' => []],
        ];
    }

    /** Pending invitations for this user - the count the old Invitations item carried. */
    public static function badge(string $key, User $user): ?int
    {
        return $key === 'invitations' ? CalendarEventInvitation::forUser($user->id)->pending()->count() : null;
    }

    public static function badgeColor(): string
    {
        return '#ef4444'; // the old Invitations item's red count
    }
}
