<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Reference;

use App\Services\Docuperfect\Compiler\Golden\CompiledTemplateGoldenHarness;
use App\Services\Docuperfect\Compiler\Ingest\DeterministicSegmenter;
use App\Services\Docuperfect\Compiler\Ingest\HtmlIngestor;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Rendering\CdsGoldenRenderProbe;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderer;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use PHPUnit\Framework\TestCase;

/**
 * AT-177 / WS5 — the REFERENCE-PROOF-FROM-PRODUCTION test. cc3's ReferencePackProofTest proves
 * the HAND-COMPILED CDS; this proves the WS4-E ENGINE reproduces it FROM the real live template
 * render (spec §8: "ingest must produce what hand-compilation would"), and runs the side-by-side
 * truth test of the compiled render vs the current live runtime render.
 *
 * Fixtures `tests/Fixtures/esign/live-template-{117,119}.html` are the actual output of the live
 * blades `docuperfect.web-templates.cds.template-{117,119}` (the production runtime render).
 * Pure PHPUnit (no DB).
 */
final class LiveReferenceIngestProofTest extends TestCase
{
    private function fixture(string $family): string
    {
        return file_get_contents(__DIR__ . "/../../../../Fixtures/esign/live-template-{$family}.html") ?: '';
    }

    /** Ingest the live render through the WS4-E pipeline → segmented CDS structure. */
    private function ingest(string $family): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ws5') . '.html';
        file_put_contents($path, $this->fixture($family));
        try {
            $doc = (new HtmlIngestor())->ingest($path, "live-{$family}.html", ['family' => $family, 'title' => 'Mandatory Disclosure']);

            return (new DeterministicSegmenter())->segment($doc)->structure;
        } finally {
            @unlink($path);
        }
    }

    private function dictionary(): InMemoryDataDictionaryResolver
    {
        return InMemoryDataDictionaryResolver::atVersion(1, [
            'property_address' => ['category' => 'property', 'type' => 'string'],
        ]);
    }

    private function lint(array $structure): \App\Services\Docuperfect\Compiler\Linter\LintReport
    {
        return (new CompiledTemplateLinter())->lint(
            $structure,
            $this->dictionary(),
            null,
            new LinterContext(new CdsRenderParityVerifier()),
        );
    }

    private function normalise(string $html): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', strip_tags($html))));
    }

    // ── WS5-A: ingest the live production render → publishable CDS ──────────────

    public function test_119_auto_compiles_publishable_from_the_live_production_render(): void
    {
        $structure = $this->ingest('119');

        // Parties + a signature surface for each were reconstructed from the live render alone.
        $this->assertEqualsCanonicalizing(['agent', 'seller'], array_column($structure['parties'], 'key'));

        $report = $this->lint($structure);
        $this->assertTrue($report->publishable(), 'live 119 must auto-compile publishable: ' . json_encode($report->failedRules()));

        $golden = (new CompiledTemplateGoldenHarness())->certify(Cds::fromArray($structure), $this->dictionary(), new CdsGoldenRenderProbe());
        $this->assertTrue($golden->certifiable(), 'live 119 must certify.');
    }

    public function test_117_compiles_from_live_render_after_operator_binds_the_one_field(): void
    {
        $structure = $this->ingest('117');

        // Signers reconstructed; one fill-point detected (the property-address line) — UNBOUND.
        $this->assertEqualsCanonicalizing(['agent', 'seller'], array_column($structure['parties'], 'key'));
        $this->assertTrue($this->lint($structure)->ruleFailed('L1'), 'the detected fill-point is unbound until the operator binds it.');

        // Operator confirms: bind the fill-point (the human-in-the-loop step, §3.3).
        foreach ($structure['blocks'] as &$block) {
            if (($block['type'] ?? '') === 'field_group') {
                foreach ($block['fields'] as &$field) {
                    $field['binding'] = 'property_address';
                }
                unset($field);
            }
        }
        unset($block);

        $this->assertTrue($this->lint($structure)->publishable(), 'once bound, live 117 compiles publishable.');
    }

    // ── WS5-B: side-by-side truth test — compiled render vs live runtime render ──

    /**
     * @dataProvider references
     * @param list<string> $essentialPhrases
     * @param list<string> $expectedSigners
     */
    public function test_compiled_render_reproduces_live_content_and_signers(string $family, array $essentialPhrases, array $expectedSigners): void
    {
        $cds = Cds::fromArray($family === '117' ? ReferencePackCds::template117() : ReferencePackCds::template119());
        $active = array_map(static fn (string $p): string => $p . '_1', $expectedSigners);
        $compiled = (new CdsRenderer())->renderDocument($cds, DeliveryMode::PdfWetInk, $active);

        $compiledText = $this->normalise($compiled->html);
        $liveText = $this->normalise($this->fixture($family));

        // (1) No content dropped: every essential legal phrase in the live doc survives compiled.
        foreach ($essentialPhrases as $phrase) {
            $needle = $this->normalise($phrase);
            $this->assertStringContainsString($needle, $liveText, "sanity: live {$family} contains '{$phrase}'");
            $this->assertStringContainsString($needle, $compiledText, "compiled {$family} must reproduce '{$phrase}'");
        }

        // (2) Signer topology matches: the same role parties sign in both.
        $liveSigners = [];
        if (preg_match_all('/data-marker-party="([a-z]+)(?:_\d+)?"/', $this->fixture($family), $m)) {
            foreach ($m[1] as $role) {
                $liveSigners[$role] = true;
            }
        }
        foreach ($expectedSigners as $signer) {
            $this->assertArrayHasKey($signer, $liveSigners, "sanity: live {$family} has a {$signer} marker");
            $this->assertStringContainsString('data-marker-party="' . $signer . '"', $compiled->html, "compiled {$family} signs {$signer}");
        }
    }

    /** @return iterable<string,array{0:string,1:list<string>,2:list<string>}> */
    public static function references(): iterable
    {
        yield '117' => ['117', ['IMMOVABLE PROPERTY CONDITION REPORT', 'Disclaimer', 'defects in the roof', 'THUS DONE AND SIGNED'], ['seller', 'agent']];
        yield '119' => ['119', ['ADDENDUM B', 'EXTRA INFORMATION', 'Electrical Compliance Certificate'], ['seller', 'agent']];
    }
}
