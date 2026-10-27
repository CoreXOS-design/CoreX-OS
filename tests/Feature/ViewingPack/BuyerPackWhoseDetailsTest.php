<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Models\Contact;
use App\Models\User;
use App\Models\ViewingPack;
use App\Notifications\BuyerPackMadeByAnotherAgentNotification;
use App\Services\ViewingPack\ViewingPackBuyerPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AT-509 — "Whose details go on the buyer pack?" (.ai/specs/viewing-pack.md, section of the same name).
 *
 * Proves: the choice is only offered when the buyer is under ANOTHER agent; "Show my details" puts the
 * downloader on the cover AND tells the buyer's agent in their notifications; "Show the agent's details"
 * (and any plain download) keeps ruling D and tells nobody; the buyer's own agent is never asked.
 * The PDF itself is not built here (pdfunite) - the service's download() is stubbed and asked who the
 * cover agent is; coverData() is checked directly for the override.
 */
final class BuyerPackWhoseDetailsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $owner;
    private User $other;
    private Contact $buyer;
    private ViewingPack $pack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Seaside ' . Str::random(5), 'slug' => 's-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->owner = $this->user('Olga Owner');
        $this->other = $this->user('Ben Builder');

        $this->buyer = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'created_by_user_id' => $this->owner->id, 'agent_id' => $this->owner->id,
            'first_name' => 'Nomsa', 'last_name' => 'Ngcobo', 'email' => 'n-' . Str::random(6) . '@example.test',
        ]);
        DB::table('contacts')->where('id', $this->buyer->id)->update(['agent_id' => $this->owner->id]);

        // The pack is Ben's (he built it), the buyer is Olga's.
        $this->pack = ViewingPack::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_id' => $this->buyer->id, 'agent_id' => $this->other->id,
            'status' => ViewingPack::STATUS_DRAFT, 'title' => 'Saturday viewings',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function user(string $name): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'name' => $name, 'is_active' => true,
        ]);
    }

    /** Stub the PDF build; record the cover agent download() was given. */
    private function stubPdf(?int &$coverAgentId, bool &$called): void
    {
        $mock = Mockery::mock(ViewingPackBuyerPdfService::class)->makePartial();
        $mock->shouldReceive('download')->andReturnUsing(function ($pack, $coverAgent = null) use (&$coverAgentId, &$called) {
            $called = true;
            $coverAgentId = $coverAgent?->id;

            return response('%PDF-stub');
        });
        $this->app->instance(ViewingPackBuyerPdfService::class, $mock);
    }

    public function test_show_my_details_puts_me_on_the_cover_and_tells_the_buyers_agent(): void
    {
        Notification::fake();
        $cover = null; $called = false;
        $this->stubPdf($cover, $called);

        $this->actingAs($this->other)->get(route('corex.viewing-packs.buyer-pack', $this->pack) . '?details=mine')->assertOk();

        $this->assertTrue($called);
        $this->assertSame($this->other->id, $cover, 'the downloader is the cover agent');
        Notification::assertSentTo($this->owner, BuyerPackMadeByAnotherAgentNotification::class, function ($n) {
            $data = $n->toArray($this->owner);
            $this->assertSame('Ben Builder made a buyer pack for Nomsa Ngcobo', $data['title']);
            $this->assertStringContainsString('/contacts/' . $this->buyer->id, (string) $data['action_url']);
            $this->assertSame(BuyerPackMadeByAnotherAgentNotification::EVENT_KEY, $data['event_key']);

            return true;
        });
        Notification::assertNotSentTo($this->other, BuyerPackMadeByAnotherAgentNotification::class);
    }

    public function test_show_the_agents_details_or_a_plain_download_keeps_ruling_d_and_tells_nobody(): void
    {
        foreach (['?details=agent', ''] as $suffix) {
            Notification::fake();
            $cover = -1; $called = false;
            $this->stubPdf($cover, $called);

            $this->actingAs($this->other)->get(route('corex.viewing-packs.buyer-pack', $this->pack) . $suffix)->assertOk();

            $this->assertTrue($called);
            $this->assertNull($cover, "no override for '{$suffix}' - the buyer's agent stays on the cover");
            Notification::assertNothingSent();
        }
    }

    public function test_the_buyers_own_agent_is_never_overridden_or_notified(): void
    {
        Notification::fake();
        $cover = -1; $called = false;
        $this->stubPdf($cover, $called);
        $this->pack->update(['agent_id' => $this->owner->id]);

        $this->actingAs($this->owner)->get(route('corex.viewing-packs.buyer-pack', $this->pack) . '?details=mine')->assertOk();

        $this->assertNull($cover);
        Notification::assertNothingSent();
    }

    public function test_an_inactive_buyers_agent_is_not_notified(): void
    {
        Notification::fake();
        $cover = null; $called = false;
        $this->stubPdf($cover, $called);
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);

        $this->actingAs($this->other)->get(route('corex.viewing-packs.buyer-pack', $this->pack) . '?details=mine')->assertOk();

        $this->assertSame($this->other->id, $cover);
        Notification::assertNothingSent();
    }

    public function test_the_choice_is_offered_only_when_the_buyer_is_under_another_agent(): void
    {
        $this->actingAs($this->other);
        $html = view('command-center.viewing-packs._buyer-pack-download', ['pack' => $this->pack])->render();
        $this->assertStringContainsString('data-buyer-pack-ask', $html);
        $this->assertStringContainsString('Whose details go on the buyer pack?', $html);
        $this->assertStringContainsString('?details=mine', $html);
        $this->assertStringContainsString('?details=agent', $html);
        $this->assertStringContainsString("Show Olga Owner's details", $html);

        $this->actingAs($this->owner);
        $html = view('command-center.viewing-packs._buyer-pack-download', ['pack' => $this->pack])->render();
        $this->assertStringNotContainsString('data-buyer-pack-ask', $html);
        $this->assertStringNotContainsString('?details=', $html);
        $this->assertStringContainsString(route('corex.viewing-packs.buyer-pack', $this->pack), $html);
    }

    public function test_cover_data_uses_the_chosen_agent_over_the_buyers_agent(): void
    {
        $svc = app(ViewingPackBuyerPdfService::class);
        $m = new \ReflectionMethod($svc, 'coverData');
        $m->setAccessible(true);
        $pack = $this->pack->fresh(['contact', 'agent', 'agency', 'viewingPackProperties']);

        $this->assertSame('Olga Owner', $m->invoke($svc, $pack)['agentName'], 'ruling D by default');
        $this->assertSame('Ben Builder', $m->invoke($svc, $pack, $this->other)['agentName'], 'the chosen agent wins');
    }
}
