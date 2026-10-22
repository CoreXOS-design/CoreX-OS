<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchShare;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\CoreMatchShareHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-503 - "a sent match is sent; 'new' means only a property never sent to this buyer."
 *
 * 1. The board count and the send snapshot use the SAME property set (a hidden property is in
 *    neither), so "Send 1 new" can never stick.
 * 2. The buyer-level Share button and the mobile WhatsApp share record a confirmed share exactly
 *    like the composer does.
 * 3. A confirm returns the fresh status so the page updates in place; the senders show an error
 *    with Retry instead of a silent catch.
 */
final class SentIsSentAt503Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Sent Is Sent Co', 'slug' => 'sis-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Sentbuyer', 'last_name' => 'Test', 'is_buyer' => true, 'buyer_state' => 'warm',
            'phone' => '0821234567', 'email' => 'buyer@example.test',
        ]);
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'title' => 'Listing', 'status' => 'active',
            'listing_type' => 'sale', 'price' => 2_000_000, 'beds' => 3, 'garages' => 1, 'property_type' => 'House',
        ], $overrides));
    }

    private function match(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'name' => 'Wishlist', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
            'price_min' => 1_500_000, 'price_max' => 2_500_000, 'beds_min' => 3,
        ], $overrides));
    }

    private function boardHtml(): string
    {
        return $this->actingAs($this->agent)->get(route('corex.core-matches.index'))->assertOk()->getContent();
    }

    /** "Send N new" as the board renders it, or null when the badge is not shown. */
    private function boardSendNew(string $html): ?int
    {
        return preg_match('/data-testid="core-match-send-new"[^>]*>\s*Send (\d+) new/', $html, $m) ? (int) $m[1] : null;
    }

    // ── 1. same set ───────────────────────────────────────────────────────────

    public function test_a_hidden_matching_property_is_counted_in_neither_the_board_nor_the_send(): void
    {
        $match = $this->match();
        $a = $this->property(['title' => 'A']);
        $hidden = $this->property(['title' => 'Hidden one']);
        $match->update(['hidden_property_ids' => [$hidden->id]]);

        $match->mintShareLink($this->agent->id)->confirmSent(ContactMatchShare::CHANNEL_WHATSAPP);

        // Before the fix the board diffed the hidden property against a snapshot that could never hold it.
        $this->assertNull($this->boardSendNew($this->boardHtml()), 'a sent match is sent - no "Send N new" for a hidden property');
        $this->assertSame(0, app(CoreMatchShareHistoryService::class)->neverSentProperties($match)->count());
        $this->assertSame(0, app(CoreMatchShareHistoryService::class)->statusFor($match)['never_sent_count']);
        $this->assertNotNull($a->id);
    }

    public function test_board_count_equals_the_quick_view_set_with_a_hidden_and_a_genuinely_new_property(): void
    {
        $match = $this->match();
        $this->property(['title' => 'Sent already']);
        $hidden = $this->property(['title' => 'Hidden one']);
        $match->update(['hidden_property_ids' => [$hidden->id]]);
        $match->mintShareLink($this->agent->id)->confirmSent(ContactMatchShare::CHANNEL_EMAIL);

        $new = $this->property(['title' => 'Brand new']);

        $unsent = app(CoreMatchShareHistoryService::class)->neverSentProperties($match);
        $this->assertSame([$new->id], $unsent->pluck('id')->all());
        $this->assertSame(1, $this->boardSendNew($this->boardHtml()), 'board and quick view agree: exactly the one never-sent property');
    }

    public function test_a_near_miss_over_budget_that_the_send_includes_is_counted_by_the_board_too(): void
    {
        $match = $this->match(); // price_max 2,500,000
        $this->property(['title' => 'In range']);
        $match->mintShareLink($this->agent->id)->confirmSent(ContactMatchShare::CHANNEL_EMAIL);

        // 10% over the stated ceiling: inside the relaxed +/-30% band, so the buyer's link and every
        // send include it. The board used to score it with no band and never count it as new.
        $nearMiss = $this->property(['title' => 'Slightly over budget', 'price' => 2_750_000]);

        $unsent = app(CoreMatchShareHistoryService::class)->neverSentProperties($match);
        $this->assertTrue($unsent->contains('id', $nearMiss->id), 'precondition: the send set includes the near-miss');
        $this->assertSame($unsent->count(), $this->boardSendNew($this->boardHtml()), 'board and send agree on the near-miss');
    }

    public function test_after_sending_the_new_property_the_count_drops_and_stays_down(): void
    {
        $match = $this->match();
        $this->property(['title' => 'First']);
        $match->mintShareLink($this->agent->id)->confirmSent(ContactMatchShare::CHANNEL_EMAIL);
        $this->property(['title' => 'Second']);
        $this->assertSame(1, $this->boardSendNew($this->boardHtml()));

        $share = $match->mintShareLink($this->agent->id);
        $this->actingAs($this->agent)->postJson(route('corex.core-matches.shares.confirm', $share), ['channel' => 'email'])->assertOk();

        $this->assertNull($this->boardSendNew($this->boardHtml()));
        $this->assertNull($this->boardSendNew($this->boardHtml()), 'a second look still shows nothing new');
    }

    // ── 3. status comes back from the confirm ─────────────────────────────────

    public function test_confirm_returns_the_fresh_status_so_the_page_can_update_in_place(): void
    {
        $match = $this->match();
        $this->property(['title' => 'A']);
        $share = $match->mintShareLink($this->agent->id);

        $json = $this->actingAs($this->agent)
            ->postJson(route('corex.core-matches.shares.confirm', $share), ['channel' => 'whatsapp'])
            ->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertSame($match->id, $json['status']['match_id']);
        $this->assertSame(0, $json['status']['never_sent_count']);
        $this->assertNotNull($json['status']['last_shared_at']);
        $this->assertNotNull($json['status']['last_shared_human']);
    }

    public function test_a_stranger_cannot_confirm_someone_elses_share(): void
    {
        $share = $this->match()->mintShareLink($this->agent->id);
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->actingAs($other)->postJson(route('corex.core-matches.shares.confirm', $share), ['channel' => 'email'])->assertForbidden();
        $this->assertNull($share->fresh()->confirmed_at);
    }

    public function test_the_senders_wire_an_event_a_visible_error_and_a_retry_with_no_silent_catch(): void
    {
        $match = $this->match();
        $this->property();

        $bar = $this->actingAs($this->agent)->get(route('corex.contacts.matches.results', [$this->contact, $match]))->assertOk()->getContent();
        $this->assertStringContainsString('core-match-sent', $bar);
        $this->assertStringContainsString('retryShare()', $bar);
        $this->assertStringContainsString('NOT recorded', $bar);
        $this->assertStringNotContainsString('}).catch(() => {}); // Best-effort', $bar);
        $this->assertStringContainsString('data-testid="core-match-share-status"', $bar);

        $buyerBar = view('command-center.buyers._buyer-share-bar', ['buyer' => $this->contact])->render();
        // @js() JSON-escapes the slashes, so compare the escaped form.
        $this->assertStringContainsString(str_replace('/', '\\/', route('corex.core-matches.buyer-shares.confirm', $this->contact->id)), $buyerBar);
        $this->assertStringContainsString('retryShare()', $buyerBar);
    }

    // ── 2. buyer-level Share ──────────────────────────────────────────────────

    public function test_buyer_level_share_records_a_confirmed_share_for_each_active_wishlist_only(): void
    {
        $one = $this->match(['name' => 'One']);
        $two = $this->match(['name' => 'Two']);
        $paused = $this->match(['name' => 'Paused', 'status' => 'paused']);
        $this->property();

        $json = $this->actingAs($this->agent)
            ->postJson(route('corex.core-matches.buyer-shares.confirm', $this->contact->id), ['channel' => 'whatsapp'])
            ->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], array_column($json['statuses'], 'match_id'));
        foreach ([$one, $two] as $m) {
            $share = ContactMatchShare::where('contact_match_id', $m->id)->first();
            $this->assertNotNull($share->confirmed_at);
            $this->assertSame('whatsapp', $share->channel);
            $this->assertSame($this->agent->id, $share->shared_by_user_id);
            $this->assertGreaterThan(0, $share->properties()->count(), 'the live set is snapshotted like the composer does');
        }
        $this->assertSame(0, ContactMatchShare::where('contact_match_id', $paused->id)->count());
        $this->assertNotNull($this->contact->fresh()->last_contacted_at, 'the buyer clock is reset like the composer does');
    }

    public function test_buyer_level_share_for_a_buyer_in_another_agency_is_a_404(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        auth()->guard()->forgetUser();
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Elsewhere']);
        $stranger = Contact::create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Not', 'last_name' => 'Yours', 'is_buyer' => true]);

        $this->actingAs($this->agent)
            ->postJson(route('corex.core-matches.buyer-shares.confirm', $stranger->id), ['channel' => 'email'])
            ->assertNotFound();
    }

    public function test_buyer_level_share_rejects_an_unknown_channel(): void
    {
        $this->match();
        $this->actingAs($this->agent)
            ->postJson(route('corex.core-matches.buyer-shares.confirm', $this->contact->id), ['channel' => 'carrier-pigeon'])
            ->assertStatus(422);
    }

    // ── 2. mobile WhatsApp ────────────────────────────────────────────────────

    public function test_mobile_whatsapp_post_records_a_confirmed_share_with_a_dated_link(): void
    {
        $match = $this->match();
        $this->property();
        Sanctum::actingAs($this->agent);

        $json = $this->postJson(route('v1.mobile.core-matches.share-whatsapp.post', $match))->assertOk()->json();

        $share = ContactMatchShare::where('contact_match_id', $match->id)->first();
        $this->assertNotNull($share);
        $this->assertNotNull($share->confirmed_at);
        $this->assertSame('whatsapp', $share->channel);
        $this->assertTrue($json['share_recorded']);
        $this->assertSame(0, $json['never_sent_count']);
        $this->assertSame($share->url(), $json['share_url']);
        $this->assertStringContainsString(rawurlencode($share->url()), (string) $json['wa_link']);
        $this->assertSame(1, Communication::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'the provisional communication is still logged once');
    }

    public function test_mobile_whatsapp_get_is_only_a_preview_and_records_nothing(): void
    {
        $match = $this->match();
        $this->property();
        Sanctum::actingAs($this->agent);

        $this->getJson(route('v1.mobile.core-matches.share-whatsapp.get', $match))->assertOk();

        $this->assertSame(0, ContactMatchShare::where('contact_match_id', $match->id)->count());
    }

    public function test_mobile_whatsapp_failure_is_a_clear_error_with_nothing_half_recorded(): void
    {
        $match = $this->match();
        $this->property();
        Sanctum::actingAs($this->agent);
        ContactMatchShare::creating(function () {
            throw new \RuntimeException('simulated outage');
        });

        $json = $this->postJson(route('v1.mobile.core-matches.share-whatsapp.post', $match))->assertStatus(500)->json();

        $this->assertSame('share_not_recorded', $json['error']);
        $this->assertFalse($json['share_recorded']);
        $this->assertSame(0, Communication::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(), 'no communication row, so a retry cannot duplicate it');
    }
}
