<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Rendering;

use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderer;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use PHPUnit\Framework\TestCase;

/**
 * WS2-d — the reference proof (spec §8.1). The zero-field templates 117 (MDF) and 119
 * (Addendum B) are the campaign's proving ground: they isolate signature surfaces + letterhead
 * + pagination from field-binding. The render-only runtime must reproduce their known-good
 * content and signable surfaces, lint publishable, and hold web↔PDF parity — before any
 * field-bearing work (116).
 *
 * Pure unit (no DB): zero-field CDS needs no dictionary; the WS2 verifier makes L6 live.
 */
final class ReferencePackProofTest extends TestCase
{
    /** @return iterable<string,array{0:array,1:list<string>,2:list<string>}> */
    public static function references(): iterable
    {
        yield '117 MDF' => [ReferencePackCds::template117(), ['seller', 'agent', 'buyer'], ['IMMOVABLE PROPERTY CONDITION REPORT', 'Statements in connection with Property', 'defects in the roof', 'THUS DONE AND SIGNED']];
        yield '119 Addendum B' => [ReferencePackCds::template119(), ['seller', 'agent'], ['ADDENDUM B', 'EXTRA INFORMATION', 'Electrical Compliance Certificate']];
    }

    /**
     * @dataProvider references
     * @param list<string> $parties
     * @param list<string> $mustContain
     */
    public function test_reference_template_is_publishable_with_parity_live(array $structure, array $parties, array $mustContain): void
    {
        $context = new LinterContext(new CdsRenderParityVerifier());
        $report = (new CompiledTemplateLinter())->lint($structure, new InMemoryDataDictionaryResolver(), null, $context);

        $this->assertTrue(
            $report->publishable(),
            'Reference template must lint publishable. Blocking: ' . implode('; ', array_map(fn ($f) => $f->rule . ':' . $f->code, $report->blocking())),
        );
    }

    /**
     * @dataProvider references
     * @param list<string> $parties
     * @param list<string> $mustContain
     */
    public function test_reference_render_reproduces_content_and_surfaces_in_all_modes(array $structure, array $parties, array $mustContain): void
    {
        $cds = Cds::fromArray($structure);
        $renderer = new CdsRenderer();
        $active = array_map(fn ($p) => $p . '_1', $parties);

        foreach ([DeliveryMode::WebEsign, DeliveryMode::PdfWetInk, DeliveryMode::Download] as $mode) {
            $surface = $renderer->renderDocument($cds, $mode, $active);

            // Faithful known-good content.
            foreach ($mustContain as $needle) {
                $this->assertStringContainsString($needle, $surface->html, "{$mode->value} must contain: {$needle}");
            }
            // Letterhead present.
            $this->assertStringContainsString('HOME FINDERS COASTAL', $surface->html);
            // A compiled signable surface per declared party.
            foreach ($parties as $party) {
                $this->assertStringContainsString('data-marker-party="' . $party . '"', $surface->html);
            }
            $this->assertStringContainsString('data-marker-type="signature"', $surface->html);
        }
    }

    /**
     * @dataProvider references
     * @param list<string> $parties
     */
    public function test_reference_holds_web_pdf_parity(array $structure, array $parties): void
    {
        $active = array_map(fn ($p) => $p . '_1', $parties);
        $result = (new CdsRenderParityVerifier())->verify($structure, $active);

        $this->assertTrue($result->matched, 'web↔PDF parity must hold. Diffs: ' . implode('; ', $result->differences));
        $this->assertSame($result->webHash, $result->pdfHash, 'proven-equal parity hashes must match');
    }

    public function test_pack_sequencing_117_then_119(): void
    {
        // The Sales Mandate Pack signs 117 then 119 in order; both compile and render independently.
        $renderer = new CdsRenderer();
        $s117 = $renderer->renderDocument(Cds::fromArray(ReferencePackCds::template117()), DeliveryMode::PdfWetInk, ['seller_1', 'agent_1', 'buyer_1']);
        $s119 = $renderer->renderDocument(Cds::fromArray(ReferencePackCds::template119()), DeliveryMode::PdfWetInk, ['seller_1', 'agent_1']);

        $this->assertStringContainsString('Mandatory Disclosure', $s117->html . ' ' . 'Immovable Property Condition Report');
        $this->assertStringContainsString('ADDENDUM B', $s119->html);
        // Distinct compiled documents, each with its own signature surfaces.
        $this->assertNotSame($s117->fingerprintHash(), $s119->fingerprintHash());
    }
}
