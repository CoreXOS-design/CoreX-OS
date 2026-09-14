<?php

declare(strict_types=1);

namespace Tests\Feature\ViewingPack;

use App\Models\Contact;
use App\Models\User;
use App\Models\ViewingPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-393 — Viewing Packs list filters: search (pack title OR buyer name), agent,
 * status, composing with the archived toggle and carried by the pagination.
 * Spec: .ai/specs/viewing-pack.md §"List page filters".
 *
 * On an unseeded grants table the permission layer takes its TEST-SUITE posture
 * (admin=all, branch_manager=branch, agent=own) — same as ViewingPackCalendarPermissionTest.
 */
final class ViewingPackIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $admin;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin  = $this->user('admin', 'Ada Admin');
        $this->agentA = $this->user('agent', 'Amos Adams');
        $this->agentB = $this->user('agent', 'Bella Botha');
    }

    private function user(string $role, string $name): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => $role, 'name' => $name,
        ]);
    }

    private function buyer(string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'created_by_user_id' => $this->admin->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => 'b-' . Str::random(6) . '@example.test',
        ]);
    }

    private function pack(string $title, User $agent, Contact $buyer, string $status = ViewingPack::STATUS_DRAFT): ViewingPack
    {
        return ViewingPack::create([
            'agency_id'  => $this->agencyId,
            'branch_id'  => $this->branchId,
            'contact_id' => $buyer->id,
            'agent_id'   => $agent->id,
            'status'     => $status,
            'title'      => $title,
        ]);
    }

    private function visit(User $as, array $query = [])
    {
        return $this->actingAs($as)->get(route('corex.viewing-packs.index', $query));
    }

    public function test_search_matches_pack_title_or_buyer_name(): void
    {
        $this->pack('Seaview Saturday', $this->agentA, $this->buyer('Nomsa', 'Ngcobo'));
        $this->pack('Ramsgate Run', $this->agentA, $this->buyer('Pieter', 'Prinsloo'));
        $this->pack('Uvongo Units', $this->agentB, $this->buyer('Thandi', 'Zulu'));

        $this->visit($this->admin, ['q' => 'seaview'])
            ->assertOk()->assertSee('Seaview Saturday')->assertDontSee('Ramsgate Run')->assertDontSee('Uvongo Units');

        $this->visit($this->admin, ['q' => 'prinsloo'])
            ->assertOk()->assertSee('Ramsgate Run')->assertDontSee('Seaview Saturday');

        $this->visit($this->admin, ['q' => 'Thandi Zulu'])
            ->assertOk()->assertSee('Uvongo Units')->assertDontSee('Seaview Saturday');
    }

    public function test_agent_and_status_filters_narrow_the_list(): void
    {
        $this->pack('Draft by A', $this->agentA, $this->buyer('One', 'Buyer'));
        $this->pack('Ready by A', $this->agentA, $this->buyer('Two', 'Buyer'), ViewingPack::STATUS_READY);
        $this->pack('Draft by B', $this->agentB, $this->buyer('Three', 'Buyer'));

        $this->visit($this->admin, ['agent_id' => $this->agentA->id])
            ->assertOk()->assertSee('Draft by A')->assertSee('Ready by A')->assertDontSee('Draft by B');

        $this->visit($this->admin, ['status' => ViewingPack::STATUS_READY])
            ->assertOk()->assertSee('Ready by A')->assertDontSee('Draft by A')->assertDontSee('Draft by B');

        $this->visit($this->admin, ['agent_id' => $this->agentA->id, 'status' => ViewingPack::STATUS_DRAFT])
            ->assertOk()->assertSee('Draft by A')->assertDontSee('Ready by A')->assertDontSee('Draft by B');
    }

    public function test_filters_compose_with_the_archived_toggle(): void
    {
        $live = $this->pack('Live Lucerne', $this->agentA, $this->buyer('Live', 'Buyer'));
        $gone = $this->pack('Archived Anerley', $this->agentA, $this->buyer('Gone', 'Buyer'));
        $gone->delete();

        $this->visit($this->admin, ['archived' => 1, 'q' => 'anerley'])
            ->assertOk()->assertSee('Archived Anerley')->assertDontSee('Live Lucerne');

        $res = $this->visit($this->admin, ['archived' => 1, 'q' => 'lucerne']);
        $res->assertOk()->assertDontSee('Live Lucerne')->assertSee('No packs match these filters');
        $this->assertStringContainsString('name="archived" value="1"', $res->getContent(), 'archived toggle rides along with the filter form');
    }

    public function test_agent_picker_is_hidden_for_own_scope_and_filters_never_widen_visibility(): void
    {
        $mine   = $this->pack('Mine', $this->agentA, $this->buyer('My', 'Buyer'));
        $theirs = $this->pack('Theirs', $this->agentB, $this->buyer('Their', 'Buyer'));

        $res = $this->visit($this->agentA, ['agent_id' => $this->agentB->id]);
        $res->assertOk()->assertDontSee('Theirs');
        $this->assertStringNotContainsString('name="agent_id"', $res->getContent(), 'own-scope agent gets no agent picker');

        $this->visit($this->admin)->assertOk()->assertSee('name="agent_id"', false);
    }

    public function test_pagination_carries_the_filters(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->pack(sprintf('Paged %02d', $i), $this->agentA, $this->buyer('P', (string) $i));
        }
        $this->pack('Other', $this->agentB, $this->buyer('O', 'B'));

        $page1 = $this->visit($this->admin, ['agent_id' => $this->agentA->id]);
        $page1->assertOk()->assertDontSee('>Other<', false);
        $this->assertSame(25, substr_count($page1->getContent(), 'Paged '), 'page 1 shows 25 rows');
        $this->assertStringContainsString('agent_id=' . $this->agentA->id . '&amp;page=2', $page1->getContent());

        $page2 = $this->visit($this->admin, ['agent_id' => $this->agentA->id, 'page' => 2]);
        $page2->assertOk();
        $this->assertSame(1, substr_count($page2->getContent(), 'Paged '), 'page 2 shows the last row');
    }
}
