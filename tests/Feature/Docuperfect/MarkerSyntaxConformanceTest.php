<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\User;
use App\Services\Docuperfect\CdsParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * AT-262 — marker syntax is ONE truth, and a zero-field import warns and teaches.
 *
 * The ticket suspected the import UI taught a syntax the parser did not read
 * (●●●● / xxxx). It does not — that string exists nowhere in the product, and the
 * only marker guide (the import screen) already matched the parser exactly. But the
 * two WERE independently maintained: a hardcoded Blade list beside a hardcoded
 * regex. Two copies of one fact, with nothing asserting they agree — the same shape
 * that bit us on the property address.
 *
 * These tests are that assertion. If anyone changes the regex without the guide, or
 * the guide without the regex, this goes red.
 */
final class MarkerSyntaxConformanceTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDE = 'resources/views/docuperfect/importer/index.blade.php';

    // ── ONE TRUTH: what we READ is what we TEACH ─────────────────────────

    /** Every syntax the parser advertises must be a syntax the parser actually detects. */
    public function test_every_advertised_marker_is_detected_by_the_parser(): void
    {
        foreach (CdsParserService::acceptedMarkers() as $marker) {
            $result = $this->parseDocx('Before ' . $marker['example'] . ' after.');

            $found = collect($result['sections'] ?? [])
                ->flatMap(fn ($s) => $s['content'] ?? [])
                ->pluck('type')
                ->filter(fn ($t) => $t !== 'text')
                ->values();

            $this->assertNotEmpty(
                $found,
                "The importer advertises `{$marker['token']}` ({$marker['label']}) but the parser detects nothing for it."
            );
        }
    }

    /** ...and every syntax the parser accepts must be TAUGHT on the import screen. */
    public function test_every_accepted_marker_is_taught_on_the_import_screen(): void
    {
        $guide = file_get_contents(base_path(self::GUIDE));
        $this->assertNotFalse($guide, 'the import guide view must exist');

        foreach (CdsParserService::acceptedMarkers() as $marker) {
            $this->assertStringContainsString(
                $marker['delim'],
                $guide,
                "The parser accepts `{$marker['delim']}` but the import screen never tells an author about it — "
                . 'an author cannot use a marker nobody taught them.'
            );
        }
    }

    /** The split regex is COMPOSED from the list — it is not a second, hand-kept copy. */
    public function test_the_split_pattern_is_built_from_the_accepted_marker_list(): void
    {
        $pattern = CdsParserService::markerSplitPattern();

        foreach (CdsParserService::acceptedMarkers() as $marker) {
            $this->assertStringContainsString($marker['pattern'], $pattern);
        }
        $this->assertNotFalse(@preg_match($pattern, ''), 'the composed pattern must be a valid regex');
    }

    // ── The three field markers still detect their own types ─────────────

    public function test_the_field_signature_and_initial_markers_detect_their_own_types(): void
    {
        $types = fn (array $r) => collect($r['sections'] ?? [])
            ->flatMap(fn ($s) => $s['content'] ?? [])->pluck('type')->all();

        $this->assertContains('field_placeholder',     $types($this->parseDocx('Name: @@@@')));
        $this->assertContains('signature_placeholder', $types($this->parseDocx('Sign: %%%%')));
        $this->assertContains('initial_placeholder',   $types($this->parseDocx('Initial: ####')));
    }

    /**
     * THE BUG, named. The marker guide sits directly above the STANDARD upload form —
     * but that form runs DocxParserService, which read only underscore runs and square
     * brackets. The markers it printed (@@@@ / %%%% / ####) belong to CdsParserService,
     * behind a different form. So an author who followed the on-screen instructions
     * uploaded a correctly-marked document, got ZERO fields, and was told it was ready.
     *
     * Every syntax the screen teaches must work on the form the screen teaches it for.
     */
    public function test_the_standard_import_form_reads_the_markers_its_own_guide_teaches(): void
    {
        foreach (['@@@@', '%%%%', '####'] as $marker) {
            $response = $this->actingAs($this->importer())
                ->postJson(route('docuperfect.import.parse'), [
                    'document'      => $this->docxUpload("Full names: {$marker}"),
                    'template_name' => 'Guide ' . Str::random(4),
                ]);

            $response->assertOk();
            $this->assertGreaterThan(
                0,
                (int) $response->json('field_count'),
                "The import screen teaches `{$marker}`, but the standard importer detected nothing for it — "
                . 'an author who followed the printed instructions would get an empty review screen.'
            );
            $this->assertFalse((bool) $response->json('zero_fields'));
        }
    }

    /** Underscores and brackets — the Word-native conventions — still work. Additive, not a swap. */
    public function test_the_existing_underscore_and_bracket_conventions_still_work(): void
    {
        foreach (['Name: ________', 'Name: [Full Name]'] as $body) {
            $response = $this->actingAs($this->importer())
                ->postJson(route('docuperfect.import.parse'), [
                    'document'      => $this->docxUpload($body),
                    'template_name' => 'Legacy ' . Str::random(4),
                ]);

            $response->assertOk();
            $this->assertGreaterThan(0, (int) $response->json('field_count'),
                "Accepting the taught markers must not break `{$body}` — thousands of documents use it.");
        }
    }

    // ── ZERO-FIELD GUARD ─────────────────────────────────────────────────

    /**
     * A document with no markers imported "successfully" and dropped the author on a
     * review screen with nothing on it. The document was fine — it simply had no
     * markers, because nobody had told the author what a marker is. Now it warns AND
     * teaches, and carries the accepted syntaxes with the warning.
     */
    public function test_an_import_with_no_markers_warns_and_teaches_instead_of_reporting_ready(): void
    {
        $response = $this->actingAs($this->importer())
            ->postJson(route('docuperfect.import.parse'), [
                'document'      => $this->docxUpload('A plain agreement with no markers at all.'),
                'template_name' => 'No markers ' . Str::random(4),
            ]);

        $response->assertOk()
            ->assertJson(['zero_fields' => true, 'field_count' => 0, 'block_count' => 0]);

        // The warning must TEACH — it carries the accepted syntaxes with it.
        $markers = $response->json('accepted_markers');
        $this->assertNotEmpty($markers, 'a zero-field warning that teaches nothing is just a dead end');

        $tokens = array_column($markers, 'token');
        $this->assertContains('@@@@', $tokens);
        $this->assertContains('%%%%', $tokens);
        $this->assertContains('####', $tokens);
    }

    /** A document that DOES carry markers is never nagged. */
    public function test_an_import_with_markers_is_not_flagged(): void
    {
        $response = $this->actingAs($this->importer())
            ->postJson(route('docuperfect.import.parse'), [
                'document'      => $this->docxUpload('Full names: @@@@ and signature %%%%'),
                'template_name' => 'Marked ' . Str::random(4),
            ]);

        $response->assertOk()->assertJson(['zero_fields' => false]);
        $this->assertGreaterThan(0, (int) $response->json('field_count'));
        $this->assertEmpty($response->json('accepted_markers'),
            'no need to teach an author who already marked their document');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function importer(): User
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'A' . Str::random(5), 'slug' => 'a-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
    }

    private function parseDocx(string $text): array
    {
        $path = $this->writeDocx($text);
        $result = app(CdsParserService::class)->parse($path);
        @unlink($path);

        return $result;
    }

    private function docxUpload(string $text): UploadedFile
    {
        return new UploadedFile(
            $this->writeDocx($text),
            'test.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,   // test mode — skip the is_uploaded_file() check
        );
    }

    /** A minimal but genuinely valid .docx carrying one paragraph of text. */
    private function writeDocx(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cds') . '.docx';

        $document = <<<XML
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
          <w:body>
            <w:p><w:r><w:t xml:space="preserve">{$this->esc($text)}</w:t></w:r></w:p>
          </w:body>
        </w:document>
        XML;

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        return $path;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
