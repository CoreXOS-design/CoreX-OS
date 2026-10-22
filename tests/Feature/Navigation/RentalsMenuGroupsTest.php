<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * AT-466 (15 Oct 2026) - Johan: "the rental menu is quite large, we need to group by actions. Command centre at the top."
 * .ai/specs/rentals-menu-tabs.md (Andre, 22 Oct 2026) - the group headings are gone: Letting, Leases and Maintenance are now ONE menu item
 * each that opens a screen with tabs, so the panel reads as nine items, Command Centre still at the top, in a fixed (not alphabetised)
 * order. Reads the sidebar source on purpose: the order IS the contract.
 */
final class RentalsMenuGroupsTest extends TestCase
{
    private function sidebar(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/layouts/corex-sidebar.blade.php');
    }

    private function panel(): string
    {
        $src = $this->sidebar();
        $start = strpos($src, '<div class="corex-nav-panel-title">Rentals</div>');
        $end = strpos($src, '>Reports</a>', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($src, (int) $start, (int) $end - (int) $start + strlen('>Reports</a>'));
    }

    public function test_the_panel_reads_command_centre_then_the_nine_items_with_no_headings(): void
    {
        preg_match_all('/corex-nav-sublabel[^>]*>([^<]+)<\/div>|>([^<>]+)<\/a>/', $this->panel(), $m, PREG_SET_ORDER);
        $seq = array_map(fn ($x) => ($x[1] !== '' ? '# ' . $x[1] : html_entity_decode($x[2])), $m);

        $this->assertSame([
            'Command Centre', 'Properties', 'Contacts', 'Letting', 'Leases', 'Notices', 'Maintenance', 'Rental Take-On Import', 'Reports',
        ], $seq);
    }

    public function test_the_tabbed_items_land_on_their_hubs(): void
    {
        $panel = $this->panel();
        foreach (['letting' => 'Letting', 'leases' => 'Leases', 'maintenance' => 'Maintenance'] as $key => $label) {
            $this->assertMatchesRegularExpression("/route\\('corex\\.hubs\\.{$key}'\\)[^\\n]*>{$label}<\\/a>/", $panel, "{$label} opens its tabbed screen");
        }
    }

    public function test_the_panel_keeps_its_order(): void
    {
        $this->assertMatchesRegularExpression('/inStack\(\'rental-applications\'\) \}" data-manual-order>/', $this->sidebar(), 'the Rentals panel is not alphabetised');
    }
}
