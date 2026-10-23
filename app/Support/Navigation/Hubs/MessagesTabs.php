<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/communications-menu-tabs.md - Communications > Messages: Review Incoming Messages | Message Archive | Flagged Messages |
 * Archive Access Requests | To File (attorney emails). All sat inside the sidebar's @feature('communications') block.
 */
final class MessagesTabs extends TabHub
{
    public static function label(): string
    {
        return 'Messages';
    }

    public static function tabs(): array
    {
        $f = 'communications';

        return [
            'incoming' => ['label' => 'Review Incoming Messages', 'route' => 'communications.triage.index', 'old' => 'Review Incoming Messages',
                'feature' => $f, 'permissions' => ['triage_communications']],
            'archive' => ['label' => 'Message Archive', 'route' => 'compliance.comm-archive.index', 'old' => 'Message Archive',
                'feature' => $f, 'permissions' => ['access_communication_archive']],
            'flagged' => ['label' => 'Flagged Messages', 'route' => 'compliance.comm-flags.index', 'old' => 'Flagged Messages',
                'feature' => $f, 'permissions' => ['view_communication_flag_register']],
            'access-requests' => ['label' => 'Archive Access Requests', 'route' => 'corex.comms-access.inbox', 'old' => 'Archive Access Requests',
                'feature' => $f, 'permissions' => ['communications.view']],
            'to-file' => ['label' => 'To File (attorney emails)', 'route' => 'corex.comms-suspense.index', 'old' => 'To File (attorney emails)',
                'feature' => $f, 'permissions' => ['deal_comms_suspense.view']],
        ];
    }
}
