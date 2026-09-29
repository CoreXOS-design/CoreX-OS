<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\LeaseTenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1 (data model) verification for .ai/specs/rental-inspections.md.
 * Every test exercises the real model/DB layer directly — no controller
 * exists yet (stage 3). Covers the §11 acceptance criteria this stage can
 * actually prove.
 */
final class RentalInspectionDataModelTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'RI Test Agency', 'slug' => 'ri-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'title' => 'RI Test Property',
            'status' => 'active',
            'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 12000,
            'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeTenant(?Lease $lease = null): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Tenant', 'last_name' => (string) uniqid(), 'email' => uniqid() . '@example.test',
        ]);
        LeaseTenant::create(['lease_id' => ($lease ?? $this->lease)->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $contact;
    }

    private function makeItem(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE,
            'label' => $label,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id,
            'lease_id' => $this->lease->id,
            'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeObservation(RentalInspection $inspection, RentalInspectionItem $item, string $condition, ?User $observedBy = null): RentalInspectionObservation
    {
        // record() is the one real entry point (§14.1 fix 2) — create and
        // discrepancy-detection happen atomically, not as two calls a test
        // helper remembers to make in the right order.
        return RentalInspectionObservation::record([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => ($observedBy ?? $this->agent)->id,
            'condition' => $condition,
            'notes' => $condition !== RentalInspectionObservation::CONDITION_GOOD ? 'Test note' : null,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);
    }

    public function test_inspection_denormalizes_property_id_from_lease_at_creation(): void
    {
        $inspection = $this->makeInspection();

        $this->assertSame($this->property->id, $inspection->property_id);
    }

    /**
     * §0.4 — genuinely two different agents. Both calls used the same
     * $this->agent until 2026-09-22 (see RentalInspectionDiscrepancy::
     * sameAuthor(), added after Johan found his own sequential correction
     * of one item, on one device, being treated as a conflict).
     */
    public function test_two_conflicting_observations_produce_exactly_one_discrepancy_referencing_both(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $obs1 = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $obs2 = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, $secondAgent);

        $this->assertSame(1, RentalInspectionDiscrepancy::count());
        $discrepancy = RentalInspectionDiscrepancy::first();
        $this->assertNull($discrepancy->resolved_at);
        $this->assertEqualsCanonicalizing(
            [$obs1->id, $obs2->id],
            $discrepancy->observations()->pluck('rental_inspection_observations.id')->all(),
        );
    }

    public function test_a_third_conflicting_observation_joins_the_same_discrepancy_not_a_new_one(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $thirdAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, $secondAgent);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_FAIR, $thirdAgent);

        $this->assertSame(1, RentalInspectionDiscrepancy::count(), 'one row per conflicting GROUP, not per pair');
        $this->assertSame(3, RentalInspectionDiscrepancy::first()->observations()->count());
    }

    /**
     * 2026-09-22, Johan (property 5792, live during his demo) — companion
     * to the two tests above: the SAME agent recording a different
     * condition on the SAME item, sequentially, is a correction, not a
     * conflict, and must never create a discrepancy. See
     * RentalInspectionRecordingControllerTest for the controller-level
     * equivalent (this one exercises the model directly).
     */
    public function test_the_same_agent_recording_a_different_condition_does_not_create_a_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_FAIR);

        $this->assertSame(0, RentalInspectionDiscrepancy::count());
    }

    public function test_matching_observations_across_different_inspections_do_not_conflict(): void
    {
        $item = $this->makeItem();
        $inIns = $this->makeInspection(RentalInspection::TYPE_IN);
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->makeObservation($inIns, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($outIns, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $this->assertSame(0, RentalInspectionDiscrepancy::count(), 'ordinary wear between check-in and check-out is not a discrepancy');
    }

    /** §0.4 — genuinely two different agents; see RentalInspectionDiscrepancy::sameAuthor(). */
    public function test_inspection_cannot_be_treated_as_completable_while_discrepancy_unresolved(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, $secondAgent);

        $this->assertTrue($inspection->hasUnresolvedDiscrepancy());

        $discrepancy = RentalInspectionDiscrepancy::first();
        $winner = $discrepancy->observations()->orderByDesc('rental_inspection_observations.id')->first();
        $discrepancy->resolve($winner, $this->agent, 'Confirmed damaged on recheck.');

        $inspection->refresh();
        $this->assertFalse($inspection->fresh()->hasUnresolvedDiscrepancy());
    }

    /** §0.4 — genuinely two different agents; see RentalInspectionDiscrepancy::sameAuthor(). */
    public function test_resolving_a_discrepancy_never_touches_the_losing_observation(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $loser = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $winner = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, $secondAgent);

        $discrepancy = RentalInspectionDiscrepancy::first();
        $discrepancy->resolve($winner, $this->agent, 'Agreed.');

        $this->assertDatabaseHas('rental_inspection_observations', ['id' => $loser->id, 'condition' => 'good']);
        $this->assertSame(2, $discrepancy->observations()->count(), 'losing observation stays on the pivot, never removed');
    }

    /** §0.4 — genuinely two different agents; see RentalInspectionDiscrepancy::sameAuthor(). */
    public function test_current_observation_excludes_unresolved_discrepancy_participants(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, $secondAgent);

        $this->assertNull($item->fresh()->currentObservation(), 'both sit inside an unresolved discrepancy — no current condition yet');

        $discrepancy = RentalInspectionDiscrepancy::first();
        $winner = $discrepancy->observations()->orderByDesc('rental_inspection_observations.id')->first();
        $discrepancy->resolve($winner, $this->agent, 'Resolved.');

        $current = $item->fresh()->currentObservation();
        $this->assertNotNull($current);
        $this->assertSame($winner->id, $current->id);
    }

    public function test_retiring_an_item_does_not_hide_its_observation_history(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);

        $item->update(['is_retired' => true]);

        $this->assertSame(1, $item->fresh()->fullHistory()->count(), 'is_retired only blocks NEW observations, never hides history');
    }

    public function test_client_idempotency_key_prevents_a_retried_sync_from_double_recording(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $key = (string) \Illuminate\Support\Str::uuid();

        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            'client_idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            'client_idempotency_key' => $key,
        ]);
    }

    public function test_settings_default_to_seven_days_when_no_row_exists(): void
    {
        $this->assertSame(7, RentalInspectionSetting::faultReportWindowDaysFor($this->agency->id));
        $this->assertSame(7, RentalInspectionSetting::signingWindowDaysFor($this->agency->id));
    }

    public function test_settings_row_overrides_the_default(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'fault_report_window_days' => 14,
        ]);

        $this->assertSame(14, RentalInspectionSetting::faultReportWindowDaysFor($this->agency->id));
        $this->assertSame(7, RentalInspectionSetting::signingWindowDaysFor($this->agency->id), 'unset column still falls back to the default');
    }

    public function test_refusal_reason_presets_default_is_neutral_with_other_always_last(): void
    {
        $presets = RentalInspectionSetting::refusalReasonPresetsFor($this->agency->id);

        $this->assertSame('other', end($presets)['key'], '"other" must always be present and always last');
        $this->assertContains('disputes_condition', array_column($presets, 'key'));
        $this->assertStringNotContainsStringIgnoringCase('HFC', json_encode($presets), 'default wording must be multi-agency neutral');
    }

    public function test_refusal_reason_presets_agency_override_still_forces_other_last(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'refusal_reason_presets' => [
                ['key' => 'other', 'label' => 'Other'],
                ['key' => 'custom_reason', 'label' => 'A custom agency reason'],
            ],
        ]);

        $presets = RentalInspectionSetting::refusalReasonPresetsFor($this->agency->id);

        $this->assertSame('other', end($presets)['key'], '"other" is forced last even if the agency saved it elsewhere');
        $this->assertContains('custom_reason', array_column($presets, 'key'));
    }

    // ── §15.2a — RentalInspectionSignature::capture()'s invariants ──────

    public function test_a_tenant_can_sign(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $signature = RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id,
            'party_signature_path' => 'signatures/tenant.png',
        ]);

        $this->assertSame(RentalInspectionSignature::PARTY_TENANT, $signature->party_role);
        $this->assertSame(RentalInspectionSignature::DISPOSITION_SIGNED, $signature->disposition);
        $this->assertNull($signature->refusal_reason_preset);
    }

    public function test_a_signed_disposition_requires_a_signature_image(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id,
        ]);
    }

    public function test_a_tenant_can_refuse_with_a_reason_and_no_signature_image(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $signature = RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'not_present',
        ]);

        $this->assertSame(RentalInspectionSignature::DISPOSITION_REFUSED, $signature->disposition);
        $this->assertNull($signature->party_signature_path, 'a refusal must never carry a signature image — that is what makes it unmistakably not a signature');
    }

    public function test_a_refused_disposition_must_not_carry_a_signature_image(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id,
            'party_signature_path' => 'signatures/should-not-be-here.png',
            'refusal_reason_preset' => 'not_present',
        ]);
    }

    public function test_a_refused_disposition_requires_a_reason(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id,
        ]);
    }

    public function test_refusal_reason_other_requires_a_free_text_note(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'other',
        ]);
    }

    public function test_party_contact_id_must_actually_be_a_tenant_on_this_lease(): void
    {
        $strangerContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Not', 'last_name' => 'ALease Tenant', 'email' => uniqid() . '@example.test',
        ]);
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $strangerContact->id,
            'party_signature_path' => 'signatures/tenant.png',
        ]);
    }

    public function test_the_same_party_cannot_be_dispositioned_twice_on_one_inspection(): void
    {
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => 'signatures/tenant.png',
        ]);

        $this->expectException(\LogicException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id, 'refusal_reason_preset' => 'not_present',
        ]);
    }

    public function test_the_agent_has_no_refusal_option(): void
    {
        $inspection = $this->makeInspection();

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);
    }

    public function test_the_agent_cannot_sign_until_every_tenant_and_the_landlord_is_dispositioned(): void
    {
        $this->makeTenant(); // one outstanding tenant, no disposition recorded yet
        $inspection = $this->makeInspection();

        $this->expectException(\LogicException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);
    }

    public function test_the_agent_can_sign_once_every_tenant_is_dispositioned_on_a_lease_with_no_resolvable_landlord(): void
    {
        // This property has no seller/owner-side contact linked at all —
        // sellerOwnerContact() resolves null, so the landlord requirement
        // is waived (§15.4), not blocking.
        $tenant = $this->makeTenant();
        $inspection = $this->makeInspection();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => 'signatures/tenant.png',
        ]);

        $signature = RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

        $this->assertSame(RentalInspectionSignature::PARTY_AGENT, $signature->party_role);
        $this->assertNull($signature->party_contact_id, 'the agent is never identified via a Contact');
        $this->assertTrue($inspection->hasAgentSignature());
    }

    /**
     * §15.4, Stage 3 — the flip side of the test above: when a landlord IS
     * resolvable, the agent must wait on THEM too, not just the tenants.
     */
    public function test_the_agent_cannot_sign_until_a_resolvable_landlord_is_also_dispositioned(): void
    {
        $tenant = $this->makeTenant();
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\ContactProperty::create(['contact_id' => $landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
        $inspection = $this->makeInspection();
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => 'signatures/tenant.png',
        ]);

        $this->expectException(\LogicException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);
    }

    public function test_the_agent_cannot_sign_twice_on_the_same_inspection(): void
    {
        $inspection = $this->makeInspection(); // no tenants on this lease — nothing outstanding
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

        $this->expectException(\LogicException::class);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent-again.png',
        ]);
    }

    public function test_outstanding_signatories_lists_every_undispositioned_tenant(): void
    {
        $tenantOne = $this->makeTenant();
        $tenantTwo = $this->makeTenant();
        $inspection = $this->makeInspection();

        $this->assertCount(2, $inspection->outstandingSignatories());

        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenantOne->id, 'refusal_reason_preset' => 'not_present',
        ]);

        $remaining = $inspection->outstandingSignatories();
        $this->assertCount(1, $remaining);
        $this->assertSame($tenantTwo->id, $remaining->first()['party_contact_id']);
    }

    public function test_agency_scoping_hides_another_agencys_inspection(): void
    {
        // BelongsToAgency force-stamps agency_id from the acting user on create
        // (an ordinary user can never spoof another tenant's agency_id) — that
        // includes User itself, so building the "other agency" fixtures while
        // still acting as $this->agent would silently stamp $otherAgent (and
        // everything created under it) onto THIS agency instead. Log out first:
        // with no authenticated user, an explicit agency_id is trusted verbatim.
        \Illuminate\Support\Facades\Auth::logout();

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $otherAgent->id,
        ]);
        RentalInspection::create(['agency_id' => $otherAgency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $otherAgent->id]);

        $this->actingAs($this->agent);
        $this->makeInspection();

        $this->assertSame(1, RentalInspection::count(), 'AgencyScope must exclude the other agency\'s inspection for the acting user');
    }
}
