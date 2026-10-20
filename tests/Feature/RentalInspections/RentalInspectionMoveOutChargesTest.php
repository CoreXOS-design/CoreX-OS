<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\RentalDepositDeductionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan's ruling, 20 Oct 2026: money is NOT tracked in CoreX (the accounting system handles it). "Record deduction amounts" is an agency
 * setting, default OFF. Off: a charge to the tenant needs no amount and shows no amount field; the lease's card and page are "Move-out charges"
 * and list the charged items only (no totals, no R figure, no deposit held), with any linked work order as a link. On: the audit-fix behaviour,
 * titled "Deposit deductions".
 */
final class RentalInspectionMoveOutChargesTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'Charges Agency', 'slug' => 'chg-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '4 Charges Road', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'deposit_amount' => 18000, 'start_date' => now()->subMonths(11),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->actingAs($this->agent);
    }

    private function amountsOn(bool $on = true): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['record_deduction_amounts' => $on]);
    }

    private function inspection(string $type, string $status = RentalInspection::STATUS_COMPLETED): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'completed_at' => $status === RentalInspection::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function observe(RentalInspection $insp, RentalInspectionItem $item, string $condition): RentalInspectionObservation
    {
        return RentalInspectionObservation::create([
            'agency_id' => $insp->agency_id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => $condition, 'notes' => $condition === 'good' ? null : 'Marked.',
            'source' => $insp->type === 'in' ? 'in_inspection' : 'out_inspection',
        ]);
    }

    /** A completed In, and an Out with one item gone from good to damaged. */
    private function declinedItem(string $outStatus = RentalInspection::STATUS_IN_PROGRESS): array
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', $outStatus);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Kitchen counter', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->observe($in, $item, 'good');
        $observation = $this->observe($out, $item, 'damaged');

        return [$out, $item, $observation];
    }

    private function postFinding(RentalInspection $out, RentalInspectionItem $item, array $data)
    {
        return $this->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $item]), $data);
    }

    // ── the setting ──────────────────────────────────────────────────────

    public function test_amounts_are_off_by_default_and_the_setting_saves_without_being_reset_by_a_step_that_omits_it(): void
    {
        $this->assertFalse(RentalInspectionSetting::recordDeductionAmountsFor($this->agency->id));
        $this->assertFalse(RentalInspectionSetting::recordDeductionAmountsFor(null));

        $base = ['fault_report_window_days' => 14, 'out_inspection_signing_window_days' => 7];
        $this->post(route('corex.settings.rental-inspections.update'), $base + ['record_deduction_amounts' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(RentalInspectionSetting::recordDeductionAmountsFor($this->agency->id));

        // a Setup Wizard step that never renders the toggle must not reset it
        $this->post(route('corex.settings.rental-inspections.update'), $base)->assertSessionHasNoErrors();
        $this->assertTrue(RentalInspectionSetting::recordDeductionAmountsFor($this->agency->id));

        $this->post(route('corex.settings.rental-inspections.update'), $base + ['record_deduction_amounts' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(RentalInspectionSetting::recordDeductionAmountsFor($this->agency->id));
    }

    public function test_the_setting_is_on_the_settings_page_and_in_the_setup_wizard_copy(): void
    {
        $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()->assertSee('Record deduction amounts');

        $keys = [];
        $copy = config('agency-onboarding-copy');
        array_walk_recursive($copy, function ($value, $key) use (&$keys) {
            if ($key === 'key') {
                $keys[] = $value;
            }
        });
        $this->assertContains('record_deduction_amounts', $keys);
    }

    // ── off: no money ────────────────────────────────────────────────────

    public function test_with_amounts_off_a_charge_to_the_tenant_needs_no_amount_and_any_amount_sent_is_not_kept(): void
    {
        [$out, $item] = $this->declinedItem();

        $this->postFinding($out, $item, ['disposition' => 'charge_tenant', 'note' => 'Burn mark'])->assertSessionHasNoErrors();
        $finding = RentalInspectionItemFinding::firstOrFail();
        $this->assertSame('charge_tenant', $finding->disposition);
        $this->assertNull($finding->amount);

        $this->postFinding($out, $item, ['disposition' => 'charge_tenant', 'note' => 'Burn mark, again', 'amount' => '999'])->assertSessionHasNoErrors();
        $this->assertNull(RentalInspectionItemFinding::whereNull('superseded_at')->firstOrFail()->amount, 'money is not tracked, so a sent amount is dropped');
    }

    public function test_with_amounts_off_the_comparison_shows_no_amount_field_and_with_amounts_on_it_does(): void
    {
        [$out, $item] = $this->declinedItem();
        $this->get(route('corex.rental-inspections.deposit-comparison', $out))->assertOk()->assertDontSee('name="amount"', false);

        $this->amountsOn();
        $this->get(route('corex.rental-inspections.deposit-comparison', $out))->assertOk()->assertSee('name="amount"', false);
    }

    public function test_with_amounts_off_the_summary_carries_no_money_even_when_an_older_finding_has_an_amount(): void
    {
        [$out, $item] = $this->declinedItem(RentalInspection::STATUS_COMPLETED);
        RentalInspectionItemFinding::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $out->id, 'rental_inspection_item_id' => $item->id,
            'disposition' => 'charge_tenant', 'note' => 'Burn mark', 'amount' => 777.00, 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(),
        ]);

        $summary = app(RentalDepositDeductionsService::class)->summaryFor($this->lease);
        $this->assertFalse($summary['record_amounts']);
        $this->assertSame('Move-out charges', $summary['title']);
        $this->assertNull($summary['total']);
        $this->assertSame(0, $summary['unpriced']);
        $this->assertNull($summary['deposit']);
        $this->assertNull($summary['lines']->first()['amount']);
    }

    public function test_with_amounts_off_the_page_lists_the_charged_items_with_a_work_order_link_and_no_money(): void
    {
        [$out, $item, $observation] = $this->declinedItem(RentalInspection::STATUS_COMPLETED);
        RentalInspectionItemFinding::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $out->id, 'rental_inspection_item_id' => $item->id,
            'disposition' => 'charge_tenant', 'note' => 'Burn mark', 'amount' => 777.00, 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(),
        ]);
        $wo = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION,
            'reported_by_user_id' => $this->agent->id, 'title' => 'Replace counter top', 'description' => 'Replace counter top',
            'status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id, 'reported_inspection_observation_id' => $observation->id,
        ]);

        $page = $this->get(route('corex.leases.deposit-deductions', $this->lease))->assertOk();
        $page->assertSee('Move-out charges')->assertSee('Kitchen counter')->assertSee('Burn mark')->assertSee($this->agent->name)
            ->assertSee('Replace counter top')->assertSee(route('corex.rental-work-orders.show', $wo), false)
            ->assertDontSee('Deposit deductions')->assertDontSee('Total recorded')->assertDontSee('Deposit held')
            ->assertDontSee('777.00')->assertDontSee('R777')->assertDontSee('18,000')->assertDontSee('no amount recorded');

        $card = $this->get(route('corex.leases.show', $this->lease))->assertOk();
        $card->assertSee('Move-out charges')->assertSee('Replace counter top')->assertDontSee('Deposit deductions')->assertDontSee('R777')->assertDontSee('777.00');
    }

    public function test_with_amounts_off_the_next_step_is_named_move_out_charges(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);
        $this->inspection('out');

        $step = app(LeaseHubService::class)->nextStep($this->lease->fresh(), $this->agent);
        $this->assertSame('Review the move-out charges', $step['label']);
    }

    // ── on: today's behaviour ────────────────────────────────────────────

    public function test_with_amounts_on_it_is_deposit_deductions_with_amounts_totals_and_the_deposit(): void
    {
        $this->amountsOn();
        [$out, $item] = $this->declinedItem(RentalInspection::STATUS_COMPLETED);

        $this->postFinding($out, $item, ['disposition' => 'charge_tenant', 'note' => 'Burn mark'])->assertSessionHasErrors('amount');
        $this->postFinding($out, $item, ['disposition' => 'charge_tenant', 'note' => 'Burn mark', 'amount' => '300'])->assertSessionHasNoErrors();

        $this->get(route('corex.leases.deposit-deductions', $this->lease))->assertOk()
            ->assertSee('Deposit deductions')->assertSee('R300.00')->assertSee('Total recorded')->assertSee('R18,000.00');
        $this->get(route('corex.leases.show', $this->lease))->assertOk()->assertSee('Deposit deductions')->assertSee('R300.00');

        $this->lease->update(['status' => Lease::STATUS_EXPIRED]);
        $this->assertSame('Review the deposit deductions', app(LeaseHubService::class)->nextStep($this->lease->fresh(), $this->agent)['label']);
    }
}
