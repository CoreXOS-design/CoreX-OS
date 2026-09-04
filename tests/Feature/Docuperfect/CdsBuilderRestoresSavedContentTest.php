<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-04 — the CDS builder must reopen the document the agent SAVED,
 * not the document that was originally imported.
 *
 * The bug: cdsBuilder() decided whether to restore saved content with
 *
 *     $hasSavedState = !empty($draft->tags) && !empty($draft->mappings);
 *
 * which asks whether the document has TAGGED FIELDS, not whether it has
 * SAVED CONTENT. A template with nothing to bind — headings, a table, an
 * insertable-block marker — is perfectly legitimate; ADDENDUM B on live is
 * exactly that. For every such template the builder took the FRESH path on
 * every load, re-rendered the original cds_json, and never injected the
 * saved tagged_html at all.
 *
 * The result was silent, total loss of authoring work. The save itself
 * succeeded every time: draft, template editor_state and the published
 * blade were all written correctly, and the database could be shown to
 * prove it. Then the builder reopened and redrew the untouched import. The
 * agent's report was "I click save and it does not save anything", and
 * every check against stored data said the save had worked — which is how
 * this survived several rounds of investigation aimed at the wrong end of
 * the problem.
 *
 * These tests pin both directions of the contract.
 */
final class CdsBuilderRestoresSavedContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The regression itself: saved content, zero tagged fields. The builder
     * must still hand the page the saved document.
     */
    public function test_untagged_template_still_restores_its_saved_content(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        $saved = '<div class="corex-h1">ADDENDUM B</div>'
               . '<p data-insertable-block-marker="1">~~~~OTHER_CONDITIONS~~~~</p>';

        $draft = $this->makeDraft($user, [
            'tags'        => [],   // nothing to bind — a legitimate document
            'mappings'    => [],
            'tagged_html' => $saved,
        ]);

        $response = $this->actingAs($user)->get('/docuperfect/templates/cds/builder/' . $draft->id);
        $response->assertOk();

        $this->assertTrue(
            $response->viewData('hasSavedState'),
            'A draft with saved content and no tagged fields must still take the restore path — '
            . 'gating on tags/mappings silently discarded every save on an untagged template'
        );
        $this->assertSame($saved, $response->viewData('savedTaggedHtml'));
    }

    /**
     * The other direction: a freshly imported draft has no saved content
     * yet, so it must still take the FRESH path and get its automatic
     * field parse. The fix must not turn every import into a restore.
     */
    public function test_fresh_import_still_takes_the_parse_path(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();

        $draft = $this->makeDraft($user, [
            'tags'        => [],
            'mappings'    => [],
            'tagged_html' => null,   // never saved — exactly what the importer creates
        ]);

        $response = $this->actingAs($user)->get('/docuperfect/templates/cds/builder/' . $draft->id);
        $response->assertOk();

        $this->assertFalse(
            $response->viewData('hasSavedState'),
            'A never-saved import must still be parsed fresh, or auto-tagging never runs'
        );
    }

    /**
     * A tagged template must keep behaving as it always did — this is the
     * case that worked before and masked the bug.
     */
    public function test_tagged_template_restores_as_before(): void
    {
        $user = $this->seedAgentWithTemplatePermissions();
        $saved = '<p><span data-tag-id="tag-1">[Seller]</span></p>';

        $draft = $this->makeDraft($user, [
            'tags'        => [['id' => 'tag-1', 'type' => 'input']],
            'mappings'    => ['tag-1' => ['label' => 'Seller']],
            'tagged_html' => $saved,
        ]);

        $response = $this->actingAs($user)->get('/docuperfect/templates/cds/builder/' . $draft->id);
        $response->assertOk();
        $this->assertTrue($response->viewData('hasSavedState'));
        $this->assertSame($saved, $response->viewData('savedTaggedHtml'));
    }

    private function makeDraft(User $user, array $overrides): CdsDraft
    {
        $template = DocuperfectTemplate::create([
            'name'            => 'Restore Test Template',
            'render_type'     => 'web',
            'template_type'   => 'cds',
            'category'        => 'sales',
            'signing_parties' => ['owner_party'],
            'field_mappings'  => [],
            'owner_id'        => $user->id,
            'cds_json'        => ['sections' => []],
        ]);

        return CdsDraft::create(array_merge([
            'user_id'            => $user->id,
            'agency_id'          => $user->agency_id ?? 1,
            'template_name'      => $template->name,
            'cds_json'           => ['sections' => []],
            'settings'           => [],
            'source_template_id' => $template->id,
            'status'             => 'draft',
        ], $overrides));
    }

    private function seedAgentWithTemplatePermissions(): User
    {
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
