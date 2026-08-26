<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * Value object describing one suggested-action chip rendered on a prospecting
 * row. Output of SuggestedActionResolver::resolve().
 *
 * Spec: .ai/specs/build-e-suggested-action-chips-spec.md §8.3.
 *
 * The DTO is intentionally view-friendly: $tier maps to the four visual
 * tiers in spec §6.2, $icon to one of four lucide icons, and $clickType
 * tells the view partial which of $href / $modalKey / $alpineCall to use.
 *
 * tooltipHtml is server-rendered safe HTML composed by the resolver — never
 * user-supplied. Numeric values inside it are pre-formatted; the view emits
 * it through {!! !!}.
 *
 * 2026-08-14 — statusBadgeLabel/statusBadgeTier (both optional): R2/R4 used
 * to make the WARNING itself the (broken) click target — the whole chip was
 * "CLAIM EXPIRES SOON" with a dead alpine dispatch nothing on the MIC page
 * ever listened for. Now the primary chip is the real "Continue" CTA (same
 * anchor pattern PITCH NOW uses), and when these two fields are set the view
 * renders a small, non-interactive secondary badge next to it carrying the
 * original warning text — the urgency stays visible, but it's no longer the
 * (only, dead) thing you can click.
 */
final class SuggestedAction
{
    public function __construct(
        public readonly string  $rank,        // 'R1'..'R10' (R10 = always-on Pitch Now fallback)
        public readonly string  $label,       // 'PITCH NOW · HIGH'
        public readonly string  $tier,        // 'critical'|'action'|'await'|'info'
        public readonly string  $icon,        // 'alarm-clock'|'target'|'clock'|'info'
        public readonly string  $tooltipHtml, // safe HTML
        public readonly string  $clickType,   // 'anchor'|'modal'|'alpine'
        public readonly ?string $href = null,        // when clickType='anchor'
        public readonly ?string $modalKey = null,    // when clickType='modal'
        public readonly ?string $alpineCall = null,  // when clickType='alpine'
        public readonly ?string $statusBadgeLabel = null, // e.g. 'CLAIM EXPIRES SOON'
        public readonly ?string $statusBadgeTier = null,  // reuses the same tier palette, text-only
    ) {}
}
