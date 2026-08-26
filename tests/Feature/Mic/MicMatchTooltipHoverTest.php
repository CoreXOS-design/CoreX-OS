<?php

namespace Tests\Feature\Mic;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-08-14 — locks in the "Ellie's read" buyer-match hover tooltip fix on
 * the Market Intelligence worklist (Work tab, per-row demand microbar).
 *
 * Root cause: `load()` set `tooltip` once on first hover and nothing ever
 * cleared it — x-show="tooltip || loading" stayed permanently true, leaving
 * a blank card stuck on top of the row(s) below with no close mechanism
 * (no mouseleave handler, no click-outside, no Escape key). Reported by
 * Johan as "blankly cover the other property rows... no way to close them."
 *
 * Fix: an `open` flag driven by mouseenter/mouseleave (+ focusin/focusout
 * for keyboard users) now gates visibility — a true hover-reveal, transient
 * tooltip — while the fetched text stays cached per-listing so re-hovering
 * doesn't re-fetch. max-height/overflow-y caps the box so a long AI
 * sentence can't grow into a multi-row-covering block.
 *
 * The row partial (_listing-row.blade.php) only renders the tooltip markup
 * for rows with buyer-tier matches, which the shared test DB doesn't
 * guarantee — so the row-partial assertions here read the source file
 * directly (deterministic regardless of DB state), while the JS contract
 * (always inline on the Work tab response) is asserted via HTTP.
 */
class MicMatchTooltipHoverTest extends TestCase
{
    public function test_work_tab_ships_hover_gated_tooltip_js_contract(): void
    {
        $agent = $this->resolveTestAgent();
        if ($agent === null) {
            $this->markTestSkipped('No active agent user in the test DB.');
        }
        if (!$agent->hasPermission('access_prospecting')) {
            $this->markTestSkipped('Test agent lacks access_prospecting permission.');
        }
        $this->actingAs($agent);

        $response = $this->get(route('market-intelligence.work'));
        $response->assertOk();

        // The old one-way latch (load() with no open/close pair) must never
        // reappear — show()/hide() driven by an `open` flag is the contract.
        $response->assertSee('show()', false);
        $response->assertSee('hide()', false);
        $response->assertSee('open: false', false);
        $response->assertSee('this.open = true', false);
        $response->assertSee('this.open = false', false);
    }

    public function test_listing_row_partial_wires_mouseleave_and_gated_visibility(): void
    {
        $path = resource_path('views/corex/market-intelligence/_listing-row.blade.php');
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        // Hover-reveal must be a real pair — show on enter, hide on leave —
        // not just a one-shot mouseenter with nothing to close it.
        $this->assertStringContainsString('@mouseenter="show()"', $source);
        $this->assertStringContainsString('@mouseleave="hide()"', $source);
        $this->assertStringContainsString('@focusin="show()"', $source);
        $this->assertStringContainsString('@focusout="hide()"', $source);

        // Visibility must be gated on `open`, not just on tooltip/loading
        // content ever having been fetched once.
        $this->assertStringContainsString('x-show="open && (tooltip || loading)"', $source);

        // Compact + capped so a long AI sentence can't grow into a
        // multi-row-covering block.
        $this->assertStringContainsString('max-height: 120px', $source);
        $this->assertStringContainsString('overflow-y: auto', $source);
    }

    public function test_work_blade_js_no_longer_has_the_uncleared_latch(): void
    {
        $path = resource_path('views/corex/market-intelligence/work.blade.php');
        $this->assertFileExists($path);
        $source = file_get_contents($path);

        // The old bug: a bare `load()` with a `loaded` guard and nothing to
        // ever flip `tooltip` back to hidden must not reappear as the public
        // entry point — `show()`/`hide()` are the entry points now.
        $this->assertStringContainsString('show() {', $source);
        $this->assertStringContainsString('hide() {', $source);
        $this->assertStringContainsString('ensureLoaded()', $source);
    }

    /**
     * Resolve an active agent/admin user from the test DB. Returns null
     * when the DB is empty (CI cold-start) or unreachable (local dev
     * without the dedicated test database provisioned) — the caller marks
     * itself skipped. Mirrors MicSmokeTest's resolver.
     */
    private function resolveTestAgent(): ?User
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return null;
        }

        try {
            return User::query()
                ->where('is_active', true)
                ->whereIn('role', ['admin', 'super_admin', 'branch_manager', 'agent'])
                ->whereNotNull('agency_id')
                ->orderByRaw("FIELD(role, 'super_admin', 'admin', 'branch_manager', 'agent')")
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
