<?php

namespace Tests\Feature\Proforma;

use App\Mail\Proforma\ProformaInvoiceMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\Proforma\ProformaInvoice;
use App\Models\Proforma\ProformaInvoiceLine;
use App\Models\Scopes\AgencyScope;
use App\Models\SplitterDocType;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use App\Services\Proforma\ProformaAdminService;
use App\Services\Proforma\ProformaGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProformaInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** Build an agency + granted deal with a seller + property. */
    private function makeDeal(array $dealOverrides = [], bool $vatRegistered = true): array
    {
        $agency = Agency::create([
            'name' => 'HFC', 'slug' => 'hfc-' . uniqid(),
            'trading_name' => 'Home Finders Coastal', 'vat_no' => $vatRegistered ? '4870264498' : null,
            'vat_registered' => $vatRegistered,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Sea-view home', 'address' => '12 Marine Drive, Shelly Beach',
            'suburb' => 'Shelly Beach', 'agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]));
        $seller = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Annelise', 'last_name' => 'van der Merwe',
            'email' => 'seller' . Str::random(4) . '@example.co.za', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]));
        $property->contacts()->attach($seller->id, ['role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create(array_merge([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'listing_agent_id' => $agent->id, 'seller_name' => 'Annelise van der Merwe', 'property_address' => '12 Marine Drive',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
            'accepted_status' => 'G',   // granted
        ], $dealOverrides)));

        return compact('agency', 'branch', 'agent', 'property', 'seller', 'deal');
    }

    private function service(): ProformaGenerationService
    {
        return app(ProformaGenerationService::class);
    }

    public function test_granted_deal_generates_with_deal_truth_commission_and_vat(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();

        $invoice = $this->service()->generate($deal, $agent);

        $pools = DealMoneyLineRebuilder::computeDealPools($deal);
        $excl  = round((float) $pools['listingPool'] + (float) $pools['sellingPool'], 2);

        $this->assertSame(ProformaInvoice::STATUS_ISSUED, $invoice->status);
        $commission = $invoice->lines->firstWhere('kind', 'commission');
        $this->assertNotNull($commission);
        $this->assertTrue($commission->is_locked, 'commission line must be locked');
        $this->assertEqualsWithDelta($excl, (float) $commission->amount_excl, 0.01);
        $this->assertEqualsWithDelta(round($excl * 0.15, 2), (float) $commission->vat_amount, 0.01, 'VAT-registered → 15% split');
        $this->assertEqualsWithDelta($excl * 1.15, (float) $invoice->total_incl, 0.02);
        $this->assertSame('Annelise van der Merwe', $invoice->issued_to_name);
    }

    public function test_non_vat_registered_agency_shows_no_vat(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal(vatRegistered: false);

        $invoice = $this->service()->generate($deal, $agent);
        $commission = $invoice->lines->firstWhere('kind', 'commission');

        $this->assertFalse((bool) $invoice->vat_registered);
        $this->assertEqualsWithDelta(0.0, (float) $commission->vat_amount, 0.001, 'not registered → no VAT');
        $this->assertEqualsWithDelta((float) $commission->amount_excl, (float) $invoice->total_incl, 0.01);
    }

    public function test_pending_and_declined_deals_are_not_eligible(): void
    {
        ['deal' => $pending, 'agent' => $agent] = $this->makeDeal(['accepted_status' => 'P']);
        $this->expectException(\DomainException::class);
        $this->service()->generate($pending, $agent);
    }

    public function test_declined_deal_generation_refused_over_http(): void
    {
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal(['accepted_status' => 'D']);

        $this->actingAs($agent)
            ->post(route('deals-dr2.proforma.generate', $deal))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, ProformaInvoice::withoutGlobalScopes()->where('deal_id', $deal->id)->count());
    }

    public function test_sequence_is_consecutive_and_a_void_never_reuses_a_number(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();

        // One active per deal → each new one needs the prior voided first (void → generate).
        $a = $this->service()->generate($deal, $agent);
        app(ProformaAdminService::class)->void($a, $agent, 'redo');
        $b = $this->service()->generate($deal, $agent);
        $this->assertSame($a->sequence_no + 1, $b->sequence_no, 'consecutive sequence');
        $this->assertNotSame($a->number, $b->number);

        // Void b, then generate c — c must NOT reuse b's number.
        app(ProformaAdminService::class)->void($b, $agent, 'test void');
        $c = $this->service()->generate($deal, $agent);
        $this->assertSame($b->sequence_no + 1, $c->sequence_no, 'void does not free the number');
        $this->assertNotSame($b->number, $c->number);
    }

    public function test_start_number_is_honoured(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent, 'agency' => $agency] = $this->makeDeal();
        DB::table('agency_proforma_settings')->updateOrInsert(
            ['agency_id' => $agency->id],
            ['number_prefix' => 'INV-', 'next_number' => 500, 'number_padding' => 5, 'due_date_rule' => 'end_of_month', 'due_days' => 30, 'created_at' => now(), 'updated_at' => now()]
        );

        $invoice = $this->service()->generate($deal, $agent);
        $this->assertSame(500, $invoice->sequence_no);
        $this->assertSame('INV-00500', $invoice->number);
    }

    public function test_admin_can_add_adjustment_line_but_commission_line_is_protected(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();
        $invoice = $this->service()->generate($deal, $agent);
        $admin   = app(ProformaAdminService::class);

        $before = (float) $invoice->total_incl;
        $admin->addLine($invoice->fresh(), $agent, 'Discount on commission', -1000.00);
        $invoice->refresh();
        $this->assertEqualsWithDelta($before - 1150.00, (float) $invoice->total_incl, 0.02, 'discount excl -1000 + VAT -150');

        // The locked commission line cannot be removed.
        $commission = $invoice->lines()->where('kind', 'commission')->first();
        $this->expectException(\DomainException::class);
        $admin->removeLine($invoice, $commission, $agent);
    }

    public function test_generation_files_pdf_as_proforma_type_and_emails_seller(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();

        $invoice = $this->service()->generate($deal, $agent);

        // Filed as a Document of the proforma_invoice type, reachable from the deal.
        $typeId = SplitterDocType::withoutGlobalScopes()->where('slug', 'proforma_invoice')->value('id');
        $this->assertNotNull($typeId, 'proforma_invoice document type registered by migration');
        $this->assertNotNull($invoice->fresh()->document_id, 'PDF filed onto the deal');
        $this->assertDatabaseHas('documents', ['id' => $invoice->fresh()->document_id, 'document_type_id' => $typeId]);

        // Emailed to the seller (Mailpit in qa1).
        Mail::assertSent(ProformaInvoiceMail::class);

        // Audit + record kept on void (no hard delete).
        $this->assertDatabaseHas('proforma_invoice_audit', ['proforma_invoice_id' => $invoice->id, 'event' => 'generated']);
        app(ProformaAdminService::class)->void($invoice, $agent, 'kept');
        $this->assertDatabaseHas('proforma_invoices', ['id' => $invoice->id, 'status' => 'voided']);
    }

    public function test_only_one_active_proforma_per_deal_until_voided(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();

        $first = $this->service()->generate($deal, $agent);
        $this->assertTrue($this->service()->hasActiveProforma($deal));

        // Second attempt blocked at the SERVICE layer (a race/API call must also refuse).
        try {
            $this->service()->generate($deal, $agent);
            $this->fail('a second active proforma should be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('active proforma', $e->getMessage());
        }
        $this->assertSame(1, ProformaInvoice::withoutGlobalScopes()->where('deal_id', $deal->id)->count());

        // The only path to a new one: admin void → generate succeeds with the NEXT number.
        app(ProformaAdminService::class)->void($first, $agent, 'redo');
        $this->assertFalse($this->service()->hasActiveProforma($deal));
        $second = $this->service()->generate($deal, $agent);
        $this->assertSame($first->sequence_no + 1, $second->sequence_no, 'new number, never reused');
        $this->assertSame('voided', $first->fresh()->status);
        $this->assertSame('issued', $second->status);
    }

    public function test_generate_endpoint_refuses_a_second_proforma(): void
    {
        Storage::fake('local');
        Mail::fake();
        ['deal' => $deal, 'agent' => $agent] = $this->makeDeal();
        $this->service()->generate($deal, $agent);

        // HTTP layer: the endpoint re-checks and flashes an error, minting nothing new.
        $this->actingAs($agent)
            ->post(route('deals-dr2.proforma.generate', $deal))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(1, ProformaInvoice::withoutGlobalScopes()->where('deal_id', $deal->id)->count());
    }
}
