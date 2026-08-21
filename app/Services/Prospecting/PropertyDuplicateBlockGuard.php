<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\User;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * Single authorisation checkpoint for every hard block (active_blocked, no_go).
 * Johan asked whether an admin should be able to override a block and has NOT
 * confirmed yet — this exists so that decision, once made, is ONE function to
 * change, not blocking logic scattered through DeedsCaptureController. Today it
 * always refuses; do not add override behaviour here until Johan confirms.
 */
class PropertyDuplicateBlockGuard
{
    public function authorizeOverride(User $user, string $band): bool
    {
        return false;
    }
}
