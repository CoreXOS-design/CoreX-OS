<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 DR2 WS8 (§12) — the per-user iCal deal feed: a tokenised, public,
 * read-only .ics of the token-user's own deal-step deadlines. Unguessable +
 * rotatable token; server-clamped scope (default own).
 */
final class DealIcalFeedTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // error pages (404) extend a @vite layout — no manifest in tests
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        $this->template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->agent->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $this->template->id, 'position' => 1, 'name' => 'Bond Approval',
            'is_locked' => false, 'is_milestone' => true, 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => 20,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
        ]);
    }

    public function test_feed_serves_the_users_deal_deadlines_as_vevents(): void
    {
        $deal = $this->makeDeal($this->agent, 'DL-REF-A', '12 Marine Dr, Margate');
        $token = $this->tokenFor($this->agent);

        $resp = $this->get(route('deals-v2.ical', $token));

        $resp->assertOk();
        $this->assertStringContainsString('text/calendar', $resp->headers->get('Content-Type'));
        $body = $resp->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('SUMMARY:' . $deal->reference, $body);
        // due date = offer + 20 days, as an all-day DATE value.
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . now()->addDays(20)->format('Ymd'), $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
    }

    public function test_unknown_token_is_404_not_a_leak(): void
    {
        $this->makeDeal($this->agent, 'DL-REF-A', '12 Marine Dr');
        $this->get(route('deals-v2.ical', Str::random(48)))->assertNotFound();
    }

    public function test_feed_defaults_to_own_scope_and_excludes_other_agents_deals(): void
    {
        $mine  = $this->makeDeal($this->agent, 'DL-MINE', '12 Marine Dr, Margate');
        $other = $this->makeDeal(
            User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]),
            'DL-OTHER', '9 Beach Rd, Uvongo'
        );
        $token = $this->tokenFor($this->agent);

        // Even asking for ?scope=all, an 'own'-permitted agent gets only their own.
        $body = $this->get(route('deals-v2.ical', $token) . '?scope=all')->assertOk()->getContent();
        $this->assertStringContainsString($mine->reference, $body);
        $this->assertStringNotContainsString($other->reference, $body);
    }

    public function test_regenerate_rotates_the_token_and_revokes_the_old_url(): void
    {
        $this->makeDeal($this->agent, 'DL-REF-A', '12 Marine Dr');
        $old = $this->tokenFor($this->agent);

        $this->actingAs($this->agent)->post(route('deals-v2.ical.regenerate'))->assertRedirect();

        $new = CalendarUserPreference::where('user_id', $this->agent->id)->value('ical_token');
        $this->assertNotSame($old, $new);
        $this->get(route('deals-v2.ical', $old))->assertNotFound();   // old revoked
        $this->get(route('deals-v2.ical', $new))->assertOk();          // new works
    }

    private function tokenFor(User $user): string
    {
        $token = Str::lower(Str::random(48));
        CalendarUserPreference::updateOrCreate(['user_id' => $user->id], ['ical_token' => $token]);
        return $token;
    }

    private function makeDeal(User $agent, string $ref, string $address): DealV2
    {
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        $deal = app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $this->template->id, 'purchase_price' => 1_850_000,
            'commission_amount' => 92_500, 'commission_vat' => 13_875, 'offer_date' => now()->toDateString(),
            'branch_id' => $this->agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);
        $deal->update(['reference' => $ref]);

        return $deal->fresh();
    }
}
