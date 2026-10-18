<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * Sidebar drill-down integrity — static gate on resources/views/layouts/corex-sidebar.blade.php.
 *
 * Incident 2026-10-07: on a fresh tab (no saved scroll) /corex opened inside the
 * "Deal Register Settings" sub-menu. Cause: the x-init scroll-restore called
 * scrollIntoView() on the active "Today" link, which lives in the CLOSED Dashboard
 * panel; scrollIntoView scrolls every ancestor, including the overflow:hidden
 * .corex-nav-viewport, sideways — exposing the topmost off-screen panel (the last one
 * in the DOM). Introduced by 039010cbc (2026-09-30).
 *
 * Source-level on purpose (no DB, no browser): it pins the structural rules; the click-through
 * is proven in a real browser separately (PHPUnit cannot see a panel).
 */
final class SidebarDrillDownIntegrityTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->src = file_get_contents(dirname(__DIR__, 3) . '/resources/views/layouts/corex-sidebar.blade.php');
    }

    /** @return array<int,string> */
    private function toggleKeys(): array
    {
        preg_match_all('/<button type="button" @click="push\(\'([^\']+)\'\)"/', $this->src, $m);
        return $m[1];
    }

    /** @return array<int,string> */
    private function panelKeys(): array
    {
        preg_match_all('/:class="\{ \'is-open\': inStack\(\'([^\']+)\'\) \}"/', $this->src, $m);
        return $m[1];
    }

    /** @return array<string,string> */
    private function parents(): array
    {
        $this->assertSame(1, preg_match('/\$navGroupParents = \[(.*?)\];/s', $this->src, $block));
        preg_match_all('/\'([^\']+)\'\s*=>\s*\'([^\']+)\'/', $block[1], $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $pair) {
            $out[$pair[1]] = $pair[2];
        }
        return $out;
    }

    public function test_every_group_key_is_unique_and_has_exactly_one_panel(): void
    {
        $toggles = $this->toggleKeys();
        $panels = $this->panelKeys();

        $this->assertNotEmpty($toggles);
        $this->assertSame([], array_keys(array_filter(array_count_values($toggles), fn ($n) => $n > 1)), 'duplicate push() key');
        $this->assertSame([], array_keys(array_filter(array_count_values($panels), fn ($n) => $n > 1)), 'duplicate panel key');
        $this->assertEqualsCanonicalizing($toggles, $panels, 'every push(key) needs its own panel and vice versa');
    }

    public function test_parent_chain_is_resolvable_and_acyclic(): void
    {
        $panels = $this->panelKeys();
        foreach ($this->parents() as $child => $parent) {
            // 'evaluation' is a pre-existing orphan (its panel was retired; evaluation.* routes still map to it).
            // Reported to the conductor 2026-10-07, deliberately not changed here.
            if ($child !== 'evaluation') {
                $this->assertContains($child, $panels, "child '$child' has no panel");
            }
            $this->assertContains($parent, $panels, "parent '$parent' of '$child' has no panel");
            $seen = [$child];
            for ($c = $parent; $c !== null; $c = $this->parents()[$c] ?? null) {
                $this->assertNotContains($c, $seen, "cycle through '$c' starting at '$child'");
                $seen[] = $c;
            }
        }
    }

    public function test_chain_walks_cannot_loop_forever(): void
    {
        $this->assertStringContainsString('c && !out.includes(c)', $this->src, 'JS chain() lost its visited guard');
        $this->assertStringContainsString('!in_array($_g, $activeChain, true)', $this->src, 'PHP $activeChain loop lost its visited guard');
    }

    public function test_nothing_scrolls_the_overflow_hidden_viewport_sideways(): void
    {
        $this->assertStringNotContainsString('scrollIntoView', $this->src, 'scrollIntoView scrolls ancestors incl. .corex-nav-viewport and exposes a closed panel');
        $this->assertStringContainsString('@scroll="if ($el.scrollLeft) $el.scrollLeft = 0"', $this->src, 'viewport must be pinned to scrollLeft 0 (focus/Tab into a closed panel does the same thing)');
    }
}
