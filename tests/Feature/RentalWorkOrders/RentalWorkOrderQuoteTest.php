<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 7 verification for .ai/specs/rental-work-orders.md §3.4c — Johan's
 * ruling: "agents will obtain quotes and thats the value that approval will
 * ride against." Proves the gate actually rides on the SELECTED quote's
 * amount against the property's threshold, never cost_amount.
 */
final class RentalWorkOrderQuoteTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'RWO Quote Agency', 'slug' => 'rwo-quote-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function workOrder(array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function supplier(): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => 'Acme Plumbing', 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    // ── The gate itself ─────────────────────────────────────────────

    public function test_selecting_a_quote_at_or_under_the_threshold_needs_no_approval(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote([
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 500, 'quote_date' => now(),
        ], $this->admin);

        $workOrder->selectQuote($quote, $this->admin);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->fresh()->owner_approval_status);
        $this->assertTrue($quote->fresh()->is_selected);
    }

    public function test_selecting_a_quote_over_the_threshold_requires_approval(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote([
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 500.01, 'quote_date' => now(),
        ], $this->admin);

        $workOrder->selectQuote($quote, $this->admin);

        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $workOrder->fresh()->owner_approval_status);
        // and the existing assignSupplier() gate refuses exactly as it already did:
        $this->expectException(\LogicException::class);
        $workOrder->fresh()->assignSupplier($this->supplier()->id, null, $this->admin);
    }

    public function test_the_gate_uses_the_propertys_own_override_over_the_agency_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $this->property->update(['rental_no_approval_spend_threshold' => 5000]);
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote([
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 4000, 'quote_date' => now(),
        ], $this->admin);

        $workOrder->selectQuote($quote, $this->admin);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->fresh()->owner_approval_status);
    }

    public function test_selecting_a_different_quote_unselects_the_previous_one(): void
    {
        $workOrder = $this->workOrder();
        $supplier = $this->supplier();
        $first = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $second = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 200, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($first, $this->admin);

        $workOrder->selectQuote($second, $this->admin);

        $this->assertFalse($first->fresh()->is_selected);
        $this->assertTrue($second->fresh()->is_selected);
    }

    public function test_editing_the_selected_quotes_amount_re_evaluates_the_gate(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 400, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->fresh()->owner_approval_status);

        $this->actingAs($this->admin)->put(route('corex.rental-work-orders.quotes.update', [$workOrder, $quote]), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 900, 'quote_date' => now()->format('Y-m-d'),
            'detail_text' => 'revised quote',
        ])->assertRedirect();

        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $workOrder->fresh()->owner_approval_status);
    }

    // ── Full CRUD / input-space ─────────────────────────────────────

    public function test_store_rejects_neither_document_nor_detail_text(): void
    {
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $workOrder), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d'),
        ])->assertSessionHasErrors('quote');

        $this->assertSame(0, $workOrder->quotes()->count());
    }

    public function test_store_accepts_detail_text_alone(): void
    {
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $workOrder), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d'),
            'detail_text' => 'Quoted verbally, R100 for the tap washer.',
        ])->assertRedirect();

        $this->assertSame(1, $workOrder->quotes()->count());
    }

    public function test_store_accepts_a_document_alone(): void
    {
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $workOrder), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d'),
            'document' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $quote = $workOrder->quotes()->first();
        $this->assertNotNull($quote->document_storage_path);
        Storage::disk('local')->assertExists($quote->document_storage_path);
    }

    public function test_store_can_select_immediately(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $workOrder = $this->workOrder();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $workOrder), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d'),
            'detail_text' => 'x', 'is_selected' => '1',
        ])->assertRedirect();

        $this->assertTrue($workOrder->quotes()->first()->is_selected);
    }

    public function test_archiving_the_selected_quote_unselects_it_but_leaves_approval_status_alone(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 500]);
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);

        $workOrder->archiveQuote($quote, $this->admin);

        $this->assertSoftDeleted($quote);
        $this->assertFalse($quote->fresh()->is_selected);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->fresh()->owner_approval_status);
    }

    public function test_restoring_an_archived_quote_brings_it_back(): void
    {
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $workOrder->archiveQuote($quote, $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.restore', [$workOrder, $quote->id]))
            ->assertRedirect();

        $this->assertNotSoftDeleted($quote);
    }

    public function test_archive_and_select_are_folded_into_work_order_history(): void
    {
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);
        $workOrder->archiveQuote($quote, $this->admin);

        $actions = $workOrder->fresh()->history()->pluck('action')->all();

        $this->assertContains('Quote captured', $actions);
        $this->assertContains('Quote selected', $actions);
        $this->assertContains('Quote archived', $actions);
    }

    // ── Scoping ──────────────────────────────────────────────────────

    public function test_a_quote_from_one_work_order_404s_through_another_work_orders_url(): void
    {
        $workOrderA = $this->workOrder(['title' => 'A']);
        $workOrderB = $this->workOrder(['title' => 'B']);
        $quote = $workOrderA->recordQuote(['agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()], $this->admin);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.select', [$workOrderB, $quote]))
            ->assertNotFound();
    }

    public function test_download_404s_when_no_document_was_attached(): void
    {
        $workOrder = $this->workOrder();
        $quote = $workOrder->recordQuote([
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now(), 'detail_text' => 'x',
        ], $this->admin);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.quotes.download', [$workOrder, $quote]))
            ->assertNotFound();
    }

    public function test_download_serves_the_attached_document_for_a_scoped_user(): void
    {
        $workOrder = $this->workOrder();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $workOrder), [
            'agency_service_provider_id' => $this->supplier()->id, 'amount' => 100, 'quote_date' => now()->format('Y-m-d'),
            'document' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ]);
        $quote = $workOrder->quotes()->first();

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.quotes.download', [$workOrder, $quote]))
            ->assertOk();
    }

    // ── PDF output ───────────────────────────────────────────────────

    public function test_quotes_appear_on_the_work_order_pdf(): void
    {
        $workOrder = $this->workOrder();
        $supplier = $this->supplier();
        $quote = $workOrder->recordQuote([
            'agency_service_provider_id' => $supplier->id, 'amount' => 1234.56, 'quote_date' => now(),
            'detail_text' => 'Replace burst geyser element',
        ], $this->admin);
        $workOrder->selectQuote($quote, $this->admin);

        $html = view('corex.rental-work-orders.pdf', [
            'workOrder' => $workOrder->fresh()->load(['property', 'lease.tenants.contact', 'supplier', 'agency', 'branch', 'quotes.supplier']),
            'logo' => null,
            'agencyName' => $this->agency->name,
        ])->render();

        $this->assertStringContainsString('Acme Plumbing', $html);
        $this->assertStringContainsString('1,234.56', $html);
        $this->assertStringContainsString('Selected', $html);
        $this->assertStringContainsString('Replace burst geyser element', $html);
    }

    public function test_no_quotes_section_when_none_captured(): void
    {
        $workOrder = $this->workOrder();

        $html = view('corex.rental-work-orders.pdf', [
            'workOrder' => $workOrder->fresh()->load(['property', 'lease.tenants.contact', 'supplier', 'agency', 'branch', 'quotes.supplier']),
            'logo' => null,
            'agencyName' => $this->agency->name,
        ])->render();

        $this->assertStringNotContainsString('<h2>Quotes</h2>', $html);
    }
}
