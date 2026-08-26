<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Models\ProspectingClaim;
use Tests\TestCase;   // boots the app so now()/datetime casts resolve (no DB used)

/**
 * MIC funnel phase 2 — ProspectingClaim staleness helpers (Johan 2026-08-13).
 * Pure model logic, no DB: staleness clock = last_updated_at (fallback claimed_at); warn/release
 * gates honour is_active, released_at, and the warn dedup.
 */
final class StaleClaimHelpersTest extends TestCase
{
    private function claim(array $attrs): ProspectingClaim
    {
        $c = new ProspectingClaim();
        $c->is_active = $attrs['is_active'] ?? true;
        $c->released_at = $attrs['released_at'] ?? null;
        $c->warned_at = $attrs['warned_at'] ?? null;
        $c->last_updated_at = $attrs['last_updated_at'] ?? null;
        $c->claimed_at = $attrs['claimed_at'] ?? null;
        return $c;
    }

    public function test_stale_age_uses_last_updated_then_claimed(): void
    {
        $this->assertSame(8, $this->claim(['last_updated_at' => now()->subDays(8)])->staleAgeDays());
        // No last_updated_at → falls back to claimed_at.
        $this->assertSame(12, $this->claim(['claimed_at' => now()->subDays(12)])->staleAgeDays());
    }

    public function test_needs_warning_at_or_past_warn_days_only_when_unwarned(): void
    {
        $warn = 7;
        $this->assertTrue($this->claim(['last_updated_at' => now()->subDays(7)])->needsStaleWarning($warn));
        $this->assertTrue($this->claim(['last_updated_at' => now()->subDays(9)])->needsStaleWarning($warn));
        // Under the line → no warn.
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(3)])->needsStaleWarning($warn));
        // Already warned → dedup, no re-warn.
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(9), 'warned_at' => now()->subDay()])->needsStaleWarning($warn));
        // Released / inactive → never.
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(9), 'released_at' => now()])->needsStaleWarning($warn));
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(9), 'is_active' => false])->needsStaleWarning($warn));
    }

    public function test_stale_for_review_at_or_past_release_days(): void
    {
        $release = 10;
        $this->assertTrue($this->claim(['last_updated_at' => now()->subDays(10)])->isStaleForReview($release));
        $this->assertTrue($this->claim(['last_updated_at' => now()->subDays(20)])->isStaleForReview($release));
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(8)])->isStaleForReview($release));
        // A warned-but-not-yet-released claim (8 days, warn 7) is NOT yet in review.
        $this->assertFalse($this->claim(['last_updated_at' => now()->subDays(8)])->isStaleForReview($release));
    }
}
