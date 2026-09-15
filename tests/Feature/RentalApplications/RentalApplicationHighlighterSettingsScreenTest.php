<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationHighlighter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design-standard audit, 2026-09-09 — the two lowest-priority gaps on the
 * highlighter settings screen, built last per Johan's own ordering:
 *
 *   1. The Archived section used to vanish entirely (no heading, no
 *      message) when nothing was archived — the same class of bug already
 *      fixed on the rental application audit trail. Pins the real empty
 *      state.
 *   2. Search/sort/pagination on the Archived list — real, server-side,
 *      because archived rows have no reorder dependency to protect
 *      (unlike active rows, where search stays client-side; see
 *      RentalApplicationSettingsController::edit()'s own comment for why).
 */
final class RentalApplicationHighlighterSettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function archived(string $label, int $sortOrder): RentalApplicationHighlighter
    {
        $h = RentalApplicationHighlighter::create([
            'agency_id' => $this->agency->id, 'label' => $label, 'color' => '#2d6cdf',
            'role_scope' => 'agent', 'sort_order' => $sortOrder,
        ]);
        $h->delete();

        return $h;
    }

    public function test_archived_section_shows_a_real_empty_state_when_nothing_is_archived(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit'))
            ->assertOk()->assertSee('Archived')->assertSee('Nothing archived.');
    }

    public function test_archived_section_lists_rows_once_something_is_archived(): void
    {
        $owner = $this->owner();
        $this->archived('Deposit Proof', 0);

        $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit'))
            ->assertOk()->assertSee('Deposit Proof')->assertDontSee('Nothing archived.');
    }

    public function test_search_filters_the_archived_list_by_label(): void
    {
        $owner = $this->owner();
        $this->archived('Deposit Proof', 0);
        $this->archived('Pet Deposit', 1);
        $this->archived('Unrelated Marker', 2);

        $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit', ['highlighter_q' => 'Deposit']))
            ->assertOk()->assertSee('Deposit Proof')->assertSee('Pet Deposit')->assertDontSee('Unrelated Marker');
    }

    public function test_search_with_no_matches_shows_a_real_empty_state(): void
    {
        $owner = $this->owner();
        $this->archived('Deposit Proof', 0);

        $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit', ['highlighter_q' => 'ZZZNoMatch']))
            ->assertOk()->assertSee('Nothing archived.');
    }

    public function test_sort_archived_alphabetically_vs_most_recently_archived(): void
    {
        $owner = $this->owner();
        $this->archived('Zebra Marker', 0);
        $this->archived('Alpha Marker', 1);

        $alphabetical = $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit', ['highlighter_archived_sort' => 'label']))->getContent();
        $this->assertLessThan(strpos($alphabetical, 'Zebra Marker'), strpos($alphabetical, 'Alpha Marker'));
    }

    public function test_archived_list_paginates_at_ten(): void
    {
        $owner = $this->owner();
        foreach (range(1, 12) as $i) {
            $this->archived("Marker {$i}", $i);
        }

        // Counting the literal Restore BUTTON tag, not the bare word — the
        // shared layout's own unrelated Alpine component has a JS comment
        // containing "Restore" too ("Restore panel state..."), which a
        // naive substr_count over the whole page would wrongly fold in.
        $page1 = $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit'));
        $page1->assertOk();
        $this->assertSame(10, substr_count($page1->getContent(), '>Restore</button>'));

        $page2 = $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit', ['highlighter_archived_page' => 2]));
        $page2->assertOk();
        $this->assertSame(2, substr_count($page2->getContent(), '>Restore</button>'));
    }

    /** Active rows (and their reorder up/down mechanism) must be completely unaffected by any of the above. */
    public function test_active_highlighters_still_render_and_reorder_regardless_of_archived_search(): void
    {
        $owner = $this->owner();
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $this->archived('Some Archived Thing', 10);

        $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit', ['highlighter_q' => 'Some']))
            ->assertOk()->assertSee('Income')->assertSee('Expense')->assertSee('Unpaid');
    }
}
