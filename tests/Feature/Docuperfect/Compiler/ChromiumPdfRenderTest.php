<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler;

use App\Services\Docuperfect\Compiler\Rendering\ChromiumPdfRenderService;
use PHPUnit\Framework\TestCase;

/**
 * WS2 — the internal headless-Chromium PDF render service. Integration test: it drives the
 * real engine on the box. Self-skips where Chromium is unavailable so CI without a browser
 * stays green (the structural L6 parity check does not need Chromium — see the unit tests).
 */
final class ChromiumPdfRenderTest extends TestCase
{
    private ChromiumPdfRenderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChromiumPdfRenderService();
        if (! $this->service->isAvailable()) {
            $this->markTestSkipped('Headless Chromium is not installed on this host.');
        }
    }

    public function test_prints_html_to_a_valid_pdf(): void
    {
        $pdf = $this->service->htmlToPdf(
            '<section data-block-id="p1" data-block-type="prose"><h1>Mandatory Disclosure Form</h1><p>The seller confirms.</p></section>'
            . '<section data-block-id="s1" data-block-type="signature">'
            . '<div data-anchor-party="seller" data-anchor-kind="signature" data-marker-party="seller" data-marker-type="signature"><span class="cds-sign-line"></span></div>'
            . '</section>',
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf), 'A real rendered PDF should be more than a stub.');
    }

    public function test_wraps_a_fragment_into_a_printable_document(): void
    {
        // A bare fragment (no <html>) must still print — the service wraps it in an A4 doc.
        $pdf = $this->service->htmlToPdf('<p>bare fragment</p>');
        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
