<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Branch;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateGeneration;
use App\Models\Document;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsReimportService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Docuperfect\TemplateTransfer\TransferFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Re-import from Word on a saved CDS template. Spec: .ai/specs/cds-reimport-from-word.md.
 * Real .docx files go through the real parser; nothing here is mocked.
 */
final class CdsReimportFromWordTest extends TestCase
{
    use RefreshDatabase;
    use TransferFixtures;

    private const V1 = [
        'LEASE AGREEMENT',
        'Seller: ~~~~Seller - Full name~~~~',
        'Address: ~~~~Seller - Physical address~~~~',
        'Erf: ~~~~Property - Erf~~~~',
        'Price: ~~~~Asking price (Rand)~~~~',
    ];

    /** Corrected file: a new paragraph + a new field ABOVE the others, and the price field removed. */
    private const V2 = [
        'LEASE AGREEMENT',
        'A newly added introductory paragraph that was not in the first file.',
        'Email: ~~~~Seller - Email~~~~',
        'Seller: ~~~~Seller - Full name~~~~',
        'Address: ~~~~Seller - Physical address~~~~',
        'Erf: ~~~~Property - Erf~~~~',
    ];

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('template-sources');
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function docxBytes(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reimp') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $body = '';
        foreach ($lines as $l) {
            $body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($l, ENT_XML1) . '</w:t></w:r></w:p>';
        }
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function upload(array $lines, string $name = 'lease-v2.docx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->docxBytes($lines));
    }

    private function parse(array $lines): array
    {
        $path = tempnam(sys_get_temp_dir(), 'reimp') . '.docx';
        file_put_contents($path, $this->docxBytes($lines));
        try {
            return app(CdsParserService::class)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    /** An agency user with the keys the builder needs. $perms=[] makes a user with none. */
    private function agencyUser($agency, string $role, array $perms): User
    {
        PermissionService::forceProductionPosture();
        // Branch stamps the AUTHENTICATED user's agency on create; vouch for this one explicitly.
        $branch = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $agency->id, 'name' => 'HQ ' . $role]));
        Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $agency->id]);
        foreach ($perms as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id], []);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function admin($agency): User
    {
        return $this->agencyUser($agency, 'admin', ['manage_templates', 'access_docuperfect']);
    }

    /** A saved CDS template whose every field is bound to a recognisable label. */
    private function cdsTemplate($agency, User $owner): Template
    {
        $cds = $this->parse(self::V1);
        $tags = [];
        $mappings = [];
        foreach (app(CdsReimportService::class)->fieldList($cds) as $f) {
            $id = 'tag-old-' . $f['index'];
            $tags[] = ['id' => $id, 'type' => 'input', 'label' => '[INPUT ' . ($f['index'] + 1) . ']', 'number' => $f['index'] + 1, 'parserIndex' => $f['index']];
            $mappings[$id] = ['mappingType' => 'named_field', 'typeKey' => 'sf:t' . $f['index'], 'label' => 'BOUND ' . $f['key'], 'party' => 'owner_party', 'editable_by' => ['agent'], 'sourceType' => 'contact', 'sourceContactType' => 'seller'];
        }

        return Template::create([
            'name' => 'Mega Lease Agreement 2026', 'template_type' => 'cds', 'render_type' => 'web', 'category' => 'rentals',
            'page_count' => 0, 'fields_json' => [], 'is_esign' => true, 'party_mode' => 'per_party',
            'signing_parties' => ['owner_party', 'agent', 'acquiring_party'], 'allowed_delivery_modes' => 'esign,download', 'security_tier' => 'high',
            'cds_json' => $cds,
            'editor_state' => ['tags' => $tags, 'mappings' => $mappings, 'tagged_html' => null, 'document_look' => 'source'],
            'agency_id' => $agency->id, 'owner_id' => $owner->id, 'is_global' => false,
        ]);
    }

    private function keyOf(array $cds, string $needle): int
    {
        foreach (app(CdsReimportService::class)->fieldList($cds) as $f) {
            if (str_contains((string) $f['key'], $needle)) {
                return $f['index'];
            }
        }
        $this->fail("No field with '{$needle}' in the parsed file");
    }

    private function stage(User $user, Template $t, array $lines = self::V2)
    {
        return $this->actingAs($user)->post(route('docuperfect.templates.reimport', $t->id), ['document' => $this->upload($lines)]);
    }

    private function save(User $user, CdsDraft $draft, Template $t)
    {
        return $this->actingAs($user)->post(route('docuperfect.cds.generate'), [
            'draft_id' => $draft->id,
            'template_name' => $t->name,
            'party_mode' => 'per_party',
            'allowed_delivery_modes' => 'esign,download',
            'security_tier' => 'high',
            'signing_parties' => json_encode(['owner_party', 'agent', 'acquiring_party']),
            'category' => 'rentals',
            'is_esign' => '1',
            'document_look' => 'source',
        ]);
    }

    // ── upload: nothing changes yet, report is right ─────────────────────

    public function test_upload_keeps_the_id_and_settings_changes_nothing_and_carries_bindings_by_name(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $before = $t->fresh();
        $newCds = $this->parse(self::V2);

        $resp = $this->stage($admin, $t);

        $draft = CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->latest('id')->firstOrFail();
        $resp->assertRedirect(route('docuperfect.cds.builder', $draft));

        // Nothing is written to the template until Save.
        $after = $t->fresh();
        $this->assertSame($before->id, $after->id);
        $this->assertEquals($before->cds_json, $after->cds_json);
        $this->assertEquals($before->editor_state, $after->editor_state);
        $this->assertEquals($before->updated_at, $after->updated_at);

        // The draft carries the new wording and the template's own settings.
        $this->assertEquals($newCds['sections'], $draft->cds_json['sections']);
        $this->assertSame('per_party', $draft->settings['party_mode']);
        $this->assertSame(['owner_party', 'agent', 'acquiring_party'], $draft->settings['signing_parties']);
        $this->assertSame('source', $draft->settings['document_look']);
        $this->assertNull($draft->tagged_html);

        // Bindings were carried by NAME even though every field moved down one place.
        $gen = TemplateGeneration::where('template_id', $t->id)->firstOrFail();
        $this->assertSame(TemplateGeneration::PENDING, $gen->status);
        $this->assertSame($draft->id, (int) $gen->draft_id);
        $name = $this->keyOf($newCds, 'full_name');
        $addr = $this->keyOf($newCds, 'physical_address');
        $erf = $this->keyOf($newCds, 'erf');
        $email = $this->keyOf($newCds, 'email');
        $this->assertSame(1, $name - $this->keyOf($this->parse(self::V1), 'full_name'), 'the field really did move');
        $this->assertStringContainsString('full_name', $gen->carried_bindings[$name]['label']);
        $this->assertStringContainsString('physical_address', $gen->carried_bindings[$addr]['label']);
        $this->assertStringContainsString('erf', $gen->carried_bindings[$erf]['label']);
        $this->assertArrayNotHasKey($email, $gen->carried_bindings, 'a new field must not inherit another field\'s binding');

        // The report lists exactly what was dropped and what is new.
        $r = $gen->report;
        $this->assertSame(4, $r['old_count']);
        $this->assertSame(4, $r['new_count']);
        $this->assertSame(3, $r['matched_count']);
        $this->assertCount(1, $r['dropped']);
        $this->assertStringContainsString('asking_price', $r['dropped'][0]['name']);
        $this->assertCount(1, $r['added']);
        $this->assertSame($email, $r['added'][0]['new_index']);
    }

    public function test_the_builder_opens_with_the_report_first_and_the_carried_bindings_on_the_fields(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $this->stage($admin, $t);
        $draft = CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->latest('id')->firstOrFail();

        $resp = $this->actingAs($admin)->get(route('docuperfect.cds.builder', $draft))->assertOk();
        $html = $resp->getContent();

        // What came back + the next action, before anything else on the page.
        $this->assertStringContainsString('Word file read: lease-v2.docx', $html);
        $this->assertStringContainsString('3 of the 4 fields in the old template', $html);
        $this->assertStringContainsString('Next: check the fields above', $html);
        $this->assertLessThan(strpos($html, 'Template Name'), strpos($html, 'Word file read'));
        // Each new/unmatched field has a link that scrolls to it.
        $this->assertStringContainsString('cdsGoToField(' . $this->keyOf($this->parse(self::V2), 'email') . ')', $html);
        // The settings panel carries the control.
        $this->assertStringContainsString('Re-import from Word', $html);
        // The app's own confirm dialog, never a browser confirm(): a native pop-up freezes the page for
        // anyone driving it from a browser extension and breaks the "no browser dialogs" rule.
        $this->assertSame(1, preg_match('/<form[^>]*\/reimport"[^>]*>/s', $html, $form));
        $this->assertStringContainsString('data-confirm="Re-import replaces', $form[0]);
        $this->assertStringNotContainsString('onsubmit', $form[0]);
        $this->assertStringNotContainsString("return confirm(", $html);

        $fields = $resp->viewData('fields');
        $name = $this->keyOf($this->parse(self::V2), 'full_name');
        $this->assertStringContainsString('full_name', $fields[$name]['binding']['label']);
    }

    // ── save: same id, previous generation kept, file kept privately ─────

    public function test_save_updates_the_same_template_and_keeps_the_previous_generation_and_the_file(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $oldCds = $t->cds_json;
        $this->stage($admin, $t);
        $draft = CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->latest('id')->firstOrFail();

        $this->save($admin, $draft, $t)->assertRedirect();

        $after = $t->fresh();
        $this->assertSame($t->id, $after->id, 'same template id');
        $this->assertEquals($this->parse(self::V2)['sections'], $after->cds_json['sections']);
        $this->assertSame(['owner_party', 'agent', 'acquiring_party'], $after->signing_parties);
        $this->assertSame('per_party', $after->party_mode);
        $this->assertSame('source', $after->editor_state['document_look']);

        $gens = TemplateGeneration::where('template_id', $t->id)->orderBy('generation')->get();
        $this->assertCount(2, $gens);
        // Generation 1 = what the template held before (no Word file was ever kept for it).
        $this->assertSame(TemplateGeneration::SUPERSEDED, $gens[0]->status);
        $this->assertNull($gens[0]->document_id);
        $this->assertEquals($oldCds, $gens[0]->cds_json);
        $this->assertNotEmpty($gens[0]->editor_state['mappings']);
        // Generation 2 = the new Word file, stored privately.
        $this->assertSame(TemplateGeneration::CURRENT, $gens[1]->status);
        $doc = Document::withoutGlobalScopes()->findOrFail($gens[1]->document_id);
        $this->assertSame('template_source', $doc->source_type);
        $this->assertSame('local', $doc->disk);
        $this->assertSame($agency->id, (int) $doc->agency_id);
        $this->assertTrue(Storage::disk('local')->exists($doc->storage_path));
        $this->assertFalse(Storage::disk('public')->exists($doc->storage_path), 'never on the web-served disk');
        $this->assertSame($this->docxBytes(self::V2), Storage::disk('local')->get($doc->storage_path));

        // A second re-import: the first file's generation is superseded, not deleted.
        $this->stage($admin, $after, self::V1);
        $draft2 = CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->where('status', 'draft')->latest('id')->firstOrFail();
        $this->save($admin, $draft2, $after)->assertRedirect();

        $gens = TemplateGeneration::withTrashed()->where('template_id', $t->id)->orderBy('generation')->get();
        $this->assertCount(3, $gens);
        $this->assertSame([TemplateGeneration::SUPERSEDED, TemplateGeneration::SUPERSEDED, TemplateGeneration::CURRENT], $gens->pluck('status')->all());
        $this->assertEquals($this->parse(self::V2)['sections'], $gens[1]->cds_json['sections'], 'the superseded generation holds the content it replaced');
        $this->assertSame($doc->id, (int) $gens[1]->document_id);
        $this->assertTrue(Storage::disk('local')->exists($doc->storage_path), 'the replaced Word file is still there');
        $this->assertSame(2, Document::withoutGlobalScopes()->where('source_type', 'template_source')->where('source_id', $t->id)->count());
    }

    public function test_an_upload_that_is_never_saved_leaves_the_template_alone_and_a_newer_upload_overtakes_it(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $this->stage($admin, $t);
        $this->stage($admin, $t, self::V1);

        $this->assertSame([TemplateGeneration::ABANDONED, TemplateGeneration::PENDING], TemplateGeneration::where('template_id', $t->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->where('status', 'draft')->count());
        $this->assertSame(0, TemplateGeneration::where('template_id', $t->id)->where('status', 'current')->count());
    }

    // ── the stored file is private and scoped ────────────────────────────

    public function test_the_stored_word_file_is_served_only_through_the_authenticated_route_to_template_managers(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $other = $this->agency('Other Agency');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $this->stage($admin, $t);
        $draft = CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->latest('id')->firstOrFail();
        $this->save($admin, $draft, $t);
        $doc = Document::withoutGlobalScopes()->where('source_type', 'template_source')->where('source_id', $t->id)->firstOrFail();
        $url = route('corex.documents.file', ['document' => $doc, 'download' => 1]);

        // Not under the public URL space at all.
        $this->assertStringNotContainsString('/storage/', $url);

        $this->actingAs($admin)->get($url)->assertOk();

        $clerk = $this->agencyUser($agency, 'clerk', ['access_docuperfect']);   // same agency, may not manage templates
        $this->actingAs($clerk)->get($url)->assertForbidden();

        $stranger = $this->agencyUser($other, 'admin', ['manage_templates', 'access_docuperfect']);   // another agency
        $this->actingAs($stranger)->get($url)->assertNotFound();

        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    // ── gates and input space ────────────────────────────────────────────

    public function test_another_agencys_admin_cannot_re_import_into_this_template(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $other = $this->agency('Other Agency');
        $t = $this->trackBlade($this->cdsTemplate($agency, $this->admin($agency)));
        $stranger = $this->agencyUser($other, 'admin', ['manage_templates', 'access_docuperfect']);

        $this->stage($stranger, $t)->assertNotFound();

        $this->assertSame(0, TemplateGeneration::where('template_id', $t->id)->count());
        $this->assertSame(0, CdsDraft::withoutGlobalScopes()->where('source_template_id', $t->id)->count());
    }

    public function test_a_user_without_manage_templates_is_refused(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $t = $this->trackBlade($this->cdsTemplate($agency, $this->admin($agency)));
        $clerk = $this->agencyUser($agency, 'clerk', ['access_docuperfect']);

        $this->stage($clerk, $t)->assertForbidden();
        $this->assertSame(0, TemplateGeneration::where('template_id', $t->id)->count());
    }

    public function test_bad_uploads_are_answered_in_plain_language_and_write_nothing(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $t = $this->trackBlade($this->cdsTemplate($agency, $admin));
        $url = route('docuperfect.templates.reimport', $t->id);

        $this->actingAs($admin)->post($url, [])->assertSessionHasErrors(['document' => 'Choose the corrected Word file (.docx) to re-import.']);
        $this->actingAs($admin)->post($url, ['document' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])
            ->assertSessionHasErrors(['document' => 'That is not a Word .docx file.']);
        $this->actingAs($admin)->post($url, ['document' => UploadedFile::fake()->createWithContent('broken.docx', 'this is not a zip')])
            ->assertSessionHasErrors('document');
        $this->actingAs($admin)->post($url, ['document' => UploadedFile::fake()->createWithContent('empty.docx', $this->docxBytes([]))])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, TemplateGeneration::where('template_id', $t->id)->count());
        $this->assertSame(0, Document::withoutGlobalScopes()->where('source_type', 'template_source')->count());
        $this->assertEquals($t->fresh()->cds_json, $t->cds_json);
    }

    public function test_a_template_not_built_from_a_word_file_cannot_be_re_imported(): void
    {
        $agency = $this->agency('Cape Town Lettings');
        $admin = $this->admin($agency);
        $pdf = $this->pdfTemplate($agency, $admin);

        $this->stage($admin, $pdf)->assertSessionHasErrors('document');
        $this->assertSame(0, TemplateGeneration::count());
    }
}
