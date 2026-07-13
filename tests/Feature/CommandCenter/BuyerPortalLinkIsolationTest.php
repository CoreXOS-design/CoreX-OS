<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Buyer portal-link generate/revoke were cross-tenant IDOR: generate validated
 * only that contact_id existed ANYWHERE and derived the agency with
 * withoutGlobalScopes(), so any authenticated user could mint a public portal
 * link for any contact in any agency; revoke updated by raw id with no agency
 * scope.
 *
 * Fix: generate resolves the contact through its global scopes (findOrFail →
 * 404 cross-tenant); revoke scopes the update to the caller's agency.
 */
final class BuyerPortalLinkIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Agency, 1: Branch, 2: User} */
    private function agencyWithAgent(string $tag): array
    {
        $agency = Agency::create(['name' => "A-{$tag}", 'slug' => "a-{$tag}-" . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => "B-{$tag}"]);
        $agent  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'agent', 'is_active' => true,
        ]);
        $agent->forceFill(['email_verified_at' => now()])->save();   // passes 'verified'

        return [$agency, $branch, $agent];
    }

    private function contactFor(Agency $agency, Branch $branch): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'B', 'last_name' => 'Buyer',
        ]);
    }

    public function test_cannot_generate_a_portal_link_for_another_agencys_contact(): void
    {
        [, , $agentA] = $this->agencyWithAgent('a');
        [$agencyB, $branchB] = $this->agencyWithAgent('b');
        $theirContact = $this->contactFor($agencyB, $branchB);

        $this->actingAs($agentA)
            ->post('/corex/command-center/buyers/portal-links/generate', ['contact_id' => $theirContact->id])
            ->assertNotFound();

        $this->assertSame(
            0,
            DB::table('buyer_portal_links')->where('contact_id', $theirContact->id)->count(),
            'no public link may be minted for another agency\'s contact'
        );
    }

    public function test_can_generate_a_portal_link_for_own_contact(): void
    {
        [$agencyA, $branchA, $agentA] = $this->agencyWithAgent('a');
        $myContact = $this->contactFor($agencyA, $branchA);

        $this->actingAs($agentA)
            ->post('/corex/command-center/buyers/portal-links/generate', ['contact_id' => $myContact->id])
            ->assertRedirect();

        $this->assertSame(
            1,
            DB::table('buyer_portal_links')->where('contact_id', $myContact->id)->whereNull('revoked_at')->count(),
            'an agent may mint a link for their own agency\'s contact'
        );
    }

    public function test_cannot_revoke_another_agencys_link(): void
    {
        [, , $agentA] = $this->agencyWithAgent('a');
        [$agencyB, $branchB] = $this->agencyWithAgent('b');
        $theirContact = $this->contactFor($agencyB, $branchB);

        $foreignLinkId = DB::table('buyer_portal_links')->insertGetId([
            'contact_id' => $theirContact->id, 'agency_id' => $agencyB->id,
            'token' => bin2hex(random_bytes(16)), 'generated_by_user_id' => $agentA->id,
            'generated_at' => now(), 'access_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($agentA)
            ->post("/corex/command-center/buyers/portal-links/{$foreignLinkId}/revoke")
            ->assertNotFound();

        $this->assertTrue(
            DB::table('buyer_portal_links')->where('id', $foreignLinkId)->whereNull('revoked_at')->exists(),
            'another agency\'s link must not be revocable'
        );
    }
}
