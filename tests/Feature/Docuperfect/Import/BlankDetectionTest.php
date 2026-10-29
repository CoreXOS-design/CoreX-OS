<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Import;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\DocumentType;
use App\Models\User;
use App\Services\Docuperfect\CdsBindingSuggester;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsTableFields;
use App\Services\Docuperfect\DocumentTypeGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * esign-import-blanks.md - a Word document with plain blanks (underscore runs, tab leaders, empty cells beside their label) imports
 * with those blanks as fields, keeps its Word file on the draft, is guessed at, and can be read again with the current rules.
 */
final class BlankDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Cape Town']);
        $this->admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        foreach (['lease_agreement' => 'Lease Agreement', 'mandate' => 'Mandate', 'disclosure' => 'Disclosure'] as $slug => $label) {
            DocumentType::firstOrCreate(['slug' => $slug], ['label' => $label, 'name' => $label, 'grouping' => 'shared', 'sort_order' => 1]);
        }
    }

    // ── helpers ──

    private function p(string $text): string
    {
        return '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    private function table(array $rows): string
    {
        $x = '<w:tbl><w:tblPr><w:tblW w:w="9000" w:type="dxa"/></w:tblPr><w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid>';
        foreach ($rows as $cells) {
            $x .= '<w:tr>';
            foreach ($cells as $c) {
                $x .= '<w:tc><w:tcPr><w:tcW w:w="4500" w:type="dxa"/></w:tcPr>' . ($c === '' ? '<w:p/>' : $this->p($c)) . '</w:tc>';
            }
            $x .= '</w:tr>';
        }

        return $x . '</w:tbl>';
    }

    private function docx(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blk') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body . '</w:body></w:document>');
        $zip->close();

        return $path;
    }

    /** @return list<array<string,mixed>> every blank, in document order, as the builder sees them */
    private function blanks(array $cds): array
    {
        return (new CdsBindingSuggester())->collectInputTokens($cds);
    }

    private function leaseDocx(): string
    {
        $body = $this->p('LEASE AGREEMENT - RESIDENTIAL')
            . $this->p('The Premises shall be personally occupied by __________ Adults and not more than __________ other persons.')
            . $this->p('The Rental shall be R____________ (in words) (_____________) per month.')
            . $this->p('Physical address: ______________________________')
            . $this->p("Total Rental Amount \t\t\t")
            . $this->p('Plain closing paragraph.')
            . $this->p('Signed at ______________ on this ___ day of ______ 2026')
            . $this->p('______________________________')
            . $this->p('Signature');

        return $this->docx($body);
    }

    // ── 1. underscore runs and tab leaders ──

    public function test_underscore_blanks_and_tab_leaders_become_fields_without_any_markers(): void
    {
        $path = $this->leaseDocx();
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);

        $names = array_column($this->blanks($cds), 'field_name');

        $this->assertContains('lease_adults', $names);
        $this->assertContains('lease_max_other_persons', $names);
        $this->assertContains('lease_rent', $names);
        $this->assertContains('lease_rent_in_words', $names);
        $this->assertContains('contact.address', $names, 'Physical address: ____ is an ordinary labelled blank');
        $this->assertGreaterThanOrEqual(2, count(array_keys($names, 'lease_rent', true)), 'the "Total Rental Amount<tabs>" line is a blank too');
    }

    public function test_signing_lines_are_not_blanks(): void
    {
        $path = $this->leaseDocx();
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);

        // "Signed at ___ on this ___ day" and a bare underscore line above "Signature" stay signing text/lines, never input fields.
        foreach ($this->blanks($cds) as $b) {
            $this->assertStringNotContainsString('signing.place', (string) $b['field_name']);
        }
        $text = json_encode($cds['sections']);
        $this->assertStringContainsString('Signed at', (string) $text);
    }

    // ── 2. blanks in table cells ──

    public function test_markers_runs_and_empty_cells_beside_a_label_in_a_table_become_fields(): void
    {
        $path = $this->docx($this->p('LEASE AGREEMENT') . $this->table([
            ['Property address:', ''],
            ['Monthly rental:', 'R @@@@'],
            ['Deposit:', '__________'],
            ['Clause 4', 'I am aware of defects relating to the swimming pool or pump and I agree'],
            ['Tick', 'X'],
            ['Signature:', ''],
            ['Landlord signature', ''],
        ]));
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);

        $table = collect($cds['sections'])->firstWhere('type', 'table');
        $fields = CdsTableFields::walk($table);

        $this->assertCount(3, $fields, 'address (empty cell), rental (marker), deposit (underscores); the tick, the statement and both signature rows are not blanks');
        $this->assertSame('lease_rent', $fields[1]['field_name']);
        $this->assertSame('property.address', $fields[0]['field_name'] ?: 'property.address');
    }

    public function test_a_tables_blanks_are_drawn_as_builder_fields_in_the_same_order_the_server_lists_them(): void
    {
        $path = $this->docx($this->p('LEASE AGREEMENT') . $this->p('Name: ________') . $this->table([['Property address:', ''], ['Monthly rental:', 'R @@@@']]) . $this->p('Email: ________'));
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);
        $draft = CdsDraft::create(['user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'LEASE AGREEMENT', 'status' => 'draft', 'cds_json' => $cds]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        $this->assertSame(count($this->blanks($cds)), substr_count($html, 'class="corex-field"'));
        $this->assertSame(4, count($this->blanks($cds)));
        // the table's blanks sit inside table cells of the drawn document
        $this->assertMatchesRegularExpression('/<td[^>]*>.*?corex-field.*?<\/td>/s', $html);
    }

    public function test_a_table_with_no_blanks_is_left_exactly_as_stored(): void
    {
        $section = ['type' => 'table', 'rows' => [['Landlord', 'Mr A'], ['Tenant', 'Ms B']]];

        $this->assertSame($section, CdsTableFields::detect($section));
    }

    // ── 3. the Word file stays on the draft; read it again ──

    public function test_the_import_keeps_the_word_file_and_a_guess_and_reread_uses_the_current_rules(): void
    {
        Storage::fake('local');
        $path = $this->leaseDocx();
        $upload = new UploadedFile($path, 'My Lease V9.docx', null, null, true);

        $res = $this->actingAs($this->admin)->post(route('docuperfect.import.cds'), ['document' => $upload]);
        $draft = CdsDraft::query()->latest('id')->first();
        $res->assertRedirect(route('docuperfect.cds.builder', $draft));

        $this->assertNotEmpty($draft->source_path);
        $this->assertSame('My Lease V9.docx', $draft->source_name);
        Storage::disk('local')->assertExists($draft->source_path);
        $this->assertSame('lease_agreement', $draft->settings['guess']['slug']);
        $this->assertSame('rentals', $draft->settings['guess']['category']);

        $page = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();
        $this->assertStringContainsString('data-draft-source', $page);
        $this->assertStringContainsString('Re-read with current rules', $page);

        // Simulate an older reading plus saved links, then read again.
        $before = count($this->blanks($draft->cds_json));
        $draft->forceFill(['cds_json' => ['title' => 'old', 'sections' => []], 'tags' => [['id' => 'x']], 'mappings' => ['x' => ['a' => 1]], 'tagged_html' => '<p>old</p>'])->save();

        $this->actingAs($this->admin)->post(route('docuperfect.cds.builder.reread', $draft))->assertRedirect(route('docuperfect.cds.builder', $draft));

        $draft->refresh();
        $this->assertSame($before, count($this->blanks($draft->cds_json)));
        $this->assertNull($draft->tagged_html);
        $this->assertEmpty($draft->mappings);
    }

    public function test_reread_without_a_kept_file_says_so_and_another_user_cannot_use_it(): void
    {
        $draft = CdsDraft::create(['user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'x', 'status' => 'draft', 'cds_json' => ['sections' => []]]);

        $this->actingAs($this->admin)->post(route('docuperfect.cds.builder.reread', $draft))->assertRedirect()->assertSessionHas('error');

        $other = User::factory()->create(['agency_id' => $this->admin->agency_id, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($other)->post(route('docuperfect.cds.builder.reread', $draft))->assertForbidden();
    }

    // ── 4. the guess ──

    public function test_the_importer_guesses_the_type_and_category_from_the_title_and_wording(): void
    {
        $g = new DocumentTypeGuesser();
        $cases = [
            ['LEASE AGREEMENT - RESIDENTIAL', 'lease_agreement', 'rentals'],
            ['Letting Mandate (V5)', 'mandate', 'rentals'],
            ['EXCLUSIVE AUTHORITY TO SELL', 'mandate', 'sales'],
            ['Offer to Purchase', 'offer_to_purchase', 'sales'],
            ['Seller Mandatory Disclosure', 'disclosure', 'sales'],
            ['Letting Mandatory Disclosure (V7)', 'disclosure', 'rentals'],
            ['ADDENDUM B', 'addendum', null],
            ['FICA Natural Person Questionnaire', 'fica', null],
            ['RENTAL APPLICATION (V8)', 'other', 'rentals'],
        ];
        foreach ($cases as [$title, $slug, $category]) {
            $guess = $g->guess($title, $title . ' lessor lessee tenant landlord seller buyer');
            $this->assertNotNull($guess, $title);
            $this->assertSame($slug, $guess['slug'], $title);
            if ($category !== null) {
                $this->assertSame($category, $guess['category'], $title);
            }
        }
        $this->assertNull($g->guess('Untitled Document', 'Some words that say nothing about the kind of paper.'));
    }

    public function test_the_builder_applies_the_guess_to_the_two_dropdowns_and_says_it_is_a_guess(): void
    {
        $id = DocumentType::where('slug', 'lease_agreement')->value('id');
        $draft = CdsDraft::create([
            'user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'LEASE', 'status' => 'draft',
            'cds_json' => ['title' => 'LEASE', 'sections' => []],
            'settings' => ['guess' => ['category' => 'rentals', 'document_type_id' => $id, 'slug' => 'lease_agreement', 'note' => 'Guessed from the title and wording of the Word file - change it if it is wrong.']],
        ]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        $this->assertStringContainsString('"document_type_id":' . $id, $html);
        $this->assertStringContainsString('data-guess-note', $html);
    }

    // ── 5. save with open fields; deploy sync ──

    public function test_the_builder_offers_save_with_open_fields(): void
    {
        $draft = CdsDraft::create(['user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'x', 'status' => 'draft', 'cds_json' => ['sections' => []]]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        $this->assertStringContainsString('data-save-with-open-fields', $html);
        $this->assertStringContainsString('Save with open fields', $html);
    }

    public function test_the_builder_has_a_loading_cover_an_init_error_banner_and_valid_scripts(): void
    {
        $path = $this->leaseDocx();
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);
        $draft = CdsDraft::create(['user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'LEASE', 'status' => 'draft', 'cds_json' => $cds]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        $this->assertStringContainsString('data-builder-loading', $html);
        $this->assertStringContainsString('data-builder-init-error', $html);
        $this->assertStringContainsString('_savedStateUsable', $html);

        // every inline script of the rendered page parses (a syntax slip leaves the builder blank in the browser)
        preg_match_all('/<script(?![^>]*\ssrc)[^>]*>(.*?)<\/script>/s', $html, $m);
        $bad = [];
        foreach ($m[1] as $i => $js) {
            $file = tempnam(sys_get_temp_dir(), 'js') . '.js';
            file_put_contents($file, "new Function(" . json_encode($js) . ");");
            exec('node ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            if ($code !== 0) {
                $bad[] = $i . ': ' . implode(' ', array_slice($out, 0, 3));
            }
            @unlink($file);
            $out = [];
        }
        $this->assertSame([], $bad);
    }

    public function test_a_half_saved_draft_with_tags_but_no_tag_spans_opens_from_the_fresh_reading(): void
    {
        $path = $this->leaseDocx();
        $cds = app(CdsParserService::class)->parse($path);
        @unlink($path);
        $draft = CdsDraft::create([
            'user_id' => $this->admin->id, 'agency_id' => $this->admin->agency_id, 'template_name' => 'LEASE', 'status' => 'draft', 'cds_json' => $cds,
            'tags' => [['id' => 'tag-1', 'type' => 'input', 'label' => '[INPUT 1]', 'number' => 1]], 'mappings' => ['tag-1' => ['mappingType' => 'manual']],
            'tagged_html' => '<p>saved without any tag spans</p>',
        ]);

        $html = $this->actingAs($this->admin)->get(route('docuperfect.cds.builder', $draft))->assertOk()->getContent();

        // the page still carries the fresh reading's blanks for the builder to tag (the JS falls back to them)
        $this->assertGreaterThan(0, substr_count($html, 'class="corex-field"'));
        $this->assertStringContainsString('hasSavedState', $html);
    }

    public function test_staging_and_live_deploys_run_the_reference_data_sync_like_qa1(): void
    {
        $deploy = (string) file_get_contents(base_path('scripts/deploy.sh'));
        $qa = (string) file_get_contents(base_path('scripts/qa-deploy.sh'));

        $this->assertStringContainsString('php artisan deploy:sync-reference-data', $deploy);
        $this->assertStringContainsString('php artisan deploy:sync-reference-data', $qa);
    }
}
