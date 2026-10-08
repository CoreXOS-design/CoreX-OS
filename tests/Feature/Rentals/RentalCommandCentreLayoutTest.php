<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rental-command-centre.md §16 — the compact tile strip and the right-hand panel's heading (Johan, 2026-10-07).
 * Layout only: the tiles still count what they counted (RentalCommandCentreServiceTest owns that).
 */
final class RentalCommandCentreLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $query = []): string
    {
        $agency = Agency::create(['name' => 'Layout Agency ' . uniqid(), 'slug' => 'lay-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        return $this->actingAs($admin)->get(route('corex.rentals.command-centre.index', $query))->assertOk()->getContent();
    }

    public function test_the_eleven_tiles_form_a_compact_six_across_strip_so_they_wrap_to_two_rows(): void
    {
        $html = $this->page();

        $this->assertCount(11, RentalCommandCentreService::TILES);
        $this->assertStringContainsString('data-qa="rcc-tiles"', $html);
        $this->assertStringContainsString('lg:grid-cols-6', $html, 'six across from the laptop breakpoint = two rows of eleven');
        $this->assertStringNotContainsString('lg:grid-cols-5', $html, 'the old five-across, three-row strip is gone');

        foreach (array_keys(RentalCommandCentreService::TILES) as $key) {
            $this->assertStringContainsString('data-qa="rcc-tile-' . $key . '"', $html, "tile {$key} is still there");
            $this->assertStringContainsString('tile=' . $key, $html, "tile {$key} still filters the list on click");
        }
        $this->assertStringContainsString('Inactive / off market', $html, 'the label stays in full');
    }

    public function test_the_active_tile_keeps_its_highlight_and_the_list_says_which_tile_filters_it(): void
    {
        $html = $this->page(['tile' => 'occupied']);

        $this->assertMatchesRegularExpression('/data-qa="rcc-tile-occupied"\s+style="border-color:color-mix/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-qa="rcc-tile-unoccupied"\s+style="border-color:color-mix/', $html);
        $this->assertStringContainsString('Showing: Occupied', $html);
    }

    public function test_tile_text_is_clamped_to_two_lines(): void
    {
        $html = $this->page();

        // Johan's ruling: max 2 lines of text per tile (full wording stays in the tooltip).
        $this->assertSame(11, substr_count($html, '-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">'), 'one clamp per tile label');
    }

    public function test_the_right_hand_panel_has_a_heading(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Property list (0)', $html);
        $this->assertStringContainsString('Needs action (', $html, 'the left panel keeps its own heading');
    }
}
