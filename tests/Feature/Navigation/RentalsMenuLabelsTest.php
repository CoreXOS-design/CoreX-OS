<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Support\Navigation\TabHubs;
use Tests\TestCase;

/**
 * AT-461 (15 Oct 2026) - Johan: inside the Rentals menu "I prefer not marked as rental as we are in the rental menu."
 * The six labels that carried a "Rental" prefix lose it; the old wording stays findable in the menu search.
 * .ai/specs/rentals-menu-tabs.md (22 Oct 2026) - all six are now TABS (Letting / Leases / Maintenance); the tab carries the short label
 * and both names - the short one and the old "Rental ..." one - are menu-search entries pointing at that tab.
 */
final class RentalsMenuLabelsTest extends TestCase
{
    /** new label => old label (kept for the menu search) */
    private const RENAMED = [
        'Applications'              => 'Rental Applications',
        'Application Authorisation' => 'Rental Application Authorisation',
        'Crews'                     => 'Rental Crews',
        'Fault Reports'             => 'Rental Fault Reports',
        'Fault Types'               => 'Rental Fault Types',
        'Inspections'               => 'Rental Inspections',
    ];

    public function test_the_six_tabs_drop_the_rental_prefix_and_keep_both_names_in_the_menu_search(): void
    {
        $tabs = [];
        foreach (['letting', 'leases', 'maintenance'] as $hub) {
            foreach (TabHubs::HUBS[$hub]::tabs() as $def) {
                $tabs[$def['label']] = (array) $def['old'];
            }
        }

        foreach (self::RENAMED as $new => $old) {
            $this->assertArrayHasKey($new, $tabs, "'{$new}' is a tab label");
            $this->assertContains($new, $tabs[$new], "the menu search finds '{$new}'");
            $this->assertContains($old, $tabs[$new], "the menu search still finds the old '{$old}'");
            $this->assertArrayNotHasKey($old, $tabs, "'{$old}' is no longer a visible label");
        }
    }

    public function test_the_menu_search_index_reads_the_extra_entries(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/layouts/corex-sidebar.blade.php'));

        $this->assertStringContainsString("a.getAttribute('data-nav-extra')", $src, 'search index must read the extra entries off the link');
    }
}
