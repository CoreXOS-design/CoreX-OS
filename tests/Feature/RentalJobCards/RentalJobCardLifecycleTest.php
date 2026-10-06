<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-442 — internal job cards. Proves: a job card builds its own work order
 * (assignment_type='internal'); tasks/lines; totals with prices on/off
 * (rental_work_order_settings.capture_prices_on_job_cards); the quote-to-
 * owner flow reuses the EXISTING threshold gate (RentalWorkOrder::
 * selectQuote()) — auto-approve at/under, pending over; raising from an
 * already-approved fault report; worker+agent sign-off gating completion;
 * scope guard on direct-URL access; archive/restore.
 */
final class RentalJobCardLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'RJC Lifecycle Agency', 'slug' => 'rjc-lifecycle-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        // AT-442 follow-up (item 8) — "Send to owner as quote" now hard-blocks
        // without a real landlord/owner/seller/lessor contact linked
        // (RentalJobCardService::sendToOwnerAsQuote() via Property::
        // landlordContact(), no sole-contact fallback) — every quote-sending
        // test in this file needs one. The no-landlord-blocks-the-send
        // behaviour itself is proven in RentalJobCardAt442FollowUpTest.
        $landlord = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'jane.landlord-' . uniqid() . '@example.test',
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        // Agency::create() does not fire AgencyCreated in tests — seed
        // explicitly, same convention every other per-agency list's tests use.
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
    }

    private function catalogueItem(array $attrs = []): RentalCatalogueItem
    {
        return RentalCatalogueItem::create(array_merge([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'GEYSER-EL', 'description' => 'Geyser element',
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 450, 'sort_order' => 1,
            'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    // ── Creation — a job card BUILDS its own work order ─────────────────

    public function test_creating_a_job_card_creates_the_linked_work_order_as_internal(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, [
            'title' => 'Fix the gate motor', 'description' => 'Gate motor not responding.',
        ], $this->admin);

        $workOrder = $jobCard->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $workOrder->assignment_type);
        $this->assertSame($jobCard->id, $workOrder->jobCard->id);
        $this->assertSame(RentalJobCard::STATUS_DRAFT, $jobCard->status);
    }

    public function test_an_existing_work_order_defaults_to_outside_supplier(): void
    {
        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Legacy work order', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $workOrder->assignment_type);
        $this->assertNull($workOrder->jobCard);
    }

    public function test_store_with_assignment_type_internal_creates_a_job_card_not_a_bare_work_order(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id, 'assignment_type' => 'internal',
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED,
            'title' => 'Fix the fence', 'description' => 'Fence panel down.',
        ])->assertRedirectContains('rental-job-cards');

        $jobCard = RentalJobCard::firstWhere('title', 'Fix the fence');
        $this->assertNotNull($jobCard);
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $jobCard->workOrder->assignment_type);
    }

    public function test_raising_from_an_approved_fault_report_works_for_the_internal_path_too(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Blocked drain', 'description' => 'Kitchen drain blocked.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $faultReport->recordApproval($this->admin, [
            'decision' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
            'evidence_type' => 'whatsapp', 'evidence_text' => 'Go ahead',
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $faultReport), [
            'title' => $faultReport->title, 'description' => $faultReport->description, 'assignment_type' => 'internal',
        ])->assertRedirectContains('rental-job-cards');

        $jobCard = RentalJobCard::firstWhere('title', 'Blocked drain');
        $this->assertNotNull($jobCard);
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $jobCard->workOrder->assignment_type);
        $this->assertSame($faultReport->id, $jobCard->workOrder->reported_fault_report_id);
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $jobCard->workOrder->owner_approval_status);
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_tasks_add_tick_and_archive(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $service = app(RentalJobCardService::class);

        $task = $service->addTask($jobCard, 'Replace geyser element', $this->admin);
        $this->assertFalse($task->is_done);

        $service->toggleTask($jobCard, $task, $this->admin);
        $this->assertTrue($task->fresh()->is_done);
        $this->assertSame($this->admin->id, $task->fresh()->done_by_user_id);

        $service->archiveTask($jobCard, $task, $this->admin);
        $this->assertSoftDeleted($task);
    }

    // ── Lines & totals — prices on/off ───────────────────────────────────

    public function test_line_total_computed_when_prices_are_on(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $item = $this->catalogueItem();

        app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $item->id, 'quantity' => 2,
        ], $this->admin);

        $jobCard->refresh();
        $this->assertSame('900.00', (string) $jobCard->total_amount); // 2 * 450
    }

    public function test_no_prices_anywhere_when_the_setting_is_off(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => false]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $item = $this->catalogueItem();

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $item->id, 'quantity' => 2, 'unit_price' => 999, // ignored
        ], $this->admin);

        $this->assertNull($line->unit_price);
        $this->assertNull($line->line_total);
        // The cached total itself may be a coalesced 0.00 (SUM() of an
        // all-NULL column, via Laravel's own numericAggregate() ?: 0) —
        // harmless, since the UI's pricesOn gate (show.blade.php) never
        // renders a total column at all when this setting is off. The
        // real assertion is that no PRICE figure was computed anywhere.
        $this->assertNotEquals('999.00', (string) $jobCard->fresh()->total_amount);
    }

    /**
     * Conductor's ruling, AT-442 follow-up — the worker's printed copy and
     * the owner's quote PDF are not the same audience. §17.4.7 (6 Oct 2026):
     * restated in COST terms — the worker's printed copy shows the crew's cost
     * (never selling) and only with show_costs_on_printed_job_card (default
     * off); the owner quote PDF always shows SELLING.
     */
    public function test_printed_job_card_hides_costs_by_default_never_shows_selling_but_the_owner_quote_always_shows_selling(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'rental_catalogue_item_id' => $this->catalogueItem(['default_price' => 450])->id, 'quantity' => 1,
        ], $this->admin);
        // Sells at R450; costs R200 (the foundation has no UI to enter cost — Build 1 adds it).
        $line->forceFill(['unit_cost' => 200, 'cost_total' => 200])->save();
        $jobCard->refresh();

        $pdfService = app(\App\Services\Rentals\RentalDocumentPdfService::class);
        // Agency VAT set-up (2026-10-05) — this fixture agency isn't VAT
        // registered, so the real RentalDocumentPdfService would always pass
        // this exact "nothing to show" shape; these views now require it.
        $noVat = ['registered' => false, 'pricesOn' => false, 'captureMode' => null, 'subtotalExcl' => null, 'totalVat' => null, 'totalIncl' => null, 'groups' => []];

        $printHtml = view('corex.rental-job-cards.print', [
            'jobCard' => $jobCard, 'costsOn' => false, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Test Agency',
        ])->render();
        $this->assertStringNotContainsString('450.00', $printHtml);
        $this->assertStringNotContainsString('200.00', $printHtml);

        // Turn the print setting on — now the COST shows, and still never the selling price.
        $printHtmlOn = view('corex.rental-job-cards.print', [
            'jobCard' => $jobCard, 'costsOn' => true, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Test Agency',
        ])->render();
        $this->assertStringContainsString('200.00', $printHtmlOn);
        $this->assertStringContainsString('Total cost', $printHtmlOn);
        $this->assertStringNotContainsString('450.00', $printHtmlOn);

        // The owner quote PDF is never gated by show_costs_on_printed_job_card, and shows SELLING only (never the cost).
        $quoteHtml = view('corex.rental-job-cards.quote-pdf', [
            'jobCard' => $jobCard, 'pricesOn' => true, 'vat' => $noVat, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Test Agency',
        ])->render();
        $this->assertStringContainsString('450.00', $quoteHtml);
        $this->assertStringNotContainsString('200.00', $quoteHtml);
    }

    public function test_a_free_text_line_works_with_no_catalogue_item(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $line = app(RentalJobCardService::class)->addLine($jobCard, [
            'description' => 'Odd bracket, no catalogue match', 'quantity' => 1,
        ], $this->admin);

        $this->assertSame('Odd bracket, no catalogue match', $line->description);
        $this->assertNull($line->rental_catalogue_item_id);
    }

    public function test_archiving_a_line_recalculates_the_total(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $service = app(RentalJobCardService::class);
        $item = $this->catalogueItem();
        $line = $service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 1], $this->admin);

        $service->archiveLine($jobCard, $line, $this->admin);

        $this->assertSame('0.00', (string) $jobCard->fresh()->total_amount);
    }

    // ── Quote to owner — reuses the EXISTING threshold gate ──────────────

    public function test_sending_a_quote_at_or_under_threshold_auto_approves(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['rental_catalogue_item_id' => $this->catalogueItem()->id, 'quantity' => 1], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();

        $jobCard->refresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $jobCard->workOrder->owner_approval_status);
        $this->assertSame(RentalJobCard::STATUS_APPROVED, $jobCard->status);
        $quote = $jobCard->quotes()->first();
        $this->assertTrue($quote->is_selected);
        $this->assertNull($quote->agency_service_provider_id);
        $this->assertSame($jobCard->id, $quote->rental_job_card_id);
        Storage::disk('local')->assertExists($quote->document_storage_path);
    }

    public function test_sending_a_quote_over_threshold_requires_owner_approval(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 100, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['rental_catalogue_item_id' => $this->catalogueItem()->id, 'quantity' => 1], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();

        $jobCard->refresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $jobCard->workOrder->owner_approval_status);
        $this->assertSame(RentalJobCard::STATUS_QUOTED, $jobCard->status);

        // The existing recordApproval() action (reachable from the work
        // order's own screen) flips it — and the job card picks that up.
        $jobCard->workOrder->recordApproval($this->admin, [
            'decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Approved via email',
        ]);
        $jobCard->syncStatusFromWorkOrder();

        $this->assertSame(RentalJobCard::STATUS_APPROVED, $jobCard->status);
    }

    public function test_sending_a_quote_with_no_lines_is_rejected(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))
            ->assertSessionHasErrors('rental_job_card');
    }

    // ── Sign-off gates completion ─────────────────────────────────────────

    public function test_complete_refuses_without_both_sign_offs(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $this->expectException(\LogicException::class);
        $jobCard->complete($this->admin);
    }

    public function test_worker_and_agent_sign_off_then_complete_syncs_the_work_order(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'completion_requires_photo' => false]);
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $jobCard->workerSignOff($this->admin);
        $jobCard->agentSignOff($this->admin);

        // Through the SERVICE — this is the one place the work-order sync
        // lives (found missing, live Tinker verification: completing the
        // model alone never touched the work order, because that sync had
        // been written only inside the web controller — moved here so
        // every caller gets it, never duplicated in a controller).
        app(RentalJobCardService::class)->complete($jobCard, $this->admin);

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $jobCard->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $jobCard->workOrder->fresh()->status);
    }

    public function test_tenant_confirmation_is_not_required_to_complete(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        $jobCard->workerSignOff($this->admin);
        $jobCard->agentSignOff($this->admin);

        $jobCard->complete($this->admin); // no tenantConfirm() call at all

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $jobCard->fresh()->status);
    }

    // ── Archive / restore ─────────────────────────────────────────────────

    public function test_archive_and_restore(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);

        $this->actingAs($this->admin)->delete(route('corex.rental-job-cards.destroy', $jobCard))->assertRedirect();
        $this->assertSoftDeleted($jobCard);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.restore', $jobCard->id))->assertRedirect();
        $this->assertNotSoftDeleted($jobCard->fresh());
    }

    public function test_cannot_delete_once_it_has_history(): void
    {
        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, ['title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addTask($jobCard, 'Something', $this->admin);

        $this->actingAs($this->admin)->delete(route('corex.rental-job-cards.destroy', $jobCard))
            ->assertSessionHasErrors('rental_job_card');
        $this->assertNull($jobCard->fresh()->deleted_at);
    }

    // ── Scope guard — direct-URL access by ID is blocked ──────────────────

    public function test_cross_agency_job_card_is_not_reachable_by_id(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherJobCard = app(RentalJobCardService::class)->createForProperty($otherProperty, ['title' => 'Other job'], $otherAdmin);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $otherJobCard))->assertNotFound();
    }

    public function test_mobile_api_404s_on_a_job_card_outside_scope(): void
    {
        $otherAgency = Agency::create(['name' => 'Other2', 'slug' => 'other2-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property 2', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherJobCard = app(RentalJobCardService::class)->createForProperty($otherProperty, ['title' => 'Other job 2'], $otherAdmin);

        $this->actingAs($this->admin)->getJson('/api/v1/mobile/rental-job-cards/' . $otherJobCard->id)->assertNotFound();
    }

    // ── Full internal job, end to end (the verification scenario) ────────

    public function test_full_internal_job_catalogue_to_completion(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 200, 'capture_prices_on_job_cards' => true, 'completion_requires_photo' => false]);
        $item = $this->catalogueItem(['default_price' => 450]);

        $jobCard = app(RentalJobCardService::class)->createForProperty($this->property, [
            'title' => 'Geyser replacement', 'description' => 'Geyser burst, needs replacing.',
        ], $this->admin);
        $service = app(RentalJobCardService::class);
        $service->addLine($jobCard, ['rental_catalogue_item_id' => $item->id, 'quantity' => 1], $this->admin); // 450 > 200 threshold
        $service->addLine($jobCard, ['description' => 'Call-out labour', 'type' => 'labour', 'quantity' => 1, 'unit_price' => 300], $this->admin);

        $jobCard->refresh();
        $this->assertSame('750.00', (string) $jobCard->total_amount);

        app(\App\Services\Rentals\RentalDocumentPdfService::class);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();
        $jobCard->refresh();
        $this->assertSame(RentalJobCard::STATUS_QUOTED, $jobCard->status);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $jobCard->workOrder->owner_approval_status);

        $jobCard->workOrder->recordApproval($this->admin, [
            'decision' => 'approved', 'evidence_type' => 'whatsapp', 'evidence_text' => 'Go ahead, approved.',
        ]);
        $jobCard->syncStatusFromWorkOrder();
        $this->assertSame(RentalJobCard::STATUS_APPROVED, $jobCard->fresh()->status);

        $jobCard->workerSignOff($this->admin);
        $jobCard->agentSignOff($this->admin);
        $jobCard->tenantConfirm('All good, thanks', $this->admin);
        $service->complete($jobCard, $this->admin);

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $jobCard->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $jobCard->workOrder->fresh()->status);
        $this->assertNotNull($jobCard->fresh()->tenant_confirmed_at);
    }
}
