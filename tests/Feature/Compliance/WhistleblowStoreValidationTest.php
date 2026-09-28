<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bug fixed 2026-09-28: filing a Tier 1 report with a missing/short seller
 * statement (or a Tier 2/3 report with no evidence) threw a bare
 * InvalidArgumentException out of WhistleblowComplaintService::submit() ->
 * validateTierRequirements(), which the controller never caught — a 500 page
 * that also lost the agent's typed input. See
 * .ai/specs/whistleblower-compliance-spec.md.
 */
final class WhistleblowStoreValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tier1_short_seller_statement_returns_field_error_not_500(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();

        $response = $this->actingAs($user)->post(route('compliance.whistleblow.store'), [
            'tier'                       => 'tier_1',
            'property_address'           => '1 QA Test Street, Test Suburb',
            'seller_statement'           => 'too short',
            'agent_notes'                => 'QA regression check — must not 500.',
            'subjects'                   => [
                ['agency_name' => 'Rival Realty', 'portal_url' => 'https://example.com/listing/1', 'portal_source' => 'p24'],
            ],
            '_idempotency_token'         => (string) Str::uuid(),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('seller_statement');
        $response->assertSessionHasInput('property_address', '1 QA Test Street, Test Suburb');
        $response->assertSessionHasInput('agent_notes', 'QA regression check — must not 500.');

        $this->assertSame(
            0,
            WhistleblowComplaint::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->count(),
            'a failed validation must not leave an orphaned draft complaint behind'
        );
    }

    public function test_tier3_missing_evidence_returns_field_error_not_500(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();

        $response = $this->actingAs($user)->post(route('compliance.whistleblow.store'), [
            'tier'               => 'tier_3',
            'property_address'   => '2 QA Test Street, Test Suburb',
            'subjects'           => [
                ['agency_name' => 'Unregistered Co', 'portal_url' => 'https://example.com/listing/2', 'portal_source' => 'pp'],
            ],
            '_idempotency_token' => (string) Str::uuid(),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('evidence_files');

        $this->assertSame(
            0,
            WhistleblowComplaint::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->count()
        );
    }

    public function test_tier1_valid_seller_statement_saves(): void
    {
        [, $user] = $this->seedAgencyAndUser();

        $response = $this->actingAs($user)->post(route('compliance.whistleblow.store'), [
            'tier'                       => 'tier_1',
            'property_address'           => '3 QA Test Street, Test Suburb',
            'seller_statement'           => 'The seller told me directly that no mandate or FICA was ever signed with this agency.',
            'subjects'                   => [
                ['agency_name' => 'Rival Realty', 'portal_url' => 'https://example.com/listing/3', 'portal_source' => 'p24'],
            ],
            '_idempotency_token'         => (string) Str::uuid(),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertStatus(302);

        $this->assertSame(1, WhistleblowComplaint::count());
        $this->assertSame('pending_approval', WhistleblowComplaint::first()->status);
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);

        return [$agencyId, $user];
    }
}
