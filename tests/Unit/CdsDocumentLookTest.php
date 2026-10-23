<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use App\Support\Docuperfect\DocumentLook;
use Tests\TestCase;
use ZipArchive;

/**
 * Document look ("CoreX standard" vs "Match source document") — the DB-free half:
 * the parser keeps the source paragraph's alignment + shading, the renderer marks them as
 * inert data attributes, and the stylesheet only acts on them under .corex-look-source.
 * (The published-blade wrapper half is in Tests\Feature\Docuperfect\CdsDocumentLookBladeTest.)
 */
class CdsDocumentLookTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** @param string[] $paragraphs raw <w:p> XML */
    private function parseDocx(array $paragraphs, string $stylesXml = ''): array
    {
        $path = tempnam(sys_get_temp_dir(), 'look') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . self::W . '><w:body>'
            . implode('', $paragraphs) . '</w:body></w:document>');
        if ($stylesXml !== '') {
            $zip->addFromString('word/styles.xml',
                '<?xml version="1.0" encoding="UTF-8"?><w:styles ' . self::W . '>' . $stylesXml . '</w:styles>');
        }
        $zip->close();

        try {
            return (new CdsParserService())->parse($path)['sections'];
        } finally {
            @unlink($path);
        }
    }

    private function p(string $text, string $pPr = '', string $rPr = ''): string
    {
        return '<w:p>' . ($pPr !== '' ? "<w:pPr>{$pPr}</w:pPr>" : '')
            . '<w:r>' . ($rPr !== '' ? "<w:rPr>{$rPr}</w:rPr>" : '')
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    private function find(array $sections, string $needle): array
    {
        foreach ($sections as $s) {
            if (str_contains(json_encode($s), $needle)) {
                return $s;
            }
        }
        $this->fail("No section containing '{$needle}'");
    }

    // ---- parser: what the Word source did -------------------------------------------------

    public function test_parser_keeps_paragraph_alignment_and_shading(): void
    {
        $sections = $this->parseDocx([
            $this->p('LEASE AGREEMENT', '<w:jc w:val="center"/>', '<w:b/>'),
            $this->p('Right aligned note', '<w:jc w:val="right"/>'),
            $this->p('Justified body paragraph of the lease that runs on for a while and is not a heading at all, so it stays a paragraph.', '<w:jc w:val="both"/>'),
            $this->p('Parties', '<w:shd w:val="clear" w:color="auto" w:fill="D9D9D9"/>', '<w:b/>'),
            $this->p('Plain paragraph with no formatting at all that is long enough to never be a heading.'),
        ]);

        $title = $this->find($sections, 'LEASE AGREEMENT');
        $this->assertSame('center', $title['align']);

        $this->assertSame('right', $this->find($sections, 'Right aligned note')['align']);
        $this->assertSame('justify', $this->find($sections, 'Justified body paragraph')['align']);
        $this->assertSame('d9d9d9', $this->find($sections, 'Parties')['shading']);

        // Standard-look content stays exactly as before: no keys at all.
        $plain = $this->find($sections, 'Plain paragraph with no formatting');
        $this->assertArrayNotHasKey('align', $plain);
        $this->assertArrayNotHasKey('shading', $plain);
    }

    public function test_parser_alignment_follows_the_paragraph_style_and_an_explicit_left_overrides_it(): void
    {
        $styles = '<w:style w:type="paragraph" w:styleId="Base"><w:name w:val="Base"/><w:pPr><w:jc w:val="center"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Child"><w:name w:val="Child"/><w:basedOn w:val="Base"/></w:style>';

        $sections = $this->parseDocx([
            $this->p('Inherits centre through the parent style, long enough to stay a plain paragraph in the output.', '<w:pStyle w:val="Child"/>'),
            $this->p('Explicit left beats the centred style, again long enough to stay a plain paragraph in the output.', '<w:pStyle w:val="Child"/><w:jc w:val="left"/>'),
        ], $styles);

        $this->assertSame('center', $this->find($sections, 'Inherits centre')['align']);
        $this->assertArrayNotHasKey('align', $this->find($sections, 'Explicit left beats'));
    }

    public function test_parser_ignores_auto_and_white_shading_and_reads_run_level_shading(): void
    {
        $sections = $this->parseDocx([
            $this->p('Auto shaded paragraph that is long enough to stay a plain paragraph in the parsed output.', '<w:shd w:val="clear" w:color="auto" w:fill="auto"/>'),
            $this->p('White shaded paragraph that is long enough to stay a plain paragraph in the parsed output.', '<w:shd w:val="clear" w:fill="FFFFFF"/>'),
            $this->p('Run shaded paragraph that is long enough to stay a plain paragraph in the parsed output.', '', '<w:shd w:val="clear" w:fill="EEEEEE"/>'),
        ]);

        $this->assertArrayNotHasKey('shading', $this->find($sections, 'Auto shaded'));
        $this->assertArrayNotHasKey('shading', $this->find($sections, 'White shaded'));
        $this->assertSame('eeeeee', $this->find($sections, 'Run shaded')['shading']);
    }

    // ---- renderer: inert attributes --------------------------------------------------------

    public function test_renderer_marks_alignment_and_bands_but_leaves_unmarked_sections_untouched(): void
    {
        $bold = fn (string $t) => [['type' => 'text', 'value' => $t, 'bold' => true]];
        $html = (new CdsRendererService())->render(['sections' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'LEASE AGREEMENT', 'content' => $bold('LEASE AGREEMENT'), 'align' => 'center'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Parties', 'content' => $bold('Parties'), 'shading' => 'd9d9d9'],
            ['type' => 'heading', 'level' => 2, 'text' => 'Property Address: ', 'content' => $bold('Property Address: ')],
            ['type' => 'heading', 'level' => 2, 'text' => 'TENANT', 'content' => [['type' => 'text', 'value' => 'TENANT']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Body']], 'align' => 'justify'],
            ['type' => 'clause', 'level' => 1, 'number' => '1', 'content' => [['type' => 'text', 'value' => 'Clause']], 'align' => 'right'],
        ]]);

        $this->assertStringContainsString('<div class="corex-h2" data-src-align="center" data-src-band="1">LEASE AGREEMENT</div>', $html, 'bold all-caps + centred');
        $this->assertStringContainsString('<div class="corex-h2" data-src-band="1">Parties</div>', $html, 'source shading = band');
        $this->assertStringContainsString('<div class="corex-h2">Property Address: </div>', $html, 'bold mixed-case label is not a band');
        $this->assertStringContainsString('<div class="corex-h2">TENANT</div>', $html, 'all-caps but not bold is not a band');
        $this->assertStringContainsString('<div class="corex-clause corex-clause-indent-1" data-src-align="justify">', $html);
        $this->assertStringContainsString('<div class="corex-clause corex-clause-indent-1" data-src-align="right">', $html);
    }

    // ---- the look setting + the stylesheet -------------------------------------------------

    public function test_unknown_or_missing_look_is_standard(): void
    {
        $this->assertSame('standard', DocumentLook::normalise(null));
        $this->assertSame('standard', DocumentLook::normalise('anything-else'));
        $this->assertSame('source', DocumentLook::normalise('source'));
        $this->assertSame('standard', DocumentLook::fromState(null));
        $this->assertSame('standard', DocumentLook::fromState(['tags' => []]));
        $this->assertSame('source', DocumentLook::fromState(['document_look' => 'source']));
        $this->assertSame('', DocumentLook::wrapperClass('standard'));
        $this->assertSame(' corex-look-source', DocumentLook::wrapperClass('source'));
    }

    public function test_every_look_rule_is_scoped_so_the_standard_look_cannot_change(): void
    {
        $css = file_get_contents(public_path('css/corex-document.css'));
        $start = strpos($css, '/* === DOCUMENT LOOK: "Match source document"');
        $this->assertNotFalse($start, 'look block missing from corex-document.css');

        // Everything from the look block's marker on: every selector must sit under .corex-look-source.
        $block = substr($css, $start);
        $block = preg_replace('#/\*.*?\*/#s', '', $block);
        preg_match_all('/([^{}]+)\{[^}]*\}/', $block, $rules);
        $this->assertNotEmpty($rules[1]);
        foreach ($rules[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                $this->assertStringStartsWith('.corex-look-source', trim($selector), 'unscoped look rule: ' . trim($selector));
            }
        }

        // Greyscale only: no brand teal / slate-blue in the look block.
        $this->assertDoesNotMatchRegularExpression('/#0d9488|#0f172a|#1e293b|#64748b/i', $block);
    }
}
