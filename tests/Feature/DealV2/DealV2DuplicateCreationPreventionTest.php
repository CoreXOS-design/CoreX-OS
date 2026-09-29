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
 * Duplicate-deal fix, 2026-09-29 — same guarantees as
 * Tests\Feature\Dr2\Dr2DuplicateCreationPreventionTest, for the DealV2
 * pipeline's own create path (deals-v2/create-form.blade.php and the wizard
 * both write through DealPipelineService::createDeal()).
 */
final class DealV2DuplicateCreationPreventionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private array $agents;
    private Property $property;
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
        ];

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '12 Marine Drive, Margate', 'address' => '12 Marine Drive, Margate',
            'agent_id' => $this->agents[0]->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

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
            'total_commission_inc_vat' => 115_000,
            'commission_percentage' => 7.5,
            'offer_date' => '2026-03-01',
            'listing_split_percent' => 60,
            'selling_split_percent' => 40,
            'listing_agents' => [(string) $this->agents[0]->id],
            'selling_agents' => [(string) $this->agents[0]->id],
        ], $overrides);
    }

    public function test_the_same_create_token_submitted_twice_yields_exactly_one_deal(): void
    {
        $token = (string) Str::uuid();
        $before = DealV2::withoutGlobalScopes()->count();

        $first = $this->actingAs($this->admin)->post(route('deals-v2.store'), $this->formPayload(['create_token' => $token]));
        $first->assertRedirect();

        $second = $this->actingAs($this->admin)->post(route('deals-v2.store'), $this->formPayload(['create_token' => $token]));
        $second->assertRedirect();

        $this->assertSame($before + 1, DealV2::withoutGlobalScopes()->count(), 'a resubmitted token must never create a second deal');

        $deal = DealV2::withoutGlobalScopes()->where('create_token', $token)->first();
        $this->assertNotNull($deal);
        // Both responses land on the SAME deal's show page — proof the second
        // POST was recognised as a resubmission, not a fresh create.
        $first->assertRedirect(route('deals-v2.show', $deal));
        $second->assertRedirect(route('deals-v2.show', $deal));
    }

    public function test_two_different_submissions_get_distinct_consecutive_references(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.store'), $this->formPayload(['create_token' => (string) Str::uuid()]))
            ->assertRedirect();
        $this->actingAs($this->admin)->post(route('deals-v2.store'), $this->formPayload(['create_token' => (string) Str::uuid()]))
            ->assertRedirect();

        $deals = DealV2::withoutGlobalScopes()->where('agency_id', $this->agencyId)->orderBy('id')->get();
        $this->assertCount(2, $deals);

        $year = now()->format('Y');
        $prefix = "DL-{$year}-";
        $this->assertStringStartsWith($prefix, $deals[0]->reference);
        $this->assertStringStartsWith($prefix, $deals[1]->reference);

        $seqA = (int) substr($deals[0]->reference, strlen($prefix));
        $seqB = (int) substr($deals[1]->reference, strlen($prefix));
        $this->assertSame($seqA + 1, $seqB, 'consecutive, not colliding');
    }

    public function test_duplicate_create_token_is_rejected_at_the_db_layer_even_bypassing_the_app_check(): void
    {
        $token = (string) Str::uuid();

        DealV2::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'reference' => 'DL-2026-90001', 'create_token' => $token, 'deal_type' => 'bond', 'status' => 'active',
            'property_id' => $this->property->id, 'listing_agent_id' => $this->agents[0]->id,
            'pipeline_template_id' => $this->template->id, 'purchase_price' => 100,
            'commission_amount' => 10, 'commission_vat' => 1.5, 'commission_status' => 'Not Paid',
            'offer_date' => '2026-03-01', 'overall_rag' => 'grey', 'created_by_id' => $this->admin->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DealV2::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'reference' => 'DL-2026-90002', 'create_token' => $token, 'deal_type' => 'bond', 'status' => 'active',
            'property_id' => $this->property->id, 'listing_agent_id' => $this->agents[0]->id,
            'pipeline_template_id' => $this->template->id, 'purchase_price' => 200,
            'commission_amount' => 20, 'commission_vat' => 3.0, 'commission_status' => 'Not Paid',
            'offer_date' => '2026-03-01', 'overall_rag' => 'grey', 'created_by_id' => $this->admin->id,
        ]);
    }
}
