<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 docs (AT-226 lane) — the backend 3-pillar filing proof for cc4's split:
 * a document uploaded on the canonical DR2 deal view (the DR1 twin on `deals`)
 * files itself to the deal (via the deals_v2 twin), its property, and the
 * property's contacts — the bridge from the DR1 deal to the deals_v2 doc engine.
 */
class DealDocumentBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_on_the_dr1_deal_files_to_deal_property_and_contacts(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
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

        // The deals_v2 twin (documents.deal_id FKs here).
        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The canonical DR2 deal — the DR1-faithful twin on `deals`, pointing at deals_v2.
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
        ]));

        $doc = app(DealDocumentService::class)->fileDealDocumentFromDeal($deal, [
            'original_name' => 'offer-to-purchase.pdf', 'storage_path' => 'deal-docs/otp.pdf', 'source_type' => 'deal',
        ], $agent);

        // Pillar 1 — the deal (via the deals_v2 twin) + reachable from the DR1 deal by source.
        $this->assertSame((int) $twinId, (int) $doc->deal_id, 'filed to the deals_v2 twin');
        $this->assertSame('deal', $doc->source_type);
        $this->assertSame((int) $deal->id, (int) $doc->source_id, 'reachable from the DR1 deal');
        $this->assertSame((int) $agency->id, (int) $doc->agency_id, 'agency stamped (no landmine)');

        // Pillar 2 — the property.
        $this->assertTrue($doc->properties()->where('properties.id', $property->id)->exists(), 'reachable from the property');

        // Pillar 3 — the property's contacts (the seller).
        $this->assertTrue($doc->contacts()->where('contacts.id', $seller->id)->exists(), 'reachable from the seller contact');
    }

    public function test_per_step_attach_records_on_deal_step_documents_with_agency(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'agent_id' => $agent->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]));
        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_000_000, 'commission_amount' => 50_000, 'commission_vat' => 7_500,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $agent->id, 'property_id' => $property->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_000_000, 'total_commission' => 57_500,
            'reference' => 'REG-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'deal_v2_id' => $twinId,
        ]));
        // A real pipeline step (via the tested provisioner) + a step instance on the twin.
        app(\App\Services\DealV2\DealPipelineTemplateProvisioner::class)->provisionDefaultsForAgency($agency->id, $agent->id);
        $templateStepId = \App\Models\DealV2\DealPipelineStep::withoutGlobalScopes()
            ->where('agency_id', $agency->id)->value('id');
        $stepId = DB::table('deal_step_instances')->insertGetId([
            'deal_id' => $twinId, 'agency_id' => $agency->id, 'pipeline_step_id' => $templateStepId,
            'name' => 'Gas COC', 'trigger_type' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $doc = app(DealDocumentService::class)->fileDealDocumentFromDeal($deal, [
            'original_name' => 'gas-coc.pdf', 'storage_path' => 'deal-docs/gas.pdf', 'pipeline_step_id' => $stepId,
        ], $agent);

        $this->assertDatabaseHas('deal_step_documents', [
            'deal_step_instance_id' => $stepId,
            'document_id'           => $doc->id,
            'agency_id'             => $agency->id,   // NOT-NULL stamped (AT-203)
        ]);
    }
}
