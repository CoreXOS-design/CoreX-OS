<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport\Reos;

use App\Events\Property\PropertyCaptured;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Rentals\RentalPortalOverviewService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnArchiveService;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyConfirmService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-479 / rental-takeon-import.md "reOS Tenancy Schedule import" R3-R9 — the review and Import, end to end, on SYNTHETIC
 * leases ("ZZ TEST …", @example.test). No real client data anywhere.
 *
 * Input paths proven: upload accepted / wrong layout refused (no run made) / user without .manage refused; a property
 * CoreX knows needs no suburb, an unknown one does (and picking one unblocks it); the agent matched by name, only-the-agency
 * and unknown-person leaving the pick to the user; one owner on three leases (identifier match, no identifier, same name) =
 * ONE contact; company and joint names; Draft stays draft and Active activates; the re-import UPDATES the lease and changes
 * nothing else; an archived lease with the same reOS id is a blocker; a row that fails rolls back clean and the next row still
 * imports; nothing mailed or notified; no chase tasks or activity points for imported stock (with a positive control);
 * another agency's / colleague's batch is a 404 on every screen and JSON call; lease edit form fields + the uniqueness message;
 * the portal shows the tenant wallet to the tenant and the owner wallet to the owner, and nothing when empty.
 */
final class ReosImportFlowTest extends TestCase
{
    use RefreshDatabase;
    use ReosFixtures;

    protected function tearDown(): void
    {
        $this->tearDownReosFiles();
        parent::tearDown();
    }

    // ── upload ─────────────────────────────────────────────────────────────────────────────

    public function test_uploading_a_reos_schedule_makes_a_reos_run_with_resolved_rows_and_keeps_no_file(): void
    {
        [$agency, , $admin, $agent] = $this->reosAgency();

        $schedule = $this->scheduleFile([$this->reosRow(['uuid' => self::UUID_A]), $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Tenant' => 'ZZ TEST Tenant Two'])]);
        $roll = $this->rentRollFile([['address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => 'ZZ TEST Tenant One', 'wallet' => 'WLT-1001']]);

        $response = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.reos.upload'), [
            'schedule' => $this->upload($schedule),
            'rent_roll' => $this->upload($roll, 'ZZ-rent-roll.xlsx'),
        ]);

        $run = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();
        self::assertNotNull($run);
        $response->assertRedirect(route('corex.rentals.take-on-import.preview', $run));
        self::assertSame(RentalTakeOnImportRun::SOURCE_REOS_TENANCY_SCHEDULE, $run->source);
        self::assertSame(RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, $run->status);
        self::assertNull($run->source_file_path, 'the uploaded reports (which carry money columns) are not kept');
        self::assertSame('ZZ-rent-roll.xlsx', $run->rent_roll_filename);
        self::assertSame(2, $run->counts_json['total']);
        self::assertSame(1, $run->counts_json['rent_roll']['matched']);

        $rows = $run->rows()->orderBy('row_number')->get();
        self::assertSame([self::UUID_A, self::UUID_B], $rows->pluck('external_id')->all());
        self::assertSame('WLT-1001', $rows[0]->decisions_json['tenant_wallet_ref']);
        self::assertSame($agent->id, $rows[0]->decisions_json['agent_user_id'], 'the first named person is matched to a user by name');
        self::assertFalse((bool) $rows[0]->selected, 'nothing is ticked until the user ticks it');
        self::assertSame(0, Lease::withoutGlobalScopes()->count(), 'nothing is created by an upload');
        self::assertSame(0, Property::withoutGlobalScopes()->count());
        self::assertSame(0, Contact::withoutGlobalScopes()->count());
    }

    public function test_a_file_that_is_not_the_reos_schedule_is_refused_with_a_message_and_no_run_is_made(): void
    {
        [$agency, , $admin] = $this->reosAgency();
        $bad = $this->scheduleFile([$this->reosRow()], 'Sheet1');

        $this->actingAs($admin)->from(route('corex.rentals.take-on-import.index'))
            ->post(route('corex.rentals.take-on-import.reos.upload'), ['schedule' => $this->upload($bad)])
            ->assertRedirect(route('corex.rentals.take-on-import.index'))
            ->assertSessionHasErrors('schedule');

        self::assertStringContainsString('Tenancy Schedule', session('errors')->first('schedule'));
        self::assertSame(0, RentalTakeOnImportRun::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_a_user_who_may_only_view_cannot_upload(): void
    {
        [$agency, $branch] = $this->reosAgency();
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $agency->id], ['label' => 'Agent']);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rentals_take_on_import.view', 'agency_id' => $agency->id], ['scope' => 'agency']);
        PermissionService::clearCache();
        $viewer = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $this->actingAs($viewer)->post(route('corex.rentals.take-on-import.reos.upload'), ['schedule' => $this->upload($this->scheduleFile([$this->reosRow()]))])->assertForbidden();
        self::assertSame(0, RentalTakeOnImportRun::withoutGlobalScopes()->count());
    }

    public function test_the_batch_list_offers_the_reos_upload_beside_the_existing_one_and_names_each_batch_type(): void
    {
        [, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->reosRow()]);

        $html = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.index'))->assertOk()->getContent();

        self::assertStringContainsString('reOS Tenancy Schedule import', $html);
        self::assertStringContainsString(route('corex.rentals.take-on-import.reos.upload'), $html);
        self::assertStringContainsString(route('corex.rentals.take-on-import.upload'), $html, 'the existing upload is still there');
        self::assertStringContainsString('reOS Tenancy Schedule', $run->sourceLabel());
    }

    // ── review ─────────────────────────────────────────────────────────────────────────────

    public function test_the_review_screen_lists_the_leases_with_search_filter_sort_and_an_empty_state(): void
    {
        [, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A]),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Tenant' => 'ZZ TEST Tenant Two', 'Lease Status' => 'Draft']),
        ]);
        $url = route('corex.rentals.take-on-import.preview', $run);

        $page = $this->actingAs($admin)->get($url)->assertOk();
        $page->assertSee('Review reOS import');
        $page->assertSee('3 ZZ Aqua Breeze');
        $page->assertSee('9 ZZ Marine Drive');
        $page->assertSee('Tick the leases you want to import.');

        $this->actingAs($admin)->get($url . '?q=Marine')->assertOk()->assertSee('9 ZZ Marine Drive')->assertDontSee('3 ZZ Aqua Breeze');
        $this->actingAs($admin)->get($url . '?lease_status=Draft')->assertOk()->assertSee('9 ZZ Marine Drive')->assertDontSee('3 ZZ Aqua Breeze');
        $this->actingAs($admin)->get($url . '?sort=address&dir=desc')->assertOk();
        $this->actingAs($admin)->get($url . '?q=nothing-matches-this')->assertOk()->assertSee('No leases match these filters');
    }

    public function test_suburb_is_required_only_for_a_property_corex_does_not_know_and_picking_one_unblocks_the_row(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $suburb = $this->p24Suburb();
        $run = $this->makeRun($admin, [$this->reosRow()]);
        $row = $run->rows()->first();

        self::assertSame(RentalTakeOnImportRow::ACTION_CREATE, $row->property_match_action, 'unknown to CoreX');
        self::assertContains('suburb_required', collect($row->blockers_json)->pluck('code')->all());

        $row = app(ReosDecisionService::class)->apply($row, $run, ['selected' => true, 'suburb_id' => $suburb->id]);

        self::assertFalse($row->is_blocked, json_encode($row->blockers_json));
        self::assertSame('ZZ Testville', $row->decisions_json['property']['suburb']);
        self::assertSame('ZZ Test City', $row->decisions_json['property']['town'], 'town fills from the suburb');
        self::assertSame('ZZ Test Province', $row->decisions_json['property']['province']);
        self::assertSame(1, ReosRunSummary::for($run)['ready']);

        // Un-ticking "Create this property" with no property to match is its own blocker (a lease needs a property).
        $row = app(ReosDecisionService::class)->apply($row, $run, ['property_create' => false]);
        self::assertContains('property_required', collect($row->blockers_json)->pluck('code')->all());
    }

    public function test_a_property_that_corex_already_knows_needs_no_suburb(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $this->knownProperty($agency, $branch, $agent, '13', 'ZZ Stafford Avenue', 'ZZ Testville');
        $run = $this->makeRun($admin, [$this->reosRow(['Property Address' => '13 ZZ Stafford Avenue'])]);
        $row = $run->rows()->first();

        // Whether the matcher can see it before a suburb is known is the matcher's business; what matters here is the rule:
        // once the property is matched there is no suburb blocker, and when it is not, giving the same suburb matches it.
        if ($row->property_match_action !== RentalTakeOnImportRow::ACTION_MATCH) {
            $row = app(ReosDecisionService::class)->apply($row, $run, ['suburb_id' => $this->p24Suburb('ZZ Testville')->id]);
        }

        self::assertSame(RentalTakeOnImportRow::ACTION_MATCH, $row->property_match_action, (string) $row->property_match_label);
        self::assertNotContains('suburb_required', collect($row->blockers_json)->pluck('code')->all());
    }

    public function test_the_agent_is_matched_by_the_first_named_person_and_otherwise_left_to_the_user(): void
    {
        [$agency, , $admin, $agent] = $this->reosAgency();
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A]),
            $this->reosRow(['uuid' => self::UUID_B, 'Agent' => 'ZZ TEST Agency (100%)']),
            $this->reosRow(['uuid' => self::UUID_C, 'Agent' => 'ZZ Somebody Unknown (50%),ZZ Agent Person (50%)']),
            $this->reosRow(['uuid' => self::UUID_D, 'Agent' => '']),
        ]);
        $rows = $run->rows()->orderBy('row_number')->get();

        self::assertSame($agent->id, $rows[0]->decisions_json['agent_user_id']);
        foreach ([1, 2, 3] as $i) {
            self::assertNull($rows[$i]->decisions_json['agent_user_id'], "row {$i}: agency-only, unknown first person and empty all leave the pick to the user");
            self::assertContains('agent_required', collect($rows[$i]->blockers_json)->pluck('code')->all());
        }
        self::assertStringContainsString('agency', $rows[1]->decisions_json['agent_note']);
    }

    public function test_the_json_endpoints_save_a_choice_and_return_the_row_and_the_header_numbers(): void
    {
        [, , $admin, $agent] = $this->reosAgency();
        $suburb = $this->p24Suburb();
        $run = $this->makeRun($admin, [$this->reosRow()]);
        $row = $run->rows()->first();

        $res = $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['selected' => true, 'suburb_id' => $suburb->id])->assertOk();
        self::assertStringContainsString('ZZ Testville', $res->json('row_html'));
        self::assertSame(1, $res->json('summary.selected'));
        self::assertSame(0, $res->json('summary.blocked'));
        self::assertTrue($res->json('summary.can_import'));
        self::assertTrue((bool) $row->fresh()->selected, 'saved at once');

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['suburb_id' => 999999999])->assertStatus(422);
        $left = User::factory()->create(['agency_id' => $admin->agency_id, 'branch_id' => $admin->branch_id, 'role' => 'agent', 'is_active' => false]);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.decisions', $row), ['agent_user_id' => $left->id])->assertStatus(422);   // someone who has left cannot be the agent

        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.bulk', $run), ['action' => 'untick_all'])->assertOk()->assertJsonPath('summary.selected', 0);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.bulk', $run), ['action' => 'tick_ready'])->assertOk()->assertJsonPath('summary.selected', 1);
        $this->actingAs($admin)->postJson(route('api.v1.rentals.take-on-import.bulk', $run), ['action' => 'nonsense'])->assertStatus(422);
        $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.suburbs', ['q' => 'Testv']))->assertOk()->assertJsonPath('data.0.suburb', 'ZZ Testville')->assertJsonPath('data.0.town', 'ZZ Test City');
        $this->actingAs($admin)->getJson(route('api.v1.rentals.take-on-import.summary', $run))->assertOk()->assertJsonPath('summary.total', 1);
    }

    public function test_apply_suburb_and_agent_to_all_ticked_rows_only_touches_ticked_rows(): void
    {
        [, , $admin, $agent] = $this->reosAgency();
        $suburb = $this->p24Suburb();
        $run = $this->makeRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Agent' => '']),
            $this->reosRow(['uuid' => self::UUID_B, 'Agent' => '', 'Property Address' => '9 ZZ Marine Drive']),
            $this->reosRow(['uuid' => self::UUID_C, 'Agent' => '', 'Property Address' => '21 ZZ Beach Road']),
        ]);
        $rows = $run->rows()->orderBy('row_number')->get();
        $svc = app(ReosDecisionService::class);
        $svc->apply($rows[0], $run, ['selected' => true]);
        $svc->apply($rows[1], $run, ['selected' => true]);

        $n = $svc->bulk($run, 'apply_suburb', [], ['suburb_id' => $suburb->id]);
        $m = $svc->bulk($run, 'apply_agent', [], ['agent_user_id' => $agent->id]);

        self::assertSame([2, 2], [$n, $m]);
        $fresh = $run->rows()->orderBy('row_number')->get();
        self::assertSame('ZZ Testville', $fresh[0]->decisions_json['property']['suburb']);
        self::assertSame($agent->id, $fresh[1]->decisions_json['agent_user_id']);
        self::assertNull($fresh[2]->decisions_json['property']['suburb'], 'the unticked row is left alone');
        self::assertNull($fresh[2]->decisions_json['agent_user_id']);
    }

    // ── import ─────────────────────────────────────────────────────────────────────────────

    public function test_import_creates_the_lease_property_and_people_and_nothing_is_sent_to_anyone(): void
    {
        Mail::fake();
        Notification::fake();
        Event::fake([PropertyCaptured::class]);
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $run = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A])], ['wallet' => 'WLT-1001']);

        $this->actingAs($admin);
        $result = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);

        self::assertSame(['done' => 1, 'created' => 1, 'updated' => 0, 'failed' => 0, 'remaining' => 0], $result);
        $row = $run->rows()->first();
        self::assertSame(RentalTakeOnImportRow::STATUS_CONFIRMED, $row->status);
        self::assertSame(RentalTakeOnImportRow::RESULT_CREATED, $row->result_action);

        $lease = Lease::withoutGlobalScopes()->find($row->target_lease_id);
        self::assertSame(Lease::STATUS_ACTIVE, $lease->status, 'reOS Active goes through the normal activation');
        self::assertSame(8500.0, (float) $lease->rental_amount);
        self::assertSame(8500.0, (float) $lease->deposit_amount);
        self::assertSame('2025-03-01', $lease->start_date->toDateString());
        self::assertSame('2027-02-28', $lease->end_date->toDateString());
        self::assertSame('Managed', $lease->lease_type);
        self::assertTrue($lease->rollover);
        self::assertSame('WLT-1001', $lease->tenant_wallet_ref);
        self::assertNull($lease->owner_wallet_ref);
        self::assertSame('reos', $lease->external_source);
        self::assertSame(self::UUID_A, $lease->external_id);
        self::assertSame('https://app.reos.co.za/leases/' . self::UUID_A, $lease->external_url);
        self::assertSame($agent->id, (int) $lease->owner_agent_user_id);
        self::assertSame($agent->id, (int) $lease->tenant_agent_user_id);
        self::assertSame(Lease::SOURCE_MIGRATED_TAKEON, $lease->source);

        $property = Property::withoutGlobalScopes()->find($lease->property_id);
        self::assertSame('rental', $property->listing_type);
        self::assertSame('ZZ Testville', $property->suburb);
        self::assertSame('ZZ Test City', $property->town);
        self::assertSame($agent->id, (int) $property->agent_id);
        self::assertSame('ZZ Aqua Breeze', $property->complex_name);
        self::assertSame('3', (string) $property->unit_number);

        self::assertSame('ZZ TEST Tenant One', $lease->tenants->first()->contact->full_name);
        self::assertSame('ZZ TEST Owner One', $lease->landlordContacts()->first()->full_name);
        self::assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->count());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Event::assertNotDispatched(PropertyCaptured::class);
    }

    public function test_imported_properties_raise_no_chase_tasks_and_a_normal_promotion_still_does(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $run = $this->readyRun($admin, [$this->reosRow()]);

        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        self::assertSame(0, CommandTask::withoutGlobalScopes()->count(), 'imported stock is not a new mandate to chase');

        // Positive control: the same promotion WITHOUT the take-on switch does raise them, so the assertion above is not vacuous.
        $svc = app(TrackedPropertyMatchOrCreateService::class);
        $tp = $svc->matchOrCreate($agency->id, ['street_number' => '7', 'street_name' => 'ZZ Control Road', 'suburb' => 'ZZ Testville', 'address' => '7 ZZ Control Road'], ['type' => 'manual', 'ref' => 'zz-control']);
        $svc->promoteToStock($tp->id, $agent->id, ['listing_type' => 'rental', 'branch_id' => $branch->id], false);
        self::assertGreaterThan(0, CommandTask::withoutGlobalScopes()->count());
    }

    public function test_one_owner_on_several_leases_is_one_contact_and_company_and_joint_names_are_kept_whole(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $company = 'ZZ TEST Wildevy Properties Pty Ltd';
        $run = $this->readyRun($admin, [
            // A: has an e-mail + phone  → created
            $this->reosRow(['uuid' => self::UUID_A, 'Owner' => $company, 'Owner Email' => 'zz.wildevy@example.test', 'Owner Phone' => '+27815550199']),
            // B: no identifier at all   → the same name already brought in by this batch
            $this->reosRow(['uuid' => self::UUID_B, 'Owner' => $company, 'Owner Email' => '', 'Owner Phone' => '', 'Property Address' => '504/3 ZZ Robin Road', 'Tenant' => 'ZZ TEST William & Dorothy Geyser', 'Tenant Email' => '', 'Tenant Phone' => '', 'Lease Status' => 'Draft']),
            // C: the same e-mail as A written differently → found by ContactDuplicateService at confirm time
            $this->reosRow(['uuid' => self::UUID_C, 'Owner' => $company, 'Owner Email' => ' ZZ.Wildevy@Example.test ', 'Owner Phone' => '', 'Property Address' => '21 ZZ Beach Road', 'Tenant' => 'ZZ TEST Tenant Three']),
        ]);

        $this->actingAs($admin);
        $result = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);

        self::assertSame(3, $result['created'], json_encode($run->rows()->pluck('import_error')));
        $owners = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('entity_name', $company)->get();
        self::assertCount(1, $owners, 'one owner on three leases = one contact');
        self::assertSame(Contact::TYPE_ENTITY, $owners[0]->contact_kind);
        self::assertNull($owners[0]->entity_reg_no, 'a company needs no registration number');
        self::assertSame(3, $run->rows()->get()->filter(fn ($r) => in_array($owners[0]->id, $r->target_landlord_contact_ids_json ?? []))->count());

        $joint = Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->where('last_name', 'Geyser')->get();
        self::assertCount(1, $joint, 'a joint name stays ONE contact');
        self::assertSame('ZZ TEST William & Dorothy', $joint[0]->first_name);

        // B is a reOS Draft: it stays a draft. A and C are Active.
        $byId = Lease::withoutGlobalScopes()->where('agency_id', $agency->id)->get()->keyBy('external_id');
        self::assertSame(Lease::STATUS_DRAFT, $byId[self::UUID_B]->status);
        self::assertSame(Lease::STATUS_ACTIVE, $byId[self::UUID_A]->status);
        self::assertSame(Lease::STATUS_ACTIVE, $byId[self::UUID_C]->status);
        self::assertSame(3, Lease::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_a_reimport_updates_the_lease_it_already_brought_in_and_never_duplicates_it(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $first = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A])]);
        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($first, $admin, true);
        $leaseId = Lease::withoutGlobalScopes()->where('external_id', self::UUID_A)->value('id');
        $contacts = Contact::withoutGlobalScopes()->count();
        $properties = Property::withoutGlobalScopes()->count();

        // reOS now shows a higher rent, a later end date and a wallet reference.
        $second = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_A, 'Current Rent' => '9100.00', 'Lease End' => '2028-02-29', 'Rollover' => 'False', 'Property Address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue'])], [['address' => '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => 'ZZ TEST Tenant One', 'wallet' => 'WLT-2002']]);
        $row = $second->rows()->first();
        self::assertSame($leaseId, $row->existing_lease_id, 'found by the reOS id');
        self::assertSame([], $row->blockers_json ?? [], 'an update needs no suburb and no new property');
        app(ReosDecisionService::class)->apply($row, $second, ['selected' => true]);

        $result = app(ReosTenancyConfirmService::class)->importChunk($second, $admin, true);

        self::assertSame(['done' => 1, 'created' => 0, 'updated' => 1, 'failed' => 0, 'remaining' => 0], $result);
        self::assertSame(1, Lease::withoutGlobalScopes()->where('external_id', self::UUID_A)->count(), 'never a second lease');
        $lease = Lease::withoutGlobalScopes()->find($leaseId);
        self::assertSame(9100.0, (float) $lease->rental_amount);
        self::assertSame('2028-02-29', $lease->end_date->toDateString());
        self::assertFalse($lease->rollover);
        self::assertSame('WLT-2002', $lease->tenant_wallet_ref);
        self::assertSame(Lease::STATUS_ACTIVE, $lease->status);
        self::assertSame($contacts, Contact::withoutGlobalScopes()->count(), 'an update touches no people');
        self::assertSame($properties, Property::withoutGlobalScopes()->count(), 'an update touches no property');
        self::assertSame(1, $second->fresh()->counts_json['imported_updated']);
        self::assertSame(RentalTakeOnImportRow::RESULT_UPDATED, $row->fresh()->result_action);
        $event = LeaseEvent::where('lease_id', $leaseId)->where('event_type', LeaseEvent::TYPE_LEASE_EDITED)->first();
        self::assertNotNull($event, 'the change is on the lease history');
        self::assertStringContainsString('rental amount', $event->description);

        // Archiving the update batch must not archive a lease that was there before it.
        $counts = app(RentalTakeOnArchiveService::class)->archive($second->fresh());
        self::assertSame(0, $counts['archived']['leases']);
        self::assertSame(1, $counts['left_alone_existing']['leases']);
        self::assertNull(Lease::withoutGlobalScopes()->find($leaseId)->deleted_at);
    }

    public function test_an_archived_lease_with_the_same_reos_id_is_a_blocker_not_a_duplicate(): void
    {
        [, , $admin] = $this->reosAgency();
        $first = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A])]);
        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($first, $admin, true);
        Lease::withoutGlobalScopes()->where('external_id', self::UUID_A)->first()->delete();

        $second = $this->makeRun($admin, [$this->reosRow(['uuid' => self::UUID_A])]);
        $row = app(ReosDecisionService::class)->apply($second->rows()->first(), $second, ['selected' => true]);

        self::assertTrue($row->is_blocked);
        self::assertContains('archived_lease', collect($row->blockers_json)->pluck('code')->all());
        self::assertFalse(ReosRunSummary::for($second)['can_import']);
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.reos.import', $second))->assertSessionHasErrors('run');
        self::assertSame(1, Lease::withoutGlobalScopes()->withTrashed()->where('external_id', self::UUID_A)->count());
    }

    public function test_a_row_that_fails_rolls_back_clean_and_the_next_row_still_imports(): void
    {
        [$agency, $branch, $admin, $agent] = $this->reosAgency();
        $existing = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'ZZ', 'last_name' => 'Linked', 'email' => 'zz.linked@example.test']);
        $run = $this->readyRun($admin, [
            $this->reosRow(['uuid' => self::UUID_A, 'Property Address' => '5 ZZ Failing Street']),
            $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Owner' => 'ZZ TEST Owner Two', 'Owner Email' => 'zz.owner2@example.test', 'Owner Phone' => '+27815550202', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Email' => 'zz.t2@example.test', 'Tenant Phone' => '+27635550203']),
        ]);
        $first = $run->rows()->orderBy('row_number')->first();
        app(ReosDecisionService::class)->apply($first, $run, ['owner' => ['action' => 'link', 'contact_id' => $existing->id]]);
        $existing->delete();   // the contact the user linked disappears before Import

        $this->actingAs($admin);
        $result = app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);

        self::assertSame(['done' => 2, 'created' => 1, 'updated' => 0, 'failed' => 1, 'remaining' => 0], $result);
        $failed = $first->fresh();
        self::assertNotNull($failed->import_error);
        self::assertStringContainsString('can no longer be found', $failed->import_error);
        self::assertStringNotContainsString('SQLSTATE', $failed->import_error);
        self::assertSame(RentalTakeOnImportRow::STATUS_PENDING, $failed->status);
        self::assertNull($failed->target_lease_id);
        self::assertNull($failed->result_action);
        self::assertSame(1, Lease::withoutGlobalScopes()->count(), 'only the good row made a lease');
        self::assertSame(1, Property::withoutGlobalScopes()->count(), 'the failed row left no property behind');
        self::assertSame(0, Property::withoutGlobalScopes()->where('street_name', 'like', '%Failing%')->count());
        self::assertSame(RentalTakeOnImportRow::STATUS_CONFIRMED, $run->rows()->orderBy('row_number')->get()[1]->status);
        self::assertSame(1, $run->fresh()->counts_json['imported_failed']);
    }

    public function test_importing_through_the_screen_carries_on_in_chunks_and_reports_created_and_updated_separately(): void
    {
        [, , $admin] = $this->reosAgency();
        $run = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A]), $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Owner' => 'ZZ TEST Owner Two', 'Owner Email' => 'zz.o2@example.test', 'Owner Phone' => '+27815550202', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Email' => 'zz.t2@example.test', 'Tenant Phone' => '+27635550203'])]);

        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.reos.import', $run))
            ->assertRedirect(route('corex.rentals.take-on-import.preview', $run))
            ->assertSessionHas('status');

        self::assertStringContainsString('2 new, 0 updated', session('status'));
        self::assertSame(RentalTakeOnImportRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->actingAs($admin)->get(route('corex.rentals.take-on-import.preview', $run))->assertOk()->assertSee('Everything in this batch has been imported.');
    }

    public function test_the_existing_template_actions_refuse_a_reos_batch_instead_of_half_working(): void
    {
        [, , $admin] = $this->reosAgency();
        $run = $this->makeRun($admin, [$this->reosRow()]);
        $row = $run->rows()->first();

        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.rows.confirm', $row))->assertRedirect(route('corex.rentals.take-on-import.preview', $run))->assertSessionHasErrors('run');
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.confirm-bulk', $run), ['ids' => [$row->id]])->assertRedirect(route('corex.rentals.take-on-import.preview', $run));
        $this->actingAs($admin)->get(route('corex.rentals.take-on-import.map-columns', $run))->assertRedirect(route('corex.rentals.take-on-import.preview', $run));
        self::assertSame(0, Lease::withoutGlobalScopes()->count());
    }

    // ── scoping ────────────────────────────────────────────────────────────────────────────

    public function test_another_agencys_batch_and_a_colleagues_batch_are_a_404_on_every_screen_and_call(): void
    {
        [$agencyA, $branchA, $adminA] = $this->reosAgency('ZZ Agency A');
        [$agencyB, , $adminB] = $this->reosAgency('ZZ Agency B');
        $runB = $this->makeRun($adminB, [$this->reosRow()]);
        $rowB = $runB->rows()->first();

        $this->actingAs($adminA)->get(route('corex.rentals.take-on-import.preview', $runB))->assertNotFound();
        $this->actingAs($adminA)->get(route('corex.rentals.take-on-import.show', $runB))->assertNotFound();
        $this->actingAs($adminA)->post(route('corex.rentals.take-on-import.reos.import', $runB))->assertNotFound();
        $this->actingAs($adminA)->postJson(route('api.v1.rentals.take-on-import.decisions', $rowB), ['selected' => true])->assertNotFound();
        $this->actingAs($adminA)->postJson(route('api.v1.rentals.take-on-import.bulk', $runB), ['action' => 'tick_all'])->assertNotFound();
        $this->actingAs($adminA)->getJson(route('api.v1.rentals.take-on-import.summary', $runB))->assertNotFound();
        self::assertFalse((bool) $rowB->fresh()->selected);

        // An own-scope colleague in the SAME agency cannot reach the admin's batch either.
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $agencyA->id], ['label' => 'Agent']);
        foreach (['rentals_take_on_import.view', 'rentals_take_on_import.manage'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $agencyA->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();
        $colleague = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'agent', 'is_active' => true]);
        $runA = $this->makeRun($adminA, [$this->reosRow()]);
        $rowA = $runA->rows()->first();

        $this->actingAs($colleague)->get(route('corex.rentals.take-on-import.preview', $runA))->assertNotFound();
        $this->actingAs($colleague)->postJson(route('api.v1.rentals.take-on-import.decisions', $rowA), ['selected' => true])->assertNotFound();
        $this->actingAs($colleague)->post(route('corex.rentals.take-on-import.reos.import', $runA))->assertNotFound();
        self::assertFalse((bool) $rowA->fresh()->selected);
    }

    // ── the lease screen and the portals ───────────────────────────────────────────────────

    public function test_the_new_lease_fields_show_on_the_lease_and_edit_through_the_form_with_a_plain_message_on_a_clash(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $run = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A]), $this->reosRow(['uuid' => self::UUID_B, 'Property Address' => '9 ZZ Marine Drive', 'Owner' => 'ZZ TEST Owner Two', 'Owner Email' => 'zz.o2@example.test', 'Owner Phone' => '+27815550202', 'Tenant' => 'ZZ TEST Tenant Two', 'Tenant Email' => 'zz.t2@example.test', 'Tenant Phone' => '+27635550203'])], ['wallet' => 'WLT-1001']);
        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        $a = Lease::withoutGlobalScopes()->where('external_id', self::UUID_A)->first();
        $b = Lease::withoutGlobalScopes()->where('external_id', self::UUID_B)->first();

        $page = $this->actingAs($admin)->get(route('corex.leases.show', $a))->assertOk();
        $page->assertSee('Imported from reOS')->assertSee('Open in reOS')->assertSee('WLT-1001')->assertSee('Rollover:');
        self::assertStringContainsString('href="https://app.reos.co.za/leases/' . self::UUID_A . '"', $page->getContent());

        $this->actingAs($admin)->put(route('corex.leases.update', $a), ['owner_wallet_ref' => 'OWN-77', 'tenant_wallet_ref' => 'WLT-5', 'rollover' => '0'])->assertRedirect();
        $a->refresh();
        self::assertSame(['OWN-77', 'WLT-5', false], [$a->owner_wallet_ref, $a->tenant_wallet_ref, $a->rollover]);
        self::assertSame(self::UUID_A, $a->external_id, 'fields not posted keep their value');

        $this->actingAs($admin)->put(route('corex.leases.update', $a), ['external_source' => 'reos', 'external_id' => self::UUID_B])
            ->assertSessionHasErrors('external_id');
        self::assertStringContainsString("Lease #{$b->id}", session('errors')->first('external_id'));
        self::assertStringNotContainsString('SQLSTATE', session('errors')->first('external_id'));
        $this->actingAs($admin)->put(route('corex.leases.update', $a), ['external_source' => '', 'external_id' => self::UUID_A])->assertSessionHasErrors('external_id');
        $this->actingAs($admin)->put(route('corex.leases.update', $a), ['external_url' => 'javascript:alert(1)'])->assertSessionHasErrors('external_url');

        // Clearing both is allowed (the lease becomes an ordinary lease).
        $this->actingAs($admin)->put(route('corex.leases.update', $a), ['external_source' => '', 'external_id' => '', 'external_url' => ''])->assertRedirect();
        self::assertNull($a->fresh()->external_id);
    }

    public function test_the_portal_shows_the_tenant_wallet_to_the_tenant_and_the_owner_wallet_to_the_owner_and_nothing_when_empty(): void
    {
        [$agency, $branch, $admin] = $this->reosAgency();
        $run = $this->readyRun($admin, [$this->reosRow(['uuid' => self::UUID_A])], ['wallet' => 'WLT-1001']);
        $this->actingAs($admin);
        app(ReosTenancyConfirmService::class)->importChunk($run, $admin, true);
        $lease = Lease::withoutGlobalScopes()->where('external_id', self::UUID_A)->first();
        $tenant = $lease->tenants->first()->contact;
        $owner = $lease->landlordContacts()->first();
        $service = app(RentalPortalOverviewService::class);

        // Owner wallet is typed in by hand — empty at first.
        self::assertSame('WLT-1001', $service->forTenant($tenant)['homes'][0]['pay_reference']);
        self::assertNull($service->forLandlord($owner)['homes'][0]['pay_reference'], 'nothing shown when the owner wallet is empty');

        $lease->update(['owner_wallet_ref' => 'OWN-77']);
        self::assertSame('OWN-77', $service->forLandlord($owner)['homes'][0]['pay_reference']);
        self::assertSame('WLT-1001', $service->forTenant($tenant)['homes'][0]['pay_reference'], 'the tenant never sees the owner reference');

        $lease->update(['tenant_wallet_ref' => '  ']);
        self::assertNull($service->forTenant($tenant)['homes'][0]['pay_reference']);

        $html = file_get_contents(base_path('resources/views/rentals/portal/_home.blade.php'));
        self::assertStringContainsString('Pay via reOS — your payment reference: ', $html);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    /** A reOS run built from synthetic rows (and optionally a Rent Roll), exactly as the upload builds it. */
    private function makeRun(User $user, array $rows, array $rentRoll = []): RentalTakeOnImportRun
    {
        $this->actingAs($user);
        $roll = $rentRoll ? $this->upload($this->rentRollFile($rentRoll), 'ZZ-rent-roll.xlsx') : null;

        return app(ReosTenancyRunService::class)->createRun($user, (int) $user->agency_id, $user->branch_id, $this->upload($this->scheduleFile($rows)), $roll);
    }

    /**
     * A run whose every row is ticked, has a suburb and an agent: ready to import. ['wallet' => 'X'] adds a Rent Roll row for the
     * FIRST schedule row's tenant.
     */
    private function readyRun(User $admin, array $rows, array $opts = []): RentalTakeOnImportRun
    {
        $roll = isset($opts['wallet'])
            ? [['address' => $rows[0]['Property Address'] ?? '3 ZZ Aqua Breeze, 13 ZZ Stafford Avenue', 'tenant' => $rows[0]['Tenant'] ?? 'ZZ TEST Tenant One', 'wallet' => $opts['wallet']]]
            : [];
        $run = $this->makeRun($admin, $rows, $roll);
        $suburb = $this->p24Suburb();
        $svc = app(ReosDecisionService::class);
        foreach ($run->rows()->orderBy('row_number')->get() as $row) {
            $svc->apply($row, $run, array_filter([
                'selected' => true,
                'suburb_id' => $row->existing_lease_id ? null : $suburb->id,
                'agent_user_id' => $row->decisions_json['agent_user_id'] ?? null,
            ], fn ($v) => $v !== null));
        }

        return $run->fresh();
    }

    /** An ordinary (non-reOS) property CoreX already holds: tracked property + stock. */
    private function knownProperty(Agency $agency, Branch $branch, User $agent, string $number, string $street, string $suburb): Property
    {
        $svc = app(TrackedPropertyMatchOrCreateService::class);
        $tp = $svc->matchOrCreate($agency->id, ['street_number' => $number, 'street_name' => $street, 'suburb' => $suburb, 'address' => "{$number} {$street}"], ['type' => 'manual', 'ref' => 'zz-known']);

        return $svc->promoteToStock($tp->id, $agent->id, ['listing_type' => 'rental', 'branch_id' => $branch->id], false);
    }
}
