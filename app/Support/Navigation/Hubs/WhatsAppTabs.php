<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/communications-menu-tabs.md - Communications > WhatsApp: My WhatsApp Consent | WhatsApp Consent — Review |
 * WhatsApp Capture (Browser Extension) | Link My WhatsApp. "Link My WhatsApp" was a menu link to the Tools section of My Portal (the
 * self-link QR lives there) - it stays exactly that: a tab that jumps to My Portal > Tools, never claiming My Portal as its screen.
 */
final class WhatsAppTabs extends TabHub
{
    public static function label(): string
    {
        return 'WhatsApp';
    }

    public static function tabs(): array
    {
        $f = 'communications';

        return [
            'my-consent' => ['label' => 'My WhatsApp Consent', 'route' => 'communications.capture.my', 'old' => 'My WhatsApp Consent',
                'feature' => $f, 'permissions' => ['access_communication']],
            'consent-review' => ['label' => 'WhatsApp Consent — Review', 'route' => 'communications.capture.review', 'old' => 'WhatsApp Consent — Review',
                'feature' => $f, 'permissions' => ['communications.capture_review', 'access_communication']],
            'extension' => ['label' => 'WhatsApp Capture (Browser Extension)', 'route' => 'communications.wa-devices.index', 'match' => ['communications.wa-devices.*'], 'old' => 'WhatsApp Capture (Browser Extension)',
                'feature' => $f, 'permissions' => ['access_communication']],
            'link' => ['label' => 'Link My WhatsApp', 'route' => 'agent.portal', 'fragment' => 'tools', 'match' => [], 'old' => 'Link My WhatsApp',
                'feature' => $f, 'permissions' => ['access_communication', 'access_my_portal']],
        ];
    }
}
