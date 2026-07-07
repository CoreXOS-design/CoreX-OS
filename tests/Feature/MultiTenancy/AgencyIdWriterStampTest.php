<?php

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\AgentScorecardCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-203 per-writer regression: each of these writers reaches a NOT-NULL
 * agency_id table from a context where BelongsToAgency cannot auto-stamp
 * (nightly console job / public no-auth route). Each must stamp agency_id from
 * its subject pillar. These prove the stamp lands with the CORRECT value and
 * that the write survives the zero-data edge.
 */
class AgencyIdWriterStampTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    private function makeBuyer(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Bea', 'last_name' => Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'buyer-' . Str::random(5) . '@example.co.za',
        ]);
    }

    /** agent_scorecards — nightly console calc, stamp from the agent (User pillar), zero-data agent. */
    public function test_agent_scorecard_stamps_agency_from_the_user(): void
    {
        // Fresh agent: no tasks, deals, or properties — the zero-data edge.
        $card = app(AgentScorecardCalculator::class)->calculateWeekly($this->user);

        $this->assertSame($this->agency->id, $card->agency_id);
        $this->assertDatabaseHas('agent_scorecards', [
            'user_id' => $this->user->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    /** buyer_property_responses — PUBLIC buyer-portal respond, stamp from the link (Contact pillar). */
    public function test_buyer_portal_response_stamps_agency_from_the_link(): void
    {
        $property = $this->makeProperty();
        $buyer = $this->makeBuyer();
        $token = bin2hex(random_bytes(16));
        DB::table('buyer_portal_links')->insert([
            'contact_id' => $buyer->id, 'agency_id' => $this->agency->id, 'token' => $token,
            'generated_by_user_id' => $this->user->id, 'generated_at' => now(),
            'access_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post("/buyer/portal/{$token}/respond", [
            'property_id' => $property->id,
            'response' => 'interested',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('buyer_property_responses', [
            'contact_id' => $buyer->id,
            'property_id' => $property->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    /** contact_match_feedback — PUBLIC shared-match link, stamp from the match (Contact pillar). */
    public function test_shared_match_feedback_stamps_agency_from_the_match(): void
    {
        $property = $this->makeProperty();
        $buyer = $this->makeBuyer();
        $match = ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $buyer->id,
            'created_by_user_id' => $this->user->id, 'name' => 'Match ' . Str::random(4),
            'status' => 'active',
        ]);
        $slug = $match->fresh()->share_slug ?: $match->fresh()->share_token;

        $response = $this->post("/shared/match/{$slug}/feedback/{$property->id}", [
            'reaction' => 'interested',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('contact_match_feedback', [
            'contact_match_id' => $match->id,
            'property_id' => $property->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    /** buyer_lost_risk_scores — console recompute, stamp from the buyer (Contact pillar), minimal-data buyer. */
    public function test_recompute_risk_scores_stamps_agency_from_the_buyer(): void
    {
        $buyer = $this->makeBuyer();

        Artisan::call('buyers:recompute-risk', ['--buyer' => $buyer->id]);

        $this->assertDatabaseHas('buyer_lost_risk_scores', [
            'contact_id' => $buyer->id,
            'agency_id' => $this->agency->id,
        ]);
    }
}
