<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * AT-461 (15 Oct 2026) - Johan: inside the Rentals menu "I prefer not marked as rental as we are in the rental menu."
 * The six labels that carried a "Rental" prefix lose it; the old wording stays as a search-only alias
 * (data-nav-alias) so the sidebar search still finds them by "rental crews" etc. Routes/permissions are untouched.
 *
 * Reads the sidebar source on purpose: the label text and its alias attribute ARE the contract.
 */
final class RentalsMenuLabelsTest extends TestCase
{
    /** new label => old label (kept as the search alias) */
    private const RENAMED = [
        'Applications'              => 'Rental Applications',
        'Application Authorisation' => 'Rental Application Authorisation',
        'Crews'                     => 'Rental Crews',
        'Fault Reports'             => 'Rental Fault Reports',
        'Fault Types'               => 'Rental Fault Types',
        'Inspections'               => 'Rental Inspections',
    ];

    private function sidebar(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/layouts/corex-sidebar.blade.php');
    }

    private function rentalsPanel(): string
    {
        $src   = $this->sidebar();
        $start = strpos($src, '<div class="corex-nav-panel-title">Rentals</div>');
        $end   = strpos($src, '>Reports</a>', (int) $start); // the last link of the Rentals panel (AT-466 grouping)
        $this->assertNotFalse($start, 'Rentals panel title not found');
        $this->assertNotFalse($end, 'Rentals panel end (Reports) not found');

        return substr($src, (int) $start, (int) $end - (int) $start);
    }

    public function test_the_six_items_drop_the_rental_prefix_and_keep_the_old_name_as_a_search_alias(): void
    {
        $panel = $this->rentalsPanel();

        foreach (self::RENAMED as $new => $old) {
            $this->assertMatchesRegularExpression(
                '/data-nav-alias="' . preg_quote($old, '/') . '">' . preg_quote($new, '/') . '<\/a>/',
                $panel,
                "'{$new}' must be the visible label with '{$old}' as its search alias"
            );
            $this->assertStringNotContainsString('>' . $old . '</a>', $panel, "'{$old}' must no longer be a visible label");
        }
    }

    public function test_the_menu_search_index_reads_the_alias_and_matches_on_it(): void
    {
        $src = $this->sidebar();

        $this->assertStringContainsString("a.getAttribute('data-nav-alias')", $src, 'search index must read the alias off the link');
        $this->assertStringContainsString('alias.indexOf(q)', $src, 'search must match the alias');
    }
}
