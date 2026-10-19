<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeCause;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalWhoPaysService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit fix C-F2 (19 Oct 2026) - the owner's final statement said "Not yet paid" and the Who-pays ruling came later. After a ruling on a closed job whose statement already
 * went out, the statement is regenerated with the ruling and re-sent once per ruling. No money flow.
 */
final class AuditP1StatementRulingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;
    private RentalFaultType $type;
    /** @var array<int, array{0:?string,1:object}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $self = $this;
        $this->app->instance(RentalMailDispatcher::class, new class($self) extends RentalMailDispatcher {
            public function __construct(private $t) {}
            public function send(?string $recipientEmail, BaseSignatureMail $mail): void { $this->t->sent[] = [$recipientEmail, $mail]; }
        });
        $this->agency = Agency::create(['name' => 'Stmt Agency', 'slug' => 'st-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'S-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id, 'title' => 'ST Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Blocked drain / toilet', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => true, 'sort_order' => 1, 'is_active' => true]);
        RentalFaultTypeCause::seedForType($this->type);
    }

    private function closedFaultJob(bool $statementSent = true): RentalWorkOrder
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->type->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'app', 'title' => 'Drain', 'description' => 'x',
            'status' => RentalFaultReport::STATUS_RESOLVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $wo = RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->property->branch_id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Clear the drain', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_COMPLETED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'reported_by_type' => 'tenant', 'reported_at' => now(), 'completed_at' => now(), 'cost_amount' => 900,
            'paid_by' => 'not_yet_paid', 'reported_fault_report_id' => $fault->id, 'created_by_user_id' => $this->agent->id,
        ]);
        $fault->forceFill(['rental_work_order_id' => $wo->id])->save();
        $cause = RentalFaultTypeCause::withoutGlobalScopes()->where('rental_fault_type_id', $this->type->id)->where('label', 'Foreign object in the drain')->firstOrFail();
        app(RentalWhoPaysService::class)->recordFindings($wo, 'A toy car was stuck in the pipe', $cause->id, null, 'Test (agent)', $this->agent);
        if ($statementSent) {
            app(RentalWorkOrderService::class)->sendOwnerFinalStatement($wo->fresh());
        }
        $this->sent = [];   // forget the original statement

        return $wo->fresh();
    }

    public function test_a_ruling_after_the_statement_went_out_sends_the_owner_an_updated_one_showing_the_ruling(): void
    {
        $wo = $this->closedFaultJob();

        app(RentalWhoPaysService::class)->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain');

        $statements = array_values(array_filter($this->sent, fn ($s) => $s[1] instanceof RentalOwnerFinalStatementMail));
        $this->assertCount(1, $statements);
        $this->assertSame($this->landlord->email, $statements[0][0]);
        $this->assertNotNull($statements[0][1]->rulingLine);
        $this->assertStringContainsString('Tenant', $statements[0][1]->rulingLine);
        $subject = new \ReflectionMethod($statements[0][1], 'subjectText');
        $subject->setAccessible(true);
        $this->assertStringContainsString('Updated final statement', $subject->invoke($statements[0][1]));
        $this->assertStringContainsString('Who pays has now been decided', $statements[0][1]->render());
        $this->assertNotNull($wo->fresh()->final_statement_ruling_id);
        $this->assertStringContainsString('Updated final statement emailed to the owner', $wo->updates()->pluck('note')->implode(' | '));
    }

    public function test_it_is_once_per_ruling_and_a_replacement_ruling_sends_again(): void
    {
        $wo = $this->closedFaultJob();
        $svc = app(RentalWhoPaysService::class);

        $first = $svc->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain');
        $this->assertSame(0, app(RentalWorkOrderService::class)->resendFinalStatementForRuling($wo->fresh(), $first), 'the same ruling never sends twice');
        $count = fn () => count(array_filter($this->sent, fn ($s) => $s[1] instanceof RentalOwnerFinalStatementMail));
        $this->assertSame(1, $count());

        $svc->rule($wo->fresh(), $this->agent, 'owner', null, 'On reflection the pipe was old');
        $this->assertSame(2, $count(), 'a new ruling is a new statement');
    }

    public function test_nothing_is_re_sent_when_the_first_statement_never_went_out_or_the_job_is_not_closed(): void
    {
        $wo = $this->closedFaultJob(false);
        app(RentalWhoPaysService::class)->rule($wo, $this->agent, 'tenant', null, 'A toy was in the drain');
        $this->assertSame([], array_filter($this->sent, fn ($s) => $s[1] instanceof RentalOwnerFinalStatementMail), 'no statement had gone out, so the first one will already carry the ruling');

        $open = $this->closedFaultJob();
        $open->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        app(RentalWhoPaysService::class)->rule($open->fresh(), $this->agent, 'tenant', null, 'A toy was in the drain');
        $this->assertSame([], array_filter($this->sent, fn ($s) => $s[1] instanceof RentalOwnerFinalStatementMail));
    }
}
