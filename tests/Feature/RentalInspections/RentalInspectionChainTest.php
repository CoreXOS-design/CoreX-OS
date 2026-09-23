<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
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
}
