<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalPropertyWorkTermChange;
use App\Models\User;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.6.2 — the owner's work terms per rental property. Since AT-452 (rentals-faults-work-orders.md §22.7) the
 * no-approval (repair) LIMIT lives in ONE place - the owner's "How the owner wants faults handled" - and this panel keeps only the variation
 * tolerance %: edited with its own permission, blank = "use the agency default" (shown), every change recorded append-only with who, when and how it
 * was agreed, scoped OWN / BRANCH / AGENCY like every property write.
 */
final class WorkTermsTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Terms');
    }

    private function save(array $data, ?Property $property = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(route('corex.properties.rental-work-terms.update', $property ?? $this->property), $data);
    }

    private function ownerLimit(float $limit, ?Property $property = null): void
    {
        app(RentalOwnerFaultSettingsService::class)->capture($property ?? $this->property, $this->admin, ['repair_limit' => $limit, 'emergency_limit' => 0, 'callout_mode' => 'no'], 'phone_call', now(), 'Owner phoned.');
    }

    public function test_the_tolerance_is_saved_and_each_change_is_recorded_with_who_when_and_how_it_was_agreed(): void
    {
        $this->save(['variation_tolerance' => 12.5, 'agreed_with' => 'Agreed by email with the owner, 5 Oct 2026'])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $this->property->fresh();
        $this->assertSame(12.5, (float) $fresh->rental_variation_tolerance_percent);
        $this->assertSame($this->admin->id, (int) $fresh->rental_work_terms_updated_by_user_id);
        $this->assertNotNull($fresh->rental_work_terms_updated_at);

        $rows = RentalPropertyWorkTermChange::withoutGlobalScopes()->where('property_id', $this->property->id)->orderBy('id')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('variation_tolerance', $rows[0]->field);
        $this->assertNull($rows[0]->old_value);
        $this->assertSame('12.50', $rows[0]->new_value);
        $this->assertSame($this->admin->id, (int) $rows[0]->changed_by_user_id);
        $this->assertSame('Agreed by email with the owner, 5 Oct 2026', $rows[0]->agreed_with);
        $this->assertNotNull($rows[0]->changed_at);
    }

    public function test_a_later_change_keeps_the_history_and_a_blank_goes_back_to_the_agency_default(): void
    {
        $this->save(['variation_tolerance' => 10])->assertSessionHasNoErrors();
        $this->travel(1)->minutes();

        $this->save(['variation_tolerance' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->property->fresh()->rental_variation_tolerance_percent, 'blank = inherit the agency default');
        $changes = RentalPropertyWorkTermChange::withoutGlobalScopes()->where('property_id', $this->property->id)->where('field', 'variation_tolerance')->orderBy('id')->get();
        $this->assertCount(2, $changes, 'history only ever grows');
        $this->assertSame('10.00', $changes[1]->old_value);
        $this->assertNull($changes[1]->new_value, 'null = inherit');
    }

    public function test_saving_the_same_value_again_changes_nothing_and_says_so(): void
    {
        $this->save(['variation_tolerance' => 5])->assertSessionHasNoErrors();
        $stamp = $this->property->fresh()->rental_work_terms_updated_at;
        $this->travel(2)->minutes();

        $this->save(['variation_tolerance' => 5, 'agreed_with' => 'reconfirmed'])->assertRedirect()->assertSessionHas('success', 'Nothing changed — the work terms are as they were.');

        $this->assertSame(1, RentalPropertyWorkTermChange::withoutGlobalScopes()->count());
        $this->assertEquals($stamp, $this->property->fresh()->rental_work_terms_updated_at);
    }

    public function test_zero_is_a_value_not_a_blank(): void
    {
        $this->setting(['variation_tolerance_percent' => 15]);

        $this->save(['variation_tolerance' => 0])->assertSessionHasNoErrors();

        $terms = app(\App\Services\Rentals\RentalApprovalGateService::class)->termsFor($this->property->fresh());
        $this->assertSame(0.0, $terms->variationPct, 'the owner agreed "never" — not the agency\'s 15 %');
        $this->assertSame('property', $terms->pctSource);
    }

    public function test_malformed_input_is_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        foreach ([['variation_tolerance' => -5], ['variation_tolerance' => 101], ['variation_tolerance' => 'ten'], ['agreed_with' => str_repeat('x', 300)]] as $bad) {
            $this->save($bad)->assertSessionHasErrors(array_key_first($bad));
        }
        $this->assertSame(0, RentalPropertyWorkTermChange::withoutGlobalScopes()->count());
    }

    public function test_an_empty_post_is_harmless_and_the_old_limit_box_is_no_longer_accepted(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 700])->save();

        $this->save([])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(700.0, (float) $this->property->fresh()->rental_no_approval_spend_threshold);

        // the repair limit is not edited here any more: a posted no_approval_limit is ignored, whatever it says
        $this->save(['no_approval_limit' => 9999, 'variation_tolerance' => 3])->assertSessionHasNoErrors();
        $this->assertSame(700.0, (float) $this->property->fresh()->rental_no_approval_spend_threshold);
        $this->assertSame(0, RentalPropertyWorkTermChange::withoutGlobalScopes()->where('field', 'no_approval_limit')->count());
    }

    public function test_the_panel_has_no_repair_limit_field_and_one_short_tolerance_line(): void
    {
        $this->setting(['variation_tolerance_percent' => 5]);
        $this->save(['variation_tolerance' => 8, 'agreed_with' => 'By phone'])->assertSessionHasNoErrors();

        $html = view('corex.properties._rental-work-terms', ['property' => $this->property->fresh()])->render();

        $this->assertStringContainsString('Work terms agreed with the owner', $html);
        $this->assertStringNotContainsString('name="no_approval_limit"', $html);
        $this->assertStringNotContainsString('No-approval limit (R, per job)', $html);
        $this->assertStringNotContainsString('Emergency work always needs the owner to agree', $html);
        $this->assertStringContainsString('Extra work up to this % above what the owner approved goes ahead and the owner is told.', $html);
        $this->assertStringContainsString('Agency default: 5 %', $html);
        $this->assertStringContainsString('In force: <strong>8 %</strong> (set on this property)', $html);
        // the page HTML-escapes the name, and the factory's random names can carry an apostrophe ("O'Keefe") — compare the escaped form
        $this->assertStringContainsString('Last changed by ' . e($this->admin->name), $html);
        $this->assertStringContainsString('By phone', $html);
    }

    public function test_the_lease_screen_shows_the_limit_from_the_owners_own_settings_and_the_tolerance(): void
    {
        $this->ownerLimit(1750);
        $this->save(['variation_tolerance' => 12])->assertSessionHasNoErrors();
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertSee("Owner's repair limit", false)->assertSee('1,750.00')->assertSee('Extra work passed without asking, up to')->assertSee('12 % above the approved amount');
    }

    // ── ONE place for the repair limit: every reader follows the owner's own settings ──

    public function test_every_reader_of_the_repair_limit_follows_the_owners_own_settings_once_they_have_one(): void
    {
        $this->setting(['no_approval_spend_threshold' => 800]);
        $gate = app(\App\Services\Rentals\RentalApprovalGateService::class);
        $property = fn () => $this->property->fresh();

        // no owner answer yet: the old fallback chain, unchanged (the agency default)
        $this->assertSame(800.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame(800.0, $gate->termsFor($property())->noApprovalLimit);

        // the owner's own settings win - in the gate AND in every screen that asks the setting
        $this->ownerLimit(1500);
        $this->assertSame(1500.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame([1500.0, 'property'], [$gate->termsFor($property())->noApprovalLimit, $gate->termsFor($property())->limitSource]);

        // a dispute = must ask for everything = R0 for the gate
        $row = \App\Models\RentalPropertyFaultSetting::withoutGlobalScopes()->where('property_id', $this->property->id)->first();
        $contact = \App\Models\Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'first_name' => 'Own', 'last_name' => 'Er', 'email' => 'owner+' . uniqid() . '@example.invalid']);
        app(RentalOwnerFaultSettingsService::class)->dispute($this->property, $contact, (int) $row->version, null);
        $this->assertSame(0.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame(0.0, $gate->termsFor($property())->noApprovalLimit);

        // the old column is a fallback only: it is not consulted once the owner has an answer
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 99999])->saveQuietly();
        $this->assertSame(0.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
    }

    // ── permission and scope ─────────────────────────────────────────

    public function test_only_a_user_with_the_manage_work_terms_key_can_save(): void
    {
        $agent = $this->agentWith(['access_properties' => 'all', 'properties.view' => 'all', 'properties.edit' => 'all']);

        $this->save(['variation_tolerance' => 40], null, $agent)->assertForbidden();

        $this->assertNull($this->property->fresh()->rental_variation_tolerance_percent);
        // and with the key they can
        \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_work_orders.manage_work_terms', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();
        $this->save(['variation_tolerance' => 40], null, $agent)->assertSessionHasNoErrors();
        $this->assertSame(40.0, (float) $this->property->fresh()->rental_variation_tolerance_percent);
    }

    public function test_an_own_scope_agent_cannot_change_a_colleagues_property_by_direct_url(): void
    {
        $agent = $this->agentWith(['access_properties' => 'own', 'properties.view' => 'own', 'properties.edit' => 'own', 'rental_work_orders.manage_work_terms' => 'own']);
        $colleaguesProperty = $this->property;   // belongs to the admin (agent_id)
        $ownProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $this->branch->id, 'title' => '8 Own Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->save(['variation_tolerance' => 33], $colleaguesProperty, $agent)->assertForbidden();
        $this->assertNull($colleaguesProperty->fresh()->rental_variation_tolerance_percent);
        $this->assertSame(0, RentalPropertyWorkTermChange::withoutGlobalScopes()->count());

        // the control: the same agent, the same key, their OWN property — so the 403 above really was the scope
        $this->save(['variation_tolerance' => 7], $ownProperty, $agent)->assertSessionHasNoErrors();
        $this->assertSame(7.0, (float) $ownProperty->fresh()->rental_variation_tolerance_percent);
    }

    public function test_another_agencys_property_is_a_404(): void
    {
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $agent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->save(['variation_tolerance' => 1], null, $agent)->assertNotFound();

        $this->assertNull($this->property->fresh()->rental_variation_tolerance_percent);
    }

    public function test_a_sale_property_has_no_work_terms(): void
    {
        $sale = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id, 'title' => '3 Sale Street', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->save(['variation_tolerance' => 10], $sale)->assertForbidden();
    }

    // ── the duplicate input is gone and the old endpoint no longer takes it ──

    public function test_the_old_threshold_inputs_are_gone_from_the_property_screen(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();

        $this->assertStringNotContainsString('No-Approval Spend Threshold (R)', $html);
        $this->assertStringNotContainsString('name="rental_no_approval_spend_threshold"', $html);
        $this->assertStringNotContainsString('name="no_approval_limit"', $html);
        $this->assertStringContainsString('Work terms agreed with the owner', $html);
        $this->assertStringContainsString(route('corex.properties.rental-work-terms.update', $this->property), $html);
    }

    public function test_the_same_second_agency_has_its_own_defaults_and_its_own_history(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        \App\Models\RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $other->id, 'no_approval_spend_threshold' => 2500, 'variation_tolerance_percent' => 20]);
        $this->setting(['no_approval_spend_threshold' => 100, 'variation_tolerance_percent' => 0]);
        $this->save(['variation_tolerance' => 33])->assertSessionHasNoErrors();

        $this->assertSame(2500.0, \App\Models\RentalWorkOrderSetting::spendThresholdFor($other->id));
        $this->assertSame(0, RentalPropertyWorkTermChange::withoutGlobalScopes()->where('agency_id', $other->id)->count());
        $this->assertSame(1, RentalPropertyWorkTermChange::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }
}
