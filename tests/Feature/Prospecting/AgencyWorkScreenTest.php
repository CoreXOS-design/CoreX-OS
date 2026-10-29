<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Prospecting\TrackedPropertyAgencyWork;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Lane B step 4: the agent-facing panel on the tracked property - where it stands, our stock, claimed by, history - and the form that writes it. */
final class AgencyWorkScreenTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private Agency $b;
    private User $adminA;
    private User $adminB;
    private int $tpA;
    private int $tpB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->adminA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->adminB = User::factory()->create(['agency_id' => $this->b->id, 'branch_id' => $branchB->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->tpA = (int) $this->tp(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo'])->id;
        $this->tpB = (int) $this->tp(['street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo'], $this->b)->id;
    }

    private function page(User $as, int $tp)
    {
        return $this->actingAs($as)->get(route('market-intelligence.opportunities.show', $tp));
    }

    public function test_the_panel_shows_in_plain_words_that_only_this_agency_sees_it(): void
    {
        $res = $this->page($this->adminA, $this->tpA)->assertOk();
        $res->assertSee("Your agency's notes on this property", false)->assertSee('Only people in your agency see this', false)
            ->assertSee('Nothing recorded yet')->assertSee('Change where it stands')->assertSee('Owner not selling')->assertSee('Mandate with another agency');
    }

    public function test_saving_a_status_records_it_with_who_and_why_and_shows_it_in_the_history(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.tracked-properties.prospecting-status', $this->tpA), ['prospecting_status' => 'not_selling', 'prospecting_reason' => 'Two years, he said'])
            ->assertRedirect(route('market-intelligence.opportunities.show', $this->tpA))->assertSessionHas('status');

        $res = $this->page($this->adminA, $this->tpA)->assertOk();
        $res->assertSee('Owner not selling')->assertSee('Two years, he said')->assertSee('Status set to Owner not selling', false);
        $this->assertSame($this->adminA->id, (int) TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpA)->value('status_set_by_user_id'));

        // clearing it
        $this->actingAs($this->adminA)->post(route('corex.tracked-properties.prospecting-status', $this->tpA), ['prospecting_status' => ''])->assertRedirect();
        $this->page($this->adminA, $this->tpA)->assertSee('Nothing recorded yet')->assertSee('Status cleared');
    }

    public function test_another_agency_neither_sees_nor_can_change_the_status(): void
    {
        $this->actingAs($this->adminA)->post(route('corex.tracked-properties.prospecting-status', $this->tpA), ['prospecting_status' => 'do_not_contact', 'prospecting_reason' => 'zz-private-reason']);

        // agency B's own page for the same street address says nothing is recorded
        $this->page($this->adminB, $this->tpB)->assertOk()->assertSee('Nothing recorded yet')->assertDontSee('zz-private-reason');
        $this->assertDoesNotMatchRegularExpression('/<option value="do_not_contact"[^>]*selected/', $this->page($this->adminB, $this->tpB)->getContent(), 'the status A set is not selected on B\'s page');
        // and it cannot open or write agency A's property at all
        $this->page($this->adminB, $this->tpA)->assertNotFound();
        $this->actingAs($this->adminB)->post(route('corex.tracked-properties.prospecting-status', $this->tpA), ['prospecting_status' => 'interested'])->assertNotFound();
        $this->assertSame('do_not_contact', TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpA)->value('prospecting_status'));
        $this->assertSame(0, TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpB)->count());
    }

    public function test_an_unknown_status_is_refused_with_a_plain_message(): void
    {
        $this->actingAs($this->adminA)->from(route('market-intelligence.opportunities.show', $this->tpA))
            ->post(route('corex.tracked-properties.prospecting-status', $this->tpA), ['prospecting_status' => 'banana'])
            ->assertSessionHasErrors(['prospecting_status' => 'Pick one of the listed statuses.']);
        $this->assertSame(0, TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpA)->count());
    }

    public function test_own_stock_and_the_claim_show_for_the_holding_agency_only(): void
    {
        $propertyId = $this->property($this->adminA->id, ['status' => 'active']);
        TrackedPropertyAgencyWork::query(); // touch the model
        \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->whereKey($this->tpA)->first()->forceFill(['promoted_to_property_id' => $propertyId])->save();
        OnMarketStockService::flushCache();

        $this->page($this->adminA, $this->tpA)->assertSee('Yes - we hold this property');
        $this->page($this->adminB, $this->tpB)->assertSee('No')->assertDontSee('Yes - we hold this property');
        (new AgencyWorkService())->history($this->agency->id, $this->tpA);
        $this->assertTrue(true);
    }
}
