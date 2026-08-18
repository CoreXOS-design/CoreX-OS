<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyComment;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC property row comments — .ai/specs/mic-property-row-comments.md.
 *
 * Proves: happy path (add/list/edit/remove), zero vs several counts, own-vs-
 * others edit/remove authorisation (author-only edit; author-or-manage
 * remove), soft delete (no hard deletes, count excludes removed), malformed
 * input (empty/over-length body rejected without a 500), agency isolation
 * (cross-agency 404, zero leakage), and the missing-permission 403 path.
 */
final class TrackedPropertyCommentsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyAId;
    private int $agencyBId;
    private TrackedProperty $tpA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        PermissionService::clearCache();

        $this->agencyAId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency A ' . Str::random(5), 'slug' => 'aa-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyAId, 'agency_id' => $this->agencyAId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agencyBId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency B ' . Str::random(5), 'slug' => 'ab-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyBId, 'agency_id' => $this->agencyBId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        RolePermission::insert([
            ['role' => 'agent',          'permission_key' => 'access_prospecting',   'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent',          'permission_key' => 'mic.comments.view',    'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'agent',          'permission_key' => 'mic.comments.add',     'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'access_prospecting',   'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'mic.comments.view',    'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'mic.comments.add',     'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'branch_manager', 'permission_key' => 'prospecting_setup.manage', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            // 'viewer' deliberately gets access_prospecting but NEITHER comments key —
            // the missing-permission 403 path.
            ['role' => 'viewer',         'permission_key' => 'access_prospecting',   'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        $this->tpA = TrackedProperty::create([
            'agency_id'   => $this->agencyAId,
            'street_name' => 'Marine Drive',
            'suburb'      => 'Margate',
        ]);
    }

    private function agent(int $agencyId): User
    {
        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
    }

    private function manager(int $agencyId): User
    {
        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'branch_manager']);
    }

    private function viewer(int $agencyId): User
    {
        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'viewer']);
    }

    public function test_zero_comments_returns_zero_count_and_empty_list(): void
    {
        $agent = $this->agent($this->agencyAId);

        $res = $this->actingAs($agent)->getJson(
            route('corex.tracked-properties.comments.index', $this->tpA)
        );

        $res->assertOk();
        $this->assertStringContainsString('No comments yet', $res->getContent());
        $this->assertSame(0, TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->count());
    }

    public function test_happy_path_add_list_edit_remove(): void
    {
        $author = $this->agent($this->agencyAId);
        $other  = $this->agent($this->agencyAId);

        // Add — the lazy-but-valid shortcut (just a body, nothing else).
        $add = $this->actingAs($author)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'Owner wants a quick sale, motivated seller.']
        );
        $add->assertOk();
        $add->assertJson(['success' => true, 'count' => 1]);
        $comment = TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->firstOrFail();
        $this->assertSame($author->id, $comment->user_id);
        $this->assertNull($comment->edited_at);

        // Second comment from a different agent — several, right number, cross-agent.
        $this->actingAs($other)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'Confirmed with the owner — still on the market.']
        )->assertJson(['count' => 2]);

        // List shows both, author + timestamp present.
        $list = $this->actingAs($author)->getJson(route('corex.tracked-properties.comments.index', $this->tpA));
        $list->assertOk();
        $this->assertStringContainsString($author->name, $list->getContent());
        $this->assertStringContainsString($other->name, $list->getContent());

        // Edit own comment.
        $edit = $this->actingAs($author)->patchJson(
            route('corex.tracked-properties.comments.update', [$this->tpA, $comment]),
            ['body' => 'UPDATE: owner accepted an offer already, remove from pool.']
        );
        $edit->assertOk();
        $comment->refresh();
        $this->assertSame('UPDATE: owner accepted an offer already, remove from pool.', $comment->body);
        $this->assertNotNull($comment->edited_at);

        // Remove own comment — soft delete, count drops, row still in DB.
        $remove = $this->actingAs($author)->deleteJson(
            route('corex.tracked-properties.comments.destroy', [$this->tpA, $comment])
        );
        $remove->assertOk();
        $remove->assertJson(['count' => 1]);
        $this->assertSoftDeleted('tracked_property_comments', ['id' => $comment->id]);
    }

    public function test_editing_someone_elses_comment_is_forbidden(): void
    {
        $author = $this->agent($this->agencyAId);
        $intruder = $this->agent($this->agencyAId);

        $this->actingAs($author)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'Owner is difficult to reach, best call after 5pm.']
        );
        $comment = TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->firstOrFail();

        $this->actingAs($intruder)->patchJson(
            route('corex.tracked-properties.comments.update', [$this->tpA, $comment]),
            ['body' => 'hijacked']
        )->assertForbidden();

        $this->assertSame($author->id, $comment->fresh()->user_id);
    }

    public function test_removing_someone_elses_comment_forbidden_for_plain_agent_but_allowed_for_manager(): void
    {
        $author  = $this->agent($this->agencyAId);
        $bystander = $this->agent($this->agencyAId);
        $manager = $this->manager($this->agencyAId);

        $this->actingAs($author)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'Neighbour mentioned the owner is relocating for work.']
        );
        $comment = TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->firstOrFail();

        // A plain agent who is neither the author nor a manager: 403.
        $this->actingAs($bystander)->deleteJson(
            route('corex.tracked-properties.comments.destroy', [$this->tpA, $comment])
        )->assertForbidden();
        $this->assertNull($comment->fresh()->deleted_at);

        // prospecting_setup.manage holder: allowed, even though not the author.
        $this->actingAs($manager)->deleteJson(
            route('corex.tracked-properties.comments.destroy', [$this->tpA, $comment])
        )->assertOk();
        $this->assertSoftDeleted('tracked_property_comments', ['id' => $comment->id]);
    }

    public function test_empty_and_over_length_body_rejected_without_500(): void
    {
        $agent = $this->agent($this->agencyAId);

        $this->actingAs($agent)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => '']
        )->assertStatus(422);

        $this->actingAs($agent)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => str_repeat('x', 1001)]
        )->assertStatus(422);

        // Whitespace-only — trimmed server-side, still fails the min-length rule.
        $this->actingAs($agent)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => '  ']
        )->assertStatus(422);

        $this->assertSame(0, TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->count());
    }

    public function test_missing_permission_is_forbidden(): void
    {
        $viewer = $this->viewer($this->agencyAId);

        $this->actingAs($viewer)->getJson(
            route('corex.tracked-properties.comments.index', $this->tpA)
        )->assertForbidden();

        $this->actingAs($viewer)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'should never land']
        )->assertForbidden();

        $this->assertSame(0, TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->count());
    }

    public function test_agency_isolation_cross_agency_access_is_404_and_zero_leakage(): void
    {
        $agentA = $this->agent($this->agencyAId);
        $agentB = $this->agent($this->agencyBId);

        $this->actingAs($agentA)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'Internal note — do not share outside the branch.']
        )->assertJson(['count' => 1]);

        // Agency B's agent hitting Agency A's TrackedProperty: 404 on every method.
        $this->actingAs($agentB)->getJson(
            route('corex.tracked-properties.comments.index', $this->tpA)
        )->assertNotFound();

        $this->actingAs($agentB)->postJson(
            route('corex.tracked-properties.comments.store', $this->tpA),
            ['body' => 'should never land']
        )->assertNotFound();

        // No leakage: Agency B never sees Agency A's comment count/content anywhere.
        $this->assertSame(1, TrackedPropertyComment::where('tracked_property_id', $this->tpA->id)->count());
    }
}
