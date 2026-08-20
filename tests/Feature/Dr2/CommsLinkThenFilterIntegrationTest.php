<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-08-20 consolidation (qa1-dr2-comms-integration) — spans cc1's manual link/unlink
 * (Dr2CommunicationLinkController) and cc5's verification-gated feed + Show selector
 * (CommunicationEventSource / PipelineListController). Written to catch exactly the bug the
 * consolidation brief called out: cc1's link() sets confirmed_at on creation, so a newly linked
 * email must be immediately visible under cc5's whereNotNull('confirmed_at') gate — not stuck
 * invisible in "suspense" the way an unconfirmed auto-suggestion would be.
 *
 * Also proves the 2026-08-20 WhatsApp scope call end-to-end: a WhatsApp communication linked via
 * the SAME manual-link endpoint never appears in the feed, under any filter, even though linking
 * itself succeeds (cc1's link() does not reject WhatsApp — the exclusion is enforced entirely on
 * the read side, in CommunicationEventSource).
 */
final class CommsLinkThenFilterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function world(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent', 'is_active' => true,
        ]);

        $dealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $branchId, 'agency_id' => $agencyId,
            'created_by_id' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000, 'total_commission' => 86_250,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Test Seller', 'property_address' => '1 Test Rd',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'deal_v2_id' => $dealV2Id,
        ]));

        return [$agencyId, $agent, $deal, $dealV2Id];
    }

    private function comm(int $agencyId, int $userId, string $channel, string $subject): Communication
    {
        return Communication::create([
            'agency_id' => $agencyId, 'channel' => $channel, 'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14), 'thread_key' => 'thread-' . Str::random(6),
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'subject' => $subject, 'body_text' => 'Body for ' . $subject,
            'occurred_at' => now(), 'captured_at' => now(),
            'owner_user_id' => $userId, 'has_attachments' => false,
        ]);
    }

    public function test_linking_an_email_from_dr2_makes_it_immediately_visible_in_the_filtered_feed_then_unlink_removes_it(): void
    {
        [$agencyId, $agent, $deal] = $this->world();
        $comm = $this->comm($agencyId, $agent->id, Communication::CHANNEL_EMAIL, 'REGISTRATION OF TRANSFER: 1 TEST RD');

        $this->actingAs($agent);

        // 1) Link the email via cc1's endpoint.
        $link = $this->postJson(
            route('deals-dr2.communications.link', $deal),
            ['communication_id' => $comm->id]
        )->assertCreated()->json();

        // The bug this consolidation exists to catch: cc1's link() must set confirmed_at, or the
        // newly created link is invisible under cc5's whereNotNull('confirmed_at') gate.
        $this->assertDatabaseHas('communication_links', [
            'id' => $link['link_id'],
            'communication_id' => $comm->id,
        ]);
        $linkRow = CommunicationLink::findOrFail($link['link_id']);
        $this->assertNotNull($linkRow->confirmed_at, 'cc1 link-creation must set confirmed_at, or cc5\'s feed gate hides every newly linked email.');

        // 2) It appears in the feed (default/all view).
        $this->get(route('deals-dr2.pipeline.list', $deal))
            ->assertOk()->assertSee('REGISTRATION OF TRANSFER: 1 TEST RD');

        // 3) Filter to emails only — still there.
        $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=email')
            ->assertOk()->assertSee('REGISTRATION OF TRANSFER: 1 TEST RD');

        // 4) Unlink via cc1's endpoint.
        $this->postJson(route('deals-dr2.communications.unlink', ['deal' => $deal, 'link' => $link['link_id']]))
            ->assertOk()->assertJson(['ok' => true]);

        // 5) It is gone from the feed — under both the default view and the email filter.
        $this->get(route('deals-dr2.pipeline.list', $deal))
            ->assertOk()->assertDontSee('REGISTRATION OF TRANSFER: 1 TEST RD');
        $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=email')
            ->assertOk()->assertDontSee('REGISTRATION OF TRANSFER: 1 TEST RD');
    }

    public function test_a_manually_linked_whatsapp_message_never_appears_in_the_feed_even_though_the_link_succeeds(): void
    {
        [$agencyId, $agent, $deal] = $this->world();
        $wa = $this->comm($agencyId, $agent->id, Communication::CHANNEL_WHATSAPP, 'Whatsapp linked to a deal');

        $this->actingAs($agent);

        // Linking itself is not blocked by channel — cc1's endpoint operates on any Communication.
        $link = $this->postJson(
            route('deals-dr2.communications.link', $deal),
            ['communication_id' => $wa->id]
        )->assertCreated()->json();
        $this->assertDatabaseHas('communication_links', ['id' => $link['link_id'], 'communication_id' => $wa->id]);

        // But it must never render in the deal feed, under any filter — 2026-08-20 scope call:
        // WhatsApp has no reliable per-message deal attribution, so it is excluded at the source.
        foreach (['all', 'email', 'comment', 'whatsapp'] as $feed) {
            $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=' . $feed)
                ->assertOk()->assertDontSee('Whatsapp linked to a deal');
        }
    }
}
