<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Console\Commands\BackfillP24ImportedStock;
use App\Jobs\ConfirmP24PropertyRowJob;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-419 — Imported Stock. Spec: .ai/specs/at419-imported-stock.md
 *
 * The split: active P24-imported stock stays on Properties, unchanged; every
 * off-market status from an import (withdrawn, sold, expired, …) lives on the
 * new Imported Stock page instead, tagged "Imported" with an Imported Date.
 * Non-imported properties are never affected, whatever their status.
 */
final class ImportedStockTest extends TestCase
{
    use RefreshDatabase;

    // ── The partition itself ────────────────────────────────────────────

    public function test_imported_off_market_property_appears_on_imported_stock_not_properties(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Imported', [
            'status' => 'withdrawn',
            'p24_imported_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Imported');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertSee('ZZZ-Withdrawn-Imported');
    }

    public function test_imported_active_property_stays_on_properties_unchanged(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Active-Imported', [
            'status' => 'for_sale',
            'p24_imported_at' => now(),
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();
        $res->assertSee('ZZZ-Active-Imported');
        // Unchanged means no "Imported" tag leaks onto the Properties page.
        $res->assertDontSee('Imported from Property24', false);

        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertDontSee('ZZZ-Active-Imported');
    }

    public function test_non_imported_off_market_property_stays_on_properties(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Off-market, but never touched by the P24 importer — must be totally unaffected.
        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Manual', [
            'status' => 'withdrawn',
            'p24_imported_at' => null,
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertSee('ZZZ-Withdrawn-Manual');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Manual');
    }

    /**
     * Real P24 imports often store status capitalised ("Withdrawn") rather than
     * the model's lowercase snake_case OFF_MARKET_STATUSES. The split must not
     * strand these on the wrong page.
     */
    public function test_capitalised_import_status_still_partitions_to_imported_stock(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Capitalised', [
            'status' => 'Withdrawn',
            'p24_imported_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Capitalised');
        $this->get(route('corex.properties.imported-stock'))->assertOk()->assertSee('ZZZ-Withdrawn-Capitalised');
    }

    /**
     * A withdrawn/expired P24 import that later gets picked up by the
     * UNRELATED stale-stock/duplicate-resolution pipeline
     * (TrackedPropertyMatchOrCreateService / PropertyDuplicateTakeService)
     * flips to 'draft' or 'prospecting' — at that point it belongs to the
     * Drafts/Prospecting workflow, not Imported Stock, even though
     * p24_imported_at is still set. Found live on HFC's restored data
     * (Andre, 2026-09-15): 3 drafts + 7 prospecting rows had p24_imported_at
     * set from their original import.
     */
    public function test_imported_property_reclassified_to_draft_or_prospecting_goes_to_properties_not_imported_stock(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Reclassified-Draft', [
            'status' => 'draft',
            'p24_imported_at' => now(),
        ]);
        $this->property($agencyId, $admin, 'ZZZ-Reclassified-Prospecting', [
            'status' => 'prospecting',
            'p24_imported_at' => now(),
        ]);
        $this->property($agencyId, $admin, 'ZZZ-Reclassified-NotSelling', [
            'status' => 'not_selling',
            'p24_imported_at' => now(),
        ]);

        $index = $this->get(route('corex.properties.index'))->assertOk();
        $index->assertSee('ZZZ-Reclassified-Draft');
        $index->assertSee('ZZZ-Reclassified-Prospecting');
        $index->assertSee('ZZZ-Reclassified-NotSelling');

        $imported = $this->get(route('corex.properties.imported-stock'))->assertOk();
        $imported->assertDontSee('ZZZ-Reclassified-Draft');
        $imported->assertDontSee('ZZZ-Reclassified-Prospecting');
        $imported->assertDontSee('ZZZ-Reclassified-NotSelling');
    }

    public function test_imported_stock_page_shows_tag_and_imported_date(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Sold-Imported', [
            'status' => 'sold',
            'p24_imported_at' => now()->setDate(2026, 8, 1),
        ]);

        $res = $this->get(route('corex.properties.imported-stock'))->assertOk();
        $res->assertSee('Imported from Property24', false);
        $res->assertSee('1 Aug 2026', false);
    }

    // ── AT-422: a typed search on Properties also finds Imported Stock ───
    //
    // Spec amendment 2026-09-19. With NO search the partition above is unchanged
    // (Properties hides Imported Stock); with a search term the Properties list
    // looks across both and tags the imported rows, so nobody has to repeat a
    // search on a second page.

    private const IMPORTED_TAG = 'title="Imported from Property24"';

    public function test_search_on_properties_also_finds_imported_off_market_stock_with_the_tag(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Imported', [
            'status' => 'withdrawn',
            'p24_imported_at' => now(),
        ]);

        // No search: default unchanged — still hidden from Properties.
        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('ZZZ-Withdrawn-Imported');

        // Typed search: found, and tagged in BOTH the grid card and the table row.
        $res = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Withdrawn-Imported']))
            ->assertOk()
            ->assertSee('ZZZ-Withdrawn-Imported');
        $this->assertSame(2, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_search_that_does_not_match_does_not_pull_in_imported_stock(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Withdrawn-Imported', [
            'status' => 'withdrawn',
            'p24_imported_at' => now(),
        ]);

        // Searching is not "show all imported" — only rows that actually match.
        $this->get(route('corex.properties.index', ['search' => 'ZZZ-Nothing-Like-This']))
            ->assertOk()
            ->assertDontSee('ZZZ-Withdrawn-Imported');
    }

    public function test_search_finds_active_imported_stock_without_a_tag(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Active-Imported', [
            'status' => 'for_sale',
            'p24_imported_at' => now(),
        ]);

        // Active imported stock is ordinary Properties stock: found, never tagged.
        $res = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Active-Imported']))
            ->assertOk()
            ->assertSee('ZZZ-Active-Imported');
        $this->assertSame(0, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_search_tags_only_the_imported_rows_among_mixed_results(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Mixed-Imported',  ['status' => 'sold',   'p24_imported_at' => now()]);
        $this->property($agencyId, $admin, 'ZZZ-Mixed-Manual',    ['status' => 'sold',   'p24_imported_at' => null]);
        $this->property($agencyId, $admin, 'ZZZ-Mixed-ActiveImp', ['status' => 'active', 'p24_imported_at' => now()]);

        $res = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Mixed']))
            ->assertOk()
            ->assertSee('ZZZ-Mixed-Imported')
            ->assertSee('ZZZ-Mixed-Manual')
            ->assertSee('ZZZ-Mixed-ActiveImp');

        // One imported off-market row => the tag appears once per view (grid + table), no more.
        $this->assertSame(2, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    // ── AT-422: in a search, imported stock always comes AFTER live/non-imported ──

    /** @return list<string> the result titles in the order the page lists them */
    private function searchTitles(array $query): array
    {
        return $this->get(route('corex.properties.index', $query))
            ->assertOk()
            ->viewData('properties')->getCollection()->pluck('title')->all();
    }

    private function backdate(Property $p, int $minutesAgo): void
    {
        DB::table('properties')->where('id', $p->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_search_lists_live_and_non_imported_first_then_imported_even_when_imported_is_newest(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $live     = $this->property($agencyId, $admin, 'ZZZ-Order-Live',         ['status' => 'for_sale']);
        $manual   = $this->property($agencyId, $admin, 'ZZZ-Order-Manual-Sold',  ['status' => 'sold', 'p24_imported_at' => null]);
        $imported = $this->property($agencyId, $admin, 'ZZZ-Order-Imported',     ['status' => 'withdrawn', 'p24_imported_at' => now()]);
        $this->backdate($live, 30);
        $this->backdate($manual, 20);
        $this->backdate($imported, 0);   // newest — the default "newest first" sort would put it on top

        $this->assertSame(
            ['ZZZ-Order-Manual-Sold', 'ZZZ-Order-Live', 'ZZZ-Order-Imported'],
            $this->searchTitles(['search' => 'ZZZ-Order'])
        );
    }

    public function test_imported_stock_stays_last_whatever_sort_is_chosen(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Two imported rows chosen so every sort direction would otherwise float one of
        // them to the top: highest price + first alphabetically, and lowest price + last.
        $this->property($agencyId, $admin, 'ZZZ-Sort-M-Live',      ['status' => 'for_sale', 'price' => 1500000]);
        $this->property($agencyId, $admin, 'ZZZ-Sort-N-Manual',    ['status' => 'sold',     'price' => 1200000, 'p24_imported_at' => null]);
        $this->property($agencyId, $admin, 'ZZZ-Sort-A-Imp-High',  ['status' => 'withdrawn', 'price' => 9000000, 'p24_imported_at' => now()]);
        $this->property($agencyId, $admin, 'ZZZ-Sort-Z-Imp-Low',   ['status' => 'expired',   'price' => 100000,  'p24_imported_at' => now()]);

        foreach (['newest', 'oldest', 'price_desc', 'price_asc', 'title', 'status', 'marketing_status'] as $sort) {
            foreach (['asc', 'desc'] as $dir) {
                $titles = $this->searchTitles(['search' => 'ZZZ-Sort', 'sort' => $sort, 'dir' => $dir]);

                $this->assertCount(4, $titles, "sort={$sort} dir={$dir}");
                $this->assertEqualsCanonicalizing(
                    ['ZZZ-Sort-M-Live', 'ZZZ-Sort-N-Manual'],
                    array_slice($titles, 0, 2),
                    "live/non-imported must be the first two for sort={$sort} dir={$dir}"
                );
                $this->assertEqualsCanonicalizing(
                    ['ZZZ-Sort-A-Imp-High', 'ZZZ-Sort-Z-Imp-Low'],
                    array_slice($titles, 2),
                    "imported must be the last two for sort={$sort} dir={$dir}"
                );
            }
        }
    }

    public function test_imported_stock_is_last_even_when_it_is_the_viewers_own_listing(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agentA = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $this->actingAs($admin);

        // The AT-394 "my own listings first" rule must not lift the viewer's own IMPORTED
        // listing above a colleague's live one — imported comes last, full stop.
        $this->property($agencyId, $admin,  'ZZZ-Own-Imported',       ['status' => 'withdrawn', 'p24_imported_at' => now()]);
        $this->property($agencyId, $agentA, 'ZZZ-Colleague-Live',     ['status' => 'for_sale']);
        $this->property($agencyId, $admin,  'ZZZ-Own-Live',           ['status' => 'for_sale']);

        $titles = $this->searchTitles(['search' => 'ZZZ-', 'agent_ids' => 'all']);

        $this->assertSame('ZZZ-Own-Imported', end($titles), 'imported stock must be last');
        // Below the imported line, the existing own-first rule still applies.
        $this->assertSame('ZZZ-Own-Live', $titles[0], "the viewer's own live listing still leads");
    }

    public function test_imported_stock_stays_after_live_properties_across_pages(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $perPage = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Page']))
            ->viewData('properties')->perPage();

        // One more live row than fits a page, plus two imported rows made the NEWEST so an
        // unordered "newest first" list would put them on page 1.
        for ($i = 1; $i <= $perPage + 1; $i++) {
            $p = $this->property($agencyId, $admin, sprintf('ZZZ-Page-Live-%02d', $i), ['status' => 'for_sale']);
            $this->backdate($p, 600 - $i);
        }
        $this->property($agencyId, $admin, 'ZZZ-Page-Imported-1', ['status' => 'withdrawn', 'p24_imported_at' => now()]);
        $this->property($agencyId, $admin, 'ZZZ-Page-Imported-2', ['status' => 'sold',      'p24_imported_at' => now()]);

        $page1 = $this->searchTitles(['search' => 'ZZZ-Page']);
        $page2 = $this->searchTitles(['search' => 'ZZZ-Page', 'page' => 2]);

        $this->assertCount($perPage, $page1);
        foreach ($page1 as $title) {
            $this->assertStringStartsWith('ZZZ-Page-Live-', $title, 'no imported row may appear on page 1');
        }
        $this->assertCount(3, $page2);
        $this->assertStringStartsWith('ZZZ-Page-Live-', $page2[0]);
        $this->assertEqualsCanonicalizing(['ZZZ-Page-Imported-1', 'ZZZ-Page-Imported-2'], array_slice($page2, 1));
    }

    public function test_search_finds_imported_stock_whatever_its_status_casing(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // P24 imports land capitalised ("Withdrawn"), lowercase, and with a portal-only
        // status like 'rented'/'let_out' — every one is imported off-market stock.
        foreach (['Withdrawn', 'SOLD', 'expired', 'let_out', 'rented'] as $i => $status) {
            $this->property($agencyId, $admin, "ZZZ-Casing-{$i}", ['status' => $status, 'p24_imported_at' => now()]);
        }

        $res = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Casing']))->assertOk();
        foreach (range(0, 4) as $i) {
            $res->assertSee("ZZZ-Casing-{$i}");
        }
        $this->assertSame(10, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_search_result_for_reclassified_draft_or_prospecting_is_not_tagged_imported(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Reclassified out of the imported bucket (Andre, 2026-09-15): ordinary Properties
        // rows, so the search must not brand them "Imported" either.
        $this->property($agencyId, $admin, 'ZZZ-Reclass-Draft',       ['status' => 'draft',       'p24_imported_at' => now()]);
        $this->property($agencyId, $admin, 'ZZZ-Reclass-Prospecting', ['status' => 'prospecting', 'p24_imported_at' => now()]);

        $res = $this->get(route('corex.properties.index', ['search' => 'ZZZ-Reclass']))
            ->assertOk()
            ->assertSee('ZZZ-Reclass-Draft')
            ->assertSee('ZZZ-Reclass-Prospecting');
        $this->assertSame(0, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_search_keeps_honouring_the_agent_filter_for_imported_stock(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $agentA = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $agentB = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $this->actingAs($admin);

        $this->property($agencyId, $agentA, 'ZZZ-Agent-Alpha-Imported', ['status' => 'withdrawn', 'p24_imported_at' => now()]);
        $this->property($agencyId, $agentB, 'ZZZ-Agent-Bravo-Imported', ['status' => 'withdrawn', 'p24_imported_at' => now()]);

        // Imported rows obey the picked agent exactly like every other row (2026-09-13 ruling)…
        $this->get(route('corex.properties.index', ['agent_ids' => (string) $agentA->id, 'search' => 'ZZZ-Agent']))
            ->assertOk()
            ->assertSee('ZZZ-Agent-Alpha-Imported')
            ->assertDontSee('ZZZ-Agent-Bravo-Imported');

        // …and "All Agents" opens the whole agency.
        $this->get(route('corex.properties.index', ['agent_ids' => 'all', 'search' => 'ZZZ-Agent']))
            ->assertOk()
            ->assertSee('ZZZ-Agent-Alpha-Imported')
            ->assertSee('ZZZ-Agent-Bravo-Imported');
    }

    public function test_plain_agent_search_shows_a_colleagues_imported_listing_read_only_with_the_tag(): void
    {
        $agencyId = $this->makeAgency();
        $me        = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $colleague = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $theirs = $this->property($agencyId, $colleague, 'ZZZ-Colleague-Imported', [
            'status' => 'withdrawn',
            'p24_imported_at' => now(),
        ]);

        // AT-394's read-only "Already listed" row must exist for imported stock too — it is
        // the whole point of the widened search (don't re-capture what is already there) —
        // and must say it is imported.
        $res = $this->actingAs($me)
            ->get(route('corex.properties.index', ['search' => 'ZZZ-Colleague-Imported']))
            ->assertOk()
            ->assertSee('ZZZ-Colleague-Imported')
            ->assertSee('Already listed');

        $rows = $res->viewData('properties')->getCollection()->keyBy('id');
        $this->assertTrue((bool) $rows[$theirs->id]->owned_by_other, 'colleague listing must be read-only');
        $this->assertSame(2, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_search_on_rentals_properties_also_finds_imported_rentals(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Rental-Imported', [
            'status' => 'let_out',
            'listing_type' => 'rental',
            'p24_imported_at' => now(),
        ]);

        $this->get(route('corex.rentals.properties.index'))->assertOk()->assertDontSee('ZZZ-Rental-Imported');

        $res = $this->get(route('corex.rentals.properties.index', ['search' => 'ZZZ-Rental-Imported']))
            ->assertOk()
            ->assertSee('ZZZ-Rental-Imported');
        $this->assertSame(2, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_imported_stock_page_search_is_unchanged_and_tags_each_row_once_per_view(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $this->property($agencyId, $admin, 'ZZZ-Sold-Imported', ['status' => 'sold', 'p24_imported_at' => now()]);
        $this->property($agencyId, $admin, 'ZZZ-Sold-Manual',   ['status' => 'sold', 'p24_imported_at' => null]);

        // The Imported Stock page still lists only imported off-market rows, search or not.
        $res = $this->get(route('corex.properties.imported-stock', ['search' => 'ZZZ-Sold']))
            ->assertOk()
            ->assertSee('ZZZ-Sold-Imported')
            ->assertDontSee('ZZZ-Sold-Manual');
        $this->assertSame(2, substr_count($res->getContent(), self::IMPORTED_TAG));
    }

    public function test_is_imported_stock_mirrors_the_imported_off_market_scope(): void
    {
        $cases = [
            // status, p24_imported_at set?, expected
            ['withdrawn',   true,  true],
            ['Withdrawn',   true,  true],
            ['SOLD',        true,  true],
            ['let_out',     true,  true],
            ['rented',      true,  true],
            ['for_sale',    true,  false],  // active
            ['active',      true,  false],
            ['draft',       true,  false],  // reclassified out of the bucket
            ['prospecting', true,  false],
            ['not_selling', true,  false],
            ['withdrawn',   false, false],  // never imported
            ['',            true,  false],  // blank status must not throw or match
        ];

        foreach ($cases as [$status, $stamped, $expected]) {
            $p = new Property();
            $p->status = $status;
            $p->p24_imported_at = $stamped ? now() : null;

            $this->assertSame($expected, $p->isImportedStock(), "status='{$status}' stamped=" . ($stamped ? 'y' : 'n'));
        }
    }

    // ── Permission gate ──────────────────────────────────────────────────

    public function test_imported_stock_route_denied_without_its_own_permission(): void
    {
        $agencyId = $this->makeAgency();
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        // Seed SOME grants (so the "unseeded → allow all" test fallback doesn't
        // apply) but deliberately withhold access_imported_stock. properties.view
        // (with a scope) is paired alongside access_properties in every real role
        // grant — without it, PropertyController::index()'s dataScope resolves to
        // null and the page 500s regardless of this test's concern.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => $agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'agency_id' => $agencyId, 'scope' => 'own']);

        $this->actingAs($agent);

        $this->get(route('corex.properties.imported-stock'))->assertForbidden();
        // access_properties alone must not leak into the new page.
        $this->get(route('corex.properties.index'))->assertOk();
    }

    // ── ConfirmP24PropertyRowJob stamping ────────────────────────────────

    public function test_confirm_job_stamps_p24_imported_at_on_first_confirm(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'importing',
        ]);
        $row = P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'pending', 'resolved_agent_id' => $admin->id,
            'mapped_json' => [
                'p24_listing_number' => (string) random_int(1000000, 9999999),
                'title' => 'Confirm Job Test', 'status' => 'Withdrawn', 'price' => 900000,
            ],
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $admin->id))->handle();

        $property = Property::withoutGlobalScopes()->findOrFail($row->fresh()->target_id);
        $this->assertNotNull($property->p24_imported_at);
        $this->assertTrue($property->p24_imported_at->diffInMinutes(now()) < 1);
    }

    public function test_reconfirm_does_not_reset_an_already_stamped_imported_date(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $original = now()->subDays(30);
        $property = $this->property($agencyId, $admin, 'ZZZ-Reimport', [
            'status' => 'for_sale',
            'p24_listing_number' => '5551234',
            'p24_ref' => '5551234',
            'p24_imported_at' => $original,
        ]);

        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'importing',
        ]);
        $row = P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => '5551234', 'status' => 'pending', 'resolved_agent_id' => $admin->id,
            'mapped_json' => ['p24_listing_number' => '5551234', 'title' => 'ZZZ-Reimport', 'status' => 'Withdrawn'],
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $admin->id))->handle();

        $property->refresh();
        $this->assertSame($original->toDateTimeString(), $property->p24_imported_at->toDateTimeString());
        // The re-import DID update the status though (a real refresh) — confirms
        // this wasn't just a job no-op.
        $this->assertSame('Withdrawn', $property->status);
    }

    // ── Backfill command ─────────────────────────────────────────────────

    public function test_backfill_dry_run_makes_no_changes(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $property = $this->property($agencyId, $admin, 'ZZZ-Backfill-Candidate', [
            'status' => 'sold', 'p24_imported_at' => null,
        ]);
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'completed',
        ]);
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => now()->subDays(10), 'mapped_json' => [],
        ]);

        $this->artisan(BackfillP24ImportedStock::class)->assertSuccessful();

        $this->assertNull($property->fresh()->p24_imported_at);
    }

    public function test_backfill_apply_stamps_only_null_rows_with_earliest_confirmed_at(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $property = $this->property($agencyId, $admin, 'ZZZ-Backfill-Apply', [
            'status' => 'sold', 'p24_imported_at' => null,
        ]);
        $alreadyStamped = $this->property($agencyId, $admin, 'ZZZ-Already-Stamped', [
            'status' => 'sold', 'p24_imported_at' => now(),
        ]);
        $run = P24ImportRun::create([
            'user_id' => $admin->id, 'agency_id' => $agencyId,
            'kind' => 'listings_images', 'status' => 'completed',
        ]);
        $earliest = now()->subDays(20);
        // Two confirm rows for the SAME property (a re-import) — earliest wins.
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => $earliest, 'mapped_json' => [],
        ]);
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $property->id,
            'confirmed_at' => now()->subDays(5), 'mapped_json' => [],
        ]);
        // A row pointing at the ALREADY-stamped property — must be left alone.
        P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed', 'target_id' => $alreadyStamped->id,
            'confirmed_at' => now()->subDays(1), 'mapped_json' => [],
        ]);
        $untouchedOriginal = $alreadyStamped->p24_imported_at->toDateTimeString();

        $this->artisan(BackfillP24ImportedStock::class, ['--apply' => true])->assertSuccessful();

        $this->assertSame($earliest->toDateTimeString(), $property->fresh()->p24_imported_at->toDateTimeString());
        $this->assertSame($untouchedOriginal, $alreadyStamped->fresh()->p24_imported_at->toDateTimeString());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'admin',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
