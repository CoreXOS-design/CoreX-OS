<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchLinkOpen;
use App\Models\ContactMatchShare;
use App\Models\ContactMatchShareProperty;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Matching\CoreMatchShareHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-Core-Matches, share-history piece — Johan's ruling: "the history is
 * nice to have... but the link should actually operate live... any
 * tracking happens internally." Proves:
 *
 * - a share snapshots exactly the live match set at that moment, via the
 *   SAME query (ClientMatchResolver) the live link itself runs;
 * - "not seen since last send" = today's live matches minus everything
 *   EVER shared, the actual deliverable;
 * - a property withdrawn then relisted still counts as already-seen (Q3);
 * - the share record is soft-delete-only evidence and survives set-aside;
 * - "opened" is a separate event from "shared", never conflated (Q1);
 * - the read endpoint is gated the same as the rest of Core Matches.
 */
final class ContactMatchShareHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Share History Co', 'slug' => 'sh-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Share', 'last_name' => 'Test',
        ]);
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 2_000_000,
            'beds'          => 3,
            'garages'       => 1,
            'property_type' => 'House',
        ], $overrides));
    }

    private function match(): ContactMatch
    {
        return ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'agent_id'           => $this->agent->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1_500_000,
            'price_max'          => 2_500_000,
            'beds_min'           => 3,
        ]);
    }

    public function test_a_share_snapshots_exactly_the_live_matched_properties_at_that_moment(): void
    {
        $match = $this->match();
        $propertyA = $this->property(['title' => 'A']);
        $propertyB = $this->property(['title' => 'B']);
        // Doesn't match this wishlist (out of price range) — must NOT be snapshotted.
        $this->property(['title' => 'Too Expensive', 'price' => 9_000_000]);

        $share = ContactMatchShare::record($match, $this->agent->id, ContactMatchShare::CHANNEL_WHATSAPP);

        $snapshotIds = ContactMatchShareProperty::where('contact_match_share_id', $share->id)->pluck('property_id')->sort()->values();
        $this->assertSame([$propertyA->id, $propertyB->id], $snapshotIds->sort()->values()->all());
    }

    public function test_never_sent_properties_is_todays_live_matches_minus_everything_ever_shared(): void
    {
        $match = $this->match();
        $propertyA = $this->property(['title' => 'A']);

        ContactMatchShare::record($match, $this->agent->id);

        // A new property enters the market AFTER the first share.
        $propertyB = $this->property(['title' => 'B']);

        $history = app(CoreMatchShareHistoryService::class);
        $neverSent = $history->neverSentProperties($match);

        $this->assertFalse($neverSent->contains('id', $propertyA->id), 'already shared once — must not count as new');
        $this->assertTrue($neverSent->contains('id', $propertyB->id), 'never shared — must count as new');
    }

    public function test_a_property_withdrawn_then_relisted_still_counts_as_already_sent(): void
    {
        $match = $this->match();
        $property = $this->property(['title' => 'Withdraw Cycle']);

        ContactMatchShare::record($match, $this->agent->id);

        // Withdrawn — drops out of the live match set entirely.
        $property->status = 'withdrawn';
        $property->save();

        $history = app(CoreMatchShareHistoryService::class);
        $this->assertFalse($history->neverSentProperties($match)->contains('id', $property->id), 'withdrawn — not currently live, so trivially not "new" either way');

        // Relisted — SAME property row/id, back on the market.
        $property->status = 'active';
        $property->save();

        $this->assertFalse(
            $history->neverSentProperties($match)->contains('id', $property->id),
            'same property_id the buyer already saw before it was withdrawn — relisting must not resurrect it as "new"'
        );
    }

    public function test_share_records_are_soft_delete_only_and_a_trashed_share_stops_counting_as_seen(): void
    {
        $match = $this->match();
        $property = $this->property();

        $share = ContactMatchShare::record($match, $this->agent->id);
        $this->assertNotNull(ContactMatchShareProperty::where('contact_match_share_id', $share->id)->first());

        $share->delete();

        $trashed = ContactMatchShare::withTrashed()->find($share->id);
        $this->assertNotNull($trashed?->deleted_at, 'delete() must be a soft delete, not gone entirely');
        $this->assertSame(0, ContactMatchShare::count(), 'default query excludes the trashed share');
        $this->assertSame(1, ContactMatchShare::withTrashed()->count());

        $history = app(CoreMatchShareHistoryService::class);
        $this->assertTrue(
            $history->neverSentProperties($match)->contains('id', $property->id),
            'once its only share is trashed, the property is no longer "already seen" through that share'
        );
    }

    public function test_share_history_survives_the_buyer_being_set_aside(): void
    {
        $match = $this->match();
        $this->property();

        $share = ContactMatchShare::record($match, $this->agent->id);

        $match->setAside();

        $this->assertNotNull($match->fresh()->set_aside_at);
        $this->assertSame(1, ContactMatchShare::where('id', $share->id)->count(), 'set-aside touches contact_matches only — the share evidence is untouched');
        $this->assertSame(1, ContactMatchShareProperty::where('contact_match_share_id', $share->id)->count());
    }

    public function test_opening_the_shared_link_records_a_separate_open_event_not_a_share(): void
    {
        $match = $this->match();
        $this->property();
        $match->share_token = \Illuminate\Support\Str::random(48);
        $match->save();

        $this->get(route('shared.match', ['token' => $match->share_token]))->assertOk();

        $this->assertSame(1, ContactMatchLinkOpen::where('contact_match_id', $match->id)->count());
        $this->assertSame(0, ContactMatchShare::where('contact_match_id', $match->id)->count(), 'opening the link must never create a share row');
    }

    public function test_opening_the_link_repeatedly_accumulates_count_and_last_opened_at(): void
    {
        $match = $this->match();
        $this->property();
        $match->share_token = \Illuminate\Support\Str::random(48);
        $match->save();

        $this->get(route('shared.match', ['token' => $match->share_token]))->assertOk();
        $this->get(route('shared.match', ['token' => $match->share_token]))->assertOk();

        $history = app(CoreMatchShareHistoryService::class);
        $summary = $history->openSummary($match);

        $this->assertSame(2, $summary['count']);
        $this->assertNotNull($summary['last_opened_at']);
    }

    public function test_the_share_history_endpoint_returns_shares_opens_and_new_since_last_share(): void
    {
        $match = $this->match();
        $this->property(['title' => 'Already Sent']);
        ContactMatchShare::record($match, $this->agent->id, ContactMatchShare::CHANNEL_EMAIL);
        $newProperty = $this->property(['title' => 'Brand New']);

        $response = $this->actingAs($this->agent)->getJson(route('corex.core-matches.share-history', $match));

        $response->assertOk();
        $response->assertJsonCount(1, 'shares');
        $response->assertJsonPath('shares.0.channel', ContactMatchShare::CHANNEL_EMAIL);
        $newSinceIds = collect($response->json('new_since_last_share'))->pluck('id');
        $this->assertTrue($newSinceIds->contains($newProperty->id));
    }

    public function test_the_share_history_endpoint_is_gated_on_core_matches_view_permission(): void
    {
        RolePermission::create([
            'role'           => 'branch_manager',
            'permission_key' => 'core_matches.view',
            'agency_id'      => null,
        ]);
        $agentUser = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $branchManager = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager',
        ]);
        $match = $this->match();

        $this->actingAs($agentUser)->getJson(route('corex.core-matches.share-history', $match))->assertForbidden();
        $this->actingAs($branchManager)->getJson(route('corex.core-matches.share-history', $match))->assertOk();
    }
}
