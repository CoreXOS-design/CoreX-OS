<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\AgencyServiceProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WS2 (AT-158 / DR2, D2) — the reusable agency supplier directory.
 *
 * Gate: add "the electrician we always use" once → reused (one click) on the
 * next deal; a directory provider attaches to a deal as a provider party (not a
 * contact); deactivating hides it from new pickers but preserves historic deal
 * references; preferred is exclusive per specialty and orders the picker.
 */
final class DealSupplierDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private AgencyServiceProviderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AgencyServiceProviderService::class);
    }

    public function test_find_or_create_reuses_the_same_provider_across_deals(): void
    {
        $agencyId = $this->agency();

        $a = $this->svc->findOrCreate($agencyId, ['name' => 'Sparky Electrical', 'specialty' => 'electrician', 'email' => 'sparky@x.co.za']);
        // Same specialty + same email → the SAME directory row (reused, not duplicated).
        $b = $this->svc->findOrCreate($agencyId, ['name' => 'Sparky (typed differently)', 'specialty' => 'electrician', 'email' => 'Sparky@X.co.za']);

        $this->assertSame($a->id, $b->id, 'reused the existing provider');
        $this->assertSame(1, AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->count());
    }

    public function test_attach_provider_to_a_deal_creates_a_provider_party_row(): void
    {
        $agencyId = $this->agency();
        $deal = $this->deal($agencyId);
        $provider = $this->svc->findOrCreate($agencyId, ['name' => 'Bug-B-Gone', 'specialty' => 'entomologist']);

        $this->svc->attachToDeal($deal, $provider, 'entomologist');
        $this->svc->attachToDeal($deal, $provider, 'entomologist'); // idempotent

        $this->assertDatabaseHas('deal_v2_contacts', [
            'deal_id' => $deal->id, 'agency_service_provider_id' => $provider->id,
            'contact_id' => null, 'role' => 'entomologist',
        ]);
        $this->assertSame(1, DB::table('deal_v2_contacts')->where('deal_id', $deal->id)
            ->where('agency_service_provider_id', $provider->id)->count(), 'no duplicate on re-attach');
        $this->assertTrue($deal->providerParties()->where('agency_service_providers.id', $provider->id)->exists());
    }

    public function test_deactivate_hides_from_pickers_but_preserves_historic_attachment(): void
    {
        $agencyId = $this->agency();
        $deal = $this->deal($agencyId);
        $provider = $this->svc->findOrCreate($agencyId, ['name' => 'Old Sparky', 'specialty' => 'electrician']);
        $this->svc->attachToDeal($deal, $provider, 'electrician_coc');

        $this->svc->deactivate($provider);

        // Hidden from a NEW picker…
        $this->assertTrue($this->svc->search($agencyId, 'electrician')->isEmpty(), 'deactivated provider hidden from search');
        // …but the historic deal reference still resolves (soft, not deleted).
        $this->assertTrue($deal->providerParties()->where('agency_service_providers.id', $provider->id)->exists(),
            'historic deal attachment still resolves the provider');
    }

    public function test_preferred_is_exclusive_per_specialty_and_orders_the_picker(): void
    {
        $agencyId = $this->agency();
        $first = $this->svc->findOrCreate($agencyId, ['name' => 'Amp Electric', 'specialty' => 'electrician', 'is_preferred' => true]);
        $second = $this->svc->findOrCreate($agencyId, ['name' => 'Volt Electric', 'specialty' => 'electrician']);

        $this->svc->markPreferred($second);

        $this->assertFalse($first->fresh()->is_preferred, 'previous preferred cleared');
        $this->assertTrue($second->fresh()->is_preferred);

        // Picker order: preferred first.
        $order = $this->svc->search($agencyId, 'electrician')->pluck('name')->all();
        $this->assertSame('Volt Electric', $order[0]);
    }

    public function test_search_filters_by_specialty_and_active_only(): void
    {
        $agencyId = $this->agency();
        $this->svc->findOrCreate($agencyId, ['name' => 'Sparky', 'specialty' => 'electrician']);
        $this->svc->findOrCreate($agencyId, ['name' => 'Bugs', 'specialty' => 'entomologist']);
        $inactive = $this->svc->findOrCreate($agencyId, ['name' => 'Dead Sparky', 'specialty' => 'electrician']);
        $this->svc->deactivate($inactive);

        $names = $this->svc->search($agencyId, 'electrician')->pluck('name')->all();
        $this->assertSame(['Sparky'], $names, 'only active electricians; entomologist + inactive excluded');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    private function agency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        return $agencyId;
    }

    private function deal(int $agencyId): DealV2
    {
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => 'x', 'address' => 'x',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));
        $template = DealPipelineTemplate::create([
            'name' => 'T', 'deal_type' => 'bond', 'agency_id' => $agencyId, 'branch_id' => null,
            'is_default' => true, 'is_active' => true, 'created_by_id' => $agent->id,
        ]);

        return DealV2::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'reference' => DealV2::generateReference(), 'deal_type' => 'bond',
            'status' => 'active', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_000_000,
            'commission_amount' => 50_000, 'commission_vat' => 7_500, 'offer_date' => '2026-03-01',
            'overall_rag' => 'grey', 'branch_id' => $agencyId, 'created_by_id' => $agent->id,
        ]);
    }
}
