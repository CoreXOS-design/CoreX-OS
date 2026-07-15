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

    /**
     * AT-262-charset — THE LIVE BUG. The old name class was [A-Z_]+, so a marker named
     * the way agents actually name fields ("Seller - Full name", "Property - Erf /
     * Scheme", "Asking price (Rand)") failed at its first lowercase letter. This is the
     * conductor's exact 13-marker doc: all 13 must now parse, each keeping its human
     * name as the label.
     */
    public function test_all_thirteen_human_named_markers_are_detected(): void
    {
        $names = [
            'Seller - Full name and surname', 'Property - Erf / Scheme / Unit number',
            'Property - Complex / Estate name', 'Property - Street', 'Property - Township',
            'Property - District', 'Seller - Physical address', 'Seller - Telephone',
            'Seller - Email', 'Document - Asking price (Rand)', 'Document - Asking price in words',
            'Document - Mandate expiry date', 'Document - Other conditions',
        ];

        $text = '';
        foreach ($names as $n) {
            $text .= "Field: ~~~~{$n}~~~~ line. ";
        }
        $result = $this->parseDocx($text);

        $labels = collect($result['sections'] ?? [])
            ->flatMap(fn ($s) => $s['content'] ?? [])
            ->where('type', 'insertable_block_placeholder')
            ->pluck('custom_label')
            ->all();

        $this->assertCount(13, $labels, '13 human-named markers must ALL be detected, not just the caps-only ones');
        foreach ($names as $n) {
            $this->assertContains($n, $labels, "the marker \"{$n}\" was not detected — its human name was rejected");
        }
    }

    /** A human name still yields a SAFE block id (no spaces/slashes leaking into the id). */
    public function test_a_human_named_marker_gets_a_safe_slug_block_id(): void
    {
        $result = $this->parseDocx('~~~~Property - Erf / Scheme~~~~');

        $block = collect($result['sections'] ?? [])
            ->flatMap(fn ($s) => $s['content'] ?? [])
            ->firstWhere('type', 'insertable_block_placeholder');

        $this->assertSame('property_erf_scheme', $block['block_id']);
        $this->assertSame('Property - Erf / Scheme', $block['custom_label']);
        $this->assertSame('custom_named', $block['purpose']);
    }

    /** The built-in purpose tokens still map to their purposes — not broken by the widening. */
    public function test_builtin_purpose_tokens_still_map(): void
    {
        $result = $this->parseDocx('~~~~OTHER_CONDITIONS~~~~');
        $block = collect($result['sections'] ?? [])
            ->flatMap(fn ($s) => $s['content'] ?? [])
            ->firstWhere('type', 'insertable_block_placeholder');

        $this->assertSame('other_conditions', $block['purpose']);
    }

    // ── AT-262 near-miss detection ───────────────────────────────────────

    public function test_near_miss_detection_names_the_reason(): void
    {
        $svc = app(CdsParserService::class);

        $wrongTildes = $svc->detectNearMissMarkers('here ~~Seller~~ there');
        $this->assertNotEmpty($wrongTildes);
        $this->assertStringContainsString('FOUR tildes', $wrongTildes[0]['reason']);

        $badChar = $svc->detectNearMissMarkers('~~~~Price|Rand~~~~');
        $this->assertNotEmpty($badChar);
        $this->assertStringContainsString("isn't allowed", $badChar[0]['reason']);
        $this->assertStringContainsString('|', $badChar[0]['reason']);

        // A valid human-named marker is NOT a near-miss.
        $this->assertEmpty($svc->detectNearMissMarkers('~~~~Seller - Full name~~~~'));
    }

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

    // ── AT-262-cds — the CDS import must accept a docx that sniffs as zip ──

    /**
     * THE LIVE BUG. The CDS import (~~~~NAME~~~~ path) validated `mimes:docx`, which
     * checks the MIME php-fileinfo sniffs from the file's CONTENT. A .docx is a ZIP,
     * and real Word documents frequently sniff as `application/zip` — so `mimes:docx`
     * silently REJECTED a valid Word file and redirected back to the import screen
     * with no visible feedback. An agent hit Import and nothing happened.
     *
     * The fix validates by CLIENT EXTENSION (`extensions:docx`), matching the working
     * standard-import path. This proves a zip-sniffing .docx is now accepted, not
     * bounced. It uploads a plain ZIP with a .docx name (the exact sniff condition);
     * reaching the builder redirect proves validation passed.
     */
    public function test_cds_import_of_a_marked_docx_reaches_the_builder(): void
    {
        // The happy path the live bug broke: a valid marked .docx must pass validation
        // and redirect INTO the builder, not bounce back to /import. (The zip-sniff
        // mechanism itself is locked by the sibling test below — this env's fileinfo
        // recognises a structured docx, so it can't reproduce the sniff here.)
        $upload = new UploadedFile(
            $this->writeDocx('Between ~~~~SELLER_NAME~~~~ and the agency.'),
            'RealMandate.docx',
            'application/octet-stream',   // as a browser may send it
            null,
            true,
        );

        $response = $this->actingAs($this->importer())
            ->post(route('docuperfect.import.cds'), ['document' => $upload]);

        $response->assertRedirect();
        $this->assertStringContainsString('/templates/cds/builder/', (string) $response->headers->get('Location'),
            'a valid marked .docx must reach the builder, not bounce back to the import screen');
    }

    /** The old rule would have rejected exactly that file — lock the regression. */
    public function test_mimes_docx_would_have_rejected_a_zip_sniffing_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zipdocx') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', str_repeat('x', 200));
        $zip->close();

        $upload = new UploadedFile($path, 'RealMandate.docx', 'application/octet-stream', null, true);

        $old = \Illuminate\Support\Facades\Validator::make(
            ['document' => $upload], ['document' => 'required|file|mimes:docx']);
        $new = \Illuminate\Support\Facades\Validator::make(
            ['document' => $upload], ['document' => 'required|file|extensions:docx']);

        $this->assertTrue($old->fails(), 'the OLD rule rejected a zip-sniffing .docx — this was the bug');
        $this->assertTrue($new->passes(), 'the NEW rule accepts it — this is the fix');
    }
}
