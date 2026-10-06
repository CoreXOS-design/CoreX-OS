<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Events\Rentals\RentalEmergencyApprovalRecorded;
use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApprovalDecision;
use App\Models\RentalEmergencyApproval;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.8 — emergency work: the owner ALWAYS agrees; the office captures it with no cost attached; the
 * work proceeds; costs settle later and the owner sees the final amount flagged. No override exists: nothing starts emergency work
 * without this record. A mistake is voided with a reason and re-recorded, never edited or deleted.
 */
final class EmergencyApprovalTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Emergency');
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'approved_by_name' => 'Mrs Landlordson', 'approved_via' => 'phone', 'approved_at' => now()->subMinutes(20)->format('Y-m-d\TH:i'),
            'reason' => 'Burst geyser flooding the ceiling', 'reported_by_crew_name' => 'Sipho (crew)',
        ], $over);
    }

    private function record(RentalWorkOrder $wo, array $over = [])
    {
        return $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.store', $wo), $this->payload($over));
    }

    // ── recording ────────────────────────────────────────────────────

    public function test_recording_the_owners_agreement_authorises_the_work_with_no_cost_attached(): void
    {
        Event::fake([RentalEmergencyApprovalRecorded::class]);
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Burst geyser'], $this->admin);
        $wo = $card->workOrder()->first();

        $this->record($wo, ['owner_contact_id' => $this->landlord->id, 'notes' => 'Owner is overseas, replied by phone'])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $wo->fresh();
        $approval = $fresh->activeEmergencyApproval();
        $this->assertNotNull($approval);
        $this->assertSame('Mrs Landlordson', $approval->approved_by_name);
        $this->assertSame($this->landlord->id, (int) $approval->owner_contact_id);
        $this->assertSame('phone', $approval->approved_via);
        $this->assertSame($this->admin->id, (int) $approval->recorded_by_user_id);
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $fresh->owner_approval_status);
        $this->assertSame(RentalWorkOrder::BASIS_EMERGENCY, $fresh->approval_basis);
        $this->assertNull($fresh->approved_amount, 'no cost is attached to an emergency approval');
        $this->assertSame($approval->id, (int) $fresh->emergency_approval_id);

        $row = RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->sole();
        $this->assertSame('emergency', $row->decided_by);
        $this->assertSame('emergency_covered', $row->decision);
        $this->assertStringContainsString('Approved as emergency work on', $row->note);
        $this->assertStringContainsString("owner agreed by phone to Mrs Landlordson, recorded by {$this->admin->name}", $row->note);
        $this->assertDatabaseHas('rental_work_order_updates', ['rental_work_order_id' => $wo->id, 'update_type' => 'emergency_approved']);
        Event::assertDispatched(RentalEmergencyApprovalRecorded::class);

        // the work proceeds with nothing priced
        $card->fresh()->schedule(now()->addHour(), null, $this->admin);
        $this->assertSame(RentalJobCard::STATUS_SCHEDULED, $card->fresh()->status);
    }

    public function test_each_required_field_is_asked_for_in_plain_words(): void
    {
        $wo = $this->externalWorkOrder();

        foreach ([
            'approved_by_name' => 'Say who at the owner',
            'approved_via' => 'Say how the owner agreed',
            'approved_at' => 'Say when the owner agreed',
            'reason' => 'Say why this is emergency work',
        ] as $field => $message) {
            $this->record($wo, [$field => ''])->assertSessionHasErrors($field);
            $this->assertStringContainsString($message, session('errors')->first($field));
        }
        $this->assertNull($wo->fresh()->activeEmergencyApproval());
        $this->assertSame(0, RentalEmergencyApproval::withoutGlobalScopes()->count());
    }

    public function test_the_time_cannot_be_in_the_future_and_may_predate_the_entry(): void
    {
        $wo = $this->externalWorkOrder();

        $this->record($wo, ['approved_at' => now()->addHours(2)->format('Y-m-d\TH:i')])->assertSessionHasErrors('emergency');
        $this->assertNull($wo->fresh()->activeEmergencyApproval());

        $this->record($wo, ['approved_at' => now()->subDays(2)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertNotNull($wo->fresh()->activeEmergencyApproval(), 'the owner may have agreed days before the entry is made');
    }

    public function test_only_one_of_this_propertys_owners_can_be_named_and_only_one_record_stands_at_a_time(): void
    {
        $wo = $this->externalWorkOrder();
        $stranger = $this->linkLandlord('Stranger', 'tenant');   // linked to the property, but a tenant — not an owner

        $this->record($wo, ['owner_contact_id' => $stranger->id])->assertSessionHasErrors('emergency');
        $this->record($wo, ['owner_contact_id' => $this->landlord->id])->assertSessionHasNoErrors();
        $this->record($wo)->assertSessionHasErrors('emergency');   // a second active record

        $this->assertSame(1, $wo->emergencyApprovals()->count());
    }

    public function test_the_optional_fields_can_all_be_left_empty(): void
    {
        $wo = $this->externalWorkOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.store', $wo), [
            'approved_by_name' => '  Mr Owner  ', 'approved_via' => 'in_person', 'approved_at' => now()->subMinute()->format('Y-m-d\TH:i'), 'reason' => 'Gas smell',
        ])->assertSessionHasNoErrors();

        $approval = $wo->fresh()->activeEmergencyApproval();
        $this->assertSame('Mr Owner', $approval->approved_by_name, 'trimmed');
        $this->assertNull($approval->owner_contact_id);
        $this->assertNull($approval->notes);
        $this->assertNull($approval->attachment_path);
    }

    public function test_an_attachment_is_kept_on_the_private_disk_and_only_downloadable_by_someone_who_can_see_the_work_order(): void
    {
        $wo = $this->externalWorkOrder();

        $this->record($wo, ['attachment' => UploadedFile::fake()->image('whatsapp.png', 600, 800)])->assertSessionHasNoErrors();
        $approval = $wo->fresh()->activeEmergencyApproval();
        Storage::disk('local')->assertExists($approval->attachment_path);
        $this->assertStringStartsWith("rental-emergency-approvals/{$wo->id}/", $approval->attachment_path);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.emergency-approval.attachment', [$wo, $approval]))->assertOk();

        $other = $this->externalWorkOrder(['title' => 'Other job']);
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.emergency-approval.attachment', [$other, $approval]))->assertNotFound();
        $this->record($other, ['attachment' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('attachment');
    }

    public function test_a_user_without_the_key_cannot_record_or_void_and_another_agency_cannot_even_find_the_work_order(): void
    {
        $wo = $this->externalWorkOrder();
        $agent = $this->agentWith(['rental_work_orders.view' => 'all', 'rental_work_orders.record_approval' => 'all']);   // the nearest keys, but not the emergency one

        $this->actingAs($agent)->post(route('corex.rental-work-orders.emergency-approval.store', $wo), $this->payload())->assertForbidden();
        $this->assertNull($wo->fresh()->activeEmergencyApproval());

        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'elsewhere-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $stranger = \App\Models\User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $status = $this->actingAs($stranger)->post(route('corex.rental-work-orders.emergency-approval.store', $wo), $this->payload())->getStatusCode();
        $this->assertContains($status, [403, 404], 'another agency must not be able to touch this work order');
        $this->assertNull($wo->fresh()->activeEmergencyApproval());
    }

    // ── what emergency approval does to the rest of the flow ─────────

    public function test_selecting_a_quote_never_downgrades_an_emergency_approval(): void
    {
        $wo = $this->externalWorkOrder();
        $supplier = $this->supplier();
        $this->record($wo)->assertSessionHasNoErrors();

        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 48000, 'quote_date' => now(), 'detail_text' => 'full replacement'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $fresh->owner_approval_status);
        $this->assertSame(RentalWorkOrder::BASIS_EMERGENCY, $fresh->approval_basis);
        $this->assertNull($fresh->openVariation(), 'no variation and no threshold apply to emergency work');
        $this->assertSame([], $this->sent(), 'the owner is not asked for a decision on emergency work');
        $fresh->assignSupplier($supplier->id, 'plumbing', $this->admin);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $fresh->fresh()->status);
    }

    public function test_extra_work_on_an_emergency_job_never_raises_a_variation(): void
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Burst main'], $this->admin);
        $wo = $card->workOrder()->first();
        $this->record($wo)->assertSessionHasNoErrors();
        $this->travel(5)->seconds();

        app(RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'New main', 'quantity' => 1, 'unit_price' => 90000], $this->admin);

        $this->assertSame(0, $wo->fresh()->variations()->count());
    }

    // ── voiding ──────────────────────────────────────────────────────

    public function test_a_mistake_is_voided_with_a_reason_and_the_work_order_goes_back_to_needing_the_owner(): void
    {
        $wo = $this->externalWorkOrder();
        $this->record($wo)->assertSessionHasNoErrors();
        $approval = $wo->fresh()->activeEmergencyApproval();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.void', [$wo, $approval]), ['void_reason' => ''])->assertSessionHasErrors('void_reason');
        $this->assertNotNull($wo->fresh()->activeEmergencyApproval());

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.void', [$wo, $approval]), ['void_reason' => 'Recorded against the wrong property'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $fresh = $wo->fresh();
        $this->assertNull($fresh->activeEmergencyApproval());
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $fresh->owner_approval_status, 'nothing is selected, so the owner is needed again');
        $this->assertNull($fresh->approval_basis);
        $this->assertNull($fresh->emergency_approval_id);
        $voided = RentalEmergencyApproval::withoutGlobalScopes()->findOrFail($approval->id);
        $this->assertNotNull($voided->voided_at, 'voided, never deleted');
        $this->assertSame('Recorded against the wrong property', $voided->void_reason);
        $this->assertDatabaseHas('rental_work_order_updates', ['rental_work_order_id' => $wo->id, 'update_type' => 'emergency_voided']);

        $this->record($wo)->assertSessionHasNoErrors();   // the right one can now be recorded
        $this->assertSame(2, $wo->emergencyApprovals()->count());
        $this->assertSame(1, $wo->emergencyApprovals()->whereNull('voided_at')->count());
    }

    public function test_voiding_re_runs_the_gate_on_the_selected_quote_and_warns_when_work_is_already_under_way(): void
    {
        $wo = $this->externalWorkOrder();
        $supplier = $this->supplier();
        $this->record($wo)->assertSessionHasNoErrors();
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 300, 'quote_date' => now(), 'detail_text' => 'quote'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->fresh()->assignSupplier($supplier->id, 'plumbing', $this->admin);   // work under way
        $approval = $wo->fresh()->activeEmergencyApproval();

        $response = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.void', [$wo, $approval]), ['void_reason' => 'Not an emergency after all']);

        $response->assertSessionHas('warning');
        $this->assertStringContainsString('already under way', session('warning'));
        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $fresh->owner_approval_status, 'R300 is inside the R500 limit, so the quote is auto-approved again');
        $this->assertSame('300.00', $fresh->approved_amount);
        $this->assertSame(RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, $fresh->approval_basis);
    }

    // ── what the owner and the crew see ──────────────────────────────

    public function test_the_owner_gets_a_final_statement_flagged_as_emergency_when_the_work_order_closes(): void
    {
        $wo = $this->externalWorkOrder();
        $this->record($wo)->assertSessionHasNoErrors();

        $wo->fresh()->complete($this->admin, ['paid_by' => RentalWorkOrder::PAID_BY_OWNER, 'cost_amount' => 7342.5, 'completion_notes' => 'Geyser replaced']);

        $mails = $this->sent(RentalOwnerFinalStatementMail::class);
        $this->assertCount(1, $mails);
        $this->assertSame($this->landlord->email, $this->mailbox->sent[0][0]);
        $this->assertStringContainsString('Approved as emergency work on', $mails[0]->emergencyBanner);
        $html = $mails[0]->render();
        $this->assertStringContainsString('Approved as emergency work on', $html);
        $this->assertStringContainsString('R7,342.50', $html);
        $this->assertStringContainsStringIgnoringCase('Final statement', (string) $mails[0]->attachmentName());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('rental_work_order_updates')->where('rental_work_order_id', $wo->id)->where('note', 'like', '%could not%')->count());
    }

    public function test_the_crew_sees_only_a_chip_for_emergency_work(): void
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Burst main'], $this->admin);
        $wo = $card->workOrder()->first();
        $this->record($wo, ['owner_contact_id' => $this->landlord->id])->assertSessionHasNoErrors();
        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin);

        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->assertSee('Approved to proceed (emergency)')->getContent();

        $this->assertStringNotContainsString('Mrs Landlordson', $html, 'no owner name on the crew link');
        $this->assertStringNotContainsString($this->landlord->email, $html);
        $this->assertStringNotContainsString('Burst geyser flooding the ceiling', $html);
    }

    public function test_the_work_order_screen_shows_the_panel_and_the_record_then_the_void_form(): void
    {
        $wo = $this->externalWorkOrder();

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))
            ->assertOk()->assertSee("Record owner's emergency approval", false)->assertSee('Emergency approval');

        $this->record($wo)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))
            ->assertOk()->assertSee('Approved as emergency work — owner agreed')->assertSee('Void this approval')->assertSee('Mrs Landlordson')
            ->assertDontSee("Record owner's emergency approval", false);
    }

    public function test_the_quote_pdf_for_an_emergency_job_carries_the_banner(): void
    {
        $card = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Burst main'], $this->admin);
        $wo = $card->workOrder()->first();
        $this->record($wo)->assertSessionHasNoErrors();
        app(RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'New main', 'quantity' => 1, 'unit_price' => 900], $this->admin);

        $this->assertStringContainsString('Approved as emergency work on', (string) $wo->fresh()->emergencyBanner());
        $this->assertStringStartsWith('%PDF', app(\App\Services\Rentals\RentalDocumentPdfService::class)->jobCardQuotePdf($card->fresh(), 1)->output());
        $html = view('corex.rental-job-cards.quote-pdf', array_merge($this->quoteViewData($card->fresh()), ['emergencyBanner' => $wo->fresh()->emergencyBanner()]))->render();
        $this->assertStringContainsString('Approved as emergency work on', $html);
    }

    /** the variables corex.rental-job-cards.quote-pdf needs, mirroring RentalDocumentPdfService::jobCardQuotePdf() */
    private function quoteViewData(RentalJobCard $card): array
    {
        $card->loadMissing(['property', 'lease.tenants.contact', 'tasks.lines.vatType', 'lines.vatType', 'crew.members', 'workOrder.agency']);

        return [
            'jobCard' => $card, 'revision' => 1, 'pricesOn' => true, 'vat' => app(\App\Services\Rentals\RentalJobCardVatService::class)->breakdown($card),
            'vatNumber' => null, 'logo' => null, 'agencyName' => 'Emergency Agency',
        ];
    }
}
