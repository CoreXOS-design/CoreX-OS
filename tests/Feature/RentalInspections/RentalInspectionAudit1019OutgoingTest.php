<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryMoveOutSignOff;
use App\Models\User;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\RentalDepositDeductionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audit 2026-10-19 - the outgoing side (B1-B5, B31). Johan's ruling: the money stays in the agency's accounting system; CoreX only records
 * deductions WITH amounts.
 */
final class RentalInspectionAudit1019OutgoingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\RentalInspections\Concerns\RecordsAttendance;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Outgoing Agency', 'slug' => 'out-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '9 Outgoing Road', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'deposit_amount' => 18000, 'start_date' => now()->subMonths(11),
            'created_by_user_id' => $this->agent->id,
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu' . uniqid(), 'email' => 'sipho-' . uniqid() . '@example.co.za',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id]);

        DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.review_deposit_comparison', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inventories.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inventories.create', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.create', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'leases.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($this->agent);
    }

    /** Johan's ruling 20 Oct 2026: amounts are recorded only when the agency turns "Record deduction amounts" on (default off). */
    private function amountsOn(): void
    {
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['record_deduction_amounts' => true]);
    }

    private function inspection(string $type, string $status = RentalInspection::STATUS_COMPLETED): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'completed_at' => $status === RentalInspection::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function item(string $label): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function observe(RentalInspection $insp, RentalInspectionItem $item, string $condition): void
    {
        RentalInspectionObservation::create([
            'agency_id' => $insp->agency_id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => $condition, 'notes' => $condition === 'good' ? null : 'Marked.',
            'source' => $insp->type === 'in' ? 'in_inspection' : 'out_inspection',
        ]);
    }

    /** A completed In and an Out under way with one declined item. */
    private function declinedItemOnAnOut(): array
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);
        $item = $this->item('Kitchen counter');
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged');

        return [$in, $out, $item];
    }

    // ── B1 ──────────────────────────────────────────────────────────────

    public function test_a_charge_to_the_tenant_needs_an_amount(): void
    {
        $this->amountsOn();
        [, $out, $item] = $this->declinedItemOnAnOut();

        $this->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $item]), ['disposition' => 'charge_tenant', 'note' => 'Burn mark'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, RentalInspectionItemFinding::count());

        $this->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $item]), ['disposition' => 'charge_tenant', 'note' => 'Burn mark', 'amount' => '1250.50'])
            ->assertRedirect(route('corex.rental-inspections.deposit-comparison', $out));
        $this->assertSame('1250.50', (string) RentalInspectionItemFinding::firstOrFail()->amount);
    }

    public function test_another_judgement_may_carry_an_optional_amount_but_it_is_never_a_deduction(): void
    {
        $this->amountsOn();
        [, $out, $item] = $this->declinedItemOnAnOut();

        $this->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $item]), ['disposition' => 'wear_and_tear', 'note' => 'Normal use', 'amount' => '999'])
            ->assertRedirect();

        $this->assertSame('999.00', (string) RentalInspectionItemFinding::firstOrFail()->amount, 'recorded (audit A24)');
        $summary = app(RentalDepositDeductionsService::class)->summaryFor($this->lease);
        $this->assertSame(0, $summary['lines']->count(), 'but only a charge to the tenant is a deduction');
        $this->assertEqualsWithDelta(0.0, $summary['total'], 0.001);
    }

    public function test_the_lease_summary_adds_up_only_live_charges(): void
    {
        $this->amountsOn();
        [$in, $out, $item] = $this->declinedItemOnAnOut();
        $second = $this->item('Bath mat');
        $this->observe($in, $second, 'good');
        $this->observe($out, $second, 'missing');
        $user = $this->agent;

        RentalInspectionItemFinding::record($out, $item, 'charge_tenant', 'First view', $user, 500.00);
        RentalInspectionItemFinding::record($out, $item, 'charge_tenant', 'Corrected', $user, 750.00); // supersedes the 500
        RentalInspectionItemFinding::record($out, $second, 'landlord_cost', 'Not the tenant', $user);

        $summary = app(RentalDepositDeductionsService::class)->summaryFor($this->lease);

        $this->assertSame(1, $summary['lines']->count(), 'only the live charge_tenant finding is a deduction');
        $this->assertEqualsWithDelta(750.00, $summary['total'], 0.001);
        $this->assertSame(0, $summary['unpriced']);
    }

    public function test_a_lease_with_no_out_has_no_summary(): void
    {
        $this->assertNull(app(RentalDepositDeductionsService::class)->summaryFor($this->lease));
    }

    public function test_the_deductions_page_shows_amounts_and_the_money_note(): void
    {
        $this->amountsOn();
        [, $out, $item] = $this->declinedItemOnAnOut();
        $out->update(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        RentalInspectionItemFinding::record($out, $item, 'charge_tenant', 'Burn mark', $this->agent, 1250.50);

        $this->get(route('corex.leases.deposit-deductions', $this->lease))
            ->assertOk()
            ->assertSee('Kitchen counter')
            ->assertSee('R1,250.50')
            ->assertSee('Total recorded')
            ->assertSee('accounting system')
            ->assertSee('Move-out comparison');
    }

    public function test_the_deductions_page_is_404_for_a_lease_in_another_agency(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $otherProperty = Property::forceCreate(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'agent_id' => $this->agent->id, 'title' => 'Other', 'status' => 'let_out', 'listing_type' => 'rental']);
        $otherLease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 1, 'start_date' => now()->subMonth(), 'created_by_user_id' => $this->agent->id,
        ]);

        $this->get(route('corex.leases.deposit-deductions', $otherLease->id))->assertNotFound();
    }

    // ── B2 ──────────────────────────────────────────────────────────────

    public function test_completing_an_out_with_open_items_warns_and_completes_when_acknowledged(): void
    {
        [, $out] = $this->declinedItemOnAnOut(); // one declined item, no finding = an open finding
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false,
            'all_items_required_to_complete' => false, 'signatures_required_out' => false,
        ]);

        $this->recordAttendanceForEveryParty($out);
        $first = $this->postJson(route('corex.rental-inspections.complete', $out));
        $first->assertStatus(409)->assertJsonPath('requires_acknowledgement', true);
        $keys = collect($first->json('warnings'))->pluck('key')->all();
        $this->assertContains('open_findings', $keys);
        $this->assertSame(RentalInspection::STATUS_IN_PROGRESS, $out->fresh()->status, 'warnings never change anything');

        $this->postJson(route('corex.rental-inspections.complete', $out), ['acknowledge_warnings' => 1])->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $out->fresh()->status);
    }

    public function test_an_out_with_nothing_to_warn_about_completes_straight_away(): void
    {
        $out = $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false,
            'all_items_required_to_complete' => false, 'signatures_required_out' => false,
        ]);

        $this->recordAttendanceForEveryParty($out);
        $this->assertSame([], app(RentalDepositDeductionsService::class)->outCompletionWarnings($out->fresh()->load('lease')));
        $this->postJson(route('corex.rental-inspections.complete', $out))->assertOk();
    }

    public function test_open_repairs_and_an_unfinished_inventory_check_are_warned_about(): void
    {
        $out = $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);
        DB::table('rental_fault_reports')->insert($this->faultRow());
        $inventory = RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => RentalInventory::STATUS_COMPLETED, 'completed_at' => now()->subYear(), 'created_by_user_id' => $this->agent->id,
        ]);

        $keys = collect(app(RentalDepositDeductionsService::class)->outCompletionWarnings($out->fresh()->load('lease')))->pluck('key')->all();

        $this->assertContains('open_repairs', $keys);
        $this->assertContains('inventory_not_done', $keys);
        $this->assertNotNull($inventory->id);
    }

    /** @return array<string, mixed> */
    private function faultRow(): array
    {
        return [
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'status' => 'reported', 'title' => 'Leaking tap', 'description' => 'Leaking tap', 'reported_by_type' => 'tenant', 'reported_channel' => 'phone', 'reported_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    // ── B3 / B4 ─────────────────────────────────────────────────────────

    public function test_the_hub_offers_the_deductions_review_after_a_completed_out_until_it_is_marked_reviewed(): void
    {
        $this->lease->update(['status' => Lease::STATUS_EXPIRED]); // the tenancy ends once the Out is done
        $out = $this->inspection('out');

        $step = app(LeaseHubService::class)->nextStep($this->lease->fresh(), $this->agent);
        $this->assertSame('Review the move-out charges', $step['label'], 'amounts are off by default (Johan, 20 Oct)');
        $this->assertSame('corex.leases.deposit-deductions', $step['route_name']);

        $this->post(route('corex.leases.deposit-deductions.reviewed', $this->lease))->assertRedirect(route('corex.leases.deposit-deductions', $this->lease));
        $this->assertNotNull($out->fresh()->deductions_reviewed_at);
        $this->assertNull(app(LeaseHubService::class)->nextStep($this->lease->fresh(), $this->agent));
    }

    public function test_no_review_step_while_the_out_is_not_completed(): void
    {
        $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);

        $step = app(LeaseHubService::class)->nextStep($this->lease->fresh(), $this->agent);
        $this->assertNotSame('Review the deposit deductions', $step['label'] ?? null);
    }

    public function test_the_comparison_link_is_offered_only_once_the_out_is_completed(): void
    {
        $out = $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);
        $this->assertNull(app(RentalDepositDeductionsService::class)->summaryFor($this->lease)['comparison_url']);

        $out->update(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        $this->assertSame(route('corex.rental-inspections.deposit-comparison', $out), app(RentalDepositDeductionsService::class)->summaryFor($this->lease)['comparison_url']);
    }

    public function test_the_lease_hub_shows_the_deposit_deductions_card(): void
    {
        $this->amountsOn();
        [, $out, $item] = $this->declinedItemOnAnOut();
        $out->update(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);
        RentalInspectionItemFinding::record($out, $item, 'charge_tenant', 'Burn mark', $this->agent, 300);

        $this->get(route('corex.leases.show', $this->lease))->assertOk()->assertSee('Deposit deductions')->assertSee('R300.00');
    }

    // ── B5 ──────────────────────────────────────────────────────────────

    private function completedInventoryWithTwoLines(): array
    {
        $inventory = RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => RentalInventory::STATUS_COMPLETED, 'completed_at' => now()->subYear(), 'created_by_user_id' => $this->agent->id,
        ]);
        $lines = collect(['Gate remotes', 'Sofa'])->map(fn ($d, $i) => RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'room_label' => 'Lounge', 'quantity' => $i + 2,
            'description' => $d, 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
        ]));

        return [$inventory, $lines];
    }

    public function test_the_move_out_check_cannot_complete_until_every_line_has_a_finding(): void
    {
        [$inventory, $lines] = $this->completedInventoryWithTwoLines();

        $this->post(route('corex.rental-inventories.move-out.complete', $inventory))->assertSessionHasErrors('move_out');
        $this->assertFalse($inventory->fresh()->isMoveOutCompleted());

        foreach ($lines as $line) {
            $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$inventory, $line]), ['disposition_key' => 'present', 'quantity_found' => $line->quantity])->assertSuccessful();
        }
        $this->post(route('corex.rental-inventories.move-out.complete', $inventory))->assertRedirect(route('corex.rental-inventories.comparison', $inventory));
        $this->assertTrue($inventory->fresh()->isMoveOutCompleted());
    }

    public function test_a_completed_move_out_check_takes_no_more_findings(): void
    {
        [$inventory, $lines] = $this->completedInventoryWithTwoLines();
        foreach ($lines as $line) {
            $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$inventory, $line]), ['disposition_key' => 'present'])->assertSuccessful();
        }
        $inventory->fresh()->completeMoveOut($this->agent);

        $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$inventory, $lines[0]]), ['disposition_key' => 'missing', 'notes' => 'gone'])->assertStatus(409);
    }

    public function test_sign_off_is_recorded_per_party_and_a_correction_archives_the_old_row(): void
    {
        [$inventory, $lines] = $this->completedInventoryWithTwoLines();
        foreach ($lines as $line) {
            $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$inventory, $line]), ['disposition_key' => 'present'])->assertSuccessful();
        }
        $inventory = $inventory->fresh();

        // nobody can sign off before the check is completed
        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'agent', 'disposition' => 'signed', 'signed_name' => 'Agent'])->assertSessionHasErrors('move_out');

        $inventory->completeMoveOut($this->agent);
        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'agent', 'disposition' => 'signed', 'signed_name' => 'Agent One'])->assertSessionHasNoErrors();
        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'agent', 'disposition' => 'signed', 'signed_name' => 'Agent Two'])->assertSessionHasNoErrors();

        $this->assertSame(1, RentalInventoryMoveOutSignOff::where('rental_inventory_id', $inventory->id)->where('party_role', 'agent')->count(), 'one live row');
        $this->assertSame(2, RentalInventoryMoveOutSignOff::withTrashed()->where('rental_inventory_id', $inventory->id)->where('party_role', 'agent')->count(), 'the old row is archived, not deleted');
        $this->assertFalse($inventory->fresh()->moveOutSignOffState()['signed_off'], 'the tenant has not signed off yet');

        $tenantContactId = (int) $this->lease->tenants()->first()->contact_id;
        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'tenant', 'party_contact_id' => $tenantContactId, 'disposition' => 'refused', 'signed_name' => 'Sipho', 'refusal_reason' => 'Disputes the count'])->assertSessionHasNoErrors();
        $this->assertTrue($inventory->fresh()->moveOutSignOffState()['signed_off'], 'every party has a disposition and the agent signed');
    }

    public function test_a_stranger_cannot_be_signed_off_and_a_refusal_needs_a_reason(): void
    {
        [$inventory, $lines] = $this->completedInventoryWithTwoLines();
        foreach ($lines as $line) {
            $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$inventory, $line]), ['disposition_key' => 'present'])->assertSuccessful();
        }
        $inventory->fresh()->completeMoveOut($this->agent);
        $inventory = $inventory->fresh();

        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'tenant', 'party_contact_id' => 999999, 'disposition' => 'signed', 'signed_name' => 'Nobody'])->assertSessionHasErrors('move_out');
        $tenantContactId = (int) $this->lease->tenants()->first()->contact_id;
        $this->post(route('corex.rental-inventories.move-out.sign-off', $inventory), ['party_role' => 'tenant', 'party_contact_id' => $tenantContactId, 'disposition' => 'refused', 'signed_name' => 'Sipho'])->assertSessionHasErrors('move_out');
        $this->assertSame(0, RentalInventoryMoveOutSignOff::count());
    }

    // ── B31 ─────────────────────────────────────────────────────────────

    public function test_inventory_key_and_remote_lines_are_listed_for_reference_on_the_inspection(): void
    {
        $this->completedInventoryWithTwoLines();
        $in = $this->inspection('in', RentalInspection::STATUS_IN_PROGRESS);

        $this->assertSame(['2 x Gate remotes'], $in->inventoryKeyRemoteLines());
    }
}
