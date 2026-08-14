<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use PHPUnit\Framework\TestCase;

/**
 * AT-177 D4 — the DB-free half of the import/cds convergence: the literal
 * "______ / Signature" acknowledgement lines a mandate carries between clauses must become
 * a shared Seller+Agent sig_only placeholder, and the guard must NOT fire on ordinary
 * underscored blanks. (The DB-dependent binding half is covered by
 * Tests\Feature\Docuperfect\CdsImportBindingConvergenceTest and proven against template #70.)
 */
class CdsUnderscoreSignatureDetectionTest extends TestCase
{
    private function detect(array $sections): array
    {
        $svc = new CdsParserService();
        $m = new \ReflectionMethod($svc, 'detectUnderscoreSignatureLines');
        $m->setAccessible(true);
        return $m->invoke($svc, $sections);
    }

    private function placeholders(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            foreach ($s['content'] ?? [] as $it) {
                if (($it['type'] ?? '') === 'signature_placeholder') {
                    $out[] = $it;
                }
            }
        }
        return $out;
    }

    private function ph(string $blockId): array
    {
        return ['type' => 'insertable_block_placeholder', 'purpose' => 'custom_named', 'block_id' => $blockId, 'raw_token' => $blockId, 'custom_label' => $blockId];
    }

    public function test_underscore_signature_pair_becomes_shared_sig_only(): void
    {
        $sections = [
            ['type' => 'paragraph', 'content' => [$this->ph('seller_physical_address')]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => '______________________']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Signature']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'More clause content follows here.']]],
        ];
        $out = $this->detect($sections);
        $ph = $this->placeholders($out);

        $this->assertCount(1, $ph);
        $this->assertSame('sig_only', $ph[0]['suggested_variant']);
        $labels = array_map(fn ($p) => $p['label'], $ph[0]['suggested_parties']);
        $this->assertEqualsCanonicalizing(['Seller', 'Agent'], $labels);

        // No raw "Signature" label paragraph should remain.
        $remaining = 0;
        foreach ($out as $s) {
            $txt = strtolower(trim(implode('', array_map(fn ($c) => $c['value'] ?? '', $s['content'] ?? []))));
            if ($txt === 'signature') {
                $remaining++;
            }
        }
        $this->assertSame(0, $remaining);
    }

    public function test_renderer_surfaces_roster_and_variant_for_builder(): void
    {
        $sections = [
            ['type' => 'paragraph', 'content' => [$this->ph('seller_physical_address')]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => '____________']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Signature']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'trailing clause content here']]],
        ];
        $out = $this->detect($sections);
        $html = (new CdsRendererService())->render(['sections' => $out]);

        $this->assertStringContainsString('data-sig-parties="Seller,Agent"', $html);
        $this->assertStringContainsString('data-sig-variant="sig_only"', $html);
    }

    public function test_guard_leaves_ordinary_underscore_blank_alone(): void
    {
        $sections = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => '______________________']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Full name of witness']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'trailing content']]],
        ];
        $this->assertCount(0, $this->placeholders($this->detect($sections)));
    }

    public function test_guard_leaves_text_with_inline_underscore_alone(): void
    {
        // A sentence that merely contains an underscore run is not a pure sig line.
        $sections = [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Received the sum of R______ from the buyer.']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'Signature']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => 'trailing']]],
        ];
        $this->assertCount(0, $this->placeholders($this->detect($sections)));
    }
}
