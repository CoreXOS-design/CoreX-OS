<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Services\Docuperfect\DocxParserService;
use ReflectionClass;
use Tests\TestCase;

/**
 * The importer was looking for the wrong character.
 *
 * Blank detection was `/_{2,}/` — underscores only. HFC's real templates rule their
 * fill-in lines with ELLIPSIS runs (`…………`, U+2026). Measured on the four live sources:
 *
 *     exclusive-authority-to-sell-v10 :  3 underscore runs, 35 ellipsis runs
 *     offer-to-purchase-v13-enviro    :  2 underscore runs, 117 ellipsis runs
 *     fica-natural-person-v8          : 11 underscore runs,  0 ellipsis runs
 *     seller-mandatory-disclosure-v7  : 11 underscore runs,  0 ellipsis runs
 *
 * So the two launch-critical SALES documents imported as near-empty shells — 3 fields and
 * 2 fields — and it would have looked like a successful import.
 *
 * These tests use the real markup shapes rather than the real .docx, so they do not depend
 * on the Node/Mammoth toolchain.
 */
final class DocxBlankDetectionTest extends TestCase
{
    private function detect(string $html): array
    {
        $svc = new DocxParserService();
        $m = (new ReflectionClass($svc))->getMethod('detectFieldsFromHtml');
        $m->setAccessible(true);

        return $m->invoke($svc, $html);
    }

    private function inject(string $html): string
    {
        $svc = new DocxParserService();
        $m = (new ReflectionClass($svc))->getMethod('injectFieldSpans');
        $m->setAccessible(true);

        return $m->invoke($svc, $html, []);
    }

    /** The EATS shape: an ellipsis-ruled line is a blank. */
    public function test_ellipsis_runs_are_detected_as_blanks(): void
    {
        $html = '<p>I / We …………………………………………… hereby appoint</p>';

        $fields = $this->detect($html);

        $this->assertCount(1, $fields, 'an ellipsis-ruled fill-in line must be a blank');
        $this->assertSame('ellipsis', $fields[0]['source']);
    }

    /** Underscores must keep working exactly as before — this is additive. */
    public function test_underscore_runs_still_detected(): void
    {
        $fields = $this->detect('<p>Name: ________________ (Seller)</p>');

        $this->assertCount(1, $fields);
        $this->assertSame('underscore', $fields[0]['source']);
    }

    /** Dot leaders count, but ordinary prose ellipsis "..." must not. */
    public function test_dot_leaders_count_but_prose_does_not(): void
    {
        $this->assertCount(1, $this->detect('<p>Erf no ............................ Portion</p>'));
        $this->assertCount(0, $this->detect('<p>and so on... the parties agree</p>'), 'prose "..." is not a blank');
    }

    /**
     * The context the AI names fields from must survive multibyte slicing. An ellipsis is
     * 3 bytes; the offset from PREG_OFFSET_CAPTURE is a BYTE offset. Slice it with mb_*
     * without converting and every context arrives mangled — and the AI names blindly.
     */
    public function test_context_around_a_multibyte_blank_is_not_mangled(): void
    {
        $html = '<p>Property Erf / Sectional Scheme / Unit no ………………………… (District)</p>';

        $fields = $this->detect($html);

        $this->assertNotEmpty($fields);
        $this->assertStringContainsString('Unit no', $fields[0]['context_before']);
        $this->assertStringContainsString('District', $fields[0]['context_after']);
    }

    /**
     * Detection and span-injection MUST agree. If they diverge, the fields handed to the AI
     * and the spans in the HTML fall out of step and every field after the mismatch is
     * assigned to the wrong blank (STANDARDS.md — the shift-assignments bug class).
     */
    public function test_detected_blanks_and_injected_spans_stay_in_lockstep(): void
    {
        $html = '<p>Seller: …………………………… ID: ________________</p>'
              . '<p>Address: ………………………………………………</p>'
              . '<p>Price: R ..............................</p>';

        $fields = $this->detect($html);
        $spans  = substr_count($this->inject($html), 'class="field-blank"');

        $this->assertSame(4, count($fields), 'all four blanks detected');
        $this->assertSame(count($fields), $spans, 'a span per detected blank — or every later field shifts');
    }

    /** A document with no blanks at all yields none (no false positives on prose). */
    public function test_prose_only_document_yields_no_blanks(): void
    {
        $html = '<p>The Seller warrants that the property is free of defects.</p>';

        $this->assertCount(0, $this->detect($html));
    }
}
