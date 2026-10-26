<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use Tests\TestCase;
use ZipArchive;

/**
 * Bug class: nothing the Word file prints may be dropped silently. Three defects found on the Mega 2026 lease
 * (26 Oct 2026) and the report that makes the next one visible. Spec: .ai/specs/cds-reimport-from-word.md section 10,
 * .ai/specs/cds-document-look.md "Fidelity". DB-free; the fixture .docx is built here, one file carrying all three.
 *
 *  1. A table row that starts (w:gridBefore) or ends (w:gridAfter) columns in keeps its cells in their own grid
 *     columns - a label column never collapses to a hairline.
 *  2. A run inside w:hyperlink is read like any other run (the agency e-mail address).
 *  3. Paragraphs and line breaks inside a table cell survive end to end: parser -> rows -> renderer (builder, signing
 *     page and both PDF routes draw the one HTML).
 *
 * Widened 26 Oct 2026 (Johan compared the Word file with the prepared lease page by page): the CoreX render must read
 * like the Word file. One fixture test each for: numbered clauses with hanging indents; a logo picture placed left with
 * the contact block right; bordered boxes and shaded bands (never justified unless the source is); the signature table
 * with its blank lines; the running page header / footer with the page number; hard page breaks; the choice text of a
 * form row kept as printed text. The parse report counts anything still not carried over.
 */
class CdsImporterFidelityTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    private const SECT = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>';

    private function parse(string $bodyXml, array $extraParts = []): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fid') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . self::W . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"><w:body>'
            . $bodyXml . self::SECT . '</w:body></w:document>');
        foreach ($extraParts as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        try {
            return (new CdsParserService())->parse($path);
        } finally {
            @unlink($path);
        }
    }

    private static function r(string $text): string
    {
        return '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>';
    }

    private static function para(string $inner): string
    {
        return '<w:p>' . $inner . '</w:p>';
    }

    /** The Mega front-table shape: 4 grid columns, label rows that start one column in (gridBefore=1). */
    private function megaLikeTable(): string
    {
        $cell = fn (string $inner, int $w = 0, int $span = 0) => '<w:tc><w:tcPr>' . ($w ? '<w:tcW w:w="' . $w . '" w:type="dxa"/>' : '') . ($span ? '<w:gridSpan w:val="' . $span . '"/>' : '') . '</w:tcPr>' . $inner . '</w:tc>';

        return '<w:tbl><w:tblPr/><w:tblGrid>'
            . '<w:gridCol w:w="20"/><w:gridCol w:w="2400"/><w:gridCol w:w="5000"/><w:gridCol w:w="1606"/></w:tblGrid>'
            // full-width header-ish row: no offsets
            . '<w:tr>' . $cell(self::para(self::r('Agency')), 0, 2) . $cell(self::para(self::r('Tel: 1')), 0, 2) . '</w:tr>'
            // gridBefore=1: label | value(span 2)
            . '<w:tr><w:trPr><w:gridBefore w:val="1"/></w:trPr>' . $cell(self::para(self::r('Property Address:'))) . $cell(self::para(self::r('')), 0, 2) . '</w:tr>'
            // gridBefore=1 + gridAfter=1
            . '<w:tr><w:trPr><w:gridBefore w:val="1"/><w:gridAfter w:val="1"/></w:trPr>' . $cell(self::para(self::r('Centred title')), 0, 2) . '</w:tr>'
            . '</w:tbl>';
    }

    public function test_a_row_that_starts_or_ends_columns_in_keeps_its_cells_in_their_own_grid_columns(): void
    {
        $cds = $this->parse($this->megaLikeTable());
        $t = $cds['sections'][0];

        // Text stays where it was: the first row is the (short) header; the label is still the first cell of its row,
        // so consumers that read `rows` by position are unaffected.
        $this->assertSame(['Agency', 'Tel: 1'], $t['headers']);
        $this->assertSame('Property Address:', $t['rows'][0][0]);
        $this->assertSame('Centred title', $t['rows'][1][0]);

        $grid = $t['row_grid'] ?? [];
        // Keyed by the row's index in `rows` (the header row was taken out), only for rows that have an offset.
        $this->assertSame([0 => ['before' => 1, 'after' => 0], 1 => ['before' => 1, 'after' => 1]], $grid);

        $html = (new CdsRendererService())->render($cds);
        // The spacer is a real (empty) cell in the row, so the label cell is NOT first in its grid column.
        $this->assertStringContainsString('class="corex-grid-pad"', $html);
        $this->assertMatchesRegularExpression('#<tr><td class="corex-grid-pad"[^>]*></td><td[^>]*>Property Address:</td>#', $html);
        // One spacer before AND one after on the row that has both.
        $this->assertMatchesRegularExpression('#<td class="corex-grid-pad"[^>]*></td><td[^>]*colspan="2"[^>]*>Centred title</td><td class="corex-grid-pad"[^>]*></td></tr>#', $html);
        // The grid really does have a sliver first and a quarter-width label column second: the spacer takes the sliver.
        $this->assertMatchesRegularExpression('#<colgroup><col style="width:0\.2\d%"><col style="width:2\d\.\d+%">#', $html);
    }

    public function test_a_hyperlinked_run_is_read_like_any_other_run(): void
    {
        $link = '<w:hyperlink r:id="rId9" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . self::r('info@megarent.co.za') . '</w:hyperlink>';
        $body = self::para(self::r('E Mail: ') . $link)
            . '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="6000"/></w:tblGrid><w:tr>'
            . '<w:tc><w:p>' . self::r('Web:') . '</w:p></w:tc><w:tc><w:p>' . $link . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body);

        $this->assertStringContainsString('E Mail: info@megarent.co.za', $cds['original_text'], 'a hyperlink in a paragraph keeps its text, in order');
        $cell = $cds['sections'][1]['rows'][0][1] ?? ($cds['sections'][1]['headers'][1] ?? '');
        $this->assertSame('info@megarent.co.za', $cell, 'a hyperlink in a table cell keeps its text');
        $this->assertSame(0, $cds['parse_report']['unread_chars']);
    }

    public function test_paragraphs_and_line_breaks_in_a_cell_survive_to_the_rendered_page(): void
    {
        $cellXml = self::para(self::r('LEASE TERM'))
            . self::para(self::r('DURATION: shall be for the term.'))
            . self::para('')                                      // a blank paragraph is a blank line, kept
            . self::para(self::r('TERMINATION: first line') . '<w:r><w:br/></w:r>' . self::r('second line of the same paragraph'));
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="5000"/></w:tblGrid><w:tr>'
            . '<w:tc><w:p>' . self::r('Clause') . '</w:p></w:tc><w:tc>' . $cellXml . '</w:tc></w:tr>'
            . '<w:tr><w:tc><w:p>' . self::r('x') . '</w:p></w:tc><w:tc><w:p>' . self::r('y') . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body);
        $t = $cds['sections'][0];
        $cell = ($t['headers'][1] ?? $t['rows'][0][1]);

        $this->assertSame("LEASE TERM\nDURATION: shall be for the term.\n\nTERMINATION: first line\nsecond line of the same paragraph", $cell);
        $this->assertStringNotContainsString('term.TERMINATION', $cell, 'paragraphs are not glued together');

        $html = (new CdsRendererService())->render($cds);
        // Each paragraph is its own block; a blank paragraph is a blank line; a line break inside a paragraph is a <br>.
        $this->assertStringContainsString('<div class="corex-cell-p">LEASE TERM</div><div class="corex-cell-p">DURATION: shall be for the term.</div><div class="corex-cell-p">&nbsp;</div>'
            . '<div class="corex-cell-p">TERMINATION: first line<br>second line of the same paragraph</div>', $html);
        // A table stored before paragraph detail existed prints its plain text, lines as <br>, still escaped.
        unset($cds['sections'][0]['cell_paras'], $cds['sections'][0]['header_paras'], $cds['sections'][0]['headers']);
        $cds['sections'][0]['rows'][0][1] = "a <b>bold</b>\nb";
        $this->assertStringContainsString('a &lt;b&gt;bold&lt;/b&gt;<br>b', (new CdsRendererService())->render($cds));
    }

    public function test_the_parse_report_says_nothing_was_dropped_when_nothing_was(): void
    {
        $cds = $this->parse(self::para(self::r('Hello world')));

        $this->assertSame([], $cds['parse_report']['dropped']);
        $this->assertSame(0, $cds['parse_report']['unread_chars']);
        $this->assertStringStartsWith('Everything the Word file prints was carried over', $cds['parse_report']['line']);
    }

    public function test_the_parse_report_counts_what_the_importer_could_not_carry_over(): void
    {
        $picture = '<w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"/></w:drawing></w:r>';
        $textBox = '<w:r><mc:AlternateContent><mc:Choice><w:drawing><w:txbxContent><w:p><w:r><w:t>Boxed words</w:t></w:r></w:p></w:txbxContent></w:drawing></mc:Choice>'
            . '<mc:Fallback><w:pict><w:txbxContent><w:p><w:r><w:t>Boxed words</w:t></w:r></w:p></w:txbxContent></w:pict></mc:Fallback></mc:AlternateContent></w:r>';
        $nested = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="9000"/></w:tblGrid><w:tr><w:tc>'
            . '<w:p>' . self::r('Outer cell') . '</w:p>'
            . '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="4000"/></w:tblGrid><w:tr><w:tc><w:p>' . self::r('Inner table text') . '</w:p></w:tc></w:tr></w:tbl>'
            . '</w:tc></w:tr></w:tbl>';
        $cds = $this->parse(
            self::para(self::r('Body line') . $picture) . self::para($textBox) . $nested,
            ['word/header1.xml' => '<w:hdr ' . self::W . '><w:p><w:r><w:t>Letterhead</w:t></w:r></w:p></w:hdr>']
        );
        $r = $cds['parse_report'];

        $this->assertSame(1, $r['dropped']['pictures']);
        $this->assertSame(1, $r['dropped']['text_boxes'], 'the Fallback copy of a text box is not counted twice');
        $this->assertSame(1, $r['dropped']['nested_tables']);
        $this->assertSame(10, $r['dropped']['header_footer_text_chars']);
        $this->assertGreaterThan(0, $r['unread_chars'], 'text inside the text box and the nested table was not read');
        $this->assertSame(mb_strlen('Boxed words') + mb_strlen('Inner table text'), $r['unread_chars']);
        $this->assertStringStartsWith('Not carried over from the Word file:', $r['line']);
        $this->assertStringContainsString('1 picture', $r['line']);
        $this->assertStringContainsString('1 text box', $r['line']);
        $this->assertStringContainsString('1 table inside a table cell', $r['line']);
        $this->assertStringContainsString('header / footer text (10 characters)', $r['line']);
    }

    public function test_one_fixture_carries_all_three_defects_and_reads_clean(): void
    {
        // The Mega shape in one file: hyperlink in the letterhead cell, a multi-paragraph clause cell, a gridBefore row.
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="20"/><w:gridCol w:w="2400"/><w:gridCol w:w="6600"/></w:tblGrid>'
            . '<w:tr><w:trPr><w:gridBefore w:val="1"/></w:trPr><w:tc><w:p>' . self::r('E Mail:') . '</w:p></w:tc><w:tc><w:p>'
            . '<w:hyperlink r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . self::r('info@megarent.co.za') . '</w:hyperlink></w:p></w:tc></w:tr>'
            . '<w:tr><w:trPr><w:gridBefore w:val="1"/></w:trPr><w:tc><w:p>' . self::r('Terms') . '</w:p></w:tc><w:tc><w:p>' . self::r('1. First') . '</w:p><w:p>' . self::r('2. Second') . '</w:p></w:tc></w:tr>'
            . '</w:tbl>';

        $cds = $this->parse($body);
        $html = (new CdsRendererService())->render($cds);

        $this->assertStringContainsString('info@megarent.co.za', $html);
        $this->assertStringContainsString('<div class="corex-cell-p">1. First</div><div class="corex-cell-p">2. Second</div>', $html);
        $this->assertStringContainsString('corex-grid-pad', $html);
        $this->assertSame([], $cds['parse_report']['dropped']);
        $this->assertSame(0, $cds['parse_report']['unread_chars']);
    }

    /** 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const REL_NS = 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    private function rels(array $rels): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as $id => [$type, $target]) {
            $xml .= '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . $target . '"/>';
        }

        return $xml . '</Relationships>';
    }

    private function numbering(): string
    {
        return '<?xml version="1.0"?><w:numbering ' . self::W . '>'
            . '<w:abstractNum w:abstractNumId="1">'
            . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:pPr><w:ind w:left="360" w:hanging="360"/></w:pPr></w:lvl>'
            . '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1.%2"/><w:pPr><w:ind w:left="720" w:hanging="540"/></w:pPr></w:lvl>'
            . '</w:abstractNum>'
            . '<w:num w:numId="5"><w:abstractNumId w:val="1"/></w:num>'
            // a list that restarts part-way: its first level-1 item is "3.2"
            . '<w:num w:numId="6"><w:abstractNumId w:val="1"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="3"/></w:lvlOverride><w:lvlOverride w:ilvl="1"><w:startOverride w:val="2"/></w:lvlOverride></w:num>'
            . '</w:numbering>';
    }

    private static function numbered(int $numId, int $ilvl, string $text, string $jc = 'both'): string
    {
        return '<w:p><w:pPr><w:numPr><w:ilvl w:val="' . $ilvl . '"/><w:numId w:val="' . $numId . '"/></w:numPr><w:jc w:val="' . $jc . '"/></w:pPr><w:r><w:tab/><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
    }

    public function test_numbered_clauses_in_a_cell_keep_the_numbers_word_prints_and_hang_them(): void
    {
        $cell = self::numbered(5, 0, 'LEASE TERM') . self::numbered(5, 1, 'DURATION: the term.') . self::numbered(5, 1, 'TERMINATION: notice.')
            . self::numbered(6, 0, 'SECURITY DEPOSIT') . self::numbered(6, 1, 'DAMAGES: liable.');
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="9000"/></w:tblGrid><w:tr><w:tc>' . $cell . '</w:tc></w:tr><w:tr><w:tc><w:p>' . self::r('x') . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body, ['word/numbering.xml' => $this->numbering()]);
        $t = $cds['sections'][0];
        $lines = array_map(fn ($p) => $p['num'] ?? null, $t['cell_paras'][0][0] ?? ($t['header_paras'][0] ?? []));

        $this->assertSame(['1.', '1.1', '1.2', '3.', '3.2'], $lines, 'counters per list; a restarted list starts where Word says (3. then 3.2)');
        $this->assertStringContainsString('1.1 DURATION: the term.', $t['headers'][0] ?? $t['rows'][0][0], 'the number is part of the cell text');

        $html = (new CdsRendererService())->render($cds);
        $this->assertStringContainsString('<span class="corex-cell-num"', $html);
        $this->assertMatchesRegularExpression('#padding-left:27pt;text-indent:-27pt#', $html, 'the level-1 hanging indent (540 twips) hangs the number');
        $this->assertStringContainsString('text-align:justify', $html, 'justified only because the source paragraph says so');
        $this->assertStringNotContainsString("\t", strip_tags($html), 'the tab after the number is the hang, not text');
    }

    public function test_a_logo_picture_is_placed_in_its_cell_and_the_contact_block_stays_right_aligned(): void
    {
        $drawing = '<w:r><w:drawing><wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<wp:extent cx="2540000" cy="889000"/><a:graphic><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill><a:blip ' . self::REL_NS . ' r:embed="rId7"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="5000"/></w:tblGrid><w:tr>'
            . '<w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p>' . $drawing . '</w:p></w:tc>'
            . '<w:tc><w:p><w:pPr><w:jc w:val="right"/></w:pPr>' . self::r('Tel: 0845576594') . '</w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc><w:p><w:pPr><w:jc w:val="right"/></w:pPr>' . self::r('E Mail: a@b.co.za') . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body, [
            'word/_rels/document.xml.rels' => $this->rels(['rId7' => ['image', 'media/image1.png']]),
            'word/media/image1.png' => base64_decode(self::PNG),
        ]);

        $this->assertArrayHasKey('rId7', $cds['images'], 'the picture is stored once, as a data URI');
        $this->assertStringStartsWith('data:image/png;base64,', $cds['images']['rId7']['src']);
        $this->assertSame([], $cds['parse_report']['dropped'], 'a picture that was placed is not reported as dropped');

        $html = (new CdsRendererService())->render($cds);
        $this->assertMatchesRegularExpression('#<td[^>]*rowspan="2"[^>]*><div class="corex-cell-p"><img class="corex-source-image" src="data:image/png;base64,[^"]+" alt="" style="width:200pt#', $html, 'logo left, 200pt wide as printed, spanning both rows');
        $this->assertMatchesRegularExpression('#<div class="corex-cell-p" style="text-align:right">Tel: 0845576594</div>#', $html, 'contact block right-aligned');
        $this->assertSame(1, preg_match_all('#<td[^>]*>#', explode('</tr>', $html)[1], $m) ? 1 : 0, 'the row under a merged-down cell draws no cell for it');
    }

    public function test_bordered_boxes_and_shaded_bands_are_kept_and_cells_keep_their_alignment(): void
    {
        $border = '<w:tblBorders><w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>';
        $body = '<w:tbl><w:tblPr>' . $border . '</w:tblPr><w:tblGrid><w:gridCol w:w="9000"/></w:tblGrid>'
            . '<w:tr><w:tc><w:tcPr><w:shd w:val="clear" w:fill="D9D9D9"/></w:tcPr><w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . self::r('Rental Property') . '</w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:p><w:pPr><w:jc w:val="right"/></w:pPr>' . self::r('Property Address:') . '</w:p><w:p>' . self::r('line two') . '</w:p><w:p>' . self::r('line three') . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body);
        $t = $cds['sections'][0];
        $this->assertTrue($t['src_borders']);
        $html = (new CdsRendererService())->render($cds);

        $this->assertStringContainsString('data-src-borders="1"', $html);
        $this->assertStringContainsString('data-src-band="1"', $html, 'the shaded heading row is a band');
        $this->assertMatchesRegularExpression('#<t[dh][^>]*data-src-bt="1"[^>]*data-src-br="1"[^>]*data-src-bl="1"#', $html, 'the box has its top, right and left sides');
        // The one-cell, three-paragraph box is drawn as three rows: a page break can fall inside it; its sides run through all.
        $this->assertSame(3, substr_count($html, 'corex-split-row'));
        $this->assertSame(1, substr_count($html, 'data-src-bb="1"'), 'the bottom edge only on the last row');
        $this->assertStringContainsString('text-align:right', $html);
        $this->assertStringNotContainsString('text-align:justify', $html, 'never justified unless the source says so');
    }

    public function test_the_signature_table_is_two_columns_with_room_to_sign_and_date_inside_its_cells(): void
    {
        $cellOf = fn (string $who) => '<w:tc><w:tcPr/><w:p>' . self::r($who . ' SIGNATURE:') . '</w:p><w:p/><w:p/><w:p>' . self::r('DATE:') . '</w:p></w:tc>';
        $body = '<w:tbl><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4"/><w:left w:val="single" w:sz="4"/><w:bottom w:val="single" w:sz="4"/><w:right w:val="single" w:sz="4"/><w:insideV w:val="single" w:sz="4"/></w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid><w:tr>' . $cellOf('LANDLORD') . $cellOf('TENANT') . '</w:tr></w:tbl>';

        $cds = $this->parse($body);
        $t = $cds['sections'][0];

        $this->assertSame([50.0, 50.0], $t['col_widths']);
        $this->assertSame(["LANDLORD SIGNATURE:\n\n\nDATE:", "TENANT SIGNATURE:\n\n\nDATE:"], $t['rows'][0] ?? $t['headers']);
        $html = (new CdsRendererService())->render($cds);
        $this->assertSame(2, substr_count($html, 'SIGNATURE:'), 'two cells, not one collapsed line');
        $this->assertSame(2, substr_count($html, '<div class="corex-cell-p">&nbsp;</div><div class="corex-cell-p">&nbsp;</div>'), 'the blank lines are the room to sign');
    }

    public function test_the_running_page_header_and_footer_are_kept_with_the_page_number_and_the_title_page_rule(): void
    {
        $sect = '<w:sectPr ' . self::REL_NS . '><w:headerReference w:type="default" r:id="rIdH"/><w:footerReference w:type="default" r:id="rIdF"/><w:footerReference w:type="first" r:id="rIdF1"/><w:titlePg/></w:sectPr>';
        $footer = fn (string $cached) => '<w:ftr ' . self::W . '><w:p><w:pPr><w:pBdr><w:top w:val="single"/></w:pBdr></w:pPr><w:r><w:rPr><w:i/></w:rPr><w:t>VIPS CC t/a Mega Rent | Reg No 1</w:t></w:r><w:r><w:tab/></w:r>'
            . '<w:r><w:t xml:space="preserve">Page </w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>' . $cached . '</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>';
        $path = tempnam(sys_get_temp_dir(), 'pf') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document ' . self::W . '><w:body><w:p>' . self::r('Body') . '</w:p>' . $sect . '</w:body></w:document>');
        $zip->addFromString('word/_rels/document.xml.rels', $this->rels(['rIdH' => ['header', 'header1.xml'], 'rIdF' => ['footer', 'footer1.xml'], 'rIdF1' => ['footer', 'footer2.xml']]));
        $zip->addFromString('word/header1.xml', '<w:hdr ' . self::W . '><w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:i/></w:rPr><w:t>Mega Rent Lease - 50d Madeira Crescent</w:t></w:r></w:p></w:hdr>');
        $zip->addFromString('word/footer1.xml', $footer('8'));
        $zip->addFromString('word/footer2.xml', $footer('1'));
        $zip->close();
        $cds = (new CdsParserService())->parse($path);
        @unlink($path);

        $this->assertSame('Mega Rent Lease - 50d Madeira Crescent', $cds['page_header']['lines'][0]['text']);
        $this->assertSame('center', $cds['page_header']['lines'][0]['align']);
        $this->assertSame(['text' => 'VIPS CC t/a Mega Rent | Reg No 1', 'right' => 'Page {PAGE}', 'italic' => true], array_intersect_key($cds['page_footer']['lines'][0], array_flip(['text', 'right', 'italic'])));
        $this->assertTrue($cds['page_footer']['rule']);
        $this->assertTrue($cds['page_first_different']);
        $this->assertArrayHasKey('page_footer_first', $cds);
        $this->assertSame([], $cds['parse_report']['dropped'], 'the stale cached page number is replaced by {PAGE}, not reported as lost');
        $this->assertSame(0, $cds['parse_report']['unread_chars']);

        $html = (new CdsRendererService())->render($cds);
        $this->assertStringContainsString('class="corex-page-header"', $html);
        $this->assertStringContainsString('<span class="corex-page-number"></span>', $html);
        $this->assertStringContainsString('corex-page-header-cover', $html, 'page one has no header of its own: a cover keeps the repeated header off it');
        [$frame, $rest] = CdsRendererService::hoistPageFrame($html);
        $this->assertStringContainsString('corex-page-footer', $frame);
        $this->assertStringNotContainsString('corex-page-footer', $rest, 'the print-and-sign route sets the frame first in <body>, where dompdf repeats it');
    }

    private function headerOnly(string $headerText): array
    {
        $sect = '<w:sectPr ' . self::REL_NS . '><w:headerReference w:type="default" r:id="rIdH"/></w:sectPr>';
        $path = tempnam(sys_get_temp_dir(), 'ph') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document ' . self::W . '><w:body><w:p>' . self::r('Body') . '</w:p>' . $sect . '</w:body></w:document>');
        $zip->addFromString('word/_rels/document.xml.rels', $this->rels(['rIdH' => ['header', 'header1.xml']]));
        $zip->addFromString('word/header1.xml', '<w:hdr ' . self::W . '><w:p><w:r><w:t>' . $headerText . '</w:t></w:r></w:p></w:hdr>');
        $zip->close();
        $cds = (new CdsParserService())->parse($path);
        @unlink($path);

        return $cds;
    }

    public function test_a_running_header_with_the_previous_tenants_details_becomes_merge_fields_not_verbatim_text(): void
    {
        // Mega's Word header: a previous lease's property, surname and year printed on every page of every future document.
        $cds = $this->headerOnly('Mega Rent Lease – 50d Madeira Crescent – Arendse - 2026');

        $this->assertSame('Mega Rent Lease – {PROPERTY} – {SURNAME} – {YEAR}', $cds['page_header']['lines'][0]['text']);

        $html = (new CdsRendererService())->render($cds);
        $this->assertStringNotContainsString('Madeira', $html);
        $this->assertStringNotContainsString('Arendse', $html);
        $this->assertStringContainsString('data-merge="property"', $html);
        $this->assertStringContainsString('data-merge="surname"', $html);
        $this->assertStringContainsString('data-merge="year"', $html);

        // ...and the saved template fills them from each document's own data.
        $compile = new \ReflectionMethod(\App\Http\Controllers\Docuperfect\TemplateController::class, 'frameMergeFieldsToBlade');
        $compile->setAccessible(true);
        $blade = $compile->invoke((new \ReflectionClass(\App\Http\Controllers\Docuperfect\TemplateController::class))->newInstanceWithoutConstructor(), $html);
        $out = \Illuminate\Support\Facades\Blade::render($blade, ['property_address' => '12 Beach Road, Uvongo', 'lessee_last_name' => 'Dlamini', 'lease_start' => '2027-03-01']);
        $this->assertStringContainsString('Mega Rent Lease', $out);
        $this->assertStringContainsString('12 Beach Road, Uvongo', $out);
        $this->assertStringContainsString('Dlamini', $out);
        $this->assertStringContainsString('2027', $out);
        $this->assertStringNotContainsString('Arendse', $out);
    }

    public function test_an_ordinary_running_header_is_left_exactly_as_the_word_file_has_it(): void
    {
        foreach (['Mega Rent Lease – Confidential – Page 1', 'Mega Rent Lease - 50d Madeira Crescent', 'Lease Agreement – 2026'] as $text) {
            $this->assertSame($text, $this->headerOnly($text)['page_header']['lines'][0]['text'], $text);
        }
    }

    public function test_a_hard_page_break_between_boxes_is_kept(): void
    {
        $tbl = fn (string $t) => '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="9000"/></w:tblGrid><w:tr><w:tc><w:p>' . self::r($t) . '</w:p></w:tc></w:tr></w:tbl>';
        $cds = $this->parse($tbl('Page one box') . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>' . $tbl('Page two box'));

        $this->assertSame(['table', 'page_break', 'table'], array_column($cds['sections'], 'type'));
        $this->assertMatchesRegularExpression('#Page one box.*corex-src-page-break.*Page two box#s', (new CdsRendererService())->render($cds));
    }

    public function test_the_choice_text_of_a_form_row_is_kept_as_printed_text(): void
    {
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="2000"/><w:gridCol w:w="3500"/><w:gridCol w:w="3500"/></w:tblGrid><w:tr>'
            . '<w:tc><w:p>' . self::r('Lease Type:') . '</w:p></w:tc><w:tc><w:p>' . self::r('Month to month lease or fixed term') . '</w:p></w:tc>'
            . '<w:tc><w:p>' . self::r('Rent collected by the Agency @ fee of 6% of monthly rent') . '</w:p></w:tc></w:tr></w:tbl>';

        $cds = $this->parse($body);
        $cells = $cds['sections'][0]['headers'] ?? $cds['sections'][0]['rows'][0];

        $this->assertSame(['Lease Type:', 'Month to month lease or fixed term', 'Rent collected by the Agency @ fee of 6% of monthly rent'], $cells);
        $this->assertStringContainsString('Month to month lease or fixed term', (new CdsRendererService())->render($cds), 'printed choices are wording, not blanks: a field is placed inline beside them, never instead of them');
    }
}
