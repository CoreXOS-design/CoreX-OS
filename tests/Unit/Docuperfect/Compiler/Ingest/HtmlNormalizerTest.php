<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Ingest;

use App\Services\Docuperfect\Compiler\Ingest\HtmlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * WS4-E Gate 2 — the HTML normalizer that funnels every ingestor into a clean block-level body.
 */
final class HtmlNormalizerTest extends TestCase
{
    private HtmlNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HtmlNormalizer();
    }

    public function test_strips_head_style_script_and_returns_body_inner(): void
    {
        $out = $this->normalizer->normalize(
            '<html><head><title>x</title><style>.a{}</style></head><body><h1>Title</h1><script>alert(1)</script><p>Body</p></body></html>',
        );

        $this->assertStringContainsString('<h1>Title</h1>', $out);
        $this->assertStringContainsString('<p>Body</p>', $out);
        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringNotContainsString('<title', $out);
    }

    public function test_prefers_the_corex_page_container(): void
    {
        $out = $this->normalizer->normalize(
            '<body><div class="corex-document-wrapper"><div class="corex-page"><p>Inside</p></div></div></body>',
        );

        $this->assertSame('<p>Inside</p>', $out);
    }

    public function test_removes_comments(): void
    {
        $out = $this->normalizer->normalize('<body><p>Keep</p><!-- drop me --></body>');
        $this->assertStringNotContainsString('drop me', $out);
        $this->assertStringContainsString('Keep', $out);
    }

    public function test_empty_input_returns_empty(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize('   '));
    }

    public function test_bare_fragment_without_body_is_preserved(): void
    {
        $out = $this->normalizer->normalize('<p>one</p><p>two</p>');
        $this->assertStringContainsString('one', $out);
        $this->assertStringContainsString('two', $out);
    }
}
