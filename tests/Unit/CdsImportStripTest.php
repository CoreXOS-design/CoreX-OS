<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use PHPUnit\Framework\TestCase;

/**
 * AT-177 (on-site 2026-07-18) — import STRIP discipline, the DB-free half:
 *   R1 — the source doc's letterhead (company_header) is stripped; CoreX renders its own.
 *   R2 — a commission / professional-fee percentage typed in the body is tokenised (guarded).
 *   R3 — the source doc's end signature block is stripped; CoreX renders its own.
 * (The commission BINDING half is in Tests\Feature\Docuperfect\CdsImportBindingConvergenceTest.)
 */
class CdsImportStripTest extends TestCase
{
    private function commission(array $sections): array
    {
        $svc = new CdsParserService();
        $m = new \ReflectionMethod($svc, 'detectCommissionField');
        $m->setAccessible(true);
        return $m->invoke($svc, $sections);
    }

    private function hasCommissionField(array $sections): bool
    {
        foreach ($sections as $s) {
            foreach ($s['content'] ?? [] as $it) {
                if (($it['block_id'] ?? '') === 'document_commission_percentage') {
                    return true;
                }
            }
        }
        return false;
    }

    // ---- R1 / R3: render strip -------------------------------------------------------------

    public function test_render_strips_source_header_and_end_signature_block(): void
    {
        $cds = ['sections' => [
            ['type' => 'company_header', 'rows' => [['ACME Estates — FFC 123 — letterhead blurb']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Body clause the agent keeps.']]],
            ['type' => 'signature_section', 'preamble' => 'Signed at ...', 'parties' => [['role' => 'agent', 'label' => 'Agent']]],
        ]];
        $html = (new CdsRendererService())->render($cds);

        $this->assertStringNotContainsString('ACME Estates', $html, 'source letterhead must be stripped');
        $this->assertStringNotContainsString('THUS DONE AND SIGNED', $html, 'source signature block must be stripped');
        $this->assertStringContainsString('Body clause the agent keeps.', $html, 'body content must survive');
    }

    public function test_render_keeps_mid_document_signature_placeholder(): void
    {
        // A D4 acknowledgement placeholder lives inside a paragraph, not the end frame — it stays.
        $cds = ['sections' => [
            ['type' => 'paragraph', 'content' => [[
                'type' => 'signature_placeholder', 'marker' => 'signature',
                'suggested_parties' => [['role' => 'seller', 'label' => 'Seller'], ['role' => 'agent', 'label' => 'Agent']],
                'suggested_variant' => 'sig_only',
            ]]],
        ]];
        $html = (new CdsRendererService())->render($cds);
        $this->assertStringContainsString('data-sig-parties="Seller,Agent"', $html);
    }

    // ---- R2: commission tokenisation -------------------------------------------------------

    public function test_commission_percentage_is_tokenised(): void
    {
        $sections = [['type' => 'clause', 'content' => [[
            'type' => 'text',
            'value' => 'The Seller shall pay the Agency a Professional Fee of 7.5% per centum, plus VAT, of the price.',
        ]]]];
        $out = $this->commission($sections);

        $this->assertTrue($this->hasCommissionField($out), 'commission % must be tokenised');

        // The "% per centum, plus VAT" wording is preserved; only the number becomes a field.
        $text = '';
        foreach ($out[0]['content'] as $c) {
            $text .= $c['type'] === 'text' ? $c['value'] : '<F>';
        }
        $this->assertStringContainsString('Professional Fee of <F>% per centum', $text);
    }

    public function test_commission_guard_ignores_unrelated_percentage(): void
    {
        $sections = [['type' => 'clause', 'content' => [[
            'type' => 'text', 'value' => 'The purchase price attracts VAT at 15% where applicable.',
        ]]]];
        $this->assertFalse($this->hasCommissionField($this->commission($sections)), 'VAT % must NOT be tokenised');
    }

    public function test_commission_tokenises_only_the_first_occurrence(): void
    {
        $sections = [
            ['type' => 'clause', 'content' => [['type' => 'text', 'value' => 'Professional Fee of 7.5% per centum.']]],
            ['type' => 'clause', 'content' => [['type' => 'text', 'value' => 'A further commission of 2% may apply.']]],
        ];
        $out = $this->commission($sections);
        $count = 0;
        foreach ($out as $s) {
            foreach ($s['content'] ?? [] as $it) {
                if (($it['block_id'] ?? '') === 'document_commission_percentage') {
                    $count++;
                }
            }
        }
        $this->assertSame(1, $count, 'a mandate states its fee once — only the first is tokenised');
    }
}
