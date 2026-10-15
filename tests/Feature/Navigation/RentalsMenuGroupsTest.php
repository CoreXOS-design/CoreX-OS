<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * AT-466 (15 Oct 2026) - Johan: "the rental menu is quite large, we need to group by actions. Command centre at the top."
 * The Rentals sidebar panel is regrouped under the sidebar's own sub-headings (.corex-nav-sublabel), in a fixed (not alphabetised)
 * order; every link keeps its route, permission and feature gate, carries its group name for menu search, and a heading with nothing
 * under it hides itself. Reads the sidebar source on purpose: the order and the group tags ARE the contract.
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

    public function test_the_panel_reads_command_centre_then_the_groups_in_johans_order(): void
    {
        preg_match_all('/corex-nav-sublabel" data-nav-auto-group>([^<]+)<\/div>|>([^<>]+)<\/a>/', $this->panel(), $m, PREG_SET_ORDER);
        $seq = array_map(fn ($x) => ($x[1] !== '' ? '# ' . $x[1] : html_entity_decode($x[2])), $m);

        $this->assertSame([
            'Command Centre',
            '# Letting', 'Properties', 'Applications', 'Application Authorisation', 'Core Matches', 'Contacts', 'Rental Pipeline',
            '# Leases', 'Leases', 'Inspections', 'Notices Received', 'Breach Register', 'Rental Notices',
            '# Maintenance', 'Fault Reports', 'Job Cards', 'Crews', 'Parts & Labour Catalogue', 'Rental Work Orders', 'Suppliers & contractors',
            '# Set-up', 'Fault Types', 'Rental Take-On Import',
            '# Reports', 'Reports',
        ], $seq);
    }

    public function test_every_link_under_a_heading_carries_that_headings_name_for_menu_search(): void
    {
        $parts = preg_split('/corex-nav-sublabel" data-nav-auto-group>([^<]+)<\/div>/', $this->panel(), -1, PREG_SPLIT_DELIM_CAPTURE);
        $this->assertGreaterThan(8, count($parts));
        // [0] = Command Centre part, then (heading, body) pairs
        $this->assertStringNotContainsString('data-nav-group="', $parts[0], 'Command Centre stands alone, under no heading');
        for ($i = 1; $i < count($parts); $i += 2) {
            $links = substr_count($parts[$i + 1], 'class="corex-nav-subitem ');
            $this->assertGreaterThan(0, $links, "'{$parts[$i]}' has links");
            $this->assertSame($links, substr_count($parts[$i + 1], 'data-nav-group="' . $parts[$i] . '"'), "every link under '{$parts[$i]}' is tagged with it");
        }
    }

    public function test_the_panel_keeps_its_order_and_the_script_handles_headings_and_search(): void
    {
        $src = $this->sidebar();
        $this->assertMatchesRegularExpression('/inStack\(\'rental-applications\'\) \}" data-manual-order>/', $src, 'the Rentals panel is not alphabetised');
        $this->assertStringContainsString("a.getAttribute('data-nav-group')", $src, 'search index reads the group');
        $this->assertStringContainsString('sub.indexOf(q)', $src, 'search matches the group name');
        $this->assertStringContainsString('window.CorexNavHeadings', $src, 'an empty heading hides itself');
    }
}
