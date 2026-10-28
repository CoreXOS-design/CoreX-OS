<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsRendererService;
use Tests\TestCase;

/**
 * A Word file with a different first-page footer / header: the first-page variant is its own block (corex-page-first), the
 * default is still drawn - it is what pages 2+ (and every surface that cannot tell pages apart) show. The e-sign paginator
 * (a4-page-styles) uses the variant on page one only. DB-free.
 */
class CdsPageFrameFirstVariantTest extends TestCase
{
    private function cds(): array
    {
        return [
            'sections' => [['type' => 'paragraph', 'text' => 'Body']],
            'page_first_different' => true,
            'page_footer' => ['lines' => [['text' => 'Default footer', 'right' => 'Page {PAGE}']]],
            'page_footer_first' => ['lines' => [['text' => 'First page footer']], 'rule' => true],
            'page_header' => ['lines' => [['text' => 'Default header']]],
            'page_header_first' => ['lines' => [['text' => 'First page header']]],
        ];
    }

    public function test_default_and_first_page_variants_are_both_drawn_and_the_variant_is_marked(): void
    {
        $html = (new CdsRendererService())->render($this->cds());

        foreach (['header', 'footer'] as $kind) {
            $this->assertSame(1, preg_match('/<div class="corex-page-' . $kind . '">/', $html), "the default $kind is drawn (no longer replaced by the first-page one)");
            $this->assertSame(1, preg_match('/<div class="corex-page-' . $kind . ' corex-page-first[^"]*">/', $html), "the first-page $kind is its own marked block");
        }
        $this->assertStringContainsString('Default footer', $html);
        $this->assertStringContainsString('First page footer', $html);
        $this->assertLessThan(strpos($html, 'First page footer'), strpos($html, 'Default footer'), 'default first, variant after it');
        $this->assertStringContainsString('corex-page-first corex-page-rule', $html, "the variant keeps its own rule");
    }

    public function test_a_document_without_a_different_first_page_is_unchanged(): void
    {
        $cds = $this->cds();
        $cds['page_first_different'] = false;
        $html = (new CdsRendererService())->render($cds);

        $this->assertStringNotContainsString('corex-page-first', $html);
        $this->assertStringContainsString('Default footer', $html);
        $this->assertStringNotContainsString('First page footer', $html);
    }

    public function test_the_stylesheets_hide_the_variant_off_the_paginator(): void
    {
        $this->assertStringContainsString('.corex-page-first', CdsRendererService::PDF_PAGE_FRAME_CSS);
        $this->assertStringContainsString('.corex-page-footer.corex-page-first', (string) file_get_contents(public_path('css/corex-document.css')));
    }
}
