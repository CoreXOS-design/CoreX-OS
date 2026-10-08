<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionSignature;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalInspectionDueService;
use App\Services\Rentals\RentalInspectionReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * Rentals inspections walk, 8 Oct 2026 (cc4) — one test per defect the end-to-end walk found, each asserting the fixed
 * behaviour AND the case next to it that must not change. See .ai/specs/rental-inspections.md §50.
 */
final class RentalInspectionWalkFixesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** The `agent` role limited to OWN inspections — the scope the lease-agent rule is about. */
    private function ownScopeAgentRole(array $extra = []): void
    {
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        $grants = ['rental_inspections.view' => 'own', 'rental_inspections.create' => null, 'rental_inspections.edit_details' => null, 'access_properties' => null, 'properties.view' => 'all'] + $extra;
        foreach ($grants as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    private function colleague(string $name): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => $name, 'is_active' => true, 'email' => strtolower($name) . '-' . uniqid() . '@cape.test']);
    }

    // ═══ C-3 · the panel feed never carries a live link ═══════════════════════════

    public function test_the_signing_panel_feed_never_carries_a_live_link_or_token(): void
    {
        $i = $this->ready();
        $link = $this->issueLink($i, 'tenant', $this->tenant->id);

        $json = $this->getJson(route('corex.rental-inspections.signing-links.index', $i))->assertOk()->getContent();

        $this->assertStringNotContainsString($link->token, $json, 'a view-only user can read this feed — it must not hand out the secret');
        $this->assertStringNotContainsString('rental-inspection-sign/', $json);
        // …while the agent who is allowed to share it still gets it from the issue endpoint.
        $issued = $this->postJson(route('corex.rental-inspections.signing-links.issue', $i), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id, 'channel' => 'copied']);
        $this->assertStringContainsString('rental-inspection-sign/', (string) $issued->json('url'));
    }

    // ═══ C-2 · a link for someone who is no longer a party ════════════════════════

    public function test_a_link_for_a_tenant_who_left_the_lease_says_so_instead_of_offering_a_form_that_cannot_work(): void
    {
        $i = $this->ready();
        $link = $this->issueLink($i, 'tenant', $this->tenant2->id);
        LeaseTenant::where('lease_id', $this->lease->id)->where('contact_id', $this->tenant2->id)->delete();

        $this->guest();
        $page = $this->get(route('rental-inspections.sign.show', $link->token))->assertOk();
        $page->assertSee('no longer listed as a party');
        $page->assertDontSee('I have read this inspection report');

        $submit = $this->postJson(route('rental-inspections.sign.submit', $link->token), $this->signPayload(['typed_name' => 'Sipho Khumalo']));
        $submit->assertStatus(409);
        $this->assertStringContainsString('no longer listed', (string) $submit->json('message'));
        $this->assertStringNotContainsString('party_contact_id', (string) $submit->getContent(), 'no internal sentence');
        $this->assertSame(0, RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->count());
    }

    // ═══ C-8 · a refused signature leaves no orphan image behind ══════════════════

    public function test_a_refused_capture_removes_the_image_it_was_handed(): void
    {
        $i = $this->ready();
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), [
            'party_role' => 'tenant', 'disposition' => 'signed', 'party_contact_id' => $this->tenant->id, 'signature_image' => self::PNG,
        ])->assertStatus(201);
        $before = Storage::disk('local')->allFiles('rental-inspection-signatures');

        // The same party again (a double tap): refused — and the freshly stored PNG must not be left on the disk.
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), [
            'party_role' => 'tenant', 'disposition' => 'signed', 'party_contact_id' => $this->tenant->id, 'signature_image' => self::PNG,
        ])->assertStatus(422);

        $this->assertEqualsCanonicalizing($before, Storage::disk('local')->allFiles('rental-inspection-signatures'));
    }

    // ═══ P1/B2 · the lease's own agents are on its inspections ════════════════════

    public function test_the_tenants_agent_and_the_owners_agent_see_and_work_their_leases_inspection_under_own_scope(): void
    {
        $this->ownScopeAgentRole();
        $tenantAgent = $this->colleague('Tessa');
        $ownerAgent = $this->colleague('Olivia');
        $stranger = $this->colleague('Sam');
        $this->lease->forceFill(['tenant_agent_user_id' => $tenantAgent->id, 'owner_agent_user_id' => $ownerAgent->id])->save();
        $i = $this->recording(RentalInspection::TYPE_IN, ['inspector_user_id' => $this->admin->id]); // neither of them created or inspects it

        foreach ([$tenantAgent, $ownerAgent] as $agent) {
            $this->assertTrue(RentalInspection::query()->visibleTo($agent)->whereKey($i->id)->exists(), "{$agent->name} sees it in the list scope");
            $this->actingAs($agent)
                ->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'Noted by ' . $agent->name])
                ->assertOk();
        }
        $this->assertSame('Noted by Olivia', $i->fresh()->overall_notes);

        // A colleague in the same branch who is nobody on the lease still gets nothing — the rule widened, it did not open.
        $this->assertFalse(RentalInspection::query()->visibleTo($stranger)->whereKey($i->id)->exists());
        $this->actingAs($stranger)->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->assertNotFound();
    }

    public function test_a_loaded_interim_date_and_the_due_board_follow_the_lease_agents_too(): void
    {
        $this->ownScopeAgentRole(['rental_inspections.manage_planned_dates' => null]);
        $tenantAgent = $this->colleague('Tessa');
        $stranger = $this->colleague('Sam');
        $this->lease->forceFill(['tenant_agent_user_id' => $tenantAgent->id])->save();
        $date = RentalInspectionPlannedDate::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => 'interim', 'planned_on' => now()->addDays(10)->toDateString(), 'status' => 'planned', 'created_by_user_id' => $this->admin->id,
        ]);

        $this->assertTrue(RentalInspectionPlannedDate::query()->visibleTo($tenantAgent)->whereKey($date->id)->exists());
        $this->assertFalse(RentalInspectionPlannedDate::query()->visibleTo($stranger)->whereKey($date->id)->exists());

        // The board: the property is the ADMIN's, yet the tenant's agent sees this lease's rows and the stranger does not.
        $this->actingAs($tenantAgent)->get(route('corex.rental-inspections.due'))->assertOk()->assertSee('14 Jackson Street');
        $this->actingAs($stranger)->get(route('corex.rental-inspections.due'))->assertOk()->assertDontSee('14 Jackson Street');
    }

    public function test_the_agent_a_due_inspection_is_assigned_to_is_the_leases_own_agent(): void
    {
        $tenantAgent = $this->colleague('Tessa');
        $ownerAgent = $this->colleague('Olivia');
        $due = app(RentalInspectionDueService::class);

        $this->assertSame($this->admin->id, $due->responsibleAgentId($this->lease->fresh()), 'no lease agents set: the property\'s agent, as before');
        $this->lease->forceFill(['tenant_agent_user_id' => $tenantAgent->id])->save();
        $this->assertSame($tenantAgent->id, $due->responsibleAgentId($this->lease->fresh()));
        $this->lease->forceFill(['owner_agent_user_id' => $ownerAgent->id])->save();
        $this->assertSame($ownerAgent->id, $due->responsibleAgentId($this->lease->fresh()), 'both set: the owner\'s agent first');
    }

    // ═══ B3/P2 · one move-in condition per tenancy, on every creation path ════════

    public function test_a_second_in_inspection_is_refused_on_every_creation_path_once_the_tenancy_has_one(): void
    {
        $existing = $this->recording(RentalInspection::TYPE_IN);
        $existing->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        try {
            RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->admin);
            $this->fail('start() must refuse a second In');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('already has an In-inspection', $e->getMessage());
        }
        try {
            RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->admin, ['scheduled_for' => now()->addDays(3)->toDateString()]);
            $this->fail('schedule() must refuse a second In');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('already has an In-inspection', $e->getMessage());
        }
        $this->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'in'])->assertSessionHasErrors('rental_inspection');
        $this->assertSame(1, RentalInspection::where('lease_id', $this->lease->id)->where('type', 'in')->count());

        // Next to it, unchanged: Out and Routine start; a CANCELLED In does not block a fresh one.
        $this->assertSame('out', RentalInspection::start($this->property, RentalInspection::TYPE_OUT, $this->admin)->type);
        $this->assertSame('ad_hoc', RentalInspection::start($this->property, RentalInspection::TYPE_AD_HOC, $this->admin)->type);
        $existing->forceFill(['status' => RentalInspection::STATUS_CANCELLED])->save();
        $this->assertSame('in', RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->admin)->type);
    }

    // ═══ B5 · archiving takes the inspection off the calendar; restoring puts it back ═

    public function test_archiving_a_scheduled_inspection_dismisses_its_calendar_event_and_restoring_brings_it_back(): void
    {
        $i = RentalInspection::schedule($this->property, RentalInspection::TYPE_AD_HOC, $this->admin, ['scheduled_for' => now()->addDays(5)->toDateString(), 'inspector_user_id' => $this->inspector->id]);
        $event = fn () => CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $i->id)->first();
        $this->assertSame('pending', $event()->status);

        $i->forceFill(['archived_by_user_id' => $this->admin->id])->save();
        $i->delete();
        $this->assertSame('dismissed', $event()->status, 'an archived inspection is not on the inspector\'s calendar');

        $i->restore();
        $this->assertSame('pending', $event()->status);
    }

    // ═══ B7 · the due board's print and export need the export permission ═════════

    public function test_the_due_boards_print_and_export_are_gated_like_the_lists_own(): void
    {
        $this->ownScopeAgentRole();
        $viewer = $this->colleague('Vera');
        $this->lease->forceFill(['tenant_agent_user_id' => $viewer->id])->save();

        $this->actingAs($viewer);
        $this->get(route('corex.rental-inspections.due.print'))->assertForbidden();
        $this->get(route('corex.rental-inspections.due.export', ['format' => 'csv']))->assertForbidden();
        $page = $this->get(route('corex.rental-inspections.due'))->assertOk();
        $page->assertDontSee('due/print');

        $this->ownScopeAgentRole(['rental_inspections.export' => 'own']);
        $this->get(route('corex.rental-inspections.due.print'))->assertOk();
    }

    // ═══ B9 · the list finds a tenant by full name ═════════════════════════════════

    public function test_the_list_search_finds_a_tenant_by_first_and_last_name_together(): void
    {
        $this->recording(RentalInspection::TYPE_IN);

        $this->get(route('corex.rental-inspections.index', ['q' => 'Naledi Dlamini']))->assertOk()->assertSee('14 Jackson Street');
        $this->get(route('corex.rental-inspections.index', ['q' => 'Naledi']))->assertOk()->assertSee('14 Jackson Street');
        $this->get(route('corex.rental-inspections.index', ['q' => 'Dlamini Naledi']))->assertOk()->assertDontSee('14 Jackson Street');
    }

    // ═══ B8 · the reminder commands can be run for one agency, and dry ════════════

    public function test_the_due_reminder_command_can_be_limited_to_one_agency_and_run_dry(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->lease->update(['start_date' => now()->toDateString()]); // the move-in falls due today → a reminder is owed now

        Artisan::call('rentals:send-due-inspection-reminders', ['--agency' => $this->agency->id + 999, '--dry-run' => true]);
        $this->assertStringContainsString('sent: 0', Artisan::output(), 'another agency\'s id touches nothing here');

        Artisan::call('rentals:send-due-inspection-reminders', ['--agency' => $this->agency->id, '--dry-run' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('dry run', $out);
        $this->assertStringContainsString('sent: 1', $out, 'the overdue move-in would be reminded');
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertSame(0, \App\Models\RentalInspectionDueNotice::withoutGlobalScopes()->count(), 'a dry run writes nothing');
    }

    // ═══ P3 · checklist order cannot change under a signature ═════════════════════

    public function test_reordering_the_checklist_is_refused_while_the_report_is_signed_and_allowed_before(): void
    {
        $i = $this->ready();
        $second = PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $this->admin->id]);
        $calls = fn () => [
            $this->postJson(route('corex.properties.rental-inspection-rooms.reorder', $this->property), ['room_ids' => [$second->id, $this->room->id]]),
            $this->postJson(route('corex.properties.rental-inspection-items.reorder', $this->property), ['property_room_id' => $this->room->id, 'item_ids' => [$this->item->id]]),
            $this->postJson(route('corex.properties.rental-inspection-rooms.apply-default-order', $this->property)),
        ];

        foreach ($calls() as $r) {
            $r->assertOk();
        }
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $order = PropertyRoom::where('property_id', $this->property->id)->orderBy('id')->pluck('sort_order', 'id')->all();
        foreach ($calls() as $r) {
            $r->assertStatus(409);
        }
        $this->assertSame($order, PropertyRoom::where('property_id', $this->property->id)->orderBy('id')->pluck('sort_order', 'id')->all(), 'nothing moved');
    }

    // ═══ P4 · "build from advertising" keeps its one shot when it built nothing ═══

    public function test_building_from_advertising_that_makes_nothing_does_not_use_up_the_one_shot(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '2 Empty Spaces Road', 'status' => 'active', 'listing_type' => 'rental',
            'spaces_json' => ['spaces' => [['type' => 'bedroom', 'units' => []], ['type' => 'bathroom', 'units' => [['label' => '']]]]],
        ]);

        $this->postJson(route('corex.properties.rental-inspection-items.seed-from-advertising', $property))->assertStatus(409);
        $this->assertNull($property->fresh()->rental_inspection_form_seeded_at);

        $property->forceFill(['spaces_json' => ['spaces' => [['type' => 'bedroom', 'units' => [['label' => 'Main Bedroom']]]]]])->save();
        $this->postJson(route('corex.properties.rental-inspection-items.seed-from-advertising', $property))->assertSuccessful();
        $this->assertNotNull($property->fresh()->rental_inspection_form_seeded_at);
    }

    // ═══ P5 · oversize input is a 422, never a 500 ═════════════════════════════════

    public function test_oversize_notes_and_key_counts_are_refused_plainly(): void
    {
        $i = $this->recording(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.observations.store', $i), [
            'rental_inspection_item_id' => $this->item->id, 'condition' => 'good', 'notes' => str_repeat('a', 70000), 'source' => 'in_inspection',
        ])->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->postJson(route('corex.rental-inspections.details.update', $i), ['keys_count' => 99999999999])->assertStatus(422)->assertJsonValidationErrors('keys_count');
        $this->postJson(route('corex.rental-inspections.details.update', $i), ['remotes_count' => 99999999999])->assertStatus(422)->assertJsonValidationErrors('remotes_count');

        // Normal values are untouched.
        $this->postJson(route('corex.rental-inspections.details.update', $i), ['keys_count' => 4, 'remotes_count' => 2])->assertOk();
        $this->assertSame(4, $i->fresh()->keys_count);
    }

    // ═══ P6 · a difference resolved once stays as it was decided ══════════════════

    public function test_resolving_a_difference_twice_does_not_overwrite_the_first_decision(): void
    {
        $i = $this->recording(RentalInspection::TYPE_IN);
        $obs = RentalInspectionObservation::where('rental_inspection_id', $i->id)->first();
        $discrepancy = RentalInspectionDiscrepancy::forceCreate(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'rental_inspection_item_id' => $this->item->id, 'detected_at' => now()]);
        $discrepancy->observations()->attach($obs->id);

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$i, $discrepancy]), ['accepted_observation_id' => $obs->id, 'resolution_note' => 'first'])->assertOk();
        $firstAt = $discrepancy->fresh()->resolved_at;

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$i, $discrepancy]), ['accepted_observation_id' => $obs->id, 'resolution_note' => 'second'])->assertStatus(409);
        $this->assertSame('first', $discrepancy->fresh()->resolution_note);
        $this->assertEquals($firstAt, $discrepancy->fresh()->resolved_at);
    }

    // ═══ P8/B5 · one word per type, also in file names and the reports ════════════

    public function test_a_routine_inspections_pdf_is_named_with_the_word_routine(): void
    {
        $i = $this->recording(RentalInspection::TYPE_AD_HOC);
        $pdf = app(RentalInspectionReportPdfService::class);

        $this->assertStringContainsString('inspection-report-routine-', $pdf->filenameFor($i));
        $this->assertStringContainsString('inspection-for-signature-routine-', $pdf->filenameForSignatureFor($i));
        $this->assertStringNotContainsString('ad_hoc', $pdf->filenameFor($i));
        $this->assertStringContainsString('inspection-report-out-', $pdf->filenameFor($this->recording(RentalInspection::TYPE_OUT)));
    }

    // ═══ D-B2 · the PDF carries the header details ═════════════════════════════════

    public function test_the_report_pdf_carries_meters_keys_and_the_other_header_details(): void
    {
        $i = $this->recording(RentalInspection::TYPE_OUT, [
            'electricity_meter_reading' => 'EL-48213', 'water_meter_reading' => 'WA-77120', 'keys_count' => 3, 'keys_description' => 'front door x2, gate x1',
            'remotes_count' => 2, 'furnished_status' => 'Unfurnished', 'property_type' => 'Apartment/Flat', 'move_in_date_recorded' => '2026-03-01',
        ]);

        $text = $this->pdfText(app(RentalInspectionReportPdfService::class)->generate($i->fresh()));

        foreach (['EL-48213', 'WA-77120', 'front door x2', 'Unfurnished', 'Apartment/Flat', '01 Mar 2026'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }

        // Nothing recorded → no empty "Details" block.
        $bare = $this->pdfText(app(RentalInspectionReportPdfService::class)->generate($this->recording(RentalInspection::TYPE_IN)->fresh()));
        $this->assertDoesNotMatchRegularExpression('/^\s*DETAILS\s*$/mi', $bare);
    }

    private function pdfText($pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'walkpdf') . '.pdf';
        file_put_contents($file, $pdf->output());
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
        @unlink($file);

        return $text;
    }

    // ═══ D-B3 · a property with only a tenant linked has NO landlord on the roster ═

    public function test_a_tenant_is_never_put_on_the_roster_as_the_landlord(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '5 Tenant-Only Lane', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $property->contacts()->attach($this->tenant->id, ['role' => 'tenant']);
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonth(), 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $i = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => 'in', 'created_by_user_id' => $this->admin->id]);

        $this->assertNull($property->landlordContact(), 'a tenant is not the landlord');
        $landlordRow = collect($i->fresh()->signatureSummaryRows())->firstWhere('role', 'Landlord');
        $this->assertTrue($landlordRow['not_required'], 'no landlord linked → the row says so');
        $this->assertNull($landlordRow['name']);

        // The ordinary property (a real landlord linked) is unchanged.
        $normal = collect($this->recording(RentalInspection::TYPE_IN)->signatureSummaryRows())->firstWhere('role', 'Landlord');
        $this->assertSame('Pieter van Wyk', $normal['name']);
    }

    // ═══ B4 · the resend popover lists exactly who the server will send to ═════════

    public function test_the_tab_payload_carries_the_servers_own_recipient_list_for_a_completed_report(): void
    {
        $i = $this->recording(RentalInspection::TYPE_IN);
        $open = RentalInspection::tabPayloadFor($this->property);
        $this->assertSame([], $open['chain_tail']->report_recipients, 'nothing to resend before completion');

        $i->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $payload = RentalInspection::tabPayloadFor($this->property->fresh());
        $this->assertEquals($i->fresh()->distributionRecipients(), $payload['chain_tail']->report_recipients);
        $this->assertContains('pieter@example.co.za', array_column($payload['chain_tail']->report_recipients, 'email'));
    }

    // ═══ B6 · a failing resend is a plain message, not a 500 ═══════════════════════

    public function test_a_resend_that_cannot_build_the_report_answers_with_a_plain_message(): void
    {
        $i = $this->recording(RentalInspection::TYPE_IN);
        $i->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $this->app->bind(\App\Services\Rentals\RentalInspectionCopiesService::class, fn () => new class extends \App\Services\Rentals\RentalInspectionCopiesService {
            public function __construct() {}
            public function fileAndSend(RentalInspection $inspection, bool $autoOnly, ?User $triggeredBy = null, ?array $onlyEmails = null): array
            {
                throw new \RuntimeException('pdf engine down');
            }
            public function alertSendFailed(RentalInspection $inspection, \Throwable $e): void {}
        });

        $this->postJson(route('corex.rental-inspections.resend-report', $i))->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'could not be prepared or sent'));
    }

    // ═══ B7 (backfill) · a bulk run never files an archived inspection ════════════

    public function test_the_filing_backfill_skips_archived_inspections(): void
    {
        $live = $this->recording(RentalInspection::TYPE_IN);
        $live->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $archived = $this->recording(RentalInspection::TYPE_AD_HOC);
        $archived->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $archived->delete();

        Artisan::call('rental-inspections:backfill-report-filing', ['--agency' => $this->agency->id, '--dry-run' => true]);

        $this->assertSame(1, preg_match('/\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*\n\+/', Artisan::output(), $m) ?: preg_match('/(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|/', Artisan::output(), $m));
        $this->assertSame('1', $m[1], 'only the live completed inspection is counted');
    }
}
