<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderInvoice;
use App\Models\RentalWorkOrderPhoto;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P2 - the staff fault + work-order API under /api/v1/mobile. Every endpoint family proves: success, permission denied, another agency's
 * record is a 404, a same-agency record outside the user's own/branch ceiling is a 403, validation 422, an idempotent replay (200, duplicate:true,
 * ONE row), 410 for a queued write against an archived record, and - for lists - search, sort, filter, pagination and the plain empty-state sentence.
 * .ai/specs/at-508-mobile-rentals.md sections 4-7.
 */
final class MobileRentalsP2Test extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const M = '/api/v1/mobile';

    private const ALL_KEYS = [
        'rental_fault_reports.view', 'rental_fault_reports.create', 'rental_fault_reports.record_approval', 'rental_fault_reports.resolve',
        'rental_work_orders.view', 'rental_work_orders.create', 'rental_work_orders.complete', 'rental_work_orders.manage_invoices',
        'properties.view', 'access_properties',
    ];

    private User $junior;     // same agency, OWN scope, not on anything the fixtures create
    private User $viewer;     // same agency, may only look
    private User $outsider;   // another agency, full power there

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        Storage::fake('public');
        Storage::fake('local');
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function __construct() {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void {}
        });

        $this->grantScope($this->agency, 'agent', 'all', array_merge(self::ALL_KEYS, ['rental_job_cards.view_costs']));
        $this->grantScope($this->agency, 'junior', 'own', self::ALL_KEYS);
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_fault_reports.view', 'rental_work_orders.view', 'properties.view', 'access_properties']);
        $this->junior = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'junior', 'is_active' => true]);
        $this->viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);

        $otherBranch = Branch::create(['agency_id' => $this->other->id, 'name' => 'Other main', 'code' => 'O-' . $this->other->id, 'is_active' => true]);
        $this->grantScope($this->other, 'agent', 'all', array_merge(self::ALL_KEYS, ['rental_job_cards.view_costs']));
        $this->outsider = User::factory()->create(['agency_id' => $this->other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent', 'is_active' => true]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function workOrder(array $over = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $this->agent->id, 'title' => 'Fix the gate', 'description' => 'Gate sticks', 'trade_type' => 'general',
            'status' => RentalWorkOrder::STATUS_REPORTED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    /** an outside-contractor job that the approval gate lets through (so a contractor can be appointed) */
    private function outsideWorkOrder(array $over = []): RentalWorkOrder
    {
        $wo = $this->workOrder(array_merge(['assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER], $over));
        $wo->forceFill(['approval_basis' => RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED])->save();

        return $wo->fresh();
    }

    private function provider(string $name = 'Acme Plumbing', ?\App\Models\Agency $agency = null): AgencyServiceProvider
    {
        return AgencyServiceProvider::create(['agency_id' => ($agency ?? $this->agency)->id, 'name' => $name, 'email' => 'acme@example.invalid', 'is_active' => true, 'created_by_id' => $this->admin->id]);
    }

    private function jpg(string $name = 'p.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    private function faultBody(array $over = []): array
    {
        return array_merge(['title' => 'Tap drips', 'description' => 'The kitchen tap drips all night.', 'rental_fault_type_id' => $this->faultType->id], $over);
    }

    private function postMultipart(string $url, array $data)
    {
        return $this->withHeaders(['Accept' => 'application/json'])->post($url, $data);
    }

    // ── catalogue ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_fault_catalogue_is_the_agencys_own_active_list_plus_other(): void
    {
        RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Retired type', 'category' => 'x', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 2, 'is_active' => false]);
        RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->other->id, 'name' => 'Their type', 'category' => 'x', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);

        $res = $this->asStaff($this->agent)->getJson(self::M . '/rental-fault-types')->assertOk();
        // (a new agency is seeded with CoreX's default catalogue too)
        $names = array_column($res->json('data'), 'name');
        $this->assertContains('Dripping tap', $names);
        $this->assertNotContains('Retired type', $names, 'archived / inactive types are not offered');
        $this->assertNotContains('Their type', $names, 'another agency\'s catalogue is not offered');
        $this->assertSame('other', $res->json('other_option.id'));

        // with a property: the first-aid steps are worked out for it
        $withSteps = $this->asStaff($this->agent)->getJson(self::M . '/rental-fault-types?property_id=' . $this->property->id)->assertOk();
        $this->assertArrayHasKey('steps', $withSteps->json('data.0'));
        // another agency's property is simply not there
        $this->asStaff($this->outsider)->getJson(self::M . '/rental-fault-types?property_id=' . $this->property->id)->assertNotFound();
        // may not report faults -> may not pick a type
        $this->asStaff($this->viewer)->getJson(self::M . '/rental-fault-types')->assertForbidden();
    }

    // ── fault report: create ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_fault_is_reported_through_the_same_service_as_the_web_form(): void
    {
        $key = (string) Str::uuid();
        $when = now()->subHours(3)->startOfSecond();
        $res = $this->asStaff($this->agent)->postJson(self::M . "/properties/{$this->property->id}/fault-reports", $this->faultBody([
            'lease_id' => $this->lease->id, 'client_idempotency_key' => $key, 'captured_at' => $when->toIso8601String(),
        ]))->assertCreated();

        $res->assertJsonPath('duplicate', false)->assertJsonPath('status', 'reported')->assertJsonPath('property.id', $this->property->id)
            ->assertJsonPath('reference', 'FR-' . $res->json('id'));
        $this->assertIsArray($res->json('timeline'));
        $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertSame($this->agency->id, (int) $fault->agency_id);
        $this->assertSame($this->agent->id, (int) $fault->captured_by_user_id);
        $this->assertSame('agent_noticed', $fault->reported_by_type);
        $this->assertSame('app', $fault->reported_channel);
        $this->assertSame($key, $fault->client_idempotency_key);
        $this->assertSame($when->timestamp, $fault->reported_at->timestamp, 'the phone\'s capture time is the reported time');
    }

    public function test_other_not_on_the_list_is_marked_other_and_a_phone_clock_in_the_future_is_clamped(): void
    {
        $res = $this->asStaff($this->agent)->postJson(self::M . "/properties/{$this->property->id}/fault-reports",
            $this->faultBody(['rental_fault_type_id' => 'other', 'captured_at' => now()->addDay()->toIso8601String()]))->assertCreated();
        $fault = RentalFaultReport::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertTrue((bool) $fault->fault_type_other);
        $this->assertNull($fault->rental_fault_type_id);
        $this->assertSame('Other', $res->json('fault_type'));
        $this->assertTrue($fault->reported_at->lessThanOrEqualTo(now()->addSecond()), 'never reported in the future');
    }

    public function test_fault_creation_is_validated_and_guarded(): void
    {
        $url = self::M . "/properties/{$this->property->id}/fault-reports";
        $theirs = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->other->id, 'name' => 'Theirs', 'category' => 'x', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $this->asStaff($this->agent)->postJson($url, ['description' => 'x'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->asStaff($this->agent)->postJson($url, $this->faultBody(['client_idempotency_key' => 'not-a-uuid']))->assertStatus(422);
        // a fault type of another agency
        $this->asStaff($this->agent)->postJson($url, $this->faultBody(['rental_fault_type_id' => $theirs->id]))->assertStatus(422);
        // a lease that is not on this property
        $this->asStaff($this->agent)->postJson($url, $this->faultBody(['lease_id' => 999999]))->assertStatus(422);

        $this->asStaff($this->viewer)->postJson($url, $this->faultBody())->assertForbidden();                    // permission
        $this->asStaff($this->outsider)->postJson($url, $this->faultBody())->assertNotFound();                   // another agency
        $this->asStaff($this->junior)->postJson($url, $this->faultBody())->assertNotFound();                     // same agency, property outside their own book
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_the_same_queued_fault_sent_twice_is_one_fault(): void
    {
        $url = self::M . "/properties/{$this->property->id}/fault-reports";
        $body = $this->faultBody(['client_idempotency_key' => (string) Str::uuid()]);

        $first = $this->asStaff($this->agent)->postJson($url, $body)->assertCreated();
        $second = $this->asStaff($this->agent)->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->count());

        // the same key on a different property is the app's mistake, not a replay
        $other = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '99 Hill St', 'address' => '99 Hill St', 'status' => 'active', 'listing_type' => 'rental']);
        $this->asStaff($this->agent)->postJson(self::M . "/properties/{$other->id}/fault-reports", $body)->assertStatus(409)->assertJsonPath('code', 'key_reused');

        // archived meanwhile: the app is told to drop it (410), no second row appears
        RentalFaultReport::withoutGlobalScopes()->findOrFail($first->json('id'))->delete();
        $this->asStaff($this->agent)->postJson($url, $body)->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->assertSame(1, RentalFaultReport::withoutGlobalScopes()->withTrashed()->count());
    }

    // ── fault report: photos ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_fault_photo_is_stored_once_per_key_and_keeps_the_capture_time(): void
    {
        $fault = $this->fault();
        $key = (string) Str::uuid();
        $when = now()->subHour()->startOfSecond();
        $url = self::M . "/fault-reports/{$fault->id}/photos";

        $first = $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'client_idempotency_key' => $key, 'captured_at' => $when->toIso8601String()])
            ->assertCreated()->assertJsonPath('duplicate', false);
        $this->assertStringStartsWith('http', (string) $first->json('url'), 'image URLs are absolute');
        $this->assertSame($when->timestamp, RentalFaultReportPhoto::withoutGlobalScopes()->firstOrFail()->created_at->timestamp);

        $again = $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'client_idempotency_key' => $key])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, RentalFaultReportPhoto::withoutGlobalScopes()->count());

        // the key reused on another fault
        $other = $this->fault();
        $this->asStaff($this->agent)->postMultipart(self::M . "/fault-reports/{$other->id}/photos", ['photo' => $this->jpg(), 'client_idempotency_key' => $key])->assertStatus(409);
        $this->assertSame(1, RentalFaultReportPhoto::withoutGlobalScopes()->count());
    }

    public function test_fault_photo_guards(): void
    {
        $fault = $this->fault();
        $url = self::M . "/fault-reports/{$fault->id}/photos";
        $this->asStaff($this->agent)->postMultipart($url, [])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->asStaff($this->viewer)->postMultipart($url, ['photo' => $this->jpg()])->assertForbidden();
        $this->asStaff($this->outsider)->postMultipart($url, ['photo' => $this->jpg()])->assertNotFound();
        $this->asStaff($this->junior)->postMultipart($url, ['photo' => $this->jpg()])->assertForbidden();

        $fault->delete();
        $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'client_idempotency_key' => (string) Str::uuid()])->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->assertSame(0, RentalFaultReportPhoto::withoutGlobalScopes()->count());
    }

    // ── fault report: the owner's decision and the outcome ──────────────────────────────────────────────────────────────────

    public function test_the_owners_decision_is_recorded_by_the_model_and_only_once(): void
    {
        $fault = $this->fault();
        $url = self::M . "/fault-reports/{$fault->id}/approval";
        $body = ['decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner phoned and agreed.', 'contractor_name' => 'Joe Plumber'];

        $this->asStaff($this->viewer)->postJson($url, $body)->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, $body)->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, $body)->assertForbidden();
        $this->asStaff($this->agent)->postJson($url, array_diff_key($body, ['evidence_text' => 1]))->assertStatus(422);
        $this->asStaff($this->agent)->postJson($url, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok'])->assertStatus(422)
            ->assertJsonPath('message', 'Say who appoints the contractor: the owner or the agency.');

        $res = $this->asStaff($this->agent)->postJson($url, $body)->assertOk();
        $res->assertJsonPath('status', 'owner_handling')->assertJsonPath('owner_approval_status', 'approved')->assertJsonPath('approvals.0.contractor_name', 'Joe Plumber');
        // one decision, ever: the second is refused in plain words
        $this->asStaff($this->agent)->postJson($url, $body)->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertSame(1, $fault->approvals()->count());
    }

    public function test_the_outcome_closes_the_fault_and_a_closed_fault_stays_closed(): void
    {
        $fault = $this->fault();
        $url = self::M . "/fault-reports/{$fault->id}/outcome";

        $this->asStaff($this->viewer)->postJson($url, ['outcome' => 'repaired'])->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertForbidden();
        $this->asStaff($this->agent)->postJson($url, ['outcome' => 'nonsense'])->assertStatus(422);
        // the model's own rule: "repaired" needs the date it was repaired
        $this->asStaff($this->agent)->postJson($url, ['outcome' => 'repaired'])->assertStatus(422)->assertJsonStructure(['message']);

        $this->asStaff($this->agent)->postJson($url, ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertOk()
            ->assertJsonPath('status', 'resolved')->assertJsonPath('outcome', 'repaired');
        $this->asStaff($this->agent)->postJson($url, ['outcome' => 'not_repaired', 'outcome_note' => 'x'])->assertStatus(422);
    }

    // ── fault report: list + detail ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_fault_list_searches_sorts_filters_and_pages(): void
    {
        $emergency = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Burst pipe', 'category' => 'Plumbing', 'urgency' => 'emergency', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 2, 'is_active' => true]);
        $old = $this->fault(['title' => 'Old gate fault', 'reported_at' => now()->subDays(10)]);
        $mid = $this->fault(['title' => 'Mid window fault', 'reported_at' => now()->subDays(5), 'status' => RentalFaultReport::STATUS_APPROVED]);
        $new = $this->fault(['title' => 'New pipe fault', 'rental_fault_type_id' => $emergency->id, 'reported_at' => now()->subDay()]);
        $hill = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '01 Hill Street', 'address' => '01 Hill Street', 'status' => 'active', 'listing_type' => 'rental']);
        $elsewhere = $this->fault(['title' => 'Hill fault', 'property_id' => $hill->id, 'lease_id' => null, 'reported_at' => now()->subDays(20)]);

        $ids = fn ($res) => array_column($res->json('data'), 'id');
        $list = fn (string $qs = '') => $this->asStaff($this->agent)->getJson(self::M . '/fault-reports' . $qs)->assertOk();

        // default: newest first
        $this->assertSame([$new->id, $mid->id, $old->id, $elsewhere->id], $ids($list()));
        $this->assertSame([$elsewhere->id, $old->id, $mid->id, $new->id], $ids($list('?sort=oldest')));
        $this->assertSame([$elsewhere->id], array_slice($ids($list('?sort=property')), 0, 1), 'sorted by property: Hill Street (01) sorts before Sea Road (12)');
        $this->assertSame([$mid->id], $ids($list('?sort=status&status=approved')));

        // search: reference, title, tenant, fault type, address
        $this->assertSame([$old->id], $ids($list('?q=FR-' . $old->id)));
        $this->assertSame([$mid->id], $ids($list('?q=window')));
        $this->assertEqualsCanonicalizing([$old->id, $mid->id, $new->id], $ids($list('?q=Tina')), 'tenant name');
        $this->assertSame([$new->id], $ids($list('?q=Burst')), 'fault type name');
        $this->assertSame([$elsewhere->id], $ids($list('?q=Hill+Street')), 'address');

        // filters
        $this->assertSame([$new->id], $ids($list('?urgency=emergency')));
        $this->assertSame([$old->id, $elsewhere->id], array_values(array_intersect($ids($list('?date_to=' . now()->subDays(8)->toDateString())), [$old->id, $elsewhere->id])));
        $this->assertSame([$new->id], $ids($list('?date_from=' . now()->subDays(2)->toDateString())));
        $this->asStaff($this->agent)->getJson(self::M . '/fault-reports?status=nonsense')->assertStatus(422);

        // the item names what the app needs
        $row = collect($list()->json('data'))->firstWhere('id', $new->id);
        $this->assertSame(['id', 'reference', 'title', 'status', 'status_label', 'urgency', 'is_emergency', 'fault_type', 'property', 'lease_id', 'tenant_names', 'reported_at', 'owner_approval_status', 'outcome', 'work_order_id', 'archived'], array_keys($row));
        $this->assertTrue($row['is_emergency']);
    }

    public function test_the_fault_list_pages_at_25_and_says_why_it_is_empty(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->fault(['title' => "Fault {$i}", 'reported_at' => now()->subMinutes($i)]);
        }
        $p1 = $this->asStaff($this->agent)->getJson(self::M . '/fault-reports')->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 27, 'last_page' => 2, 'empty_reason' => null], $p1->json('meta'));
        $this->assertCount(2, $this->asStaff($this->agent)->getJson(self::M . '/fault-reports?page=2')->json('data'));

        $none = $this->asStaff($this->agent)->getJson(self::M . '/fault-reports?q=zzzz-nothing')->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertNotEmpty($none->json('meta.empty_reason'));
        $this->assertSame(0, $none->json('meta.total'));
    }

    public function test_a_fault_list_only_holds_what_the_detail_would_open(): void
    {
        $mine = $this->fault(['created_by_user_id' => $this->junior->id, 'title' => 'Junior wrote this']);
        $theirs = $this->fault(['title' => 'Somebody else']);
        $foreign = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->other->id, 'property_id' => $this->property->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => 'Other agency fault', 'description' => 'x',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(), 'created_by_user_id' => $this->outsider->id,
        ]);

        $this->assertSame([$mine->id], array_column($this->asStaff($this->junior)->getJson(self::M . '/fault-reports')->json('data'), 'id'));
        $this->asStaff($this->junior)->getJson(self::M . "/fault-reports/{$mine->id}")->assertOk();
        $this->asStaff($this->junior)->getJson(self::M . "/fault-reports/{$theirs->id}")->assertForbidden();

        $this->assertSame([], array_values(array_intersect([$mine->id, $theirs->id], array_column($this->asStaff($this->outsider)->getJson(self::M . '/fault-reports')->json('data'), 'id'))));
        $this->asStaff($this->outsider)->getJson(self::M . "/fault-reports/{$theirs->id}")->assertNotFound();
        $this->asStaff($this->agent)->getJson(self::M . "/fault-reports/{$foreign->id}")->assertNotFound();
        $this->asStaff($this->viewer)->getJson(self::M . "/fault-reports/{$theirs->id}")->assertOk();
    }

    public function test_archived_faults_are_listed_only_for_someone_who_may_edit_and_the_detail_says_archived(): void
    {
        $gone = $this->fault(['title' => 'Archived one']);
        $live = $this->fault(['title' => 'Live one']);
        $gone->delete();

        $this->assertSame([$live->id], array_column($this->asStaff($this->agent)->getJson(self::M . '/fault-reports')->json('data'), 'id'));
        $arch = $this->asStaff($this->agent)->getJson(self::M . '/fault-reports?archived=1')->assertOk();
        $this->assertSame([$gone->id], array_column($arch->json('data'), 'id'));
        $this->asStaff($this->viewer)->getJson(self::M . '/fault-reports?archived=1')->assertForbidden();
        $this->asStaff($this->agent)->getJson(self::M . "/fault-reports/{$gone->id}")->assertOk()->assertJsonPath('archived', true);
    }

    public function test_the_fault_detail_carries_the_agents_progress_timeline(): void
    {
        $fault = $this->fault();
        $res = $this->asStaff($this->agent)->getJson(self::M . "/fault-reports/{$fault->id}")->assertOk();
        $this->assertNotEmpty($res->json('timeline'));
        $this->assertSame(['key', 'label', 'state', 'current', 'at'], array_keys($res->json('timeline.0')));
        $this->assertSame($this->faultType->name, $res->json('fault_type_detail.name'));
        $this->assertSame('Tina Test', $res->json('tenant_names'));
        $this->asStaff($this->agent)->getJson(self::M . '/fault-reports/999999')->assertNotFound();
        // another permission is needed to look at all
        $this->grantScope($this->agency, 'nolook', 'all', ['properties.view']);
        $nolook = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'nolook', 'is_active' => true]);
        $this->asStaff($nolook)->getJson(self::M . "/fault-reports/{$fault->id}")->assertForbidden();
        $this->asStaff($nolook)->getJson(self::M . '/fault-reports')->assertForbidden();
    }

    // ── work orders: create ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_work_order_is_logged_through_the_same_service_as_the_web_form(): void
    {
        $url = self::M . "/properties/{$this->property->id}/work-orders";
        $res = $this->asStaff($this->agent)->postJson($url, [
            'title' => 'Replace gate motor', 'description' => 'Motor burnt out', 'trade_type' => 'electrical', 'priority' => 'urgent', 'lease_id' => $this->lease->id,
            'captured_at' => now()->subHours(2)->toIso8601String(),
        ])->assertCreated();

        $res->assertJsonPath('status', 'reported')->assertJsonPath('priority', 'urgent')->assertJsonPath('reference', 'WO-' . $res->json('id'));
        $wo = RentalWorkOrder::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertSame($this->agent->id, (int) $wo->created_by_user_id);
        $this->assertSame('agent_noticed', $wo->reported_by_type);
        $this->assertTrue($wo->reported_at->lessThan(now()->subHour()));

        $this->asStaff($this->agent)->postJson($url, ['description' => 'x'])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->asStaff($this->agent)->postJson($url, ['title' => 'x', 'description' => 'x', 'priority' => 'whenever'])->assertStatus(422);
        $this->asStaff($this->viewer)->postJson($url, ['title' => 'x', 'description' => 'x'])->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, ['title' => 'x', 'description' => 'x'])->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, ['title' => 'x', 'description' => 'x'])->assertNotFound();
        $this->assertSame(1, RentalWorkOrder::withoutGlobalScopes()->count());
    }

    // ── work orders: photos ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_work_order_photo_is_stored_once_per_key_and_guarded(): void
    {
        $wo = $this->workOrder();
        $url = self::M . "/work-orders/{$wo->id}/photos";
        $key = (string) Str::uuid();
        $when = now()->subMinutes(30)->startOfSecond();

        $first = $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'in_progress', 'client_idempotency_key' => $key, 'captured_at' => $when->toIso8601String()])
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('photo_type', 'in_progress');
        $this->assertSame($when->timestamp, RentalWorkOrderPhoto::withoutGlobalScopes()->firstOrFail()->created_at->timestamp);
        $again = $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'in_progress', 'client_idempotency_key' => $key])->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('id'), $again->json('id'));
        $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->count());

        $other = $this->workOrder();
        $this->asStaff($this->agent)->postMultipart(self::M . "/work-orders/{$other->id}/photos", ['photo' => $this->jpg(), 'photo_type' => 'reported', 'client_idempotency_key' => $key])->assertStatus(409);

        $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg()])->assertStatus(422)->assertJsonValidationErrors('photo_type');
        $this->asStaff($this->viewer)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'reported'])->assertForbidden();
        $this->asStaff($this->outsider)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'reported'])->assertNotFound();
        $this->asStaff($this->junior)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'reported'])->assertForbidden();

        $wo->delete();
        $this->asStaff($this->agent)->postMultipart($url, ['photo' => $this->jpg(), 'photo_type' => 'reported', 'client_idempotency_key' => (string) Str::uuid()])->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->count());
    }

    // ── work orders: appoint a contractor ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_contractor_is_appointed_through_the_shared_service(): void
    {
        $wo = $this->outsideWorkOrder();
        $acme = $this->provider();
        $theirs = $this->provider('Theirs', $this->other);
        $url = self::M . "/work-orders/{$wo->id}/assign-supplier";

        $this->asStaff($this->agent)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('agency_service_provider_id');
        $this->asStaff($this->agent)->postJson($url, ['agency_service_provider_id' => $theirs->id])->assertStatus(422);   // another agency's contractor
        $this->asStaff($this->viewer)->postJson($url, ['agency_service_provider_id' => $acme->id])->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, ['agency_service_provider_id' => $acme->id])->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, ['agency_service_provider_id' => $acme->id])->assertForbidden();

        $res = $this->asStaff($this->agent)->postJson($url, ['agency_service_provider_id' => $acme->id, 'trade_type' => 'plumbing'])->assertOk();
        $res->assertJsonPath('status', 'ordered')->assertJsonPath('supplier.name', 'Acme Plumbing')->assertJsonPath('trade_type', 'plumbing');
        $this->assertArrayHasKey('contractor_emailed', $res->json());

        // a closed job refuses, in plain words
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_CANCELLED])->save();
        $this->asStaff($this->agent)->postJson($url, ['agency_service_provider_id' => $acme->id])->assertStatus(422)->assertJsonPath('message', 'This work order is already closed.');
        $wo->delete();
        $this->asStaff($this->agent)->postJson($url, ['agency_service_provider_id' => $acme->id])->assertStatus(410)->assertJsonPath('code', 'archived');
    }

    // ── work orders: the contractor's answer ────────────────────────────────────────────────────────────────────────────────

    public function test_the_agent_records_the_contractors_answer_with_the_same_refusals_as_the_contractors_own_link(): void
    {
        $wo = $this->outsideWorkOrder();
        $url = self::M . "/work-orders/{$wo->id}/contractor-response";

        // nobody appointed yet
        $this->asStaff($this->agent)->postJson($url, ['response' => 'accepted'])->assertStatus(422);

        $acme = $this->provider();
        $this->asStaff($this->agent)->postJson(self::M . "/work-orders/{$wo->id}/assign-supplier", ['agency_service_provider_id' => $acme->id])->assertOk();

        $this->asStaff($this->agent)->postJson($url, ['response' => 'maybe'])->assertStatus(422);
        $this->asStaff($this->agent)->postJson($url, ['response' => 'declined'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($this->viewer)->postJson($url, ['response' => 'accepted'])->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, ['response' => 'accepted'])->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, ['response' => 'accepted'])->assertForbidden();

        $res = $this->asStaff($this->agent)->postJson($url, ['response' => 'accepted'])->assertOk();
        $res->assertJsonPath('contractor_response', 'accepted');
        $this->assertSame('accepted', $wo->fresh()->contractor_response);
        $this->assertTrue($wo->fresh()->updates()->where('note', 'like', "%recorded by {$this->agent->name}%")->exists(), 'history says the agent recorded it');

        $this->asStaff($this->agent)->postJson($url, ['response' => 'declined', 'reason' => 'Fully booked'])->assertOk()
            ->assertJsonPath('contractor_response', 'declined')->assertJsonPath('contractor_decline_reason', 'Fully booked');

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED])->save();
        $this->asStaff($this->agent)->postJson($url, ['response' => 'accepted'])->assertStatus(422)->assertJsonPath('message', 'This job is already closed.');
        $wo->delete();
        $this->asStaff($this->agent)->postJson($url, ['response' => 'accepted'])->assertStatus(410);
    }

    // ── work orders: invoices ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_supplier_invoice_is_filed_through_the_invoice_service(): void
    {
        $wo = $this->outsideWorkOrder();
        $url = self::M . "/work-orders/{$wo->id}/invoices";
        $body = fn (array $o = []) => array_merge(['invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'amount' => '1250.50', 'supplier_name' => 'Acme', 'document' => UploadedFile::fake()->create('inv.pdf', 30, 'application/pdf')], $o);

        $this->asStaff($this->agent)->postMultipart($url, $body(['invoice_number' => '']))->assertStatus(422)->assertJsonValidationErrors('invoice_number');
        $this->asStaff($this->agent)->postMultipart($url, array_diff_key($body(), ['document' => 1]))->assertStatus(422)->assertJsonValidationErrors('document');
        $this->asStaff($this->viewer)->postMultipart($url, $body())->assertForbidden();
        $this->asStaff($this->outsider)->postMultipart($url, $body())->assertNotFound();
        $this->asStaff($this->junior)->postMultipart($url, $body())->assertForbidden();

        $res = $this->asStaff($this->agent)->postMultipart($url, $body())->assertCreated();
        $res->assertJsonPath('invoice_number', 'INV-1')->assertJsonPath('amount', '1250.50');   // this agent may see money
        $this->assertSame(1, RentalWorkOrderInvoice::withoutGlobalScopes()->count());
        // a repeat of the same invoice number is refused by the service, not stored twice
        $this->asStaff($this->agent)->postMultipart($url, $body())->assertStatus(422);
        $this->assertSame(1, RentalWorkOrderInvoice::withoutGlobalScopes()->count());

        $wo->delete();
        $this->asStaff($this->agent)->postMultipart($url, $body(['invoice_number' => 'INV-2']))->assertStatus(410);
    }

    public function test_money_is_hidden_without_the_cost_permission(): void
    {
        $this->grantScope($this->agency, 'nocosts', 'all', array_merge(self::ALL_KEYS, ['rental_job_cards.view']));
        $nocosts = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'nocosts', 'is_active' => true]);
        $wo = $this->outsideWorkOrder(['cost_amount' => 1500, 'approved_amount' => 1200]);

        $with = $this->asStaff($this->agent)->getJson(self::M . "/work-orders/{$wo->id}")->assertOk();
        $this->assertArrayHasKey('cost_amount', $with->json());
        $without = $this->asStaff($nocosts)->getJson(self::M . "/work-orders/{$wo->id}")->assertOk();
        $this->assertArrayNotHasKey('cost_amount', $without->json());
        $this->assertArrayNotHasKey('approved_amount', $without->json());

        $created = $this->asStaff($nocosts)->postMultipart(self::M . "/work-orders/{$wo->id}/invoices", [
            'invoice_number' => 'INV-9', 'invoice_date' => now()->toDateString(), 'amount' => '10', 'document' => UploadedFile::fake()->create('i.pdf', 10, 'application/pdf'),
        ])->assertCreated();
        $this->assertArrayNotHasKey('amount', $created->json());
    }

    // ── work orders: complete ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_work_order_is_completed_through_the_shared_service(): void
    {
        $wo = $this->workOrder();
        $url = self::M . "/work-orders/{$wo->id}/complete";

        $this->asStaff($this->agent)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('paid_by');
        $this->asStaff($this->viewer)->postJson($url, ['paid_by' => 'owner'])->assertForbidden();
        $this->asStaff($this->outsider)->postJson($url, ['paid_by' => 'owner'])->assertNotFound();
        $this->asStaff($this->junior)->postJson($url, ['paid_by' => 'owner'])->assertForbidden();

        // the app still holds unsent items -> the close is refused so nothing queued is stranded
        $this->asStaff($this->agent)->postJson($url, ['paid_by' => 'owner', 'pending_client_items' => 2])->assertStatus(409)->assertJsonPath('code', 'items_pending');
        $this->assertSame('reported', $wo->fresh()->status);

        $this->asStaff($this->agent)->postJson($url, ['paid_by' => 'owner', 'completion_notes' => 'Done', 'pending_client_items' => 0])->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('paid_by', 'owner');
        $this->assertNotNull($wo->fresh()->completed_at);
        // closed means closed
        $this->asStaff($this->agent)->postJson($url, ['paid_by' => 'owner'])->assertStatus(422)->assertJsonPath('message', 'This work order is already closed.');

        $gone = $this->workOrder();
        $gone->delete();
        $this->asStaff($this->agent)->postJson(self::M . "/work-orders/{$gone->id}/complete", ['paid_by' => 'owner'])->assertStatus(410)->assertJsonPath('code', 'archived');
    }

    public function test_an_outside_contractors_job_cannot_be_completed_without_a_final_cost(): void
    {
        $wo = $this->outsideWorkOrder();
        $this->asStaff($this->agent)->postJson(self::M . "/work-orders/{$wo->id}/complete", ['paid_by' => 'owner'])->assertStatus(422)->assertJsonValidationErrors('cost_amount');
    }

    // ── work orders: list + detail ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_work_order_list_searches_sorts_filters_and_pages(): void
    {
        $acme = $this->provider('Acme Plumbing');
        $a = $this->workOrder(['title' => 'Gate motor', 'reported_at' => now()->subDays(9), 'appointment_at' => now()->addDays(5), 'agency_service_provider_id' => $acme->id, 'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER]);
        $b = $this->workOrder(['title' => 'Window latch', 'reported_at' => now()->subDays(4), 'appointment_at' => now()->addDays(1), 'status' => RentalWorkOrder::STATUS_IN_PROGRESS]);
        $c = $this->workOrder(['title' => 'Roof leak', 'reported_at' => now()->subDay(), 'contractor_name' => 'Roofer Rob']);

        $ids = fn ($res) => array_column($res->json('data'), 'id');
        $list = fn (string $qs = '') => $this->asStaff($this->agent)->getJson(self::M . '/work-orders' . $qs)->assertOk();

        $this->assertSame([$c->id, $b->id, $a->id], $ids($list()), 'newest first by default');
        $this->assertSame([$b->id, $a->id, $c->id], $ids($list('?sort=due')), 'soonest appointment first, unbooked last');
        $this->assertSame([$b->id], $ids($list('?status=in_progress')));
        $this->assertSame([$c->id], array_slice($ids($list('?sort=status&status=reported&q=Roof')), 0, 1));

        $this->assertSame([$a->id], $ids($list('?q=WO-' . $a->id)), 'reference');
        $this->assertSame([$b->id], $ids($list('?q=latch')), 'title');
        $this->assertSame([$a->id], $ids($list('?q=Acme')), 'contractor');
        $this->assertSame([$c->id], $ids($list('?q=Roofer')), 'owner contractor name');
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $ids($list('?q=Tina')), 'tenant');
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], $ids($list('?q=Sea+Road')), 'address');
        $this->assertSame([$c->id, $b->id], $ids($list('?date_from=' . now()->subDays(5)->toDateString())));
        $this->assertSame([$a->id], $ids($list('?date_to=' . now()->subDays(8)->toDateString())));
        $this->asStaff($this->agent)->getJson(self::M . '/work-orders?sort=sideways')->assertStatus(422);

        $none = $list('?q=zzzz');
        $this->assertSame([], $none->json('data'));
        $this->assertNotEmpty($none->json('meta.empty_reason'));
    }

    public function test_the_work_order_list_pages_at_25(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->workOrder(['title' => "Job {$i}", 'reported_at' => now()->subMinutes($i)]);
        }
        $p1 = $this->asStaff($this->agent)->getJson(self::M . '/work-orders')->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 26, 'last_page' => 2, 'empty_reason' => null], $p1->json('meta'));
        $this->assertCount(1, $this->asStaff($this->agent)->getJson(self::M . '/work-orders?page=2')->json('data'));
    }

    public function test_a_work_order_list_only_holds_what_the_detail_would_open_and_archived_is_for_editors(): void
    {
        $mine = $this->workOrder(['created_by_user_id' => $this->junior->id]);
        $theirs = $this->workOrder();
        $gone = $this->workOrder(['title' => 'Archived job']);
        $gone->delete();

        $this->assertSame([$mine->id], array_column($this->asStaff($this->junior)->getJson(self::M . '/work-orders')->json('data'), 'id'));
        $this->asStaff($this->junior)->getJson(self::M . "/work-orders/{$mine->id}")->assertOk();
        $this->asStaff($this->junior)->getJson(self::M . "/work-orders/{$theirs->id}")->assertForbidden();
        $this->asStaff($this->outsider)->getJson(self::M . "/work-orders/{$theirs->id}")->assertNotFound();
        $this->assertSame([], array_column($this->asStaff($this->outsider)->getJson(self::M . '/work-orders')->json('data'), 'id'));

        $this->assertSame([$gone->id], array_column($this->asStaff($this->agent)->getJson(self::M . '/work-orders?archived=1')->json('data'), 'id'));
        $this->asStaff($this->viewer)->getJson(self::M . '/work-orders?archived=1')->assertForbidden();
        $this->asStaff($this->agent)->getJson(self::M . "/work-orders/{$gone->id}")->assertOk()->assertJsonPath('archived', true);
    }

    public function test_every_p2_route_is_named_and_listed(): void
    {
        $names = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map->getName()->filter()->all();
        foreach ([
            'rental-fault-types', 'fault-reports.index', 'fault-reports.show', 'properties.fault-reports.store', 'fault-reports.photos.store', 'fault-reports.approval.store', 'fault-reports.outcome.store',
            'work-orders.index', 'work-orders.show', 'properties.work-orders.store', 'work-orders.photos.store', 'work-orders.assign-supplier', 'work-orders.contractor-response', 'work-orders.invoices.store', 'work-orders.complete',
        ] as $suffix) {
            $this->assertContains('v1.mobile.' . $suffix, $names);
        }
    }
}
