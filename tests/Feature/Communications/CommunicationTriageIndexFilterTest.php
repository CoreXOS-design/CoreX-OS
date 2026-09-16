<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationPending;
use App\Models\User;
use App\Services\Communications\CommunicationTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-393 — Review Incoming Messages (triage) list: search (sender / subject /
 * message), channel filter, and 25-per-page pagination carrying the filters.
 * Spec: .ai/specs/claude_communication_archive_triage_addendum.md §10
 *
 * The queue is PHP-filtered per agent (NOT-REAL-ESTATE suppression), so the
 * filters and paging run on the collection; these tests prove they only ever
 * narrow that per-agent set.
 */
final class CommunicationTriageIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);
    }

    private function pending(string $identifier, array $overrides = []): CommunicationPending
    {
        return CommunicationPending::create(array_merge([
            'agency_id'       => $this->agencyId,
            'channel'         => 'whatsapp',
            'direction'       => 'inbound',
            'external_id'     => 'EX-' . Str::random(10),
            'thread_key'      => $identifier,
            'from_identifier' => $identifier,
            'occurred_at'     => now()->subHour(),
            'captured_at'     => now()->subHour(),
            'body_text'       => 'Body ' . Str::random(6),
            'body_preview'    => 'Preview ' . Str::random(6),
            'expires_at'      => now()->addDays(3),
        ], $overrides));
    }

    private function visit(array $query = [])
    {
        return $this->actingAs($this->agent)->get(route('communications.triage.index', $query));
    }

    public function test_search_matches_sender_subject_or_message(): void
    {
        $this->pending('+27821110001', ['body_preview' => 'Looking for a flat in Margate']);
        $this->pending('buyer@seaview.test', ['channel' => 'email', 'subject' => 'Seaview enquiry', 'body_preview' => 'Hello there']);
        $this->pending('+27821110003', ['body_preview' => 'Unrelated plumbing quote']);

        $this->visit(['q' => 'margate'])->assertOk()
            ->assertSee('+27821110001')->assertDontSee('buyer@seaview.test')->assertDontSee('+27821110003');

        $this->visit(['q' => 'seaview enq'])->assertOk()
            ->assertSee('buyer@seaview.test')->assertDontSee('+27821110001');

        $this->visit(['q' => '0003'])->assertOk()
            ->assertSee('+27821110003')->assertDontSee('+27821110001');
    }

    public function test_channel_filter_narrows_and_offers_only_present_channels(): void
    {
        $this->pending('+27821110001');
        $this->pending('buyer@seaview.test', ['channel' => 'email', 'subject' => 'Seaview enquiry']);

        $res = $this->visit(['channel' => 'email']);
        $res->assertOk()->assertSee('buyer@seaview.test')->assertDontSee('+27821110001');
        $this->assertStringContainsString('<option value="email"', $res->getContent());
        $this->assertStringContainsString('<option value="whatsapp"', $res->getContent());
        $this->assertStringNotContainsString('<option value="sms"', $res->getContent(), 'channel list is built from the data, not hard-coded');

        // An unknown channel value is ignored, never trusted.
        $this->visit(['channel' => 'sms'])->assertOk()->assertSee('buyer@seaview.test')->assertSee('+27821110001');
    }

    public function test_filters_never_resurface_a_message_this_agent_dismissed(): void
    {
        $this->pending('+27821110009', ['body_preview' => 'Margate rental']);
        app(CommunicationTriageService::class)->flagNotRealEstate($this->agencyId, $this->agent, '+27821110009', 'Dismissed', 'EX-9');

        $this->visit(['q' => 'margate'])->assertOk()->assertDontSee('+27821110009')
            ->assertSee('No messages match these filters');
    }

    public function test_list_is_paged_at_25_and_page_links_carry_the_filters(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->pending(sprintf('+2782000%04d', $i), ['body_preview' => 'Paged message']);
        }
        $this->pending('other@x.test', ['channel' => 'email', 'body_preview' => 'Other']);

        $page1 = $this->visit(['channel' => 'whatsapp']);
        $page1->assertOk()->assertDontSee('other@x.test');
        $this->assertSame(25, substr_count($page1->getContent(), 'Paged message'), 'page 1 shows 25 rows');
        $this->assertStringContainsString('channel=whatsapp&amp;page=2', $page1->getContent(), 'page-2 link keeps the channel filter');

        $page2 = $this->visit(['channel' => 'whatsapp', 'page' => 2]);
        $page2->assertOk();
        $this->assertSame(1, substr_count($page2->getContent(), 'Paged message'), 'page 2 shows the last row');
    }
}
