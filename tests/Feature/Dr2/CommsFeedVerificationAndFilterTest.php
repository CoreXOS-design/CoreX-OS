<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 2026-08-20, live bug (deal 137 / Linda's mail) — AT-231 §3.4: a match is PROVISIONAL until
 * the agent verifies the first email. CommunicationEventSource used to render every communication_links
 * row regardless of confirmed_at, so an auto-suggested, never-verified email showed up in a deal's
 * correspondence history indistinguishable from a human-confirmed one. Fixed: only confirmed links
 * reach the feed (Johan's explicit call — exclude, don't flag-and-show).
 *
 * Also covers the Comments/Emails/WhatsApp "Show" selector (?feed=) added alongside the fix.
 */
final class CommsFeedVerificationAndFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function world(): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $user->id,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branch->id, 'agency_id' => $agency->id,
            'created_by_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => (string) random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Seller', 'property_address' => '7 Bauhinia Road',
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'deal_v2_id' => $twinId,
        ]));

        return [$agency, $branch, $user, $deal, $twinId];
    }

    private function comm(int $agencyId, int $userId, string $channel, string $subject): Communication
    {
        return Communication::create([
            'agency_id' => $agencyId, 'channel' => $channel, 'direction' => 'inbound',
            'external_id' => 'mid-' . Str::random(10), 'thread_key' => null,
            'from_identifier' => 'linda@vdsatt.co.za', 'participant_identifiers' => ['linda@vdsatt.co.za'],
            'occurred_at' => now(), 'captured_at' => now(),
            'subject' => $subject, 'body_text' => 'Body for ' . $subject,
            'has_attachments' => false, 'owner_user_id' => $userId,
        ]);
    }

    private function link(int $agencyId, int $commId, int $twinId, bool $confirmed): void
    {
        CommunicationLink::create([
            'agency_id' => $agencyId, 'communication_id' => $commId,
            'linkable_type' => DealV2::class, 'linkable_id' => $twinId,
            'link_method' => 'attorney_ref', 'confidence' => 100,
            'confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    public function test_unconfirmed_link_does_not_appear_in_the_deal_feed(): void
    {
        [$agency, , $user, $deal, $twinId] = $this->world();
        $unconfirmed = $this->comm($agency->id, $user->id, 'email', 'RE: Unrelated matter, wrong deal');
        $this->link($agency->id, $unconfirmed->id, $twinId, confirmed: false);

        $this->actingAs($user);
        $resp = $this->get(route('deals-dr2.pipeline.list', $deal))->assertOk();
        $resp->assertDontSee('Unrelated matter, wrong deal');
    }

    public function test_confirmed_link_does_appear(): void
    {
        [$agency, , $user, $deal, $twinId] = $this->world();
        $confirmed = $this->comm($agency->id, $user->id, 'email', 'REGISTRATION OF TRANSFER: BAUHINIA');
        $this->link($agency->id, $confirmed->id, $twinId, confirmed: true);

        $this->actingAs($user);
        $resp = $this->get(route('deals-dr2.pipeline.list', $deal))->assertOk();
        $resp->assertSee('REGISTRATION OF TRANSFER: BAUHINIA');
    }

    public function test_mixed_confirmed_and_unconfirmed_only_confirmed_survives(): void
    {
        [$agency, , $user, $deal, $twinId] = $this->world();
        $good = $this->comm($agency->id, $user->id, 'email', 'Confirmed correspondence');
        $bad  = $this->comm($agency->id, $user->id, 'email', 'Guessed correspondence');
        $this->link($agency->id, $good->id, $twinId, confirmed: true);
        $this->link($agency->id, $bad->id, $twinId, confirmed: false);

        $this->actingAs($user);
        $resp = $this->get(route('deals-dr2.pipeline.list', $deal))->assertOk();
        $resp->assertSee('Confirmed correspondence')->assertDontSee('Guessed correspondence');
    }

    public function test_feed_filter_returns_only_the_selected_type(): void
    {
        [$agency, , $user, $deal, $twinId] = $this->world();
        $email = $this->comm($agency->id, $user->id, 'email', 'Email subject line');
        $wa    = $this->comm($agency->id, $user->id, 'whatsapp', 'Whatsapp subject line');
        $this->link($agency->id, $email->id, $twinId, confirmed: true);
        $this->link($agency->id, $wa->id, $twinId, confirmed: true);

        $this->actingAs($user);

        // All — both present.
        $this->get(route('deals-dr2.pipeline.list', $deal))
            ->assertOk()->assertSee('Email subject line')->assertSee('Whatsapp subject line');

        // Emails only.
        $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=email')
            ->assertOk()->assertSee('Email subject line')->assertDontSee('Whatsapp subject line');

        // WhatsApp only — the case Johan called out: it must be its own reachable option, not
        // silently dropped when a filter is applied.
        $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=whatsapp')
            ->assertOk()->assertSee('Whatsapp subject line')->assertDontSee('Email subject line');

        // Comments only — neither comm-derived subject should appear.
        $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=comment')
            ->assertOk()->assertDontSee('Email subject line')->assertDontSee('Whatsapp subject line');
    }

    public function test_unconfirmed_link_is_excluded_under_every_feed_value(): void
    {
        // The verification gate and the type filter are independent — an unconfirmed link must
        // never resurface just because a specific feed value was picked.
        [$agency, , $user, $deal, $twinId] = $this->world();
        $unconfirmed = $this->comm($agency->id, $user->id, 'whatsapp', 'Never verified whatsapp');
        $this->link($agency->id, $unconfirmed->id, $twinId, confirmed: false);

        $this->actingAs($user);
        foreach (['all', 'whatsapp'] as $feed) {
            $this->get(route('deals-dr2.pipeline.list', $deal) . '?feed=' . $feed)
                ->assertOk()->assertDontSee('Never verified whatsapp');
        }
    }
}
