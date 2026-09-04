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
 *   • pruneOrphanFieldMappings() removes tag-ids absent from the
 *     live tagged_html / cds_json sources
 *   • The cdsGenerate save path applies the prune and cleans up
 *     stale CdsDraft rows
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
        $this->seedDraft($template, (int) $template->owner_id, [
            'tag-q' => ['field_name' => 'seller_id_number', 'party' => 'seller'],
        ]);

        $this->actingAs(User::findOrFail($template->owner_id));

        $canonical = $template->canonicalFieldMappings();
        $this->assertCount(1, $canonical);
        $this->assertSame('seller_id_number', $canonical['tag-q']['field_name']);
    }

    /**
     * The live bug, 2026-09-04: editing an existing template and saving
     * did not persist — the old content came back on reload.
     *
     * `edit()` mints a fresh status='draft' row on every save and
     * nothing ever cleans an abandoned one up, while paths such as
     * saveContent() write editor_state on the template without touching
     * that row. One abandoned editing session therefore outranked every
     * later save, permanently. The draft must lose once the template
     * has been saved past it.
     */
    public function test_canonical_ignores_a_draft_older_than_the_templates_own_save(): void
    {
        $template = $this->seedTemplate([
            'editor_state' => ['mappings' => ['tag-fresh' => ['field_name' => 'seller_email']]],
        ]);

        // Abandoned session from an hour ago, still status='draft'.
        $this->seedDraft($template, (int) $template->owner_id, [
            'tag-stale' => ['field_name' => 'seller_stale_value'],
        ], now()->subHour());

        // The template itself was saved after that draft was abandoned.
        $template->forceFill(['updated_at' => now()])->save();

        $this->actingAs(User::findOrFail($template->owner_id));

        $canonical = $template->fresh()->canonicalFieldMappings();
        $this->assertArrayNotHasKey('tag-stale', $canonical, 'An abandoned draft must not outrank a later save.');
        $this->assertSame('seller_email', $canonical['tag-fresh']['field_name']);
    }

    public function test_canonical_ignores_another_users_in_progress_draft(): void
    {
        $template = $this->seedTemplate([
            'editor_state' => ['mappings' => ['tag-fresh' => ['field_name' => 'seller_email']]],
        ]);
        $otherUserId = $this->seedUser();
        $this->seedDraft($template, $otherUserId, [
            'tag-theirs' => ['field_name' => 'seller_someone_elses_work'],
        ]);

        $this->actingAs(User::findOrFail($template->owner_id));

        $canonical = $template->canonicalFieldMappings();
        $this->assertArrayNotHasKey('tag-theirs', $canonical, "One person's unsaved work must not leak into another's render.");
        $this->assertSame('seller_email', $canonical['tag-fresh']['field_name']);
    }

    public function test_canonical_ignores_drafts_entirely_when_there_is_no_authenticated_user(): void
    {
        // Queue jobs and CLI renders have no current user, so there is
        // no "my in-progress work" to prefer — they must always read
        // the template's own saved state.
        $template = $this->seedTemplate([
            'editor_state' => ['mappings' => ['tag-fresh' => ['field_name' => 'seller_email']]],
        ]);
        $this->seedDraft($template, (int) $template->owner_id, [
            'tag-draft' => ['field_name' => 'seller_in_progress'],
        ]);

        $canonical = $template->canonicalFieldMappings();
        $this->assertArrayNotHasKey('tag-draft', $canonical);
        $this->assertSame('seller_email', $canonical['tag-fresh']['field_name']);
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
        $userId = $this->seedUser();
        return DocuperfectTemplate::create(array_merge([
            'name' => 'Canonical accessor test',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party'],
            'owner_id' => $userId,
        ], $overrides));
    }

    private function seedUser(): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Tester', 'email' => 't-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>> $mappings
     */
    private function seedDraft(
        DocuperfectTemplate $template,
        int $userId,
        array $mappings,
        ?\Illuminate\Support\Carbon $touchedAt = null
    ): int {
        $touchedAt ??= now();

        return (int) DB::table('cds_drafts')->insertGetId([
            'user_id'            => $userId,
            'agency_id'          => 1,
            'template_name'      => $template->name,
            'source_template_id' => $template->id,
            'cds_json'           => json_encode(['sections' => []]),
            'mappings'           => json_encode($mappings),
            'tags'               => json_encode([]),
            'tagged_html'        => '',
            'settings'           => json_encode([]),
            'status'             => 'draft',
            'created_at'         => $touchedAt,
            'updated_at'         => $touchedAt,
        ]);
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
