<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/documents-menu-tabs.md - Documents > E-Sign Settings: Recipient Presets | Recipient Templates | Finalisation Settings.
 */
final class ESignSettingsTabs extends TabHub
{
    public static function label(): string
    {
        return 'E-Sign Settings';
    }

    public static function tabs(): array
    {
        $f = 'docuperfect';

        return [
            'presets' => ['label' => 'Recipient Presets', 'route' => 'docuperfect.esign.recipient-presets.index', 'old' => 'Recipient Presets',
                'feature' => $f, 'permissions' => ['esign.settings']],
            'recipient-templates' => ['label' => 'Recipient Templates', 'route' => 'docuperfect.recipient-templates.index', 'old' => 'Recipient Templates',
                'feature' => $f, 'permissions' => ['esign.settings']],
            'finalisation' => ['label' => 'Finalisation Settings', 'route' => 'docuperfect.esign.settings.finalization', 'match' => ['docuperfect.esign.settings.finalization*'], 'old' => 'Finalisation Settings',
                'feature' => $f, 'permissions' => ['esign.settings']],
        ];
    }
}
