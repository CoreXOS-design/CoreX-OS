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
use App\Models\RentalInspectionAttendance;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionSignature;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.8 — Build I-6b: the append-only History, the split of the blanket
 * `.create` permission into per-action keys (with the one deliberate loss: archiving a COMPLETED, signed
 * inspection), and the four list-screen fixes. NOT covered, by design: the tenant/landlord portal region
 * (waits on Johan's Q13).
 */
final class RentalInspectionI6bTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;      // owner-role: sees and may do everything
    private User $agent;      // ordinary agent, branch 1
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        config(['mail.non_production_redirect' => 'test-redirect@example.test']);
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'I6b Agency', 'slug' => 'i6b-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Admin Alba']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Agent Aileen']);
        $this->actingAs($this->admin);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '8 Reef Road, Shelly Beach', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_IN, array $extra = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type, 'created_by_user_id' => $this->agent->id,
        ], $extra));
    }

    /**
     * A user who genuinely belongs to ANOTHER agency. BelongsToAgency forces any record created while an ordinary user
     * is logged in into THAT user's agency, so this logs out first and puts the acting user back afterwards.
     */
    private function userOfAnotherAgency(string $role, string $name): User
    {
        $acting = auth()->user();
        \Illuminate\Support\Facades\Auth::logout();
        $agency = Agency::create(['name' => 'Other ' . $name, 'slug' => 'other-' . uniqid()]);
        $user = User::factory()->create(['agency_id' => $agency->id, 'role' => $role, 'name' => $name]);
        $this->assertSame($agency->id, $user->fresh()->agency_id, 'the user really is in another agency');
        if ($acting) {
            $this->actingAs($acting);
        }

        return $user;
    }

    private function events(RentalInspection $inspection): array
    {
        return RentalInspectionAuditLog::where('rental_inspection_id', $inspection->id)->orderBy('id')->pluck('event')->all();
    }

    private function seedGrants(array $keys): void
    {
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        foreach ($keys as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    // ═══ History ═══════════════════════════════════════════════════════════════

    public function test_an_inspection_records_its_own_creation(): void
    {
        $inspection = $this->inspection();

        $row = RentalInspectionAuditLog::firstOrFail();
        $this->assertSame('created', $row->event);
        $this->assertSame($inspection->id, $row->rental_inspection_id);
        $this->assertSame($this->agency->id, $row->agency_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertStringContainsString('In-inspection created', $row->summary);
    }

    public function test_status_changes_cancellation_archive_and_restore_are_recorded_with_who(): void
    {
        $adHoc = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $adHoc->markCompleted();
        $status = RentalInspectionAuditLog::where('event', 'status_changed')->firstOrFail();
        $this->assertSame(['status' => 'draft'], $status->before);
        $this->assertSame(['status' => 'completed'], $status->after);
        $this->assertSame('Status changed from draft to completed.', $status->summary);

        $other = $this->inspection();
        $other->cancel($this->agent, 'Tenant moved out early.');
        $cancelled = RentalInspectionAuditLog::where('event', 'cancelled')->firstOrFail();
        $this->assertSame('Cancelled: Tenant moved out early.', $cancelled->summary);
        $this->assertSame($this->admin->id, $cancelled->user_id, 'the acting user, not the argument');

        // Archive through the real route, then restore: the history keeps who archived it, though a restore clears that column.
        $third = $this->inspection();
        $this->actingAs($this->agent)->delete(route('corex.rental-inspections.destroy', $third))->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.rental-inspections.restore', $third->id))->assertRedirect();

        $this->assertSame(['created', 'archived', 'restored'], $this->events($third));
        $this->assertNull($third->fresh()->archived_by_user_id);
        $restored = RentalInspectionAuditLog::where('event', 'restored')->firstOrFail();
        $this->assertStringContainsString('archived by Agent Aileen', $restored->summary);
        $this->assertSame($this->agent->id, RentalInspectionAuditLog::where('event', 'archived')->firstOrFail()->user_id);
    }

    public function test_header_edits_record_only_the_fields_that_changed_and_a_no_op_saves_nothing(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_IN, ['keys_count' => 2, 'electricity_meter_reading' => '1000']);

        $inspection->updateDetails(['keys_count' => 3, 'electricity_meter_reading' => '1000', 'keys_description' => 'Front and back']);
        $inspection->updateDetails(['keys_count' => 3, 'electricity_meter_reading' => '1000']);   // nothing changed

        $rows = RentalInspectionAuditLog::where('event', 'details_edited')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['keys_count' => 2, 'keys_description' => null], $rows->first()->before);
        $this->assertSame(['keys_count' => 3, 'keys_description' => 'Front and back'], $rows->first()->after);
        $this->assertSame('Details edited: keys count, keys description.', $rows->first()->summary);
    }

    public function test_reschedule_and_the_public_link_lifecycle_are_recorded_without_leaking_the_token(): void
    {
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->admin, ['scheduled_for' => now()->addDays(5)->toDateString()]);
        $inspection->reschedule(['scheduled_for' => now()->addDays(9)->toDateString()], $this->admin, 'Tenant asked.');

        $token = $inspection->generatePublicLink();
        $inspection->revokePublicLink();
        $inspection->revokePublicLink();   // nothing to revoke: no second row

        $this->assertSame(['created', 'rescheduled', 'public_link_issued', 'public_link_revoked'], $this->events($inspection));
        $this->assertSame('Rescheduled: Tenant asked.', RentalInspectionAuditLog::where('event', 'rescheduled')->firstOrFail()->summary);
        $this->assertStringNotContainsString($token, json_encode(RentalInspectionAuditLog::all()->toArray()), 'the token is the credential and never goes in the history');
    }

    public function test_attendance_signature_and_finding_corrections_and_the_automatic_copies_are_recorded(): void
    {
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => 'thabo@example.test']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $inspection = $this->inspection(RentalInspection::TYPE_OUT, ['inspector_user_id' => $this->agent->id]);
        $attend = fn (string $outcome) => $this->postJson(route('corex.rental-inspections.attendance.store', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $tenant->id, 'outcome' => $outcome])->assertStatus(201);

        $attend('did_not_attend');
        $attend('attended');                 // a correction
        $this->postJson(route('corex.rental-inspections.attendance.invitations.store', $inspection), ['party_role' => 'tenant', 'party_contact_id' => $tenant->id, 'method' => 'Phone call'])->assertStatus(201);
        $live = RentalInspectionAttendance::live()->firstOrFail();
        $this->postJson(route('corex.rental-inspections.attendance.withdraw', [$inspection, $live]))->assertOk();

        $this->assertSame(
            ['created', 'attendance_recorded', 'attendance_corrected', 'invitation_recorded', 'attendance_withdrawn'],
            $this->events($inspection)
        );
        $this->assertSame('Thabo Tenant: attended — corrected.', RentalInspectionAuditLog::where('event', 'attendance_corrected')->firstOrFail()->summary);

        // A paper signature replaced.
        $awaiting = RentalInspectionSignature::capture($inspection, 'tenant', 'awaiting_wet_ink', ['party_contact_id' => $tenant->id, 'recorded_by_user_id' => $this->admin->id]);
        RentalInspectionSignature::supersedeWetInk($awaiting, $inspection, UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"), $this->admin->id);
        $this->assertTrue(in_array('signature_superseded', $this->events($inspection), true));

        // A finding revised.
        $room = \App\Models\PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 0, 'created_by_user_id' => $this->admin->id]);
        $item = RentalInspectionItem::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id, 'kind' => 'space', 'label' => 'Walls', 'created_by_user_id' => $this->admin->id]);
        RentalInspectionItemFinding::record($inspection, $item, 'wear_and_tear', 'Normal fading.', $this->admin);
        RentalInspectionItemFinding::record($inspection, $item, 'flagged', 'Actually a stain.', $this->admin);
        $this->assertSame(1, RentalInspectionAuditLog::where('event', 'finding_superseded')->count(), 'the first finding supersedes nothing; the second replaces it');
        $this->assertStringContainsString('"Walls" changed from wear and tear to flagged', RentalInspectionAuditLog::where('event', 'finding_superseded')->firstOrFail()->summary);
    }

    public function test_the_automatic_report_mailing_is_recorded_as_a_settings_driven_action(): void
    {
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Zanele', 'last_name' => 'Tenant', 'email' => 'zanele@example.test']);
        $noEmail = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'No', 'last_name' => 'Email']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $noEmail->id, 'is_primary' => false]);
        $inspection = $this->inspection(RentalInspection::TYPE_AD_HOC, ['inspector_user_id' => $this->agent->id]);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();

        $row = RentalInspectionAuditLog::where('event', 'report_copies_sent')->firstOrFail();
        $this->assertSame(1, $row->after['skipped']);
        $this->assertGreaterThanOrEqual(2, $row->after['sent']);
        $this->assertSame(0, $row->after['failed']);
        $this->assertStringStartsWith('Report copies sent automatically on completion', $row->summary);
    }

    public function test_the_history_is_append_only_and_the_system_is_recorded_as_no_user(): void
    {
        $inspection = $this->inspection();
        $row = RentalInspectionAuditLog::firstOrFail();

        try {
            $row->update(['summary' => 'tampered']);
            $this->fail('an update must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        try {
            $row->delete();
            $this->fail('a delete must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertSame('In-inspection created.', RentalInspectionAuditLog::firstOrFail()->summary, 'the stored row is untouched');

        // No logged-in user (a scheduled job): recorded as the system.
        \Illuminate\Support\Facades\Auth::logout();
        $inspection->generatePublicLink();
        $this->assertNull(RentalInspectionAuditLog::where('event', 'public_link_issued')->firstOrFail()->user_id);
    }

    public function test_a_history_row_that_cannot_be_written_never_breaks_the_action_and_is_logged_loudly(): void
    {
        $inspection = $this->inspection();
        Log::spy();
        RentalInspectionAuditLog::creating(fn () => throw new \RuntimeException('audit disk full'));

        $inspection->cancel($this->agent, 'Reason.');

        $this->assertSame(RentalInspection::STATUS_CANCELLED, $inspection->fresh()->status);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains((string) $m, 'history row could not be written'))->atLeast()->once();
    }

    public function test_the_history_panel_lists_newest_first_filters_by_event_and_shows_edits(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_IN, ['keys_count' => 1]);
        $inspection->updateDetails(['keys_count' => 4]);
        $inspection->generatePublicLink();

        $html = $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="inspection-history"', $html);
        $this->assertStringContainsString('All events (3)', $html);
        $this->assertStringContainsString('keys count: 1 → 4', $html);
        $this->assertLessThan(strpos($html, 'In-inspection created'), strpos($html, 'Public link issued'), 'newest first');
        $this->assertStringContainsString('Admin Alba', $html);

        $filtered = $this->get(route('corex.rental-inspections.show', $inspection) . '?history_event=details_edited')->assertOk()->getContent();
        $this->assertStringContainsString('keys count: 1 → 4', $filtered);
        $this->assertStringNotContainsString('Public link issued, live', $filtered);

        $empty = $this->get(route('corex.rental-inspections.show', $inspection) . '?history_event=cancelled')->assertOk()->getContent();
        $this->assertStringContainsString('No history of that kind on this inspection.', $empty);
    }

    public function test_another_agencys_user_cannot_see_the_history(): void
    {
        $inspection = $this->inspection();
        $outsider = $this->userOfAnotherAgency('agent', 'Outsider');

        // An admin-role user of the OTHER agency too (the global "admin" role is a platform owner by design, so use a branch manager).
        $outsiderManager = $this->userOfAnotherAgency('branch_manager', 'Outsider Manager');
        $this->actingAs($outsiderManager)->get(route('corex.rental-inspections.show', $inspection))->assertNotFound();
        $this->actingAs($outsider)->get(route('corex.rental-inspections.show', $inspection))->assertNotFound();
        $this->assertSame(0, RentalInspectionAuditLog::where('rental_inspection_id', $inspection->id)->where('user_id', $outsider->id)->count());
    }

    // ═══ The permission split ══════════════════════════════════════════════════

    public function test_each_action_needs_its_own_permission_and_create_alone_no_longer_opens_them(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'access_properties' => null]);
        $scheduled = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->admin, ['scheduled_for' => now()->addDays(4)->toDateString()]);
        $archived = $this->inspection();
        $archived->delete();
        $this->actingAs($this->agent);

        $this->postJson(route('corex.rental-inspections.details.update', $scheduled), ['keys_count' => 2])->assertStatus(403);
        $this->post(route('corex.rental-inspections.cancel', $scheduled), ['cancel_reason' => 'x'])->assertStatus(403);
        $this->post(route('corex.rental-inspections.reschedule', $scheduled), ['scheduled_for' => now()->addDays(8)->toDateString()])->assertStatus(403);
        $this->delete(route('corex.rental-inspections.destroy', $scheduled))->assertStatus(403);
        $this->post(route('corex.rental-inspections.restore', $archived->id))->assertStatus(403);
        $this->post(route('corex.rental-inspections.public-link.generate', $scheduled))->assertStatus(403);
        $this->delete(route('corex.rental-inspections.public-link.revoke', $scheduled))->assertStatus(403);
        $this->get(route('corex.rental-inspections.export'))->assertStatus(403);
        $this->get(route('corex.rental-inspections.print-list'))->assertStatus(403);

        $this->assertSame(RentalInspection::STATUS_DRAFT, $scheduled->fresh()->status);
        $this->assertNull($scheduled->fresh()->deleted_at);
        $this->assertTrue($archived->fresh() === null || $archived->trashed());
    }

    public function test_holding_just_the_one_key_opens_just_that_action(): void
    {
        $this->seedGrants([
            'rental_inspections.view' => 'all', 'access_properties' => null,
            'rental_inspections.cancel' => null, 'rental_inspections.export' => null, 'rental_inspections.public_link' => null,
        ]);
        $inspection = $this->inspection();
        $this->actingAs($this->agent);

        $this->post(route('corex.rental-inspections.cancel', $inspection), ['cancel_reason' => 'Wrong property.'])->assertRedirect();
        $this->assertSame(RentalInspection::STATUS_CANCELLED, $inspection->fresh()->status);
        $this->get(route('corex.rental-inspections.export'))->assertOk();
        $this->get(route('corex.rental-inspections.print-list'))->assertOk();
        $this->delete(route('corex.rental-inspections.destroy', $inspection))->assertStatus(403);
        $this->post(route('corex.rental-inspections.reschedule', $inspection), ['scheduled_for' => now()->addDays(8)->toDateString()])->assertStatus(403);
    }

    public function test_archiving_a_completed_or_signed_inspection_needs_archive_completed_but_a_draft_does_not(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.archive' => null, 'access_properties' => null]);
        $draft = $this->inspection();
        $completed = $this->inspection(RentalInspection::TYPE_AD_HOC);
        $completed->markCompleted();
        $signed = $this->inspection();
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'T', 'last_name' => 'Signer']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        RentalInspectionSignature::capture($signed, 'tenant', 'signed', ['party_contact_id' => $tenant->id, 'party_signature_path' => 'private:s.png']);
        $this->actingAs($this->agent);

        $this->delete(route('corex.rental-inspections.destroy', $draft))->assertRedirect(route('corex.rental-inspections.index'));
        $this->assertTrue($draft->fresh()->trashed());

        foreach ([$completed, $signed] as $evidence) {
            $this->delete(route('corex.rental-inspections.destroy', $evidence))
                ->assertRedirect(route('corex.rental-inspections.show', $evidence))
                ->assertSessionHasErrors(['rental_inspection' => 'A completed or signed inspection is a record — only a manager can archive it.']);
            $this->assertFalse($evidence->fresh()->trashed());
            $this->assertNull($evidence->fresh()->archived_by_user_id);
        }

        // With the managerial key the same archive goes through.
        $this->seedGrants(['rental_inspections.archive_completed' => null]);
        $this->delete(route('corex.rental-inspections.destroy', $completed))->assertRedirect(route('corex.rental-inspections.index'));
        $this->assertTrue($completed->fresh()->trashed());
    }

    public function test_the_buttons_follow_the_permissions_on_the_page(): void
    {
        $this->seedGrants(['rental_inspections.view' => 'all', 'rental_inspections.cancel' => null, 'access_properties' => null]);
        $scheduled = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->admin, ['scheduled_for' => now()->addDays(4)->toDateString()]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $scheduled))->assertOk()->getContent();

        $this->assertStringContainsString('Cancel inspection', $html);
        $this->assertStringNotContainsString('>Reschedule<', $html);
        $this->assertStringNotContainsString('>Archive<', $html);
        $this->assertStringNotContainsString('<h2 class="text-sm font-semibold">Public link</h2>', $html);

        $list = $this->get(route('corex.rental-inspections.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Export (.xlsx)', $list);
        $this->assertStringNotContainsString('Print list', $list);
    }

    public function test_the_grant_migration_keeps_every_existing_action_and_withholds_only_archiving_completed(): void
    {
        $mk = fn (string $role, string $key, ?string $scope = null) => RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => $scope]);
        $mk('agent', 'rental_inspections.view', 'own');
        $mk('agent', 'rental_inspections.create');
        $mk('branch_manager', 'rental_inspections.view', 'branch');
        $mk('branch_manager', 'rental_inspections.create');
        $mk('branch_manager', 'rental_inspections.resolve_discrepancy');
        $mk('viewer', 'rental_inspections.view', 'all');

        $migration = require base_path('database/migrations/2026_10_13_100410_grant_split_rental_inspection_permissions.php');
        $migration->up();
        $migration->up();   // idempotent

        $has = fn (string $role, string $key) => RolePermission::where('agency_id', $this->agency->id)->where('role', $role)->where('permission_key', $key)->count();
        foreach (['edit_details', 'archive', 'restore', 'cancel', 'reschedule', 'public_link'] as $action) {
            $this->assertSame(1, $has('agent', 'rental_inspections.' . $action), "agent keeps {$action}");
            $this->assertSame(1, $has('branch_manager', 'rental_inspections.' . $action), "branch manager keeps {$action}");
            $this->assertSame(0, $has('viewer', 'rental_inspections.' . $action), 'a view-only role never had the write actions');
        }
        $this->assertSame(1, $has('viewer', 'rental_inspections.export'), 'listing/printing was never behind .create');
        $this->assertSame(1, $has('agent', 'rental_inspections.export'));
        $this->assertSame(1, $has('branch_manager', 'rental_inspections.archive_completed'));
        $this->assertSame(0, $has('agent', 'rental_inspections.archive_completed'), 'the one deliberate loss');
        $this->assertSame('own', RolePermission::where('role', 'agent')->where('permission_key', 'rental_inspections.export')->value('scope'), 'scope copied from the source row');
    }

    public function test_the_eight_keys_are_defined_and_defaulted_to_the_right_roles(): void
    {
        $source = (string) file_get_contents(base_path('config/corex-permissions.php'));

        foreach (['edit_details', 'archive', 'archive_completed', 'restore', 'cancel', 'reschedule', 'public_link', 'export'] as $key) {
            $this->assertStringContainsString("['key' => 'rental_inspections.{$key}'", $source, "{$key} is defined");
        }
        // The two role default lists each carry the six write keys; only the managerial one also carries archive_completed.
        $defaultLines = collect(explode("\n", $source))->filter(fn ($l) => str_contains($l, "'rental_inspections.edit_details', 'rental_inspections.archive'"));
        $this->assertCount(2, $defaultLines);
        $this->assertSame(1, $defaultLines->filter(fn ($l) => str_contains($l, "'rental_inspections.archive_completed'"))->count());
        foreach (['restore', 'cancel', 'reschedule', 'public_link', 'export'] as $key) {
            $this->assertSame(2, $defaultLines->filter(fn ($l) => str_contains($l, "'rental_inspections.{$key}'"))->count(), "{$key} is in both default lists");
        }
    }

    // ═══ List fixes ════════════════════════════════════════════════════════════

    private function ids(string $query = ''): array
    {
        return collect($this->get(route('corex.rental-inspections.index') . $query)->assertOk()->viewData('inspections')->items())->pluck('id')->all();
    }

    public function test_the_lease_hub_link_narrows_the_list_to_that_lease_with_a_visible_chip(): void
    {
        $otherLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 5000, 'start_date' => now()->subMonth(), 'created_by_user_id' => $this->agent->id,
        ]);
        $mine = $this->inspection();
        $theirs = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $otherLease->id, 'type' => 'in', 'created_by_user_id' => $this->agent->id]);

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $this->ids());
        $this->assertSame([$mine->id], $this->ids('?lease_id=' . $this->lease->id));
        $this->assertSame([$theirs->id], $this->ids('?lease_id=' . $otherLease->id));

        $html = $this->get(route('corex.rental-inspections.index', ['lease_id' => $this->lease->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="lease-filter-chip"', $html);
        $this->assertStringContainsString('8 Reef Road', $html);
        $this->assertStringContainsString('Show all', $html);

        // Junk and unknown ids are absorbed: ignored / matching nothing, never an error.
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $this->ids('?lease_id=abc'));
        $this->assertSame([], $this->ids('?lease_id=99999999'));
    }

    public function test_a_lease_filter_can_never_show_another_agencys_or_out_of_scope_inspections(): void
    {
        $inspection = $this->inspection();   // created by the agent, branch 1
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->actingAs($colleague);
        $this->assertSame([], $this->ids('?lease_id=' . $this->lease->id), 'own scope: the colleague\'s lease filter still shows only their own');
        $this->assertStringContainsString('a lease you cannot see', $this->get(route('corex.rental-inspections.index', ['lease_id' => $this->lease->id]))->getContent());
        $this->assertNotNull($inspection->id);
    }

    public function test_a_start_now_inspection_is_placed_by_its_creation_date_in_filters_and_sorting(): void
    {
        $future = $this->inspection(RentalInspection::TYPE_IN, ['scheduled_for' => now()->addDays(10)->toDateString()]);
        $startNow = $this->inspection(RentalInspection::TYPE_OUT);                                // never scheduled
        $past = $this->inspection(RentalInspection::TYPE_AD_HOC, ['scheduled_for' => now()->subDays(30)->toDateString()]);

        $this->assertSame([$future->id, $startNow->id, $past->id], $this->ids('?sort=scheduled_for&direction=desc'));
        $this->assertSame([$past->id, $startNow->id, $future->id], $this->ids('?sort=scheduled_for&direction=asc'));

        $today = now()->toDateString();
        $this->assertSame([$startNow->id], $this->ids("?date_from={$today}&date_to={$today}"), 'found by the day it was started');
        $this->assertSame([], $this->ids('?date_from=' . now()->subDays(20)->toDateString() . '&date_to=' . now()->subDays(10)->toDateString()));
        $this->assertEqualsCanonicalizing([$startNow->id, $future->id], $this->ids("?date_from={$today}"));

        $this->assertStringContainsString(now()->format('Y-m-d') . ' (started)', $this->get(route('corex.rental-inspections.index'))->getContent());
    }

    public function test_a_print_or_export_beyond_the_row_cap_is_refused_with_a_clear_message_and_nothing_is_streamed(): void
    {
        config(['rental-inspections.export_row_cap' => 2]);
        $this->inspection();
        $this->inspection(RentalInspection::TYPE_OUT);
        $this->get(route('corex.rental-inspections.export', ['format' => 'csv']))->assertOk();   // at the cap: fine
        $this->inspection(RentalInspection::TYPE_AD_HOC);

        foreach (['export' => ['format' => 'csv'], 'print-list' => []] as $route => $params) {
            $response = $this->get(route('corex.rental-inspections.' . $route, $params));
            $response->assertRedirect()->assertSessionHasErrors('export');
            $this->assertStringContainsString('That list has 3 inspections — more than the 2 one print or export can carry', session('errors')->first('export'));
        }

        $page = $this->followingRedirects()->get(route('corex.rental-inspections.export'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="export-refused"', $page);

        // Narrowing the list brings it back under the cap.
        $this->get(route('corex.rental-inspections.export', ['format' => 'csv', 'type' => 'ad_hoc']))->assertOk();
    }

    public function test_exports_and_prints_keep_the_screen_order_for_unscheduled_inspections(): void
    {
        $this->inspection(RentalInspection::TYPE_IN, ['scheduled_for' => now()->subDays(40)->toDateString()]);
        $this->inspection(RentalInspection::TYPE_OUT);

        $printed = $this->get(route('corex.rental-inspections.print-list'))->assertOk()->viewData('inspections');

        $this->assertSame(
            ['out', 'in'],
            $printed->pluck('type')->all(),
            'the unscheduled one (created today) prints before the one scheduled 40 days ago, matching the screen'
        );
    }

    public function test_the_inspector_filter_only_offers_people_within_the_viewers_reach(): void
    {
        $sameBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Same Branch']);
        $branch2 = Branch::forceCreate(['name' => 'Branch Two', 'agency_id' => $this->agency->id]);
        $elsewhere = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch2->id, 'role' => 'agent', 'name' => 'Other Branch']);
        $foreign = $this->userOfAnotherAgency('agent', 'Foreign Agency');
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'name' => 'Branch Boss']);

        $options = fn (User $viewer) => $this->actingAs($viewer)->get(route('corex.rental-inspections.index'))->assertOk()->viewData('inspectorOptions')->pluck('name')->all();

        $this->assertSame(['Agent Aileen'], $options($this->agent), 'an own-scope viewer sees only themselves');
        $branchView = $options($manager);
        $this->assertContains('Same Branch', $branchView);
        $this->assertContains('Branch Boss', $branchView);
        $this->assertNotContains('Other Branch', $branchView);
        $this->assertNotContains('Foreign Agency', $branchView);

        $allView = $options($this->admin);
        $this->assertContains('Other Branch', $allView);
        $this->assertNotContains('Foreign Agency', $allView, 'never another agency\'s staff');
        $this->assertNotNull($elsewhere->id);
        $this->assertNotNull($foreign->id);
    }
}
