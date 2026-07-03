<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-168 Part C — the conversation thread view CRUD: open on the newest page,
 * lazy-load older, and search within the thread.
 */
final class WaThreadPagingTest extends TestCase
{
    use RefreshDatabase;

    private const THREAD = 'wa:713510291';

    private int $agencyId;
    private User $user;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['communications.thread_page_size' => 3]);

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Elize', 'last_name' => 'Reichel', 'phone' => '0713510291',
        ]);

        // Five messages, oldest → newest, distinct bodies for search.
        foreach (range(1, 5) as $i) {
            $this->msg("Message number {$i} body", now()->subMinutes(60 - $i * 5));
        }
    }

    public function test_thread_opens_on_the_newest_page(): void
    {
        $resp = $this->actingAs($this->user)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => self::THREAD]));

        $resp->assertOk();
        $this->assertSame(5, $resp->viewData('total'));
        $this->assertSame(3, $resp->viewData('messages')->count(), 'only the newest page renders');
        $this->assertTrue($resp->viewData('hasMore'), 'older pages remain');
        // Newest three are messages 3,4,5; message 1 (oldest) is NOT on the first page.
        $resp->assertSee('Message number 5 body');
        $resp->assertSee('Message number 3 body');
        $resp->assertDontSee('Message number 1 body');
    }

    public function test_older_endpoint_returns_the_previous_page(): void
    {
        $thread = $this->actingAs($this->user)
            ->get(route('compliance.comm-archive.thread', ['threadKey' => self::THREAD]));
        $cursor = $thread->viewData('olderCursor');
        $this->assertNotNull($cursor);

        $resp = $this->actingAs($this->user)->getJson(
            route('api.v1.communications.threads.older', ['threadKey' => self::THREAD])
            . '?before_at=' . urlencode($cursor['before_at']) . '&before_id=' . $cursor['before_id']
        );

        $resp->assertOk();
        $json = $resp->json();
        $this->assertSame(2, $json['count'], 'the remaining two older messages');
        $this->assertFalse($json['has_more'], 'no more after the second page');
        $this->assertStringContainsString('Message number 1 body', $json['html']);
        $this->assertStringContainsString('Message number 2 body', $json['html']);
    }

    public function test_search_within_thread_finds_matches(): void
    {
        $resp = $this->actingAs($this->user)->getJson(
            route('api.v1.communications.threads.search', ['threadKey' => self::THREAD]) . '?q=' . urlencode('number 4')
        );

        $resp->assertOk();
        $matches = $resp->json('matches');
        $this->assertCount(1, $matches);
        $this->assertStringContainsString('Message number 4', $matches[0]['preview']);
    }

    public function test_search_requires_two_chars(): void
    {
        $resp = $this->actingAs($this->user)->getJson(
            route('api.v1.communications.threads.search', ['threadKey' => self::THREAD]) . '?q=a'
        );
        $resp->assertOk();
        $this->assertSame([], $resp->json('matches'));
    }

    private function msg(string $body, \DateTimeInterface $at): Communication
    {
        $comm = Communication::create([
            'agency_id'   => $this->agencyId,
            'channel'     => Communication::CHANNEL_WHATSAPP,
            'direction'   => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key'  => self::THREAD,
            'wa_chat_id'  => '222758646611979@lid',
            'from_identifier' => '27713510291',
            'body_text'   => $body,
            'body_status' => 'captured',
            'owner_user_id' => $this->user->id,
            'occurred_at' => $at,
            'captured_at' => now(),
        ]);
        CommunicationLink::create([
            'agency_id' => $this->agencyId, 'communication_id' => $comm->id,
            'linkable_type' => Contact::class, 'linkable_id' => $this->contact->id,
            'link_method' => CommunicationLink::METHOD_DETERMINISTIC, 'confidence' => 100, 'confirmed_at' => now(),
        ]);
        return $comm;
    }
}
