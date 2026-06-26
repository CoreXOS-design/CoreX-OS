<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Agency;
use App\Models\AgentActivityEvent;
use App\Services\SellerOutreach\OutreachActivityFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part 4 — the hard rule: outreach/canvassing actions are SOURCE-TAGGED and counted
 * SEPARATELY per stream (never blended); the total is the visible SUM of the parts.
 *
 * This test locks the never-blend + total=sum + source-filter invariants using the
 * FK-cheap streams (claim → mic_prospect, comms-tile → comms_tile). The MIC-vs-direct
 * PITCH split (a pitch is mic_prospect iff its send's property is a matched prospecting
 * listing, else direct_contact) is verified separately against real data via Tinker —
 * seeding the full seller_outreach_sends FK chain (agency+contact+property) in the empty
 * RefreshDatabase DB is brittle and adds nothing to the invariant under test here.
 */
final class OutreachActivityFeedSourceTaggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_are_counted_separately_and_total_is_the_sum_of_parts(): void
    {
        $agency = Agency::create(['name' => 'Feed Test Agency', 'slug' => 'feed-test-agency']);

        // Two MIC canvassing actions (claims) + one comms-tile send. Subjects on
        // claims/contacts carry no FK, so this needs only the agency FK.
        $this->seedEvent($agency->id, 'claim.created', \App\Models\ProspectingClaim::class, 1, ['listing_id' => 11, 'status' => 'claimed']);
        $this->seedEvent($agency->id, 'claim_feedback.recorded', \App\Models\ProspectingClaim::class, 1, ['listing_id' => 11, 'new_status' => 'contacted']);
        $this->seedEvent($agency->id, 'comms_tile_message.sent', \App\Models\Contact::class, 7, ['channel' => 'whatsapp', 'contact_id' => 7]);

        $feed = app(OutreachActivityFeedService::class)->feed($agency->id, ['days' => 3650]);

        // Each stream counted on its own — MIC and comms-tile never merged.
        $this->assertSame(2, $feed['subtotals']['mic_prospect'], 'MIC subtotal');
        $this->assertSame(0, $feed['subtotals']['direct_contact'], 'Direct subtotal');
        $this->assertSame(1, $feed['subtotals']['comms_tile'], 'Comms-tile subtotal');

        // Total is the VISIBLE SUM of the parts — never a blended figure.
        $this->assertSame(3, $feed['total']);
        $this->assertSame(array_sum($feed['subtotals']), $feed['total'], 'Total must equal the sum of the per-source subtotals.');

        // Every row carries a source tag — origin is never lost.
        foreach ($feed['rows'] as $row) {
            $this->assertContains($row['source'], ['mic_prospect', 'direct_contact', 'comms_tile']);
            $this->assertNotEmpty($row['source_label']);
        }
    }

    public function test_source_filter_narrows_rows_but_subtotals_still_count_all(): void
    {
        $agency = Agency::create(['name' => 'Feed Filter Agency', 'slug' => 'feed-filter-agency']);

        $this->seedEvent($agency->id, 'claim.created', \App\Models\ProspectingClaim::class, 1, ['listing_id' => 1, 'status' => 'claimed']);
        $this->seedEvent($agency->id, 'comms_tile_message.sent', \App\Models\Contact::class, 2, ['channel' => 'email', 'contact_id' => 2]);

        $feed = app(OutreachActivityFeedService::class)->feed($agency->id, ['days' => 3650, 'source' => 'comms_tile']);

        // Subtotals reflect ALL activity (honest breakdown)...
        $this->assertSame(1, $feed['subtotals']['mic_prospect']);
        $this->assertSame(1, $feed['subtotals']['comms_tile']);
        // ...but the visible rows are only the filtered source.
        $this->assertCount(1, $feed['rows']);
        $this->assertSame('comms_tile', $feed['rows'][0]['source']);
    }

    private function seedEvent(int $agencyId, string $type, string $subjectType, int $subjectId, array $payload): void
    {
        AgentActivityEvent::create([
            'agency_id'    => $agencyId,
            'user_id'      => null,
            'event_type'   => $type,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'payload'      => $payload,
            'occurred_at'  => now(),
            'created_at'   => now(),
        ]);
    }
}
