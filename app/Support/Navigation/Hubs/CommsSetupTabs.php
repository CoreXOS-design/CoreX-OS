<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/communications-menu-tabs.md - Communications > Setup: My Communication Setup | Email Mailboxes (import) |
 * Email Capture Setup (were spread over the "Capture", "Email" and "My Setup" headings).
 */
final class CommsSetupTabs extends TabHub
{
    public static function label(): string
    {
        return 'Setup';
    }

    public static function tabs(): array
    {
        $f = 'communications';

        return [
            'my-setup' => ['label' => 'My Communication Setup', 'route' => 'my-portal.comm-capture.index', 'old' => 'My Communication Setup',
                'feature' => $f, 'permissions' => ['access_communication']],
            'mailboxes' => ['label' => 'Email Mailboxes (import)', 'route' => 'compliance.comm-mailboxes.index', 'old' => 'Email Mailboxes (import)',
                'feature' => $f, 'permissions' => ['manage_communication_mailboxes']],
            'email-setup' => ['label' => 'Email Capture Setup', 'route' => 'settings.email-setup.index', 'old' => 'Email Capture Setup',
                'feature' => $f, 'permissions' => ['manage_communication_mailboxes']],
        ];
    }
}
