<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAttendance;
use App\Models\RentalInspectionNotification;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalInspectionAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.5 — Build I-3: the attendance record and the invitation trail.
 * NOT covered, by design: the authority-letter upload (waits on Johan's Q11) and the exact printed
 * wording (§45.11 — facts only until he approves it).
 */
final class RentalInspectionI3AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $inspector;
    private Property $property;
    private Lease $lease;
    private Contact $tenantOne;
    private Contact $tenantTwo;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        config(['mail.non_production_redirect' => 'test-redirect@example.test']);

        $this->agency = Agency::create(['name' => 'I3 Agency', 'slug' => 'i3-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent Aileen']);
        $this->inspector = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Inspector Ivy']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '21 Beachfront Road, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);

        $mk = fn (string $first, string $last, string $email) => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '0821110000',
        ]);
        $this->tenantOne = $mk('Thabo', 'Tenant', 'thabo@example.test');
        $this->tenantTwo = $mk('Zanele', 'Tenant', 'zanele@example.test');
        $this->landlord = $mk('Lerato', 'Landlord', 'lerato@example.test');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenantOne->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenantTwo->id, 'is_primary' => false]);
        ContactProperty::create(['contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
    }

    private function inspection(string $type = RentalInspection::TYPE_IN, array $extra = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => $this->agent->id, 'inspector_user_id' => $this->inspector->id,
        ], $extra));
    }

    private function url(string $name, RentalInspection $inspection, array $extra = []): string
    {
        return route('corex.rental-inspections.attendance.' . $name, array_merge([$inspection], $extra));
    }

    private function record(RentalInspection $inspection, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('store', $inspection), $payload);
    }

    /** Every expected party: attended themselves. */
    private function recordEveryoneAttended(RentalInspection $inspection): void
    {
        foreach ([$this->tenantOne, $this->tenantTwo] as $tenant) {
            $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $tenant->id, 'outcome' => 'attended'])->assertStatus(201);
        }
        $this->record($inspection, ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id, 'outcome' => 'attended'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'agent', 'party_user_id' => $this->inspector->id, 'outcome' => 'attended'])->assertStatus(201);
    }

    // ═══ Who is expected ═══════════════════════════════════════════════════════

    public function test_the_board_lists_every_tenant_the_landlord_and_the_inspector(): void
    {
        $board = app(RentalInspectionAttendanceService::class)->board($this->inspection());

        $this->assertSame(
            [['tenant', 'Thabo Tenant'], ['tenant', 'Zanele Tenant'], ['landlord', 'Lerato Landlord'], ['agent', 'Inspector Ivy']],
            collect($board['rows'])->map(fn ($r) => [$r['party_role'], $r['name']])->all()
        );
        $this->assertSame(4, $board['expected']);
        $this->assertSame(0, $board['recorded']);
        $this->assertFalse($board['complete']);
    }

    public function test_with_no_inspector_set_the_creating_agent_is_the_expected_agent(): void
    {
        $board = app(RentalInspectionAttendanceService::class)->board($this->inspection(RentalInspection::TYPE_IN, ['inspector_user_id' => null]));

        $this->assertSame('Agent Aileen', collect($board['rows'])->firstWhere('party_role', 'agent')['name']);
    }

    public function test_two_invited_landlords_each_get_a_row(): void
    {
        $second = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Pieter', 'last_name' => 'Landlord', 'email' => 'pieter@example.test']);
        ContactProperty::create(['contact_id' => $second->id, 'property_id' => $this->property->id, 'role' => 'lessor']);

        $board = app(RentalInspectionAttendanceService::class)->board($this->inspection());

        $this->assertCount(2, collect($board['rows'])->where('party_role', 'landlord'));
        $this->assertSame(5, $board['expected']);
    }

    // ═══ Recording ═════════════════════════════════════════════════════════════

    public function test_each_outcome_is_recorded_with_who_and_when(): void
    {
        $inspection = $this->inspection();

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended', 'arrived_at' => '9:05'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'did_not_attend', 'attendee_name' => 'Ignored', 'arrived_at' => '10:00'])->assertStatus(201);

        $one = RentalInspectionAttendance::where('party_contact_id', $this->tenantOne->id)->firstOrFail();
        $this->assertSame('attended', $one->outcome);
        $this->assertSame('self', $one->attended_as);
        $this->assertSame('09:05', substr((string) $one->arrived_at, 0, 5));
        $this->assertSame($this->agent->id, $one->recorded_by_user_id);
        $this->assertNotNull($one->recorded_at);

        // A party who did not come has no representative, name or arrival time on file.
        $two = RentalInspectionAttendance::where('party_contact_id', $this->tenantTwo->id)->firstOrFail();
        $this->assertSame('did_not_attend', $two->outcome);
        $this->assertNull($two->attendee_name);
        $this->assertNull($two->arrived_at);
    }

    public function test_a_representative_needs_a_name_and_is_recorded_on_the_partys_own_row(): void
    {
        $inspection = $this->inspection();
        $base = ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id, 'outcome' => 'attended', 'attended_as' => 'representative'];

        $this->record($inspection, $base)->assertStatus(422)->assertJsonPath('message', 'Enter the name of the person who attended on their behalf.');
        $this->record($inspection, $base + ['attendee_name' => '   '])->assertStatus(422);
        $this->assertSame(0, RentalInspectionAttendance::count());

        $this->record($inspection, $base + ['attendee_name' => '  Sipho Agent  '])->assertStatus(201);
        $row = RentalInspectionAttendance::firstOrFail();
        $this->assertSame('Sipho Agent', $row->attendee_name);
        $this->assertSame('landlord', $row->represents_party_role);
        $this->assertSame($this->landlord->id, $row->party_contact_id);
    }

    public function test_someone_else_in_the_room_needs_a_name_and_can_only_have_attended(): void
    {
        $inspection = $this->inspection();

        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'attended', 'attended_as' => 'co_occupant'])->assertStatus(422);
        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'did_not_attend', 'attended_as' => 'other', 'attendee_name' => 'Nobody'])->assertStatus(422);
        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'attended', 'attended_as' => 'self', 'attendee_name' => 'Nobody'])->assertStatus(422);

        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'attended', 'attended_as' => 'co_occupant', 'attendee_name' => 'Gogo Tenant'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'attended', 'attended_as' => 'other', 'attendee_name' => 'A friend'])->assertStatus(201);

        $board = app(RentalInspectionAttendanceService::class)->board($inspection);
        $this->assertCount(2, $board['others']);
        $this->assertSame(0, $board['recorded'], 'extra people never count towards the expected parties');
    }

    public function test_malformed_and_unexpected_input_is_refused_in_plain_language_never_a_500(): void
    {
        $inspection = $this->inspection();
        $stranger = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Not', 'last_name' => 'OnLease']);

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $stranger->id, 'outcome' => 'attended'])
            ->assertStatus(422)->assertJsonPath('message', 'That person is not one of the parties expected at this inspection.');
        $this->record($inspection, ['party_role' => 'tenant', 'outcome' => 'attended'])->assertStatus(422);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'maybe'])->assertStatus(422);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended', 'arrived_at' => '25:99'])
            ->assertStatus(422)->assertJsonPath('message', 'The arrival time must look like 14:30.');
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended', 'attended_as' => 'co_occupant'])->assertStatus(422);
        $this->record($inspection, [])->assertStatus(422);

        $this->assertSame(0, RentalInspectionAttendance::count());
    }

    public function test_a_party_with_no_email_or_phone_is_still_recorded(): void
    {
        $bare = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'No', 'last_name' => 'Contacts']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $bare->id, 'is_primary' => false]);
        $inspection = $this->inspection();

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $bare->id, 'outcome' => 'attended'])->assertStatus(201);

        $row = collect(app(RentalInspectionAttendanceService::class)->board($inspection)['rows'])->firstWhere('name', 'No Contacts');
        $this->assertSame('attended', $row['attendance']['outcome']);
        $this->assertSame('No invitation recorded', $row['invitation']['lines'][0]['text']);
    }

    public function test_a_correction_supersedes_and_keeps_the_old_record_then_re_record_after_a_no_show(): void
    {
        $inspection = $this->inspection();
        $payload = ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id];

        $this->record($inspection, $payload + ['outcome' => 'did_not_attend'])->assertStatus(201);
        $first = RentalInspectionAttendance::firstOrFail();

        // "did not attend, then the same person turns up" — recorded as a NEW fact, the old one kept.
        $this->record($inspection, $payload + ['outcome' => 'attended', 'arrived_at' => '09:40'])->assertStatus(201);

        $this->assertSame(2, RentalInspectionAttendance::count());
        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertNotNull($first->superseded_by_id);
        $live = RentalInspectionAttendance::live()->where('party_contact_id', $this->tenantOne->id)->get();
        $this->assertCount(1, $live);
        $this->assertSame('attended', $live->first()->outcome);
        $this->assertSame($live->first()->id, $first->superseded_by_id);

        $row = collect(app(RentalInspectionAttendanceService::class)->board($inspection)['rows'])->firstWhere('contact_id', $this->tenantOne->id);
        $this->assertSame('attended', $row['attendance']['outcome']);
    }

    public function test_withdrawing_keeps_the_record_on_file_and_returns_the_party_to_not_recorded(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'other', 'outcome' => 'attended', 'attended_as' => 'other', 'attendee_name' => 'Added by mistake'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);

        foreach (RentalInspectionAttendance::all() as $row) {
            $this->postJson($this->url('withdraw', $inspection, [$row]))->assertOk();
        }

        $this->assertSame(2, RentalInspectionAttendance::count(), 'withdrawn, never deleted');
        $this->assertSame(0, RentalInspectionAttendance::live()->count());
        $this->assertNull(RentalInspectionAttendance::first()->superseded_by_id);
        $this->assertSame(0, app(RentalInspectionAttendanceService::class)->board($inspection)['recorded']);

        // Withdrawing twice is harmless.
        $this->postJson($this->url('withdraw', $inspection, [RentalInspectionAttendance::first()]))->assertOk();
    }

    public function test_a_retried_request_with_the_same_key_returns_the_same_record(): void
    {
        $inspection = $this->inspection();
        $payload = ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended', 'client_idempotency_key' => 'abc-123'];

        $this->record($inspection, $payload)->assertStatus(201);
        $this->record($inspection, $payload)->assertStatus(201);
        $this->record($inspection, array_merge($payload, ['outcome' => 'did_not_attend']))->assertStatus(201);

        $this->assertSame(1, RentalInspectionAttendance::count());
        $this->assertSame('attended', RentalInspectionAttendance::firstOrFail()->outcome, 'a retry never rewrites what was first recorded');
    }

    public function test_attendance_cannot_be_recorded_on_a_completed_or_cancelled_inspection(): void
    {
        $completed = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $this->postJson(route('corex.rental-inspections.complete', $completed))->assertOk();
        $this->record($completed, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(409);

        $cancelled = $this->inspection();
        $cancelled->forceFill(['status' => RentalInspection::STATUS_CANCELLED])->save();
        $this->record($cancelled, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(409);
    }

    public function test_archiving_and_restoring_an_inspection_carries_its_attendance_with_it(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);

        $inspection->delete();
        $inspection->restore();

        $this->assertSame(1, app(RentalInspectionAttendanceService::class)->board($inspection->fresh())['recorded']);
    }

    // ═══ Permissions and scope ═════════════════════════════════════════════════

    private function seedGrants(array $permissions): void
    {
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach ($permissions as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    public function test_recording_needs_the_record_attendance_permission(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'access_properties' => null]);
        $inspection = $this->inspection();

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(403);
        $this->postJson(route('corex.rental-inspections.attendance.invitations.store', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'method' => 'Phone call'])->assertStatus(403);
        $this->assertSame(0, RentalInspectionAttendance::count());
    }

    public function test_correcting_or_withdrawing_someone_elses_record_needs_the_resolve_permission(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'rental_inspections.record_attendance' => null, 'access_properties' => null]);
        $inspection = $this->inspection();

        // A record made by a colleague.
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $theirs = RentalInspectionAttendance::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id,
            'outcome' => 'did_not_attend', 'attended_as' => 'self', 'recorded_by_user_id' => $colleague->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])
            ->assertStatus(403)->assertJsonPath('message', 'This was recorded by someone else. Only a manager can correct it.');
        $this->postJson($this->url('withdraw', $inspection, [$theirs]))->assertStatus(403);
        $this->assertTrue($theirs->fresh()->isLive());

        // Their OWN record is theirs to correct, and a party nobody has recorded yet is open to anyone with record_attendance.
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'attended'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'did_not_attend'])->assertStatus(201);
    }

    public function test_a_manager_can_correct_someone_elses_record(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'rental_inspections.record_attendance' => null, 'rental_inspections.resolve_discrepancy' => null, 'access_properties' => null]);
        $inspection = $this->inspection();
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        RentalInspectionAttendance::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id,
            'outcome' => 'did_not_attend', 'attended_as' => 'self', 'recorded_by_user_id' => $colleague->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);
        $this->assertSame('attended', RentalInspectionAttendance::live()->where('party_contact_id', $this->tenantOne->id)->firstOrFail()->outcome);
    }

    public function test_another_agency_or_an_out_of_scope_user_cannot_reach_the_attendance_routes(): void
    {
        $inspection = $this->inspection();
        $row = RentalInspectionAttendance::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id,
            'outcome' => 'attended', 'attended_as' => 'self', 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);

        // BelongsToAgency forces a new record into the acting user's agency, so a user of ANOTHER agency must be created
        // with nobody logged in — otherwise this "outsider" would silently be a same-agency colleague.
        \Illuminate\Support\Facades\Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        // A non-owner role: the global "admin" role is a platform owner who deliberately sees every agency.
        $outsider = User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'agent']);
        $this->assertSame($otherAgency->id, $outsider->fresh()->agency_id, 'the outsider really is in another agency');
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        foreach (['outsider' => $outsider, 'colleague' => $colleague] as $who => $user) {
            $this->actingAs($user);
            $statuses = [
                $this->getJson($this->url('show', $inspection))->getStatusCode(),
                $this->postJson($this->url('store', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'did_not_attend'])->getStatusCode(),
                $this->postJson($this->url('withdraw', $inspection, [$row]))->getStatusCode(),
            ];
            $this->assertSame([404, 404, 404], $statuses, "{$who} must not reach the attendance routes");
        }
        $this->assertTrue($row->fresh()->isLive());
    }

    public function test_an_attendance_row_from_another_inspection_cannot_be_withdrawn_through_this_one(): void
    {
        $mine = $this->inspection();
        $other = $this->inspection(RentalInspection::TYPE_OUT);
        $row = RentalInspectionAttendance::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $other->id, 'party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id,
            'outcome' => 'attended', 'attended_as' => 'self', 'recorded_by_user_id' => $this->agent->id, 'recorded_at' => now(), 'created_at' => now(),
        ]);

        $this->postJson($this->url('withdraw', $mine, [$row]))->assertNotFound();
        $this->assertTrue($row->fresh()->isLive());
    }

    // ═══ Completion guard ══════════════════════════════════════════════════════

    public function test_completion_is_refused_with_the_list_of_parties_still_without_an_outcome(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);

        $response = $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertStatus(409);

        $this->assertSame(
            ['Zanele Tenant', 'Lerato Landlord', 'Inspector Ivy'],
            collect($response->json('missing_attendance'))->pluck('name')->all()
        );
        $this->assertStringContainsString('attendance has not been recorded for Zanele Tenant (tenant), Lerato Landlord (landlord), Inspector Ivy (agent)', $response->json('message'));
        $this->assertSame(RentalInspection::STATUS_DRAFT, $inspection->fresh()->status);
    }

    public function test_once_everyone_has_an_outcome_the_attendance_guard_no_longer_blocks_and_the_inspection_completes(): void
    {
        // Property::sellerOwnerContact() (the landlord who signs) reads contacts through the acting user's own
        // Contacts scope — an admin sees the landlord whoever created the contact.
        $this->actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']));
        $inspection = $this->inspection();
        $this->recordEveryoneAttended($inspection);

        // Signatures: both tenants, the landlord, then the agent (the existing rule).
        foreach ([$this->tenantOne, $this->tenantTwo] as $tenant) {
            RentalInspectionSignature::capture($inspection, 'tenant', 'signed', ['party_contact_id' => $tenant->id, 'party_signature_path' => 'private:sig.png', 'recorded_by_user_id' => $this->agent->id]);
        }
        RentalInspectionSignature::capture($inspection, 'landlord', 'signed', ['party_contact_id' => $this->landlord->id, 'party_signature_path' => 'private:sig.png', 'recorded_by_user_id' => $this->agent->id]);
        RentalInspectionSignature::capture($inspection, 'agent', 'signed', ['party_signature_path' => 'private:sig.png', 'recorded_by_user_id' => $this->agent->id]);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_ad_hoc_inspections_are_never_gated_on_attendance(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
    }

    public function test_a_party_who_did_not_attend_is_recorded_as_no_signature_not_a_refusal(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'did_not_attend'])->assertStatus(201);
        RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenantTwo->id, 'refusal_reason_preset' => 'not_present', 'recorded_by_user_id' => $this->agent->id]);

        $page = $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->getContent();

        $this->assertStringContainsString('No signature — did not attend', $page);
        $this->assertStringNotContainsString('Refused to sign', $page);
        $this->assertStringContainsString('Did not attend', $page);

        // The same on the signed PDF and on the public report link.
        $pdf = app(\App\Services\Rentals\RentalInspectionReportPdfService::class)->generate($inspection->fresh())->getDomPDF()->outputHtml();
        $pdf = html_entity_decode($pdf, ENT_QUOTES | ENT_HTML5, 'UTF-8');   // the PDF engine writes the em dash as an entity
        $this->assertStringContainsString('No signature — did not attend', $pdf);
        $this->assertStringNotContainsString('>Refused<', $pdf);

        $inspection->generatePublicLink();
        $public = $this->get(route('rental-inspections.public.show', $inspection->fresh()->public_token))->assertOk()->getContent();
        $this->assertStringContainsString('No signature — did not attend', $public);
        $this->assertStringNotContainsString('Refused to sign', $public);
    }

    public function test_a_real_refusal_by_someone_who_attended_still_reads_as_a_refusal(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);
        RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenantOne->id, 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'Disputes the damp.', 'recorded_by_user_id' => $this->agent->id]);

        $page = $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->getContent();

        $this->assertStringContainsString('Refused to sign', $page);
        $this->assertStringContainsString('Disputes the damp.', $page);
    }

    // ═══ Backfill ══════════════════════════════════════════════════════════════

    public function test_the_migration_backfills_a_did_not_attend_row_for_every_old_not_present_refusal_and_is_idempotent(): void
    {
        $inspection = $this->inspection();
        $old = RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenantOne->id, 'refusal_reason_preset' => 'not_present', 'recorded_by_user_id' => $this->agent->id]);
        RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenantTwo->id, 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'x', 'recorded_by_user_id' => $this->agent->id]);

        $migration = require base_path('database/migrations/2026_10_13_100240_backfill_attendance_from_not_present_signatures.php');
        $migration->up();
        $migration->up();

        $rows = RentalInspectionAttendance::all();
        $this->assertCount(1, $rows, 'one row, for the not_present refusal only, and not duplicated by a second run');
        $this->assertSame('did_not_attend', $rows->first()->outcome);
        $this->assertSame('from signature record', $rows->first()->note);
        $this->assertSame($this->tenantOne->id, $rows->first()->party_contact_id);
        $this->assertSame('refused', $old->fresh()->disposition, 'the signature evidence itself is never rewritten');
    }

    // ═══ Representative signing ════════════════════════════════════════════════

    public function test_a_representative_can_sign_on_the_partys_row_only_if_attendance_shows_them(): void
    {
        $this->actingAs(User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']));
        $inspection = $this->inspection();

        try {
            RentalInspectionSignature::capture($inspection, 'landlord', 'signed', ['party_contact_id' => $this->landlord->id, 'party_signature_path' => 'private:s.png', 'signed_by_name' => 'Sipho Agent']);
            $this->fail('expected the unrecorded representative to be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Record under Attendance', $e->getMessage());
        }

        $this->record($inspection, ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id, 'outcome' => 'attended', 'attended_as' => 'representative', 'attendee_name' => 'Sipho Agent'])->assertStatus(201);
        $signature = RentalInspectionSignature::capture($inspection, 'landlord', 'signed', ['party_contact_id' => $this->landlord->id, 'party_signature_path' => 'private:s.png', 'signed_by_name' => ' sipho agent ']);

        $this->assertSame('Sipho Agent', $signature->signed_by_name, 'stored exactly as the attendance record spells it');
        $this->assertSame('representative', $signature->signing_capacity);
    }

    public function test_a_party_signing_themselves_has_no_signer_name_and_a_representative_cannot_refuse_for_them(): void
    {
        $inspection = $this->inspection();
        $self = RentalInspectionSignature::capture($inspection, 'tenant', 'signed', ['party_contact_id' => $this->tenantOne->id, 'party_signature_path' => 'private:s.png']);
        $this->assertNull($self->signed_by_name);
        $this->assertNull($self->signing_capacity);

        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'attended', 'attended_as' => 'representative', 'attendee_name' => 'Rep Name'])->assertStatus(201);
        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($inspection, 'tenant', 'refused', ['party_contact_id' => $this->tenantTwo->id, 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'x', 'signed_by_name' => 'Rep Name']);
    }

    // ═══ Invitation trail ══════════════════════════════════════════════════════

    public function test_the_trail_shows_system_invitations_reschedules_reminders_and_manual_invitations_per_party(): void
    {
        Mail::fake();
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agent, [
            'scheduled_for' => now()->addDays(6)->toDateString(), 'inspector_user_id' => $this->inspector->id,
        ]);

        $lines = fn (array $board, string $name) => collect($board['rows'])->firstWhere('name', $name)['invitation']['lines'];
        $service = app(RentalInspectionAttendanceService::class);

        $board = $service->board($inspection);
        $this->assertStringStartsWith('Invited by email on ', $lines($board, 'Thabo Tenant')[0]['text']);
        $this->assertTrue($lines($board, 'Thabo Tenant')[0]['printable']);
        $this->assertStringStartsWith('Invited by email on ', $lines($board, 'Lerato Landlord')[0]['text']);
        $this->assertStringStartsWith('Invited by email on ', $lines($board, 'Inspector Ivy')[0]['text']);

        // A later reminder shows on screen but is not part of the printed statement (§45.11 item 2 is Johan's call).
        RentalInspectionNotification::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'event' => 'reminder', 'party_role' => 'tenant',
            'recipient_contact_id' => $this->tenantOne->id, 'channel' => 'mail', 'recipient' => 'thabo@example.test', 'status' => 'sent',
        ]);
        $board = $service->board($inspection);
        $thabo = collect($board['rows'])->firstWhere('name', 'Thabo Tenant');
        $this->assertTrue(collect($thabo['invitation']['lines'])->contains(fn ($l) => str_starts_with($l['text'], 'Reminder sent ') && $l['printable'] === false));
        $this->assertStringNotContainsString('Reminder', $service->printableInvitation($thabo));

        // Failed and skipped sends are not statements that the party was told.
        $zanele = collect($board['rows'])->firstWhere('name', 'Zanele Tenant');
        $this->assertStringStartsWith('Invited by email on ', $zanele['invitation']['lines'][0]['text']);
    }

    public function test_a_start_now_inspection_says_no_invitation_was_recorded_and_a_manual_one_can_be_added(): void
    {
        $inspection = $this->inspection();
        $board = app(RentalInspectionAttendanceService::class)->board($inspection);
        $this->assertSame('No invitation recorded', $board['rows'][0]['invitation']['lines'][0]['text']);

        $this->postJson(route('corex.rental-inspections.attendance.invitations.store', $inspection), [
            'party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'method' => 'Phone call', 'occurred_at' => now()->subDays(2)->setTime(16, 20)->format('Y-m-d\TH:i'),
        ])->assertStatus(201);

        $row = RentalInspectionNotification::firstOrFail();
        $this->assertSame('invitation_manual', $row->event);
        $this->assertSame('manual', $row->channel);
        $this->assertSame('Phone call', $row->method);
        $this->assertSame($this->agent->id, $row->sent_by_user_id);
        $this->assertSame('16:20', $row->occurred_at->format('H:i'));

        $thabo = collect(app(RentalInspectionAttendanceService::class)->board($inspection)['rows'])->firstWhere('name', 'Thabo Tenant');
        $this->assertSame(
            'Invitation recorded manually by Agent Aileen, Phone call, ' . now()->subDays(2)->format('j M Y') . ' 16:20',
            $thabo['invitation']['lines'][0]['text']
        );
        $this->assertTrue($thabo['invitation']['lines'][0]['printable']);
        $this->assertTrue($thabo['invitation']['recorded']);
    }

    public function test_a_manual_invitation_is_validated(): void
    {
        $inspection = $this->inspection();
        $url = route('corex.rental-inspections.attendance.invitations.store', $inspection);
        $base = ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'method' => 'In person'];

        $this->postJson($url, array_merge($base, ['method' => '   ']))->assertStatus(422);
        $this->postJson($url, array_merge($base, ['method' => str_repeat('x', 70)]))->assertStatus(422);
        $this->postJson($url, array_merge($base, ['occurred_at' => now()->addDays(2)->toDateTimeString()]))
            ->assertStatus(422)->assertJsonPath('message', 'An invitation cannot have been given in the future.');
        $this->postJson($url, array_merge($base, ['occurred_at' => 'last tuesday-ish']))->assertStatus(422);
        $this->postJson($url, array_merge($base, ['party_contact_id' => 99999999]))->assertStatus(422);
        $this->assertSame(0, RentalInspectionNotification::count());

        // No time given = now.
        $this->postJson($url, $base)->assertStatus(201);
        $this->assertEqualsWithDelta(now()->timestamp, RentalInspectionNotification::firstOrFail()->occurred_at->timestamp, 5);
    }

    // ═══ Report naming ═════════════════════════════════════════════════════════

    public function test_the_report_names_the_inspector_who_attended_not_always_the_creator(): void
    {
        $inspection = $this->inspection()->load('lease.tenants.contact', 'property', 'signatures');

        $agentRow = collect($inspection->signatureSummaryRows())->firstWhere('role', 'Agent');
        $this->assertSame('Inspector Ivy', $agentRow['name']);

        $noInspector = $this->inspection(RentalInspection::TYPE_OUT, ['inspector_user_id' => null])->load('lease.tenants.contact', 'property', 'signatures');
        $this->assertSame('Agent Aileen', collect($noInspector->signatureSummaryRows())->firstWhere('role', 'Agent')['name']);
    }

    // ═══ List screen ═══════════════════════════════════════════════════════════

    public function test_the_list_shows_attended_of_expected_and_filters_and_sorts_by_attendance(): void
    {
        $full = $this->inspection();
        $this->recordEveryoneAttended($full);

        $partial = $this->inspection(RentalInspection::TYPE_OUT);
        $this->record($partial, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);
        $this->record($partial, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantTwo->id, 'outcome' => 'did_not_attend'])->assertStatus(201);

        $adHoc = $this->inspection(RentalInspection::TYPE_AD_HOC);

        $html = $this->get(route('corex.rental-inspections.index'))->assertOk()->getContent();
        $this->assertStringContainsString('4 of 4', $html);
        $this->assertStringContainsString('1 of 4', $html);

        $ids = fn (string $query) => collect($this->get(route('corex.rental-inspections.index') . $query)->assertOk()->viewData('inspections')->items())->pluck('id')->all();

        $this->assertSame([$partial->id], $ids('?attendance=incomplete'), 'only the not-fully-recorded in/out inspection (ad hoc never counts)');
        $this->assertSame([$partial->id], $ids('?attendance=did_not_attend'));
        $this->assertEqualsCanonicalizing([$full->id, $partial->id, $adHoc->id], $ids(''));
        $this->assertSame([$adHoc->id, $partial->id, $full->id], $ids('?sort=attended&direction=asc'));
        $this->assertSame([$full->id, $partial->id, $adHoc->id], $ids('?sort=attended&direction=desc'));
    }

    // ═══ Settings and wizard ═══════════════════════════════════════════════════

    public function test_the_attendance_wording_defaults_saves_and_a_wizard_post_that_never_rendered_it_leaves_it_alone(): void
    {
        $this->assertSame(RentalInspectionSetting::DEFAULT_ATTENDED_AS_LABELS, RentalInspectionSetting::attendedAsLabelsFor($this->agency->id));
        $this->assertSame(RentalInspectionSetting::DEFAULT_ATTENDED_AS_LABELS, RentalInspectionSetting::attendedAsLabelsFor(null));

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($admin);

        $this->post(route('corex.settings.rental-inspections.attended-as-labels'), [
            'attended_as_labels_submitted' => '1',
            'attended_as_labels' => ['self' => 'Present', 'representative' => '  Sent someone  ', 'co_occupant' => '', 'other' => str_repeat('z', 80)],
        ])->assertSessionHasNoErrors();

        $labels = RentalInspectionSetting::attendedAsLabelsFor($this->agency->id);
        $this->assertSame('Present', $labels['self']);
        $this->assertSame('Sent someone', $labels['representative']);
        $this->assertSame('Co-occupant', $labels['co_occupant'], 'a blank label keeps its default');
        $this->assertSame(60, mb_strlen($labels['other']));

        // The wizard saver is a no-op without the marker.
        $this->post(route('corex.settings.rental-inspections.update'), ['fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 7])->assertSessionHasNoErrors();
        app(\App\Http\Controllers\CoreX\RentalListsWizardSaver::class)->inspectionAttendedAsLabels(request()->duplicate([], []));
        $this->assertSame('Present', RentalInspectionSetting::attendedAsLabelsFor($this->agency->id)['self']);

        // Without the marker the canonical saver refuses rather than wiping.
        $this->post(route('corex.settings.rental-inspections.attended-as-labels'), ['attended_as_labels' => ['self' => 'Hacked']])->assertSessionHasErrors('attended_as_labels');
        $this->assertSame('Present', RentalInspectionSetting::attendedAsLabelsFor($this->agency->id)['self']);

        $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()->assertSee('How someone attended an inspection');
    }

    public function test_the_tab_payload_carries_the_attendance_board_for_the_recording_screen(): void
    {
        $inspection = $this->inspection();
        $this->record($inspection, ['party_role' => 'tenant', 'party_contact_id' => $this->tenantOne->id, 'outcome' => 'attended'])->assertStatus(201);

        $json = $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property))->assertOk()->json();
        $board = $json['chain_tail']['attendance_board'] ?? null;

        $this->assertNotNull($board);
        $this->assertSame(4, $board['expected']);
        $this->assertSame(1, $board['recorded']);
    }

    public function test_the_permission_key_is_defined_and_granted_where_create_is(): void
    {
        $source = (string) file_get_contents(base_path('config/corex-permissions.php'));

        // One definition + the two role defaults that carry rental_inspections.create (branch manager, agent).
        $this->assertGreaterThanOrEqual(3, substr_count($source, "rental_inspections.record_attendance'"));
        $this->assertSame(
            substr_count($source, "'rental_inspections.create', 'rental_inspections.record_attendance'"),
            substr_count($source, "'rental_inspections.create',") - 1,   // minus the permission's own definition row
            'every default role list that grants create also grants record_attendance'
        );
    }
}
