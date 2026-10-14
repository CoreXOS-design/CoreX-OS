<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Support\Navigation\SidebarNavAuditor;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 14 Oct 2026 - four screens shipped with a menu link (or an intended place) but the sidebar never OPENED / HIGHLIGHTED for them.
 * Pinned by name so it cannot come back unnoticed, with no allow-list entry for any of them:
 *   - Breach Register and Notices Received live in the Rentals panel, so that panel must open on every one of their pages;
 *   - the "alerts switched off" list is opened from the Users page, so it stays under Settings -> Users;
 *   - the auction's Bidder Register, Sale Room and documents hang off the auction (auctions.md 7.1: no top-level entry on
 *     purpose) so "Auction Diary" stays selected; the results export belongs to Results.
 */
final class NewScreensHaveTheirMenuPlaceTest extends TestCase
{
    /** @return array<string, array{group:?string, item:string}> route-name prefix => where it must resolve */
    private function expected(): array
    {
        return [
            'corex.breaches.'      => ['group' => 'rental-applications', 'item' => 'Breach Register'],
            'corex.lease-notices.' => ['group' => 'rental-applications', 'item' => 'Notices Received'],
            'corex.alerts-off.'    => ['group' => 'settings', 'item' => 'Users'],
            'corex.auctions.bidders.' => ['group' => 'auctions', 'item' => 'Auction Diary'],
            'corex.auctions.room.' => ['group' => 'auctions', 'item' => 'Auction Diary'],
            'corex.auctions.document' => ['group' => 'auctions', 'item' => 'Auction Diary'],
            'corex.auctions.results.export' => ['group' => 'auctions', 'item' => 'Results'],
        ];
    }

    public function test_each_screen_resolves_to_its_menu_item_and_its_panel_opens(): void
    {
        $routes = [];
        foreach (Route::getRoutes() as $r) {
            $routes[] = ['name' => $r->getName(), 'uri' => $r->uri(), 'method' => implode('|', $r->methods()), 'middleware' => $r->gatherMiddleware()];
        }
        $rows = [];
        foreach (SidebarNavAuditor::run($routes, base_path('resources/views/layouts/corex-sidebar.blade.php'))['rows'] as $row) {
            $rows[$row['route']] = $row;
        }

        $checked = 0;
        foreach ($this->expected() as $prefix => $want) {
            $matching = array_filter($rows, fn ($row, $name) => str_starts_with($name, $prefix), ARRAY_FILTER_USE_BOTH);
            $this->assertNotSame([], $matching, "no navigable route starts with {$prefix}");
            foreach ($matching as $name => $row) {
                $this->assertSame([], $row['flags'], "{$name} is flagged: " . implode(',', $row['flags']));
                $this->assertSame($want['group'], $row['resolved_group'], "{$name} opens the wrong panel");
                $this->assertTrue(
                    (bool) array_filter($row['items'], fn ($i) => str_contains(html_entity_decode($i), '» ' . $want['item'] . ' [')),
                    "{$name} does not light up '{$want['item']}': " . implode(' | ', $row['items'])
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(10, $checked);
    }

    public function test_the_breach_register_and_notices_received_links_exist_in_the_rentals_panel(): void
    {
        $blade = (string) file_get_contents(base_path('resources/views/layouts/corex-sidebar.blade.php'));

        $this->assertStringContainsString("route('corex.breaches.index')", $blade);
        $this->assertStringContainsString("route('corex.lease-notices.index')", $blade);
        $this->assertMatchesRegularExpression("/route\\('corex\\.breaches\\.index'\\)[^\\n]*>Breach Register</", $blade);
        $this->assertMatchesRegularExpression("/route\\('corex\\.lease-notices\\.index'\\)[^\\n]*>Notices Received</", $blade);
    }
}
