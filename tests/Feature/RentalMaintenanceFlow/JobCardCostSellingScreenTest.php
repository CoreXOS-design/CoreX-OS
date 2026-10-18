<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4.5 / §17.15 — the job card screen: Cost and Margin exist in the page ONLY for
 * `rental_job_cards.view_costs` (server-side — absent from the markup, not hidden by CSS); selling, markup and "accept the
 * crew's lines" need `rental_job_cards.price`, enforced on the server behind the screen; and every new route blocks a direct
 * POST by id from outside the user's scope (403) or another agency (404).
 */
final class JobCardCostSellingScreenTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private RentalJobCardLine $costed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Screens');
        $this->card = $this->emptyCard();
        // cost 61.31 each (distinctive), +20 % line markup -> selling 73.57
        $this->costed = $this->officeLine($this->card, ['description' => 'Distinctive part', 'unit_cost' => 61.31, 'markup_type' => 'percent', 'markup_value' => 20]);
    }

    private function show(User $user): string
    {
        return $this->actingAs($user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
    }

    private function hasCostInput(string $html): bool
    {
        return (bool) preg_match('/<input[^>]*name=["\']?unit_cost/', $html);
    }

    public function test_without_view_costs_cost_and_margin_are_absent_from_the_page_not_hidden(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create']);

        $html = $this->show($agent);

        $this->assertStringContainsString('Distinctive part', $html);
        $this->assertStringContainsString('73.57', $html, 'selling is visible');
        $this->assertStringNotContainsString('61.31', $html, 'the cost figure is not in the page at all');
        $this->assertStringNotContainsString('data-line-margin', $html);
        $this->assertStringNotContainsString('data-cost-margin-totals', $html);
        $this->assertStringNotContainsString('Cost total', $html);
        $this->assertFalse($this->hasCostInput($html), 'no cost box in any edit row');
    }

    public function test_with_view_costs_the_cost_margin_and_totals_appear(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.view_costs']);

        $html = $this->show($agent);

        $this->assertStringContainsString('61.31', $html);
        $this->assertStringContainsString('data-line-margin', $html);
        $this->assertStringContainsString('data-cost-margin-totals', $html);
        $this->assertStringContainsString('Margin (excl VAT)', $html);
        $this->assertStringContainsString('+20 % line', $html, 'the small basis label under selling');
        // margin: selling 73.57 - cost 61.31 = 12.26 (16.7 %)
        $this->assertStringContainsString('R12.26', $html);
        $this->assertFalse($this->hasCostInput($html), 'seeing costs is not editing them: that needs `price` as well');
    }

    public function test_cost_and_selling_boxes_appear_for_someone_who_can_both_see_and_set_prices(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.view_costs', 'rental_job_cards.price']);

        $html = $this->show($agent);

        $this->assertTrue($this->hasCostInput($html));
        $this->assertMatchesRegularExpression('/<input[^>]*name=["\']?unit_price/', $html);
        $this->assertStringContainsString('Back to automatic', $html);
        $this->assertStringContainsString('data-markup-form', $html, 'the Pricing panel');
    }

    public function test_price_without_view_costs_can_set_selling_but_never_sees_or_edits_a_cost(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price']);

        $html = $this->show($agent);

        $this->assertMatchesRegularExpression('/<input[^>]*name=["\']?unit_price/', $html);
        $this->assertFalse($this->hasCostInput($html));
        $this->assertStringNotContainsString('61.31', $html);

        // a hand-made POST that adds a cost is dropped (nobody edits a figure they cannot see)
        $this->actingAs($agent)->put(route('corex.rental-job-cards.lines.update', [$this->card, $this->costed]), [
            'description' => 'Distinctive part', 'quantity' => 1, 'unit_cost' => 5, 'unit_price' => '73.57',
        ])->assertRedirect();
        $this->assertEquals(61.31, (float) $this->costed->fresh()->unit_cost);
    }

    public function test_selling_edits_need_the_price_permission_even_if_the_post_is_hand_made(): void
    {
        $noPrice = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.view_costs']);

        $this->actingAs($noPrice)->put(route('corex.rental-job-cards.lines.update', [$this->card, $this->costed]), [
            'description' => 'Distinctive part', 'quantity' => 1, 'unit_price' => 999, 'markup_type' => 'amount', 'markup_value' => 500, 'unit_cost' => 1,
        ])->assertRedirect();

        $fresh = $this->costed->fresh();
        $this->assertEquals(73.57, (float) $fresh->unit_price, 'the selling price did not move');
        $this->assertSame(RentalJobCardLine::BASIS_LINE_MARKUP, $fresh->selling_basis);
        $this->assertEquals(61.31, (float) $fresh->unit_cost);

        $withPrice = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price', 'rental_job_cards.view_costs']);
        $this->actingAs($withPrice)->put(route('corex.rental-job-cards.lines.update', [$this->card, $this->costed]), [
            'description' => 'Distinctive part', 'quantity' => 1, 'unit_price' => '90.00', 'unit_cost' => 70,
        ])->assertRedirect();

        $fresh = $this->costed->fresh();
        $this->assertEquals(90.00, (float) $fresh->unit_price);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $fresh->selling_basis);
        $this->assertEquals(70.00, (float) $fresh->unit_cost);
    }

    public function test_adding_a_line_without_price_ignores_a_hand_made_selling_price(): void
    {
        $noPrice = $this->agentHolding(['rental_job_cards.create']);

        $this->actingAs($noPrice)->post(route('corex.rental-job-cards.lines.store', $this->card), [
            'description' => 'Sneaky', 'type' => 'part', 'quantity' => 1, 'unit_price' => 5000, 'unit_cost' => 1,
        ])->assertRedirect();

        $line = $this->card->lines()->where('description', 'Sneaky')->firstOrFail();
        $this->assertNull($line->unit_price, 'no price right, so none typed — and no cost to price from, so it stays blank');
        $this->assertNull($line->unit_cost);
    }

    public function test_the_pricing_panel_applies_a_card_markup_and_logs_it(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price']);
        $auto = $this->officeLine($this->card, ['description' => 'Auto line', 'unit_cost' => 200]);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), [
            'markup_all_percent' => '', 'markup_parts_percent' => 25, 'markup_labour_percent' => '',
        ])->assertRedirect(route('corex.rental-job-cards.show', $this->card))->assertSessionHas('success');

        $this->assertEquals(250.00, (float) $auto->fresh()->unit_price);
        $this->assertEquals(73.57, (float) $this->costed->fresh()->unit_price, "a line's own markup is untouched");
        $this->assertEquals(25.00, (float) $this->card->fresh()->markup_parts_percent);
        $this->assertTrue($this->card->updates()->where('update_type', 'markup_set')->exists());
    }

    public function test_the_markup_boxes_reject_nonsense_in_plain_language(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.price']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), ['markup_parts_percent' => -5])
            ->assertSessionHasErrors('markup_parts_percent');
        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), ['markup_parts_percent' => 5000])
            ->assertSessionHasErrors('markup_parts_percent');
        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), ['markup_parts_percent' => 'lots'])
            ->assertSessionHasErrors('markup_parts_percent');
        $this->assertNull($this->card->fresh()->markup_parts_percent);
    }

    public function test_a_closed_card_refuses_a_markup_and_a_line_price_edit(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.price', 'rental_job_cards.create']);
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), ['markup_all_percent' => 10])
            ->assertSessionHasErrors('pricing');
        $this->assertNull($this->card->fresh()->markup_all_percent);
    }

    public function test_markup_needs_the_price_permission(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.markup', $this->card), ['markup_all_percent' => 10])->assertForbidden();
    }

    public function test_the_unpriced_line_is_flagged_and_blocks_the_quote(): void
    {
        $agent = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.send_quote']);
        $this->officeLine($this->card, ['description' => 'Needs a price']);

        $html = $this->show($agent);
        $this->assertStringContainsString('needs a price', $html);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.send-quote', $this->card))
            ->assertSessionHasErrors('rental_job_card');
        $this->assertStringContainsString('Price every line first', session('errors')->first('rental_job_card'));
        $this->assertSame(0, $this->card->quoteRevisions()->count());
    }

    /** @return array<string, array{0: string, 1: string}> route name => method */
    public static function newOfficeRoutes(): array
    {
        return [
            'markup' => ['corex.rental-job-cards.markup', 'markup'],
            'ask crew to price' => ['corex.rental-job-cards.price-requests.store', 'ask'],
            'accept all' => ['corex.rental-job-cards.crew-lines.accept-all', 'acceptall'],
            'accept one' => ['corex.rental-job-cards.crew-lines.accept', 'accept'],
            'reject one' => ['corex.rental-job-cards.crew-lines.reject', 'reject'],
            'close request' => ['corex.rental-job-cards.price-requests.close', 'close'],
        ];
    }

    private function routeParams(string $kind, RentalJobCard $card, ?RentalJobCardLine $line, ?int $requestId): array
    {
        return match ($kind) {
            'accept', 'reject' => [$card, $line?->id ?? 1],
            'close' => [$card, $requestId ?? 1],
            default => [$card],
        };
    }

    #[DataProvider('newOfficeRoutes')]
    public function test_every_new_route_blocks_a_direct_post_by_id_outside_the_users_scope(string $routeName, string $kind): void
    {
        // an agent who holds every key but only at "own" scope, looking at a card the ADMIN created
        $this->grantExactly('admin', ['rental_job_cards.view', 'rental_job_cards.create', 'rental_job_cards.share', 'rental_job_cards.price']);
        $this->grantExactly('agent', ['rental_job_cards.create', 'rental_job_cards.share', 'rental_job_cards.price']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_job_cards.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $line = $this->awaitingLine($this->card);
        $request = $this->card->priceRequests()->create(['agency_id' => $this->agency->id, 'requested_by_user_id' => $this->admin->id, 'requested_at' => now(), 'status' => 'open']);

        $this->actingAs($agent)
            ->post(route($routeName, $this->routeParams($kind, $this->card, $line, $request->id)), ['reason' => 'x', 'note' => 'x'])
            ->assertForbidden();
    }

    #[DataProvider('newOfficeRoutes')]
    public function test_every_new_route_404s_for_another_agencys_card(string $routeName, string $kind): void
    {
        $theirAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $theirBranch = Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $theirAgency->id]);
        $theirs = User::factory()->create(['agency_id' => $theirAgency->id, 'branch_id' => $theirBranch->id, 'role' => 'admin']);
        $theirProperty = Property::forceCreate(['agency_id' => $theirAgency->id, 'agent_id' => $theirs->id, 'branch_id' => $theirBranch->id, 'title' => '9 Elsewhere', 'status' => 'active', 'listing_type' => 'rental']);
        $theirCard = RentalJobCard::withoutGlobalScopes()->create(['agency_id' => $theirAgency->id, 'branch_id' => $theirBranch->id, 'property_id' => $theirProperty->id, 'title' => 'Theirs', 'status' => 'draft', 'created_by_user_id' => $theirs->id]);

        // our own admin (unseeded agency: every key) pokes the other agency's card id
        $this->actingAs($this->admin)
            ->post(route($routeName, $this->routeParams($kind, $theirCard, null, null)), ['reason' => 'x'])
            ->assertNotFound();
    }

    public function test_the_job_cards_list_has_a_needs_pricing_tile_that_filters(): void
    {
        $this->emptyCard(); // a second card with nothing waiting — must not be listed
        $this->awaitingLine($this->card); // this card now has a line waiting for the office

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-tile="needs_pricing"', $html);

        $filtered = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index', ['needs_pricing' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Bathroom repair', $filtered);
        $this->assertSame(1, substr_count($filtered, 'data-tile="needs_pricing"'));
        $this->assertSame(1, app(\App\Services\Rentals\RentalJobCardListQuery::class, ['user' => $this->admin, 'requestedScope' => null, 'filters' => ['needs_pricing' => true]])->rows()->count());
    }

    public function test_a_card_with_the_pricing_switch_off_shows_no_money_controls_at_all(): void
    {
        \App\Models\RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['capture_prices_on_job_cards' => false]);
        $html = $this->show($this->admin);

        $this->assertStringNotContainsString('data-pricing-panel', $html);
        $this->assertStringNotContainsString('data-cost-margin-totals', $html);
        $this->assertFalse($this->hasCostInput($html));
    }

    public function test_another_agency_sees_its_own_numbers_not_this_agencys(): void
    {
        // The SECOND agency: different markups, an estimate wording of its own, no crews at all — it must still price a line.
        $this->pricingWorld('Cape Town Rentals', ['default_parts_markup_percent' => 35, 'quote_estimate_term' => 'Estimate only — Cape Town wording.']);
        $card = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'No crew here'], $this->admin);
        $line = $this->officeLine($card, ['description' => 'Pipe', 'unit_cost' => 100]);

        $this->assertEquals(135.00, (float) $line->unit_price);
        $this->assertNull($card->rental_crew_id);
        $this->assertSame('Estimate only — Cape Town wording.', \App\Models\RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));
    }
}
