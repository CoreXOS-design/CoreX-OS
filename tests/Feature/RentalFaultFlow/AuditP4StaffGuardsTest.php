<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026, spec rentals-faults-work-orders.md section 29 - the staff side: C-D7 (cancel and archive are guarded on the server, not only hidden), C-C3
 * (one way to the owner), C-C2 (an emergency-type fault is flagged, sorted first and goes out at the elevated severity).
 */
final class AuditP4StaffGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;
    private RentalFaultType $routine;
    private RentalFaultType $emergency;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function __construct() {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void {}
        });
        $this->agency = Agency::create(['name' => 'Guards Agency', 'slug' => 'ga-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'G Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $mk = fn (string $name, string $urgency) => RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => $name, 'category' => 'Plumbing', 'urgency' => $urgency, 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $this->routine = $mk('Dripping tap', 'routine');
        $this->emergency = $mk('Burst pipe', 'emergency');
    }

    private function fault(RentalFaultType $type, array $over = []): RentalFaultReport
    {
        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $type->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => $type->name . ' at G Unit', 'description' => 'It is a problem.',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    private function withLiveWorkOrder(): RentalFaultReport
    {
        $f = $this->fault($this->routine);
        $f->saveOwnerVersion(['owner_title' => 'Tap'], $this->agent);
        $f->requestApproval($this->agent);
        $f->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Phoned.']);
        $s = AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Acme', 'email' => 'acme@example.invalid', 'is_active' => true, 'specialty' => 'other', 'callout_fee_mode' => 'no']);
        app(RentalWorkOrderService::class)->createFromFaultDecision($f->fresh(), $this->agent, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $s->id]);

        return $f->fresh();
    }

    // ── C-D7 ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_direct_post_cannot_cancel_a_fault_that_has_a_live_work_order(): void
    {
        $f = $this->withLiveWorkOrder();
        $this->assertNotNull($f->rental_work_order_id);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.cancel', $f), ['cancel_reason' => 'oops'])->assertSessionHasErrors('rental_fault_report');
        $this->assertNotSame(RentalFaultReport::STATUS_CANCELLED, $f->fresh()->status);
    }

    public function test_cancel_is_only_for_a_fault_the_owner_has_not_decided_and_the_screen_rule_is_the_server_rule(): void
    {
        $f = $this->fault($this->routine, ['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.cancel', $f), ['cancel_reason' => 'x'])->assertSessionHasErrors('rental_fault_report');

        $open = $this->fault($this->routine);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.cancel', $open), ['cancel_reason' => 'logged in error'])->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_CANCELLED, $open->fresh()->status);
    }

    public function test_a_fault_the_owner_is_deciding_on_cannot_be_archived_by_a_direct_post(): void
    {
        $f = $this->fault($this->routine);
        $f->saveOwnerVersion(['owner_title' => 'Tap'], $this->agent);
        $f->requestApproval($this->agent);

        $this->actingAs($this->agent)->delete(route('corex.rental-fault-reports.destroy', $f))->assertSessionHasErrors('rental_fault_report');
        $this->assertNull($f->fresh()->deleted_at, 'still on the owner\'s list');

        $plain = $this->fault($this->routine);
        $this->actingAs($this->agent)->delete(route('corex.rental-fault-reports.destroy', $plain))->assertSessionHasNoErrors();
        $this->assertNotNull(RentalFaultReport::withTrashed()->find($plain->id)->deleted_at);
    }

    public function test_a_fault_with_a_live_work_order_cannot_be_archived(): void
    {
        $f = $this->withLiveWorkOrder();
        $this->assertNotNull($f->archiveBlockReason());
        $this->actingAs($this->agent)->delete(route('corex.rental-fault-reports.destroy', $f))->assertSessionHasErrors('rental_fault_report');
    }

    // ── C-C3 ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_there_is_one_way_to_the_owner(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.rental-fault-reports.request-approval'), 'the duplicate alias is gone');

        $f = $this->fault($this->routine);
        $f->saveOwnerVersion(['owner_title' => 'Tap'], $this->agent);
        // "save" with a stray send_now does NOT send
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.owner-version.store', $f), ['owner_title' => 'Tap', 'send_now' => 1])->assertSessionHasNoErrors();
        $this->assertNull($f->fresh()->sent_to_owner_at, 'Save only saves');

        // the old endpoint goes through the same service and history as "Send to owner to authorise"
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $f))->assertSessionHasNoErrors();
        $this->assertNotNull($f->fresh()->sent_to_owner_at);
        $this->assertSame(1, $f->updates()->where('update_type', \App\Models\RentalFaultReportUpdate::TYPE_SENT_TO_OWNER_TO_AUTHORISE)->count());

        // and it refuses a second send, like the one way does
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $f))->assertSessionHasErrors('rental_fault_report');
    }

    // ── C-C2 ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_an_emergency_type_fault_alerts_at_the_elevated_severity_and_a_routine_one_does_not(): void
    {
        $captured = [];
        $this->app->instance(\App\Services\CommandCenter\NotificationDispatcher::class, new class($captured) extends \App\Services\CommandCenter\NotificationDispatcher {
            public function __construct(public array &$cap) {}
            public function fire(\App\Models\User $user, string $eventKey, \Illuminate\Database\Eloquent\Model $subject, array $args): bool { $this->cap[] = [$user, $eventKey, $subject, $args]; return true; }
        });
        $svc = app(\App\Services\Rentals\RentalFaultReportService::class);
        foreach ([$this->emergency, $this->routine] as $type) {
            $svc->report($this->property, ['lease_id' => $this->lease->id, 'rental_fault_type_id' => $type->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone', 'title' => $type->name, 'description' => 'x', 'created_by_user_id' => $this->agent->id]);
        }

        $sev = array_map(fn ($a) => [$a[3]['severity'], $a[3]['title']], $captured);
        $this->assertNotEmpty($sev);
        $this->assertContains('warning', array_column($sev, 0));
        $this->assertContains('info', array_column($sev, 0));
        $this->assertTrue((bool) collect($sev)->first(fn ($s) => $s[0] === 'warning' && str_contains($s[1], 'EMERGENCY')));
    }

    public function test_an_open_emergency_is_flagged_and_sorted_above_a_newer_routine_fault(): void
    {
        $old = $this->fault($this->emergency, ['reported_at' => now()->subDays(3), 'title' => 'EM Burst pipe']);
        $new = $this->fault($this->routine, ['reported_at' => now(), 'title' => 'RT Dripping tap']);
        $closed = $this->fault($this->emergency, ['reported_at' => now()->subDays(5), 'title' => 'EM Closed burst', 'status' => RentalFaultReport::STATUS_RESOLVED]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.index'))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'RT Dripping tap'), strpos($html, 'EM Burst pipe'), 'the open emergency comes first');
        $this->assertSame(1, substr_count($html, 'data-emergency-flag'), 'only the OPEN emergency carries the flag');
    }
}
