<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan's ruling, 2026-09-23 — "an inspection is not a standalone
 * document, it is the next link in a chain... In -> Routine -> Routine ->
 * Out, any length." Covers RentalInspection::startNext()/historyFor()/
 * previousInspection()/nextInChain(), and the screen's graceful handling
 * of a first inspection with nothing to compare against (§6 of the
 * approved proposal).
 */
final class RentalInspectionChainTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Chain Test Agency', 'slug' => 'chain-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Chain Test Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(6),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(string $label = 'Ceiling'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type, ?RentalInspection $previous = null): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'previous_inspection_id' => $previous?->id, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function observe(RentalInspection $inspection, RentalInspectionItem $item, string $condition): RentalInspectionObservation
    {
        return RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $this->agent->id,
            'condition' => $condition, 'notes' => $condition !== 'good' ? 'Test note' : null,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
    }

    public function test_start_next_records_the_predecessor_and_inherits_the_lease(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        $out = RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        self::assertSame($in->id, $out->previous_inspection_id);
        self::assertSame($this->lease->id, $out->lease_id);
        self::assertSame(RentalInspection::TYPE_OUT, $out->type);
        self::assertTrue($in->fresh()->nextInChain->is($out));
    }

    public function test_start_next_refuses_in_as_a_successor_type(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->expectException(\LogicException::class);
        RentalInspection::startNext($in, RentalInspection::TYPE_IN, $this->agent);
    }

    public function test_start_next_refuses_a_second_successor_for_the_same_predecessor(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspection::startNext($in, RentalInspection::TYPE_AD_HOC, $this->agent);

        $this->expectException(\LogicException::class);
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);
    }

    public function test_history_for_walks_the_whole_chain_oldest_first(): void
    {
        $item = $this->makeItem('Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');
        $routine = $this->makeInspection(RentalInspection::TYPE_AD_HOC, $in);
        $this->observe($routine, $item, 'good');
        $out = $this->makeInspection(RentalInspection::TYPE_OUT, $routine);
        $this->observe($out, $item, 'damaged');

        // Per RentalInspection::historyFor()'s own docblock — call it on
        // the PREDECESSOR to get "everything before" $out, not on $out
        // itself (which would include $out's own value as the run's last
        // entry — exactly the bug this test guards against regressing).
        $history = $out->previousInspection->historyFor($item);

        self::assertCount(2, $history);
        self::assertSame('good', $history->first()->observation->condition);
        self::assertSame(RentalInspection::TYPE_IN, $history->first()->inspection_type);
        self::assertSame('good', $history->last()->observation->condition);
        self::assertSame(RentalInspection::TYPE_AD_HOC, $history->last()->inspection_type);
    }

    public function test_history_for_a_first_inspection_predecessor_is_empty_not_an_error(): void
    {
        $item = $this->makeItem('Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');

        self::assertTrue($in->historyFor($item)->count() >= 1); // $in's own value only, walking from itself.
        self::assertNull($in->previousInspection);
    }

    public function test_show_screen_renders_gracefully_with_no_predecessor(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $item = $this->makeItem('Ceiling');
        $this->observe($in, $item, 'good');

        $resp = $this->get(route('corex.rental-inspections.show', $in));

        $resp->assertOk();
        $resp->assertSee('Ceiling');
        $resp->assertDontSee('Compared against'); // the comparison heading only renders when comparisonRows is non-null.
    }

    public function test_show_screen_renders_the_comparison_table_when_a_predecessor_exists(): void
    {
        $item = $this->makeItem('Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');
        $out = $this->makeInspection(RentalInspection::TYPE_OUT, $in);
        $this->observe($out, $item, 'damaged');

        $resp = $this->get(route('corex.rental-inspections.show', $out));

        $resp->assertOk();
        $resp->assertSee('Compared against the in-inspection');
        $resp->assertSee('Ceiling');
        $resp->assertSee('Good');
        $resp->assertSee('Damaged');
    }

    public function test_next_route_creates_a_successor_and_redirects_to_it(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        $resp = $this->post(route('corex.rental-inspections.next', $in), ['type' => 'out']);

        $out = RentalInspection::where('previous_inspection_id', $in->id)->first();
        self::assertNotNull($out);
        self::assertSame('out', $out->type);
        $resp->assertRedirect(route('corex.rental-inspections.show', $out));
    }

    public function test_next_route_refuses_a_second_successor(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspection::startNext($in, RentalInspection::TYPE_AD_HOC, $this->agent);

        $resp = $this->post(route('corex.rental-inspections.next', $in), ['type' => 'out']);

        $resp->assertSessionHasErrors('rental_inspection');
        self::assertSame(1, RentalInspection::where('previous_inspection_id', $in->id)->count());
    }

    // ── The property tab's own chain resolution (2026-09-23) —
    // RentalInspection::chainTailFor()/tabPayloadFor()'s chain_tail/
    // chain_predecessor, generalized beyond the old fixed in/out slots so
    // the tab can render whichever inspection is the current link, not
    // just one of two hardcoded types. ────────────────────────────────

    public function test_chain_tail_for_resolves_the_actual_tail_of_a_multi_link_chain(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $routine = RentalInspection::startNext($in, RentalInspection::TYPE_AD_HOC, $this->agent);
        $out = RentalInspection::startNext($routine, RentalInspection::TYPE_OUT, $this->agent);

        $tail = RentalInspection::chainTailFor($this->property);

        // Must be the LAST link (Out), never the root (In) or the middle
        // (Routine) — this is the whole point of whereDoesntHave('nextInChain')
        // over walking from the root forward.
        self::assertTrue($tail->is($out));
        self::assertTrue($tail->previousInspection->is($routine));
    }

    public function test_chain_tail_for_returns_null_when_nothing_has_started(): void
    {
        self::assertNull(RentalInspection::chainTailFor($this->property));
    }

    public function test_chain_tail_for_stays_on_a_completed_tail_with_no_successor(): void
    {
        // §6 of the approved proposal / the tab must not go blank the
        // instant the tail completes — that is exactly when "Next
        // inspection" matters most (same reasoning already proven for
        // mostRecentOutFor() vs currentFor() elsewhere in this model).
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $in->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        self::assertTrue(RentalInspection::chainTailFor($this->property)->is($in));
    }

    public function test_tab_payload_exposes_chain_tail_and_predecessor_for_a_multi_link_chain(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $routine = RentalInspection::startNext($in, RentalInspection::TYPE_AD_HOC, $this->agent);
        $out = RentalInspection::startNext($routine, RentalInspection::TYPE_OUT, $this->agent);

        $payload = RentalInspection::tabPayloadFor($this->property);

        self::assertSame($out->id, $payload['chain_tail']->id);
        self::assertSame($routine->id, $payload['chain_predecessor']->id);
        // The pre-existing, unchanged in_inspection/out_inspection keys
        // must still resolve exactly as before (currentFor(), draft
        // status here) — nothing that already reads them needed to change.
        self::assertSame($in->id, $payload['in_inspection']->id);
    }

    public function test_tab_payload_has_null_chain_tail_when_nothing_has_started(): void
    {
        $payload = RentalInspection::tabPayloadFor($this->property);

        self::assertNull($payload['chain_tail']);
        self::assertNull($payload['chain_predecessor']);
    }

    // ── The property-scoped JSON "next" action (2026-09-23) — the tab's
    // own AJAX flow, a second caller of RentalInspection::startNext(),
    // not a second implementation. ──────────────────────────────────────

    public function test_property_scoped_next_route_creates_a_successor(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        $resp = $this->postJson(
            route('corex.properties.rental-inspections.next', [$this->property, $in]),
            ['type' => 'out'],
        );

        $resp->assertCreated();
        $out = RentalInspection::where('previous_inspection_id', $in->id)->first();
        self::assertNotNull($out);
        self::assertSame('out', $out->type);
        self::assertSame($out->id, $resp->json('id'));
    }

    public function test_property_scoped_next_route_refuses_an_inspection_from_a_different_property(): void
    {
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'A Different Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        $resp = $this->postJson(
            route('corex.properties.rental-inspections.next', [$otherProperty, $in]),
            ['type' => 'out'],
        );

        $resp->assertNotFound();
        self::assertSame(0, RentalInspection::where('previous_inspection_id', $in->id)->count());
    }

    public function test_property_scoped_next_route_refuses_a_second_successor(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspection::startNext($in, RentalInspection::TYPE_AD_HOC, $this->agent);

        $resp = $this->postJson(
            route('corex.properties.rental-inspections.next', [$this->property, $in]),
            ['type' => 'out'],
        );

        $resp->assertStatus(409);
        self::assertSame(1, RentalInspection::where('previous_inspection_id', $in->id)->count());
    }

    // ── The property tab itself renders the chain data (not just the
    // agency-level show() screen already covered above). ────────────────

    public function test_property_tab_embeds_chain_tail_and_predecessor_for_the_browser_to_read(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $item = $this->makeItem('Ceiling');
        $this->observe($in, $item, 'good');
        $out = RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));

        $resp->assertOk();
        // The embedded x-data JSON is server-rendered HTML (Standard -1s —
        // this proves the RIGHT data reached the page; it cannot prove
        // Alpine renders it correctly in a browser, which Johan verifies
        // himself on the deployed page). Js::from() HTML-escapes every
        // quote to the literal six characters ", not a real double-
        // quote character — confirmed directly via
        // Illuminate\Support\Js::from(), not assumed.
        $idNeedle = '\\u0022id\\u0022:';
        $resp->assertSee($idNeedle . $out->id, false);
        $resp->assertSee($idNeedle . $in->id, false);
        // The old two-independent-start-buttons shape is gone.
        $resp->assertDontSee('Start Out-Inspection');
    }

    // ── Regression coverage for the 2026-09-23 QA1 bug report on property
    // 5792. Both defects were invisible to a plain assertSee() pass — see
    // each test's own docblock for why, and why the assertion below IS
    // still meaningful despite that. ──────────────────────────────────────

    /**
     * DEFECT 1 root cause: wrapping the multi-root rental-inspection-
     * recording/readonly-panel @includes inside an outer
     * <template x-if="..."> violates Alpine's hard requirement that
     * x-if's <template> contain exactly ONE root element — the included
     * partials each expand to three/two top-level siblings (their own
     * <style>/<template x-if> blocks). Alpine's mount/no-mount behaviour
     * on that violation is CLIENT-SIDE JS; the raw HTTP response bytes
     * are byte-identical whether Alpine successfully mounts the content
     * or silently drops it, so assertSee() on the partial's own markup
     * cannot distinguish broken from fixed (this is exactly why a green
     * suite shipped while the page was dead, and why Johan's instruction
     * was "a passing test suite is not evidence"). What CAN be asserted
     * from raw HTML is the STRUCTURAL shape: the fix replaces the outer
     * <template x-if> wrapper with a plain <div x-show>, which has no
     * single-root constraint. Assert the fixed shape is present and the
     * broken shape is gone — a deterministic, non-JS-dependent check of
     * the actual root cause.
     */
    public function test_unified_section_wraps_the_recording_and_readonly_includes_in_x_show_not_template_x_if(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $out = RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));

        $resp->assertOk();
        $html = $resp->getContent();

        self::assertStringContainsString('x-show="chainTail.status !== \'completed\'"', $html);
        self::assertStringContainsString('x-show="chainTail.status === \'completed\'"', $html);
        self::assertStringNotContainsString('<template x-if="chainTail.status !== \'completed\'">', $html);
        self::assertStringNotContainsString('<template x-if="chainTail.status === \'completed\'">', $html);

        // The recording partial's own tile CSS (each defined exactly once,
        // in its <style> block) must still be shipped — proves the
        // @include itself was not dropped, only its wrapper changed.
        self::assertStringContainsString('.rir-item-photo-tile', $html);
        self::assertStringContainsString('.rir-room-photo-tile', $html);
        self::assertStringContainsString('.rir-tray-tile', $html);
    }

    /**
     * DEFECT 2 — property 5792 had a real In inspection (awaiting_signature)
     * and a real Out inspection (draft), both created before
     * previous_inspection_id existed, so neither carries the explicit
     * link. Johan's ruling: resolve by type+date fallback at read time
     * (inferredPredecessorFor()), never a data migration that writes a
     * permanent, possibly-wrong link. This fixture reproduces that exact
     * shape: two inspections on the same lease with NO previous_inspection_id
     * on either — startNext()/makeInspection(..., $previous) are
     * deliberately NOT used for the link.
     */
    public function test_tab_payload_falls_back_to_type_and_date_ordering_when_predecessor_link_is_absent(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $in->forceFill(['created_at' => now()->subDay()])->save();
        $out = $this->makeInspection(RentalInspection::TYPE_OUT);
        $out->forceFill(['created_at' => now()])->save();

        self::assertNull($out->previous_inspection_id);
        self::assertNull($in->previous_inspection_id);

        $payload = RentalInspection::tabPayloadFor($this->property);

        self::assertSame($out->id, $payload['chain_tail']->id);
        self::assertSame($in->id, $payload['chain_predecessor']->id);

        // The fallback is resolution-time only — it must NEVER persist a
        // link. previous_inspection_id stays null on both rows.
        self::assertNull($out->fresh()->previous_inspection_id);
    }

    /**
     * A cancelled inspection between two unlinked real inspections must
     * never be resolved as the predecessor — property 5792 has exactly
     * this shape (a cancelled Out sitting between the real In and the
     * live draft Out). inferredPredecessorFor() excludes cancelled rows.
     */
    public function test_inferred_predecessor_skips_a_cancelled_inspection_between_two_real_ones(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $in->forceFill(['created_at' => now()->subDays(2)])->save();

        $cancelled = $this->makeInspection(RentalInspection::TYPE_OUT);
        $cancelled->forceFill(['status' => RentalInspection::STATUS_CANCELLED, 'created_at' => now()->subDay()])->save();

        $out = $this->makeInspection(RentalInspection::TYPE_OUT);
        $out->forceFill(['created_at' => now()])->save();

        $predecessor = RentalInspection::inferredPredecessorFor($out);

        self::assertNotNull($predecessor);
        self::assertSame($in->id, $predecessor->id);
    }

    /**
     * When an explicit previous_inspection_id link DOES exist (a chain
     * built going forward via startNext()), the fallback must never
     * override it — even if a more-recently-created inspection on the
     * same lease would otherwise win the date ordering.
     */
    public function test_tab_payload_prefers_the_explicit_link_over_the_fallback_when_both_exist(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);

        // A decoy: an unlinked inspection created BEFORE the real tail
        // (lower id, so it never wins chainTailFor()'s own latest('id')
        // tail selection) that would win inferredPredecessorFor()'s
        // date ordering if the explicit link were ignored. It never gets
        // the chance — $out->previousInspection is set, so the
        // ?? fallback in tabPayloadFor() short-circuits before
        // inferredPredecessorFor() is ever called.
        $decoy = $this->makeInspection(RentalInspection::TYPE_AD_HOC);

        $out = RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $payload = RentalInspection::tabPayloadFor($this->property);

        self::assertSame($out->id, $payload['chain_tail']->id);
        self::assertSame($in->id, $payload['chain_predecessor']->id);
    }

    // ── The side-by-side comparison grid (2026-09-23, second round) —
    // Johan, live-tested on QA1 property 5792: "The two sides are two
    // INDEPENDENT lists rendered next to each other. They share nothing."
    // rental-inspection-recording.blade.php now drives ONE shared x-for
    // over roomGroups()/group.items, rendering a two-column grid row per
    // item with the predecessor (left, read-only) and tail (right,
    // editable) as DOM siblings — see rental-inspection-item-cell.blade.php
    // for the shared per-item skeleton. ─────────────────────────────────

    private function makeRoomItem(string $roomLabel, string $itemLabel): RentalInspectionItem
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => $roomLabel, 'label' => $roomLabel, 'source' => 'manual', 'sort_order' => 0,
            'created_by_user_id' => $this->agent->id,
        ]);

        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $itemLabel, 'space_type' => $roomLabel,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    /**
     * The exact structural claim Johan demanded proof of: "the left cell
     * and the right cell are siblings inside the same grid row container."
     * Asserts it directly from the rendered response — a real CSS Grid
     * container (.rir-compare-row) whose two DIRECT children are
     * .rir-compare-cell divs, one reading chainPredecessor (disabled,
     * read-only), one reading tailSection() (interactive) — both driven
     * by the SAME x-for="item in group.items", so this holds for every
     * item, including "Kitchen / Ceiling" (Johan's own worked example).
     */
    public function test_comparison_row_is_a_grid_container_with_predecessor_and_tail_cells_as_siblings(): void
    {
        $item = $this->makeRoomItem('Kitchen', 'Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));
        $resp->assertOk();
        $html = $resp->getContent();

        self::assertStringContainsString('rir-compare-row', $html);
        self::assertStringContainsString('rir-compare-cell', $html);

        // The row container is a real CSS Grid with two columns — never a
        // flex pair of two independently-run loops (the exact shape Johan
        // rejected: "not by luck").
        self::assertMatchesRegularExpression(
            '/<div class="rir-compare-row[^"]*" style="display:grid; grid-template-columns:1fr 1fr;/',
            $html
        );

        // Immediately inside that row: two .rir-compare-cell divs back to
        // back — the left reads chainPredecessor through the read-only
        // accessor (conditionForInspection), the right reads tailSection()
        // through the live/editable one (selectedConditionFor) — proving
        // they are the SAME row's two siblings, not two separate lists.
        self::assertMatchesRegularExpression(
            '/<div class="rir-compare-row[^"]*"[^>]*>\s*<div class="rir-compare-cell[^"]*">.*?conditionForInspection\(chainPredecessor.*?<\/div>\s*<div class="rir-compare-cell[^"]*">.*?selectedConditionFor\(tailSection\(\)/s',
            $html
        );
    }

    /**
     * Read-only means disabled controls on the SAME component, never a
     * different one — the predecessor cell's condition buttons carry a
     * literal disabled attribute; the tail cell's do not.
     */
    public function test_predecessor_cell_buttons_are_disabled_tail_cell_buttons_are_interactive(): void
    {
        $item = $this->makeRoomItem('Bedroom 1', 'Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));
        $html = $resp->getContent();

        self::assertStringContainsString('<button type="button" disabled', $html);
        self::assertStringContainsString('@click="onConditionTap(tailSection(), item, state.key)"', $html);
    }

    /**
     * The standalone Compare section is being removed (cc2) — every photo
     * tile on both cells must open cc2's shared openCompareViewer() modal,
     * with the SAME call shape already used elsewhere on this page
     * ('item', 'item_' + item.id, null, item). Never the old, now-orphaned
     * openInspectionPhoto() single-viewer path from either cell.
     */
    public function test_both_comparison_cells_open_the_shared_compare_viewer_on_photo_click(): void
    {
        $item = $this->makeRoomItem('Kitchen', 'Ceiling');
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->observe($in, $item, 'good');
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));
        $html = $resp->getContent();

        // >= 4, not an exact count: rental-inspection-recording.blade.php
        // is included TWICE (the active and completed x-show branches —
        // see show.blade.php's own FIX docblock on why), each rendering
        // BOTH cells of the shared item-cell partial with this exact call
        // — 2 cells x 2 branches = 4 from this file alone. The still-
        // present (not yet removed — that's cc2's own, separate change)
        // standalone Compare section already used this identical call
        // shape too, so the real page total is higher; asserting the
        // floor this file is responsible for avoids coupling to whether
        // cc2 has removed that section yet.
        self::assertGreaterThanOrEqual(
            4,
            substr_count($html, "openCompareViewer('item', 'item_' + item.id, null, item)"),
            'both the predecessor and tail item-cell photo tiles must call the same shared viewer'
        );
    }

    /**
     * Item 6, Johan's brief — "The tail's metadata block... moves OUT of
     * the column. It belongs in a header... must not push the right
     * column down relative to the left." Proven by absence of duplication:
     * the metadata block renders exactly once (never per-cell), and
     * nothing about it lives inside a .rir-compare-cell.
     */
    public function test_metadata_block_renders_once_not_per_cell(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));
        $html = $resp->getContent();

        self::assertSame(1, substr_count($html, 'Electricity meter'), 'the metadata block must never be duplicated per column');
    }

    /**
     * Item 7 — "Each column gets a header saying which inspection it is:
     * type, date, status." One header strip, naming both sides, sitting
     * above the shared grid it labels (never inside either cell).
     */
    public function test_column_headers_name_both_inspections(): void
    {
        $in = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspection::startNext($in, RentalInspection::TYPE_OUT, $this->agent);

        $resp = $this->get(route('corex.properties.show', $this->property->id));
        $html = $resp->getContent();

        self::assertStringContainsString('rir-compare-headers', $html);
    }

    /**
     * A completed tail renders BOTH cells read-only — the metadata/
     * discrepancy/progress/tray/notes/signing chrome (nothing left to
     * action on a completed inspection) is skipped entirely for that
     * branch, proven by rendering the partial directly with
     * $tailReadOnly=true vs false and comparing output, since both
     * x-show branches coexist in one HTTP response (Alpine's runtime
     * toggle, not server-conditional) and can't be told apart by a
     * plain assertSee() the way this can.
     */
    public function test_recording_partial_tail_read_only_flag_suppresses_lifecycle_chrome(): void
    {
        $editableHtml = view('corex.properties.partials.rental-inspection-recording', [
            'section' => 'in', 'sectionJs' => "'in'", 'predecessorJs' => 'chainPredecessor', 'tailReadOnly' => false,
        ])->render();
        $readOnlyHtml = view('corex.properties.partials.rental-inspection-recording', [
            'section' => 'in', 'sectionJs' => "'in'", 'predecessorJs' => 'chainPredecessor', 'tailReadOnly' => true,
        ])->render();

        self::assertStringContainsString('Overall notes', $editableHtml);
        self::assertStringNotContainsString('Overall notes', $readOnlyHtml);
        self::assertStringContainsString('Electricity meter', $editableHtml);
        self::assertStringNotContainsString('Electricity meter', $readOnlyHtml);

        // The room/item comparison grid itself is the one thing a
        // completed inspection still needs — it must NOT be skipped.
        self::assertStringContainsString('rir-compare-row', $editableHtml);
        self::assertStringContainsString('rir-compare-row', $readOnlyHtml);
    }
}
