<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
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
use Tests\TestCase;

/**
 * Job card rebuild, 2026-10-05 — Johan rejected the old two-screen, flat-
 * Tasks design after testing it on QA1. Proves: tasks own their own
 * lines (and a General group for task-less lines); per-task and grand
 * totals, across both VAT modes; a job card never creates a stray work
 * order any more (RentalJobCardService::createStandalone()); linking to
 * an EXISTING work order/fault report works and never duplicates it;
 * pre-seeded draft tasks from a fault report/work order/inspection
 * follow-up; agency isolation on the two new source FKs.
 */
final class RentalJobCardRebuildTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Rebuild Agency', 'slug' => 'rebuild-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Scottburgh', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '7 Lighthouse Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Pieter', 'last_name' => 'Landlord', 'email' => 'pieter.landlord-' . uniqid() . '@example.test',
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
    }

    private function catalogueItem(array $attrs = []): RentalCatalogueItem
    {
        return RentalCatalogueItem::create(array_merge([
            'agency_id' => $this->agency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $this->agency->id)->where('kind', 'part')->firstOrFail()->id,
            'code' => 'PAINT-5L', 'description' => 'Paint, 5L', 'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $this->agency->id)->where('name', 'Each')->firstOrFail()->id,
            'default_price' => 750, 'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function faultReport(array $attrs = []): RentalFaultReport
    {
        return RentalFaultReport::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Lounge wall damaged', 'description' => 'Damp patch on lounge wall.', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function existingWorkOrder(array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Fix floor tiles', 'description' => 'Lifted tiles in kitchen.', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    // ── Tasks own their own lines; a task-less line sits in General ─────

    public function test_a_task_owns_its_own_lines_and_a_task_less_line_sits_in_general(): void
    {
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Multi-task job'], $this->admin);
        $service = app(RentalJobCardService::class);
        $task1 = $service->addTask($jobCard, 'Paint lounge', $this->admin);
        $task2 = $service->addTask($jobCard, 'Fix floor', $this->admin);

        $service->addLine($jobCard, ['description' => 'Paint material', 'quantity' => 1], $this->admin, $task1);
        $service->addLine($jobCard, ['description' => 'Prep walls', 'quantity' => 1], $this->admin, $task1);
        $service->addLine($jobCard, ['description' => 'Lift tiles', 'quantity' => 1], $this->admin, $task2);
        $service->addLine($jobCard, ['description' => 'Call-out fee', 'quantity' => 1], $this->admin, null);

        $jobCard->refresh();
        $this->assertCount(2, $task1->lines()->get());
        $this->assertCount(1, $task2->lines()->get());
        $this->assertCount(1, $jobCard->generalLines()->get());
        $this->assertSame('Call-out fee', $jobCard->generalLines()->first()->description);
        // A line under one task never leaks into another task's own set.
        $this->assertFalse($task2->lines()->get()->contains('description', 'Paint material'));
    }

    // ── Totals per task + grand total, across VAT modes ──────────────────

    public function test_per_task_and_grand_totals_with_prices_on_no_vat(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);
        $service = app(RentalJobCardService::class);
        $task1 = $service->addTask($jobCard, 'Paint lounge', $this->admin);
        $task2 = $service->addTask($jobCard, 'Fix floor', $this->admin);

        $service->addLine($jobCard, ['description' => 'Paint', 'quantity' => 2, 'unit_price' => 750], $this->admin, $task1); // 1500
        $service->addLine($jobCard, ['description' => 'Prep', 'quantity' => 1, 'unit_price' => 450], $this->admin, $task1); // 450
        $service->addLine($jobCard, ['description' => 'Tiles', 'quantity' => 1, 'unit_price' => 1250], $this->admin, $task2); // 1250
        $service->addLine($jobCard, ['description' => 'Call-out', 'quantity' => 1, 'unit_price' => 200], $this->admin, null); // 200 — General

        $jobCard->refresh();
        $this->assertSame(1950.0, $task1->subtotal());
        $this->assertSame(1250.0, $task2->subtotal());
        $this->assertSame('3400.00', (string) $jobCard->total_amount); // 1950 + 1250 + 200, grand total spans every task + General
    }

    public function test_per_task_and_grand_totals_with_vat_registered_agency(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => \App\Models\Agency::VAT_CAPTURE_EXCL]);
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        \App\Models\RentalVatType::seedDefaultsFor($this->agency->id);
        $vatType = \App\Models\RentalVatType::defaultFor($this->agency->id);

        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'VAT job'], $this->admin);
        $service = app(RentalJobCardService::class);
        $task = $service->addTask($jobCard, 'Paint lounge', $this->admin);
        $service->addLine($jobCard, ['description' => 'Paint', 'quantity' => 1, 'unit_price' => 1000, 'rental_vat_type_id' => $vatType?->id], $this->admin, $task);

        $jobCard->refresh();
        $breakdown = app(\App\Services\Rentals\RentalJobCardVatService::class)->breakdown($jobCard);
        $this->assertTrue($breakdown['registered']);
        $this->assertSame(1000.0, $breakdown['subtotalExcl']);
        $this->assertGreaterThan(0, $breakdown['totalVat']);
        $this->assertSame(round($breakdown['subtotalExcl'] + $breakdown['totalVat'], 2), $breakdown['totalIncl']);
        // The task's own subtotal is the plain (VAT-exclusive, pre-breakdown) line_total sum — unaffected by the agency's VAT display.
        $this->assertSame(1000.0, $task->subtotal());
    }

    // ── Migration of existing cards — a task-less line is General, by construction ──

    public function test_a_line_with_no_task_id_set_reads_as_general_matching_the_migrated_shape(): void
    {
        // Simulates a line that existed before the rebuild migration —
        // rental_job_card_task_id simply was never set (new nullable column).
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Pre-existing job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Legacy line', 'quantity' => 1], $this->admin);

        $line = $jobCard->lines()->first();
        $this->assertNull($line->rental_job_card_task_id);
        $this->assertTrue($jobCard->generalLines()->get()->contains($line));
    }

    // ── Work order up front (Build 3, §17.3.1) — supersedes "no stray work order" ──
    // The 2026-10-05 rule was "a job card never creates a work order"; Johan's 6 Oct 2026 ruling reverses it: there is ONE
    // creation form and no job card ever exists without its work order. What survives from the rebuild is the OTHER half
    // of the complaint — a card never creates a SECOND work order, and an existing one is linked, never duplicated.

    public function test_createStandalone_with_no_source_creates_its_work_order_up_front(): void
    {
        $before = RentalWorkOrder::count();

        $jobCard = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'title' => 'Garden service',
        ], $this->admin);

        $this->assertNotNull($jobCard->rental_work_order_id);
        $this->assertNull($jobCard->rental_fault_report_id);
        $this->assertTrue($jobCard->hasSource());
        $this->assertSame($before + 1, RentalWorkOrder::count());
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $jobCard->workOrder->assignment_type);
    }

    public function test_createStandalone_with_an_existing_work_order_links_it_and_creates_no_second_one(): void
    {
        $workOrder = $this->existingWorkOrder();
        $before = RentalWorkOrder::count();

        $jobCard = app(RentalJobCardService::class)->createStandalone([
            'rental_work_order_id' => $workOrder->id, 'title' => 'Job for existing work order',
        ], $this->admin);

        $this->assertSame($workOrder->id, $jobCard->rental_work_order_id);
        $this->assertSame($this->property->id, $jobCard->property_id);
        $this->assertSame($before, RentalWorkOrder::count()); // linked, never duplicated
    }

    public function test_createStandalone_from_a_fault_report_with_no_prior_work_order_raises_it_through_the_one_gate(): void
    {
        $faultReport = $this->faultReport();
        $before = RentalWorkOrder::count();

        $jobCard = app(RentalJobCardService::class)->createStandalone([
            'fault_report_id' => $faultReport->id, 'title' => $faultReport->title,
        ], $this->admin);

        $this->assertSame($faultReport->id, $jobCard->rental_fault_report_id);
        $this->assertNotNull($jobCard->rental_work_order_id);
        $this->assertSame($before + 1, RentalWorkOrder::count());
        $this->assertSame($jobCard->rental_work_order_id, $faultReport->fresh()->rental_work_order_id);
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $faultReport->fresh()->status);
    }

    public function test_http_store_with_no_source_creates_the_job_card_and_exactly_one_work_order(): void
    {
        $workOrdersBefore = RentalWorkOrder::count();
        $jobCardsBefore = RentalJobCard::count();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id,
            'title' => 'ZZ TEST garden service',
        ])->assertRedirect();

        $this->assertSame($jobCardsBefore + 1, RentalJobCard::count());
        $this->assertSame($workOrdersBefore + 1, RentalWorkOrder::count());
        $jobCard = RentalJobCard::firstWhere('title', 'ZZ TEST garden service');
        $this->assertNotNull($jobCard);
        $this->assertNotNull($jobCard->rental_work_order_id);
    }

    public function test_http_store_builds_tasks_and_their_own_lines_and_general_lines_in_one_transaction(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id,
            'title' => 'Paint and floor job',
            'tasks' => [
                ['description' => 'Paint lounge', 'lines' => [
                    ['description' => 'Paint material', 'quantity' => 1, 'unit_price' => 750],
                    ['description' => 'Prep walls', 'quantity' => 1, 'unit_price' => 450],
                ]],
                ['description' => 'Fix floor', 'lines' => [
                    ['description' => 'Lift tiles', 'quantity' => 1, 'unit_price' => 1250],
                ]],
            ],
            'general_lines' => [
                ['description' => 'Call-out fee', 'quantity' => 1, 'unit_price' => 200],
            ],
        ])->assertRedirect();

        $jobCard = RentalJobCard::firstWhere('title', 'Paint and floor job');
        $this->assertNotNull($jobCard);
        $this->assertCount(2, $jobCard->tasks);
        $this->assertSame('Paint lounge', $jobCard->tasks->first()->description);
        $this->assertCount(2, $jobCard->tasks->first()->lines);
        $this->assertCount(1, $jobCard->tasks->last()->lines);
        $this->assertCount(1, $jobCard->generalLines()->get());
        $this->assertSame('2650.00', (string) $jobCard->fresh()->total_amount); // 750+450+1250+200
    }

    public function test_an_empty_task_description_in_the_submitted_structure_is_dropped_not_a_500(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id,
            'title' => 'Job with a blank task row',
            'tasks' => [
                ['description' => '', 'lines' => []],
                ['description' => 'Real task', 'lines' => []],
            ],
        ])->assertRedirect();

        $jobCard = RentalJobCard::firstWhere('title', 'Job with a blank task row');
        $this->assertNotNull($jobCard);
        $this->assertCount(1, $jobCard->tasks);
        $this->assertSame('Real task', $jobCard->tasks->first()->description);
    }

    // ── sendToOwnerAsQuote() never creates ANOTHER work order (the card already has its own, §17.3.1) ──

    public function test_sending_a_quote_never_creates_a_second_work_order(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $jobCard = app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'Job'], $this->admin);
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Fix', 'quantity' => 1, 'unit_price' => 100], $this->admin);

        $before = RentalWorkOrder::count();
        $this->assertNotNull($jobCard->rental_work_order_id, 'the work order exists from the first save');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();

        $jobCard->refresh();
        $this->assertSame($before, RentalWorkOrder::count());

        // Sending a SECOND quote never creates yet another work order.
        app(RentalJobCardService::class)->addLine($jobCard, ['description' => 'Extra', 'quantity' => 1, 'unit_price' => 50], $this->admin);
        $jobCard->forceFill(['status' => RentalJobCard::STATUS_DRAFT])->save();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $jobCard))->assertRedirect();
        $this->assertSame($before, RentalWorkOrder::count());
    }

    public function test_a_legacy_card_with_no_work_order_still_gets_one_lazily_at_send_quote(): void
    {
        // The safety net (§17.3.1): rows created BEFORE "no card without its work order" may have none.
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true]);
        $legacy = RentalJobCard::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'title' => 'Legacy job', 'status' => RentalJobCard::STATUS_DRAFT, 'created_by_user_id' => $this->admin->id,
        ]);
        app(RentalJobCardService::class)->addLine($legacy, ['description' => 'Fix', 'quantity' => 1, 'unit_price' => 100], $this->admin);
        $before = RentalWorkOrder::count();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $legacy))->assertRedirect();

        $this->assertSame($before + 1, RentalWorkOrder::count());
        $this->assertNotNull($legacy->fresh()->rental_work_order_id);
    }

    // ── Photos work with no linked work order ────────────────────────────

    public function test_photos_can_be_stored_on_a_job_card_with_no_linked_work_order(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        // A legacy row (created before every card got its work order up front) — photos must still work on it.
        $jobCard = RentalJobCard::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'title' => 'Legacy job', 'status' => RentalJobCard::STATUS_DRAFT, 'created_by_user_id' => $this->admin->id,
        ]);
        $this->assertNull($jobCard->rental_work_order_id);

        $file = \Illuminate\Http\UploadedFile::fake()->image('before.jpg');
        $photo = app(RentalJobCardService::class)->storePhoto($jobCard, $file, 'reported', $this->admin);

        $this->assertSame($jobCard->id, $photo->rental_job_card_id);
        $this->assertNull($photo->rental_work_order_id);
        $this->assertCount(1, $jobCard->photos()->get());
    }

    // ── "New Job Card" is now an alias for the one creation form (Build 3, §17.3.1) ──

    public function test_the_create_url_with_a_fault_report_hands_over_to_the_fault_reports_own_form(): void
    {
        $faultReport = $this->faultReport(['title' => 'Geyser dripping']);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['fault_report_id' => $faultReport->id]))
            ->assertRedirect(route('corex.rental-fault-reports.show', $faultReport));
    }

    public function test_the_create_url_with_an_existing_work_order_goes_to_that_work_order(): void
    {
        $workOrder = $this->existingWorkOrder(['title' => 'Replace gutter']);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['rental_work_order_id' => $workOrder->id]))
            ->assertRedirect(route('corex.rental-work-orders.show', $workOrder));
    }

    public function test_the_create_url_with_no_source_opens_the_work_order_form_with_internal_preselected(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['property_id' => $this->property->id]))
            ->assertRedirect(route('corex.rental-work-orders.create', ['property_id' => $this->property->id, 'assignment_type' => 'internal']));
    }

    // ── Agency isolation on the two new source FKs ───────────────────────

    public function test_a_cross_agency_work_order_id_404s_rather_than_linking(): void
    {
        $otherAgency = Agency::create(['name' => 'Other RB', 'slug' => 'other-rb-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $otherAgency->id]);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAdmin->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other agency property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherWorkOrder = RentalWorkOrder::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $otherAdmin->id,
            'title' => 'Other work order', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $otherAdmin->id,
        ]);

        // Real request, real authenticated user — AgencyScope is what must
        // actually stop this, not a bare direct-service-call assumption.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'rental_work_order_id' => $otherWorkOrder->id, 'title' => 'Should never link cross-agency',
        ])->assertNotFound();

        $this->assertNull(RentalJobCard::firstWhere('title', 'Should never link cross-agency'));
    }

    public function test_a_cross_agency_catalogue_item_id_is_dropped_not_linked_on_create(): void
    {
        $otherAgency = Agency::create(['name' => 'Other RB2', 'slug' => 'other-rb2-' . uniqid()]);
        RentalCatalogueItemType::seedDefaultsFor($otherAgency->id);
        RentalCatalogueUnit::seedDefaultsFor($otherAgency->id);
        $otherItem = RentalCatalogueItem::create([
            'agency_id' => $otherAgency->id,
            'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $otherAgency->id)->where('kind', 'part')->firstOrFail()->id,
            'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $otherAgency->id)->where('name', 'Each')->firstOrFail()->id,
            'code' => 'OTHER-AG', 'description' => 'Other agency item', 'default_price' => 100, 'sort_order' => 1,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id,
            'title' => 'Cross-agency catalogue attempt',
            'general_lines' => [
                ['rental_catalogue_item_id' => $otherItem->id, 'description' => 'Should not resolve', 'quantity' => 1],
            ],
        ])->assertRedirect();

        $jobCard = RentalJobCard::firstWhere('title', 'Cross-agency catalogue attempt');
        $this->assertNotNull($jobCard);
        $line = $jobCard->generalLines()->first();
        $this->assertNotNull($line);
        $this->assertNull($line->rental_catalogue_item_id); // the cross-agency id was dropped, not linked
        $this->assertSame('Should not resolve', $line->description); // the free-text description survives
    }

    // ── The lease-wins guard still applies on the rebuilt store() ────────

    public function test_lease_wins_guard_still_applies_on_the_rebuilt_store(): void
    {
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => 'Another property entirely', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subDays(10),
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.store'), [
            'property_id' => $this->property->id, 'lease_id' => $otherLease->id, 'title' => 'ZZ TEST lease wins rebuild',
        ])->assertRedirect();

        $jobCard = RentalJobCard::firstWhere('title', 'ZZ TEST lease wins rebuild');
        $this->assertNotNull($jobCard);
        $this->assertSame($otherProperty->id, $jobCard->property_id);
        $this->assertSame($otherLease->id, $jobCard->lease_id);
    }
}
