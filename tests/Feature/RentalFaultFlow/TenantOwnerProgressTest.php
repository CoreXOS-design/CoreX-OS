<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalOwnerHold;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.18 (Johan, 14 Oct 2026): who reports "the work has started" / "the work is finished".
 * A tenant in the property -> the TENANT acts (started / finished / not fixed) and the owner only watches; no tenant -> the owner acts as before. Both only once the
 * cost is authorised and the go-ahead has gone, not on hold, not after completion - enforced on the server, for the wrong role too.
 */
final class TenantOwnerProgressTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const T = '/api/v1/client/rentals';
    private const L = '/api/v1/client/rentals/landlord';

    private Contact $tenant;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->approvalWorld('Progress');
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina-' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    /** An authorised job whose go-ahead has gone to the contractor (status ordered, on the tenant's lease). */
    private function liveJob(bool $contractorEmail = true): RentalWorkOrder
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('Acme Plumbing', $contractorEmail ? 'acme@example.invalid' : null);
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id, 'agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);

        return $wo->fresh();
    }

    private function asTenant(): void
    {
        Sanctum::actingAs($this->clientFor($this->tenant), ['client']);
    }

    private function asOwner(): void
    {
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
    }

    private function emptyTheProperty(): void
    {
        Lease::withoutGlobalScopes()->where('property_id', $this->property->id)->update(['status' => Lease::STATUS_EXPIRED]);
    }

    private function clientView(RentalWorkOrder $wo, string $audience): array
    {
        return app(RentalWorkOrderClientViewService::class)->payload($wo->fresh(), $audience);
    }

    // ── A. with a tenant the owner only watches ───────────────────────────────

    public function test_with_a_tenant_in_the_property_the_owner_has_no_started_or_finished_and_the_server_refuses_them(): void
    {
        $wo = $this->liveJob();
        $owner = $this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertFalse($owner['owner_can_start']);
        $this->assertFalse($owner['owner_can_finish']);
        $this->assertFalse($owner['owner_can_appoint']);
        $this->assertStringContainsString('Your tenant lives in the property', (string) $owner['owner_progress_note']);

        $this->asOwner();
        foreach (['started', 'finished'] as $action) {
            $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => $action])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Your tenant lives in the property'));
        }
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertSame(0, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    // ── B + C. the tenant acts ────────────────────────────────────────────────

    public function test_the_tenant_reports_the_work_started_and_the_job_moves_to_in_progress(): void
    {
        $wo = $this->liveJob();
        $tenant = $this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertTrue($tenant['tenant_can_start']);
        $this->assertTrue($tenant['tenant_can_finish']);

        $this->asTenant();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();

        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $line = $wo->updates()->where('update_type', 'tenant_progress')->first()->note;
        $this->assertStringContainsString('Work started - reported by tenant Tina Tenant', $line);
        $this->assertFalse($this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_TENANT)['tenant_can_start'], 'already started');
    }

    public function test_the_tenant_saying_the_work_is_finished_records_their_confirmation_but_never_completes_the_job(): void
    {
        $wo = $this->liveJob();
        $this->asTenant();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'finished', 'note' => 'The tap is fixed, thank you', 'photos' => [UploadedFile::fake()->image('done.jpg')]])->assertOk();

        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $fresh->status, 'the agent completes it - with the final cost, what was found and who pays');
        $this->assertNull($fresh->completed_at);
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $this->assertSame(RentalWorkCompletionRound::VIA_TENANT_PORTAL, $round->reported_via);
        $this->assertSame('Tina Tenant (tenant)', $round->reported_by_label);
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_CONFIRMED, $round->outcome);
        $this->assertTrue((bool) $fresh->tenant_confirmed_fixed);
        $this->assertNotNull($fresh->tenant_confirmed_at, 'the field cc6 shows: "Tenant says the work is finished <date>"');
        $this->assertSame('The tap is fixed, thank you', $fresh->tenant_confirmation_note);
        $this->assertSame(1, $fresh->photos()->where('photo_type', RentalWorkOrder::PHOTO_COMPLETED)->count());
        $this->assertTrue(app(RentalWorkOrderClientViewService::class)->reportedFinished($fresh), 'the agent page reads it as "reported finished"');
        $this->assertTrue($fresh->updates()->where('update_type', 'work_reported_done')->where('note', 'like', '%the tenant on the portal%')->exists());

        // saying it twice changes nothing and is refused in plain words
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'finished'])->assertStatus(422);
        $this->assertSame(1, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
    }

    public function test_it_is_not_fixed_keeps_the_job_open_and_tells_the_agent(): void
    {
        $wo = $this->liveJob();
        $this->asTenant();

        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'not_fixed', 'note' => 'no'])->assertStatus(422);   // a reason is needed
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'not_fixed', 'note' => 'The contractor left and it still drips'])->assertOk();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status, 'still open');
        $this->assertStringContainsString('says it is NOT fixed', $wo->updates()->where('update_type', 'tenant_progress')->latest('id')->first()->note);
        // ...and it writes the SAME tenant-check fields the agent's Next line reads
        $fresh = $wo->fresh();
        $this->assertFalse((bool) $fresh->tenant_confirmed_fixed);
        $this->assertNotNull($fresh->tenant_confirmed_at);
        $this->assertSame($this->tenant->id, (int) $fresh->tenant_confirmed_by_contact_id);
        $this->assertSame('The contractor left and it still drips', $fresh->tenant_confirmation_note);

        // after someone reported the work done, "not fixed" is the usual "tenant says not fixed" row
        $wo2 = $this->liveJob();
        app(RentalCompletionService::class)->openRound($wo2, ['reported_via' => RentalWorkCompletionRound::VIA_OFFICE, 'reported_by_label' => 'The crew']);
        $this->postJson(self::T . "/work-orders/{$wo2->id}/progress", ['action' => 'not_fixed', 'note' => 'The tap still leaks badly'])->assertOk();
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo2->id)->firstOrFail();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_DISPUTED, $round->outcome);
    }

    // ── D. no tenant: the owner keeps the actions ─────────────────────────────

    public function test_with_no_tenant_in_the_property_the_owner_keeps_the_appointment_and_the_started_finished_actions(): void
    {
        $wo = $this->liveJob();
        $this->emptyTheProperty();

        $owner = $this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertTrue($owner['owner_can_appoint']);
        $this->assertTrue($owner['owner_can_start']);
        $this->assertTrue($owner['owner_can_finish']);

        $this->asOwner();
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'finished', 'note' => 'All done'])->assertOk();
        $this->assertSame(1, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());

        // and the tenant of an empty property has nothing to press
        $this->assertFalse($this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_TENANT)['tenant_can_start']);
        $this->assertFalse($this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_TENANT)['tenant_can_finish']);
    }

    // ── E. only once authorised and gone, never on hold or after completion ───────

    public function test_nothing_can_be_reported_before_the_cost_is_authorised_or_the_go_ahead_has_gone(): void
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id, 'agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);   // quoted, NOT authorised

        $this->asTenant();
        foreach (['started', 'finished'] as $action) {
            $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => $action])->assertStatus(422)->assertJsonPath('message', 'The work can start once the owner has authorised the cost.');
        }
        $view = $this->clientView($wo, RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertFalse($view['tenant_can_start']);
        $this->assertFalse($view['tenant_can_finish']);

        // authorised, but the go-ahead could not go (the contractor has no email): still nothing to press
        $noMail = $this->liveJob(false);
        $this->postJson(self::T . "/work-orders/{$noMail->id}/progress", ['action' => 'started'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sent this to the contractor'));
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $noMail->fresh()->status);
    }

    public function test_nothing_can_be_reported_while_the_job_is_stopped_or_after_it_is_closed(): void
    {
        $wo = $this->liveJob();
        $hold = RentalOwnerHold::withoutGlobalScopes()->forceCreate(['agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'stopped_by_contact_id' => $this->landlord->id, 'reason' => 'Wait', 'stopped_via' => 'link', 'stopped_at' => now()]);
        $this->asTenant();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'on hold'));

        $hold->forceFill(['resumed_at' => now()])->save();
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'finished'])->assertStatus(422)->assertJsonPath('message', 'This job is closed.');
    }

    // ── the wrong role ────────────────────────────────────────────────────────

    public function test_each_role_is_refused_on_the_others_route(): void
    {
        $wo = $this->liveJob();

        $this->asOwner();
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertNotFound();   // the owner on the tenant's route
        $this->asTenant();
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertNotFound();   // the tenant on the owner's route
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);

        // another agency's tenant cannot reach this job at all
        $stranger = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sam', 'last_name' => 'Stranger', 'email' => 'sam-' . uniqid() . '@example.invalid']);
        Sanctum::actingAs($this->clientFor($stranger), ['client']);
        $this->postJson(self::T . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertNotFound();
    }

    public function test_the_portal_page_carries_the_tenants_buttons_and_the_owner_stays_quiet(): void
    {
        $shell = file_get_contents(base_path('resources/views/rentals/portal/shell.blade.php'));
        foreach (['data-tenant-started', 'The contractor has arrived - work has started', 'data-tenant-finished', 'The work is finished', 'data-tenant-not-fixed', 'It is not fixed'] as $needle) {
            $this->assertStringContainsString($needle, $shell, $needle);
        }
    }
}
