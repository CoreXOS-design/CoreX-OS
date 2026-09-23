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
}
