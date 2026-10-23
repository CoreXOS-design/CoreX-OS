<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/documents-menu-tabs.md - Documents > E-Sign Settings: Recipient Presets | Recipient Templates | Finalisation Settings | How my signers sign.
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
            // esign-signing-modes.md step 1 - added on QA1 as a sidebar link while the menus were folded into tabs
            'signing-mode' => ['label' => 'How my signers sign', 'route' => 'docuperfect.esign.settings.signing-mode', 'match' => ['docuperfect.esign.settings.signing-mode*'], 'old' => 'How my signers sign',
                'feature' => $f, 'permissions' => ['esign.settings']],
        ];
    }
}
