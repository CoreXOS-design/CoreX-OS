<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E-sign walk-fixes FIX 3 — post-save redirect lands on a valid URL.
 *
 * The bug: cdsGenerate redirected to `templates.index` after save, which
 * dropped the agent out of the CDS builder onto the template list page
 * — the walk-test framed this as a 404 because the user lost their
 * builder context entirely. The fix routes the redirect through
 * `templates.edit`, which provisions a fresh CdsDraft and returns the
 * agent to the builder for continued editing.
 *
 * The test posts to /docuperfect/templates/cds/generate with a real
 * authed user + a draft + the required form payload, then follows the
 * redirect chain. Asserts every step in the chain returns 200/302
 * (never 404), and the final destination is the CDS builder.
 */
final class CdsBuilderRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_cds_save_redirect_chain_ends_at_builder_with_200(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        $template = DocuperfectTemplate::create([
            'name'           => 'Redirect Chain Template',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party'],
            'field_mappings' => [],
            'owner_id'       => $user->id,
            'cds_json'       => ['sections' => []],
        ]);
        $draft = CdsDraft::create([
            'user_id'            => $user->id,
            'agency_id'          => $user->agency_id ?? 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Body</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        // First hop — cdsGenerate.
        $resp = $this
            ->actingAs($user)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'      => $draft->id,
                'template_name' => $template->name,
                'is_esign'      => 1,
                'party_mode'    => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier' => 'enhanced',
                'signing_parties' => json_encode(['owner_party']),
                'category'      => 'sales',
                'document_type_id' => null,
            ]);

        // First hop must redirect (302), NOT 404.
        $this->assertNotSame(404, $resp->getStatusCode(), 'cdsGenerate must not 404 (was the walk-test bug)');
        $resp->assertRedirect();

        // Follow the redirect chain. Every hop must be 200 or another 302
        // — never 404. Track the final URL ourselves since TestResponse
        // doesn't expose the request it answered.
        $hops = 0;
        $current = $resp;
        $finalPath = parse_url($resp->headers->get('Location') ?? '', PHP_URL_PATH) ?? '';
        while ($current->isRedirect() && $hops < 5) {
            $hops++;
            $target = $current->headers->get('Location');
            $this->assertNotEmpty($target, 'Redirect target must not be empty');
            // The target is a full URL — extract the path portion.
            $path = parse_url($target, PHP_URL_PATH) ?? $target;
            $finalPath = $path;
            $current = $this->actingAs($user)->get($path);
            $this->assertNotSame(404, $current->getStatusCode(),
                'Redirect chain hop ' . $hops . ' (' . $path . ') must not 404');
        }

        // Final destination — must be 200 AND must be the CDS builder.
        $current->assertOk();
        $this->assertStringContainsString('/templates/cds/builder/', $finalPath,
            'Post-save redirect must land on the CDS builder, not the template list — got ' . $finalPath);
    }

    /**
     * The regression guard COMMIT D should have shipped. The original
     * `CdsBuilderRedirectTest` walked the redirect chain — which works
     * because the chain produces a NEW draft id. It never asserted that
     * the ORIGINAL draft url (the URL the agent's browser tab is sitting
     * on) still resolves after the save. COMMIT 5's `$draft->delete()`
     * soft-deleted that draft, so refreshing the tab 404'd.
     *
     * This test pins the contract: after cdsGenerate runs against draft
     * X, /docuperfect/templates/cds/builder/X must still return 200.
     * No more "save → refresh tab → 404".
     */
    public function test_saved_draft_url_still_resolves_after_save(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        $template = DocuperfectTemplate::create([
            'name'           => 'Refresh Tab Template',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party'],
            'field_mappings' => [],
            'owner_id'       => $user->id,
            'cds_json'       => ['sections' => []],
        ]);
        $draft = CdsDraft::create([
            'user_id'            => $user->id,
            'agency_id'          => $user->agency_id ?? 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Body</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        // Trigger the save.
        $this
            ->actingAs($user)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'      => $draft->id,
                'template_name' => $template->name,
                'is_esign'      => 1,
                'party_mode'    => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier' => 'enhanced',
                'signing_parties' => json_encode(['owner_party']),
                'category'      => 'sales',
                'document_type_id' => null,
            ])->assertRedirect();

        // The key assertion: the agent's stale browser-tab URL still
        // resolves to a 200. Without the walk-fix this hit 404 because
        // Commit 5 soft-deleted the draft on save.
        $refresh = $this
            ->actingAs($user)
            ->get('/docuperfect/templates/cds/builder/' . $draft->id);
        $refresh->assertStatus(200, 'Saved-draft URL must still resolve after save — browser tab refresh should not 404');
    }

    /**
     * The exact Staging bug, at the controller level: a real cdsGenerate()
     * save must persist and stay persisted even while a DIFFERENT user's
     * long-abandoned draft for the same template sits in the table.
     * Before the 2026-09-04 fix, Template::canonicalFieldMappings() tier 1
     * matched any status='draft' row for the template regardless of owner
     * or age, so the abandoned draft below would permanently shadow the
     * save that just happened.
     */
    public function test_save_persists_despite_another_users_abandoned_draft(): void
    {
        $editor = $this->seedAgentWithTemplatePermissions();
        $strangerId = (int) DB::table('users')->insertGetId([
            'name' => 'Abandoned Session User',
            'email' => 'stranger-' . Str::random(8) . '@x.test',
            'password' => bcrypt('p'),
            'role' => 'agent',
            'agency_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $template = DocuperfectTemplate::create([
            'name'           => 'Shadowed Save Template',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party'],
            'field_mappings' => [],
            'owner_id'       => $editor->id,
            'cds_json'       => ['sections' => []],
        ]);

        // The stranger's abandoned draft — old, never touched again.
        $abandoned = CdsDraft::create([
            'user_id'            => $strangerId,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => ['tag-zombie' => ['field_name' => 'zombie_field']],
            'tags'               => [],
            'tagged_html'        => '<p>Zombie</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);
        $abandoned->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

        // The editor's own draft — this is the one being saved for real.
        $draft = CdsDraft::create([
            'user_id'            => $editor->id,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => ['tag-real' => ['field_name' => 'seller_email', 'party' => 'seller']],
            'tags'               => [],
            'tagged_html'        => '<p><span data-tag="tag-real"></span></p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        $this
            ->actingAs($editor)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'      => $draft->id,
                'template_name' => $template->name,
                'is_esign'      => 1,
                'party_mode'    => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier' => 'enhanced',
                'signing_parties' => json_encode(['owner_party']),
                'category'      => 'sales',
                'document_type_id' => null,
            ])->assertRedirect();

        // The save must have persisted — editor_state carries the real save,
        // never the zombie.
        $saved = $template->fresh();
        $this->assertArrayHasKey('tag-real', $saved->editor_state['mappings'] ?? []);
        $this->assertArrayNotHasKey('tag-zombie', $saved->editor_state['mappings'] ?? []);

        // And the canonical accessor — read as EITHER user — must reflect
        // the real save, not the abandoned draft. This is the exact
        // assertion that failed before the fix.
        $this->actingAs($editor);
        $asEditor = $saved->fresh()->canonicalFieldMappings();
        $this->assertArrayHasKey('tag-real', $asEditor);
        $this->assertArrayNotHasKey('tag-zombie', $asEditor);

        $this->actingAs(User::findOrFail($strangerId));
        $asStranger = $saved->fresh()->canonicalFieldMappings();
        $this->assertArrayHasKey('tag-real', $asStranger);
        $this->assertArrayNotHasKey('tag-zombie', $asStranger);

        // The abandoned draft itself must still exist (soft delete only,
        // never hard-deleted) and must NOT have been silently promoted to
        // 'saved' or otherwise disturbed by someone else's save.
        $this->assertDatabaseHas('cds_drafts', [
            'id'     => $abandoned->id,
            'status' => 'draft',
        ]);
        $this->assertNull($abandoned->fresh()->deleted_at);
    }

    /**
     * The editor's OWN other superseded drafts for the same template are
     * flipped to 'abandoned' on save — a status change, never the
     * soft-delete a8af5d10a removed from this exact call site because it
     * 404'd a live browser tab. Confirms the draft's builder URL still
     * resolves after the flip.
     */
    public function test_own_superseded_draft_is_flipped_to_abandoned_not_soft_deleted(): void
    {
        $editor = $this->seedAgentWithTemplatePermissions();
        $template = DocuperfectTemplate::create([
            'name'           => 'Sibling Cleanup Template',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party'],
            'field_mappings' => [],
            'owner_id'       => $editor->id,
            'cds_json'       => ['sections' => []],
        ]);

        // An earlier, superseded session of the SAME editor's, left behind
        // by e.g. a closed tab that never clicked Save.
        $stray = CdsDraft::create([
            'user_id'            => $editor->id,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Stray</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        $draft = CdsDraft::create([
            'user_id'            => $editor->id,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'mappings'           => [],
            'tags'               => [],
            'tagged_html'        => '<p>Body</p>',
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ]);

        $this
            ->actingAs($editor)
            ->from('/docuperfect/templates/cds/builder/' . $draft->id)
            ->post('/docuperfect/templates/cds/generate', [
                'draft_id'      => $draft->id,
                'template_name' => $template->name,
                'is_esign'      => 1,
                'party_mode'    => 'shared',
                'allowed_delivery_modes' => 'esign',
                'security_tier' => 'enhanced',
                'signing_parties' => json_encode(['owner_party']),
                'category'      => 'sales',
                'document_type_id' => null,
            ])->assertRedirect();

        $this->assertDatabaseHas('cds_drafts', [
            'id'     => $stray->id,
            'status' => 'abandoned',
        ]);
        $this->assertNull($stray->fresh()->deleted_at, 'Sibling cleanup must flip status, never soft-delete on this path');

        // The stray draft's own builder URL must still resolve — the exact
        // regression a8af5d10a fixed, now re-guarded for the 'abandoned'
        // status too.
        $this->actingAs($editor)
            ->get('/docuperfect/templates/cds/builder/' . $stray->id)
            ->assertStatus(200);
    }

    private function seedAgentWithTemplatePermissions(): User
    {
        // Seed an owner-flagged role so PermissionService::userHasPermission
        // shortcuts to true. The Role model caches its all-rows snapshot
        // statically (Role::$cachedRoles), so we MUST call clearCache()
        // after the insert — otherwise an earlier test in the same suite
        // run primes the cache without our new role, and our user's
        // permission check returns false (the in-isolation test pass
        // didn't catch this because the cache started empty).
        DB::table('roles')->insertOrIgnore([
            'name' => 'test_template_owner',
            'label' => 'Test Template Owner',
            'is_owner' => true,
            'can_be_deleted' => false,
            'sort_order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Models\Role::clearCache();
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Agent Tester',
            'email' => 't-' . Str::random(8) . '@x.test',
            'password' => bcrypt('p'),
            'role' => 'test_template_owner',
            'is_admin' => 1,
            'agency_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::findOrFail($userId);
    }
}
