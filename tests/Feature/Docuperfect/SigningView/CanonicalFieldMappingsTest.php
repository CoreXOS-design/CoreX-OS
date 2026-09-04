<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E-sign reset Commit 5 — Source-of-truth collapse for field_mappings.
 *
 * The investigation Q1 found six parallel storage sites for "what
 * fields does this template have" — cds_json, editor_state.tags,
 * editor_state.mappings, editor_state.tagged_html, field_mappings,
 * fields_json, blade_view — with no canonical owner. The divergence
 * is what caused the "save 1 seller block, reload 4 seller blocks"
 * revert bug.
 *
 * These tests pin the new contract:
 *
 *   • canonicalFieldMappings() prefers drafts > editor_state > legacy
 *   • a draft only wins tier 1 when it belongs to the current viewer
 *     AND is genuinely newer than the template's own last save
 *     (2026-09-04 fix — see Template::canonicalFieldMappings() docblock;
 *     an abandoned draft used to permanently outrank every future save)
 *   • pruneOrphanFieldMappings() removes tag-ids absent from the
 *     live tagged_html / cds_json sources
 */
final class CanonicalFieldMappingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_falls_back_to_field_mappings_column_when_no_draft(): void
    {
        $template = $this->seedTemplate([
            'field_mappings' => $this->fixtureMappings(),
        ]);

        $canonical = $template->canonicalFieldMappings();
        $this->assertCount(2, $canonical);
        $this->assertSame('seller_address', $canonical['tag-a']['field_name']);
    }

    public function test_canonical_prefers_editor_state_mappings_over_legacy_column(): void
    {
        $editorStateMappings = ['tag-z' => ['field_name' => 'seller_phone', 'party' => 'seller']];
        $template = $this->seedTemplate([
            'field_mappings' => $this->fixtureMappings(), // 2 entries
            'editor_state'   => ['mappings' => $editorStateMappings],
        ]);

        $canonical = $template->canonicalFieldMappings();
        $this->assertCount(1, $canonical);
        $this->assertSame('seller_phone', $canonical['tag-z']['field_name']);
    }

    public function test_canonical_prefers_draft_over_editor_state(): void
    {
        $template = $this->seedTemplate([
            'field_mappings' => $this->fixtureMappings(),
            'editor_state'   => ['mappings' => ['tag-z' => ['field_name' => 'fallback']]],
        ]);
        $owner = User::findOrFail($template->owner_id);
        $draftMappings = ['tag-q' => ['field_name' => 'seller_id_number', 'party' => 'seller']];
        DB::table('cds_drafts')->insert([
            'user_id'            => $template->owner_id,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'source_template_id' => $template->id,
            'cds_json'           => json_encode(['sections' => []]),
            'mappings'           => json_encode($draftMappings),
            'tags'               => json_encode([]),
            'tagged_html'        => '',
            'settings'           => json_encode([]),
            'status'             => 'draft',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // The draft belongs to, and is being read by, the same user who
        // owns it — the case tier 1 exists for (an agent's own live
        // in-progress edit outranking the last full save).
        $this->actingAs($owner);
        $canonical = $template->fresh()->canonicalFieldMappings();
        $this->assertCount(1, $canonical);
        $this->assertSame('seller_id_number', $canonical['tag-q']['field_name']);
    }

    public function test_a_different_users_stale_draft_never_shadows_a_fresh_save(): void
    {
        // Reproduces the Staging bug directly: template's editor_state
        // holds the FRESH, just-saved state. A different user has an
        // old, abandoned status='draft' row for the same template that
        // predates that save. Whoever now reads canonicalFieldMappings()
        // — even the abandoned draft's own owner, reading as themselves —
        // must get the fresh save, never the stale draft.
        $freshMappings = ['tag-fresh' => ['field_name' => 'seller_email', 'party' => 'seller']];
        $template = $this->seedTemplate([
            'editor_state' => ['mappings' => $freshMappings],
        ]);
        // seedTemplate() creates the template "now" — the abandoned draft
        // below is backdated 4 days, the way a real save's updated_at
        // naturally postdates a long-idle draft's.

        $strangerId = (int) DB::table('users')->insertGetId([
            'name' => 'Stranger', 'email' => 's-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cds_drafts')->insert([
            'user_id'            => $strangerId,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'source_template_id' => $template->id,
            'cds_json'           => json_encode(['sections' => []]),
            'mappings'           => json_encode(['tag-stale' => ['field_name' => 'zombie_field']]),
            'tags'               => json_encode([]),
            'tagged_html'        => '',
            'settings'           => json_encode([]),
            'status'             => 'draft',
            'created_at'         => now()->subDays(4),
            'updated_at'         => now()->subDays(4),
        ]);

        // Read as the stranger who owns the abandoned draft — still must
        // not win, because it predates the template's last save.
        $this->actingAs(User::findOrFail($strangerId));
        $canonical = $template->fresh()->canonicalFieldMappings();
        $this->assertArrayHasKey('tag-fresh', $canonical);
        $this->assertArrayNotHasKey('tag-stale', $canonical, 'An abandoned draft older than the last save must never shadow it');
    }

    public function test_canonical_skips_tier_one_with_no_authenticated_viewer(): void
    {
        // Console/queue context — no "current session" to prefer, so a
        // draft (however recent) must not be picked arbitrarily.
        $template = $this->seedTemplate([
            'editor_state' => ['mappings' => ['tag-fresh' => ['field_name' => 'seller_email']]],
        ]);
        DB::table('cds_drafts')->insert([
            'user_id'            => $template->owner_id,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'source_template_id' => $template->id,
            'cds_json'           => json_encode(['sections' => []]),
            'mappings'           => json_encode(['tag-q' => ['field_name' => 'seller_id_number']]),
            'tags'               => json_encode([]),
            'tagged_html'        => '',
            'settings'           => json_encode([]),
            'status'             => 'draft',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $canonical = $template->fresh()->canonicalFieldMappings();
        $this->assertArrayHasKey('tag-fresh', $canonical);
        $this->assertArrayNotHasKey('tag-q', $canonical);
    }

    public function test_prune_removes_field_mappings_not_referenced_in_editor_state(): void
    {
        // Template has 3 mappings; only 2 of those tag-ids actually
        // appear in the tagged_html the agent saved. The third is an
        // orphan from a previously-deleted block.
        $template = $this->seedTemplate([
            'field_mappings' => [
                'tag-alive-1' => ['field_name' => 'seller_first_name'],
                'tag-alive-2' => ['field_name' => 'seller_address'],
                'tag-orphan'  => ['field_name' => 'seller_zombie'],
            ],
            'editor_state' => [
                'tagged_html' => '<p><span data-tag="tag-alive-1"></span><span data-tag="tag-alive-2"></span></p>',
            ],
        ]);

        $removed = $template->pruneOrphanFieldMappings();
        $this->assertSame(1, $removed);

        $canonical = $template->fresh()->canonicalFieldMappings();
        $this->assertArrayHasKey('tag-alive-1', $canonical);
        $this->assertArrayHasKey('tag-alive-2', $canonical);
        $this->assertArrayNotHasKey('tag-orphan', $canonical);
    }

    public function test_prune_is_no_op_when_no_reference_source_exists(): void
    {
        // No tagged_html, no cds_json — the prune can't safely decide
        // what's alive vs orphan, so it does nothing rather than
        // nuking everything.
        $template = $this->seedTemplate([
            'field_mappings' => $this->fixtureMappings(),
        ]);

        $removed = $template->pruneOrphanFieldMappings();
        $this->assertSame(0, $removed);
        $this->assertCount(2, $template->fresh()->canonicalFieldMappings());
    }

    // ── Helpers ──

    /**
     * @param  array<string, mixed> $overrides
     */
    private function seedTemplate(array $overrides = []): DocuperfectTemplate
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Tester', 'email' => 't-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return DocuperfectTemplate::create(array_merge([
            'name' => 'Canonical accessor test',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'owner_id' => $userId,
        ], $overrides));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fixtureMappings(): array
    {
        return [
            'tag-a' => ['field_name' => 'seller_address', 'party' => 'seller', 'editable_by' => ['owner_party', 'agent']],
            'tag-b' => ['field_name' => 'seller_phone',   'party' => 'seller', 'editable_by' => ['owner_party', 'agent']],
        ];
    }
}
