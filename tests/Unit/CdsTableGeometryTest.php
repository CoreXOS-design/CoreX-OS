<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use Tests\TestCase;
use ZipArchive;

/**
 * Imported Word tables keep their geometry: relative column widths, the table's width as a share of
 * the page, merged cells and cell alignment. The parser stores it, and the one renderer behind the
 * builder preview, the signing page and the PDF draws it. DB-free.
 */
class CdsTableGeometryTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** A4 with 1" margins -> 9026 twips of text width, so grid columns below read as round percentages. */
    private const SECT = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>';

    private function parseBody(string $bodyXml): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tbl') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . self::W . '><w:body>'
            . $bodyXml . self::SECT . '</w:body></w:document>');
        $zip->close();

        try {
            return (new CdsParserService())->parse($path)['sections'];
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param int[]   $grid  w:gridCol widths (twips); [] writes no w:tblGrid
     * @param array[] $rows  each row: list of [text, jc|null, gridSpan|null, tcW|null]
     */
    private function tbl(array $grid, array $rows, string $tblPr = ''): string
    {
        $xml = '<w:tbl><w:tblPr>' . $tblPr . '</w:tblPr>';
        if ($grid !== []) {
            $xml .= '<w:tblGrid>' . implode('', array_map(fn ($w) => '<w:gridCol w:w="' . $w . '"/>', $grid)) . '</w:tblGrid>';
        }
        foreach ($rows as $row) {
            $xml .= '<w:tr>';
            foreach ($row as $cell) {
                [$text, $jc, $span, $tcw] = $cell + [null, null, null, null];
                $xml .= '<w:tc><w:tcPr>'
                    . ($tcw ? '<w:tcW w:w="' . $tcw . '" w:type="dxa"/>' : '')
                    . ($span ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                    . '</w:tcPr><w:p>' . ($jc ? '<w:pPr><w:jc w:val="' . $jc . '"/></w:pPr>' : '')
                    . '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    private function onlyTable(array $sections): array
    {
        foreach ($sections as $s) {
            if (in_array($s['type'], ['table', 'disclosure_checklist'], true)) {
                return $s;
            }
        }
        $this->fail('No table section parsed');
    }

    /** The 3-column Yes/No/Notes style grid from the defect disclosure: wide statement, narrow Y/N, medium notes. */
    private function defectGrid(): string
    {
        return $this->tbl([4500, 1500, 3000], [
            [['Item', null], ['Y/N', 'center'], ['Notes', null]],
            [['Is the roof leaking? Describe any history of leaks and repairs carried out in the last five years', 'both'], ['Y', 'center'], ['', null]],
            [['Are there any structural defects to the dwelling or outbuildings known to the owner', null], ['N', 'center'], ['', null]],
        ], '<w:tblW w:w="5000" w:type="pct"/>');
    }

    // ---- parser: what the Word source says ---------------------------------------------------

    public function test_unequal_grid_columns_are_stored_as_percentages_that_sum_to_100(): void
    {
        $table = $this->onlyTable($this->parseBody($this->defectGrid()));

        $this->assertSame([50.0, 16.67, 33.33], $table['col_widths']);
        $this->assertEqualsWithDelta(100.0, array_sum($table['col_widths']), 0.001);
        // tblW is 100% of the page: that is what a table already gets, so nothing is stored for it.
        $this->assertArrayNotHasKey('width_pct', $table);
        // Text is untouched.
        $this->assertSame(['Item', 'Y/N', 'Notes'], $table['headers']);
        $this->assertCount(2, $table['rows']);
    }

    public function test_cell_alignment_is_kept_and_justified_only_where_the_source_justified(): void
    {
        $table = $this->onlyTable($this->parseBody($this->defectGrid()));

        $this->assertSame(['align' => 'center'], $table['header_meta'][1]);
        $this->assertSame([], $table['header_meta'][0]);
        $this->assertSame('justify', $table['cell_meta'][0][0]['align']);   // the source justified this cell
        $this->assertSame('center', $table['cell_meta'][0][1]['align']);
        $this->assertSame([], $table['cell_meta'][1][0]);                   // plain cell: nothing stored
    }

    public function test_a_table_narrower_than_the_page_keeps_its_share_of_the_page(): void
    {
        // 4513 twips of a 9026-twip text area = 50%.
        $table = $this->onlyTable($this->parseBody($this->tbl([1500, 3013], [
            [['Landlord', null], ['Mega Property Group', null]],
            [['Tenant', null], ['', null]],
        ], '<w:tblW w:w="4513" w:type="dxa"/>')));

        $this->assertSame(50.0, $table['width_pct']);
        $this->assertSame([33.24, 66.76], $table['col_widths']);
    }

    public function test_an_auto_width_table_takes_the_grid_total_as_its_width(): void
    {
        $table = $this->onlyTable($this->parseBody($this->tbl([2000, 2500], [
            [['A', null], ['B', null]],
            [['C', null], ['D', null]],
        ], '<w:tblW w:w="0" w:type="auto"/>')));

        $this->assertSame(49.9, $table['width_pct']);   // 4500 / 9026
    }

    public function test_cell_widths_are_used_when_the_table_has_no_grid(): void
    {
        $table = $this->onlyTable($this->parseBody($this->tbl([], [
            [['Clause', null, null, 6000], ['Page', null, null, 3000]],
            [['Rent', null, null, 6000], ['2', null, null, 3000]],
        ])));

        $this->assertSame([66.67, 33.33], $table['col_widths']);
    }

    public function test_a_merged_cell_and_a_centred_table_are_kept(): void
    {
        $table = $this->onlyTable($this->parseBody($this->tbl([2000, 3000, 4026], [
            [['Heading across two columns', null, 2], ['Right', null]],
            [['a', null], ['b', null], ['c', null]],
        ], '<w:tblW w:w="9026" w:type="dxa"/><w:jc w:val="center"/>')));

        $this->assertSame('center', $table['align']);
        $this->assertSame(2, $table['header_meta'][0]['span']);
        $this->assertSame([22.16, 33.24, 44.6], $table['col_widths']);
    }

    public function test_a_table_with_no_geometry_stores_nothing_extra(): void
    {
        // One column, no tblW, no alignment: nothing the renderer could do differently.
        $table = $this->onlyTable($this->parseBody($this->tbl([], [
            [['Only cell', null]],
            [['Second row', null]],
        ])));

        foreach (['col_widths', 'width_pct', 'align', 'cell_meta', 'header_meta'] as $key) {
            $this->assertArrayNotHasKey($key, $table);
        }
    }

    // ---- renderer: the one drawing of it (builder preview, signing page, PDF) ----------------

    public function test_renderer_draws_the_stored_widths_and_alignment(): void
    {
        $html = (new CdsRendererService())->render(['sections' => $this->parseBody($this->defectGrid())]);

        $this->assertStringContainsString('<table class="corex-table" style="table-layout:fixed" data-src-geometry="1">', $html);
        $this->assertStringContainsString('<colgroup><col style="width:50%"><col style="width:16.67%"><col style="width:33.33%"></colgroup>', $html);
        // The header row also carries the widths on its cells (dompdf), and the Y/N cell is centred.
        $this->assertStringContainsString('<th style="width:16.67%;text-align:center">Y/N</th>', $html);
        $this->assertStringContainsString('<td style="text-align:justify">Is the roof leaking?', $html);
        $this->assertStringContainsString('<td style="text-align:center">Y</td>', $html);
        // A cell the source did not align carries no alignment of its own.
        $this->assertStringContainsString('<td>Are there any structural defects', $html);
    }

    public function test_renderer_draws_a_narrow_centred_table_with_a_merged_cell(): void
    {
        $sections = $this->parseBody($this->tbl([2000, 3000], [
            [['Across both', null, 2]],
            [['a', null], ['b', null]],
        ], '<w:tblW w:w="4513" w:type="dxa"/><w:jc w:val="center"/>'));
        $html = (new CdsRendererService())->render(['sections' => $sections]);

        $this->assertStringContainsString('style="width:50%;table-layout:fixed;margin-left:auto;margin-right:auto"', $html);
        $this->assertStringContainsString('<th colspan="2" style="width:100%">Across both</th>', $html);
    }

    public function test_a_table_stored_before_widths_were_read_renders_exactly_as_it_always_did(): void
    {
        $html = (new CdsRendererService())->render(['sections' => [
            ['type' => 'table', 'headers' => ['A', 'B'], 'rows' => [['1', '2']]],
        ]]);

        $this->assertSame(
            '<table class="corex-table"><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
            $html
        );
    }

    public function test_disclosure_grid_keeps_widths_when_the_columns_match_what_is_drawn(): void
    {
        $grid = $this->tbl([6000, 1500, 1500], [
            [['Statement - please answer yes or no to each of the following questions', null], ['YES', 'center'], ['NO', 'center']],
            [['Is the roof sound?', null], ['', null], ['', null]],
            [['Is there damp?', null], ['', null], ['', null]],
        ]);
        // (A header row longer than 50 characters is what keeps the importer reading it as the grid's own row.)
        $sections = $this->parseBody($grid);
        $this->assertSame('disclosure_checklist', $this->onlyTable($sections)['type']);
        $html = (new CdsRendererService())->render(['sections' => $sections]);

        $this->assertStringContainsString('<colgroup><col style="width:66.67%"><col style="width:16.67%"><col style="width:16.66%"></colgroup>', $html);
        $this->assertStringContainsString('class="corex-disclosure-table" style="table-layout:fixed"', $html);
    }

    public function test_disclosure_grid_with_an_extra_column_keeps_the_stylesheet_widths(): void
    {
        $grid = $this->tbl([5000, 1000, 1000, 2026], [
            [['Statement - please answer yes or no to each of the following questions', null], ['YES', null], ['NO', null], ['Notes', null]],
            [['Is the roof sound?', null], ['', null], ['', null], ['', null]],
            [['Is there damp?', null], ['', null], ['', null], ['', null]],
        ]);
        $sections = $this->parseBody($grid);
        $this->assertSame('disclosure_checklist', $this->onlyTable($sections)['type']);
        $html = (new CdsRendererService())->render(['sections' => $sections]);

        $this->assertStringContainsString('corex-disclosure-table', $html);
        $this->assertStringNotContainsString('<colgroup>', $html);
    }

    // ---- stylesheet: no inherited justification inside cells ---------------------------------

    public function test_cells_do_not_inherit_the_pages_justified_text(): void
    {
        $css = file_get_contents(public_path('css/corex-document.css'));

        $this->assertMatchesRegularExpression(
            '/\.corex-table td,\s*\.corex-table th,[^{]*\{[^}]*text-align:\s*left;/s',
            $css
        );
    }
}
