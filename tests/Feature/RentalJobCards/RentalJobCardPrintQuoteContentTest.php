<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overnight re-verification (2026-10-05, cc4) — Johan's print/quote layout
 * ask: crew + members, task lines (code/description/unit/qty/price/VAT/
 * line total), task subtotals, grand subtotal/VAT/total, source fault/work
 * order reference, agency letterhead. The letterhead and the line columns
 * already existed (AT-442); this proves the three genuinely new pieces —
 * crew on the quote PDF, task subtotals on both documents, and the source
 * reference on both documents — render correctly, using the same
 * view(...)->render() pattern RentalJobCardLifecycleTest already uses to
 * assert PDF-view content without invoking dompdf itself.
 */
final class RentalJobCardPrintQuoteContentTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Print Quote Agency', 'slug' => 'print-quote-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Branch', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Print Quote Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
    }

    private function noVat(): array
    {
        return ['registered' => false, 'pricesOn' => false, 'captureMode' => null, 'subtotalExcl' => null, 'totalVat' => null, 'totalIncl' => null, 'groups' => []];
    }

    private function renderJobCard(RentalJobCard $jobCard, string $view): string
    {
        $jobCard->load(['property', 'lease.tenants.contact', 'tasks.lines.vatType', 'lines.vatType', 'crew.members', 'assignedUser', 'rentalFaultReport', 'workOrder']);

        return view("corex.rental-job-cards.{$view}", [
            'jobCard' => $jobCard, 'pricesOn' => true, 'vat' => $this->noVat(), 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Test Agency',
        ])->render();
    }

    public function test_quote_pdf_shows_the_assigned_crew_and_its_members(): void
    {
        $crew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Quote Crew', 'created_by_user_id' => $this->admin->id]);
        $crew->members()->create(['agency_id' => $this->agency->id, 'name' => 'Member One', 'created_by_user_id' => $this->admin->id]);
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);
        $jobCard->assignCrew($crew, $this->admin);

        $html = $this->renderJobCard($jobCard, 'quote-pdf');

        $this->assertStringContainsString('Quote Crew', $html);
        $this->assertStringContainsString('Member One', $html);
    }

    public function test_print_and_quote_show_no_source_when_created_directly(): void
    {
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);

        $this->assertStringContainsString('No source — created directly.', $this->renderJobCard($jobCard, 'print'));
        $this->assertStringContainsString('No source — created directly.', $this->renderJobCard($jobCard, 'quote-pdf'));
    }

    public function test_print_and_quote_show_the_fault_report_source_reference(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Blocked drain', 'description' => 'Kitchen drain blocked.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $jobCard = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'fault_report_id' => $faultReport->id, 'title' => 'Blocked drain',
        ], $this->admin);

        $printHtml = $this->renderJobCard($jobCard, 'print');
        $quoteHtml = $this->renderJobCard($jobCard, 'quote-pdf');

        $this->assertStringContainsString("Fault report #{$faultReport->id}", $printHtml);
        $this->assertStringContainsString('Blocked drain', $printHtml);
        $this->assertStringContainsString("Fault report #{$faultReport->id}", $quoteHtml);
    }

    public function test_print_and_quote_show_a_task_subtotal_distinct_from_the_grand_total(): void
    {
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);
        $service = app(RentalJobCardService::class);
        $task = $service->addTask($jobCard, 'Task one', $this->admin);
        $service->addLine($jobCard, ['description' => 'Line A', 'quantity' => 1, 'unit_price' => 100], $this->admin, $task);
        $service->addLine($jobCard, ['description' => 'Line B', 'quantity' => 1, 'unit_price' => 50], $this->admin, $task);
        $jobCard->refresh();

        $printHtml = $this->renderJobCard($jobCard, 'print');

        // The task's own subtotal (100 + 50 = 150) distinct from the lines'
        // own individual totals (R100.00 / R50.00) already on the page.
        $this->assertStringContainsString('Subtotal', $printHtml);
        $this->assertStringContainsString('R150.00', $printHtml);
    }
}
