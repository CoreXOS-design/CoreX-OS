<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\DealV2\PipelineUserPreference;
use App\Models\User;
use App\Services\Deal\Pipeline\PipelineEventService;
use App\Support\Pipeline\PipelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pipeline Dashboard Phase 4 — the email/WhatsApp event source (comms archive via communication_links →
 * the DR2 twin) plugs into the SAME normalizer as comments, and the per-agent default view lands the
 * agent on their remembered board/timeline/list. Spec §3.3, §3.4, Phase 4.
 */
final class PipelineEventSourcesAndPrefTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    public function test_a_linked_email_surfaces_as_a_deal_scoped_pipeline_event(): void
    {
        // A deal with a DR2 twin id (the comms morph target). No real DealV2 row needed — the source
        // matches communication_links by (linkable_type=DealV2, linkable_id=deal_v2_id).
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P', 'property_value' => 2150000, 'total_commission' => 107500,
            'deal_v2_id' => 4242,
        ]);

        $comm = Communication::create([
            'agency_id' => $this->agencyId, 'channel' => 'email', 'direction' => 'inbound',
            'external_id' => 'ext-' . Str::random(10), 'from_identifier' => 'attorney@example.co.za',
            'occurred_at' => now()->subHour(), 'captured_at' => now(), 'subject' => 'Transfer update',
            'body_text' => 'Bond registered at the deeds office today.',
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => DealV2::class, 'linkable_id' => 4242,
        ]);

        $events = app(PipelineEventService::class)->eventsForDeal($deal);

        $email = $events->firstWhere('sourceType', 'communication');
        $this->assertNotNull($email, 'the linked email appears in the normalized stream');
        /** @var PipelineEvent $email */
        $this->assertSame('email', $email->type);
        $this->assertSame(PipelineEvent::SCOPE_DEAL, $email->scope);
        $this->assertNull($email->stepId);
        $this->assertSame('inbound', $email->direction);
        $this->assertStringContainsString('deeds office', $email->body);
        // The source count reflects BOTH comment + communication sources registered.
        $this->assertSame(2, app(PipelineEventService::class)->sourceCount());
    }

    public function test_no_twin_means_no_comms_events(): void
    {
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'buyer_name' => 'No Twin', 'accepted_status' => 'P', 'property_value' => 2150000, 'total_commission' => 107500,
        ]); // deal_v2_id null
        $this->assertCount(0, app(PipelineEventService::class)->eventsForDeal($deal));
    }

    public function test_per_agent_default_view_round_trips_all_three(): void
    {
        $this->assertSame('timeline', PipelineUserPreference::viewForUser($this->admin->id)); // unset default
        foreach (['list', 'board', 'timeline'] as $view) {
            PipelineUserPreference::setViewForUser($this->admin->id, $view);
            $this->assertSame($view, PipelineUserPreference::viewForUser($this->admin->id));
        }
        $this->assertSame(1, PipelineUserPreference::where('user_id', $this->admin->id)->count());
    }

    public function test_pipeline_view_entry_redirects_to_the_remembered_view(): void
    {
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'buyer_name' => 'Redirect', 'accepted_status' => 'P', 'property_value' => 2150000, 'total_commission' => 107500,
        ]);

        PipelineUserPreference::setViewForUser($this->admin->id, 'list');
        $this->get(route('deals-dr2.pipeline.view', $deal))
            ->assertRedirect(route('deals-dr2.pipeline.list', $deal));

        PipelineUserPreference::setViewForUser($this->admin->id, 'timeline');
        $this->get(route('deals-dr2.pipeline.view', $deal))
            ->assertRedirect(route('deals-dr2.pipeline.timeline', $deal));

        PipelineUserPreference::setViewForUser($this->admin->id, 'board');
        $this->get(route('deals-dr2.pipeline.view', $deal))
            ->assertRedirect(route('deals-dr2.pipeline', $deal));
    }
}
