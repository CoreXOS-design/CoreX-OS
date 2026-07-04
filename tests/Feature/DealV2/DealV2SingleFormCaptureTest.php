<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Contact;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-R2 — the DR1-style single-page capture form writes through the SAME
 * store() shared write-path as the wizard, supports MULTIPLE agents on BOTH
 * sides, and links parties as contacts. Also asserts the default create route
 * serves the single form and the wizard lives at its own route.
 */
final class DealV2SingleFormCaptureTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private array $agents;
    private Property $property;
    private Contact $seller;
    private Contact $buyer;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_admin' => true, 'is_active' => true,
        ]);
        $this->agents = [
            User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]),
            User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]),
            User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]),
        ];

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '12 Marine Drive, Margate', 'address' => '12 Marine Drive, Margate',
            'agent_id' => $this->agents[0]->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        $this->seller = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0821112222']);
        $this->buyer = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer', 'phone' => '0823334444']);

        app(DealPipelineTemplateProvisioner::class)->provisionDefaultsForAgency($this->agencyId, $this->admin->id);
        $this->template = DealPipelineTemplate::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->where('deal_type', 'bond')->first();
    }

    private function formPayload(array $overrides = []): array
    {
        return array_merge([
            'property_id' => $this->property->id,
            'deal_type' => 'bond',
            'pipeline_template_id' => $this->template->id,
            'purchase_price' => 1_950_000,
            'total_commission_inc_vat' => 115_000, // ex 100k + 15k VAT @ 15%
            'commission_percentage' => 7.5,
            'offer_date' => '2026-03-01',
            'listing_split_percent' => 60,
            'selling_split_percent' => 40,
            // MULTI-agent on BOTH sides — the DR1 rule the wizard wrongly capped
            'listing_agents' => [(string) $this->agents[0]->id, (string) $this->agents[1]->id],
            'selling_agents' => [(string) $this->agents[2]->id],
            'contacts' => [
                ['contact_id' => $this->seller->id, 'role' => 'seller'],
                ['contact_id' => $this->buyer->id, 'role' => 'buyer'],
            ],
        ], $overrides);
    }

    public function test_single_form_creates_deal_with_multi_agent_both_sides_and_contacts(): void
    {
        $resp = $this->actingAs($this->admin)->post(route('deals-v2.store'), $this->formPayload());

        $deal = DealV2::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($deal, 'deal created');
        $resp->assertRedirect(route('deals-v2.show', $deal));

        // Multi-agent both sides — 2 listing, 1 selling
        $listing = $deal->agents->filter(fn ($a) => $a->pivot->side === 'listing');
        $selling = $deal->agents->filter(fn ($a) => $a->pivot->side === 'selling');
        $this->assertCount(2, $listing, 'two listing agents attached');
        $this->assertCount(1, $selling, 'one selling agent attached');

        // Contacts linked with roles
        $this->assertCount(2, $deal->contacts);
        $this->assertTrue($deal->contacts->contains($this->seller->id));
        $this->assertTrue($deal->contacts->contains($this->buyer->id));

        // Commission computed ex-VAT from inc-VAT
        $this->assertEqualsWithDelta(100_000, (float) $deal->commission_amount, 0.5);
        $this->assertEqualsWithDelta(15_000, (float) $deal->commission_vat, 0.5);
        $this->assertSame(60.0, (float) $deal->listing_split_percent);

        // Pipeline attached as overlay (steps materialised from the template)
        $this->assertGreaterThan(0, $deal->stepInstances()->count());
    }

    public function test_lazy_path_without_contacts_still_creates_the_deal(): void
    {
        // DR1 lazy-but-valid: contacts are not a hard gate.
        $payload = $this->formPayload(['contacts' => []]);
        $resp = $this->actingAs($this->admin)->post(route('deals-v2.store'), $payload);

        $deal = DealV2::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($deal);
        $resp->assertRedirect(route('deals-v2.show', $deal));
        $this->assertCount(0, $deal->contacts);
    }

    public function test_default_create_route_serves_the_single_page_form(): void
    {
        $resp = $this->actingAs($this->admin)->get(route('deals-v2.create'));
        $resp->assertOk();
        $resp->assertSee('Prefer a guided wizard');   // single-form-only cross-link
        $resp->assertSee('Side Splits &amp; Agents', false);
    }

    public function test_wizard_route_still_serves_the_wizard(): void
    {
        $resp = $this->actingAs($this->admin)->get(route('deals-v2.create-wizard'));
        $resp->assertOk();
        $resp->assertSee('dealWizard');   // the Alpine wizard component
    }
}
