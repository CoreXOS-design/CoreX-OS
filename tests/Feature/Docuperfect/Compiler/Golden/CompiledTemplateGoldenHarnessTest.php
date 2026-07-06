<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Golden;

use App\Services\Docuperfect\Compiler\Golden\CombinationCatalog;
use App\Services\Docuperfect\Compiler\Golden\CompiledTemplateGoldenHarness;
use App\Services\Docuperfect\Compiler\Golden\GoldenFinding;
use App\Services\Docuperfect\Compiler\Support\CallbackGoldenRenderProbe;
use App\Support\Docuperfect\Cds\Cds;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsCompiledCds;

/**
 * E-Sign Document Compiler — WS3 (Golden test harness + CI gate).
 *
 * Pure PHPUnit (no DB) — the harness, linter and CDS DTO are framework-free, so these sidestep
 * the known-failing test-DB baseline. The real Eloquent `DataDictionaryResolver` plugs into the
 * same interface the InMemory double satisfies (WS0↔WS1 Eloquent integration already proven by
 * cc2); these tests own the harness LOGIC.
 *
 * They pin §7/§10-WS3: fixtures auto-generated per party-combination FROM the CDS; the CI gate
 * blocks certification when any combination fails; the render tier is honest-PENDING (never a
 * silent green) until WS2's probe lands, and green when a faithful probe is wired.
 */
final class CompiledTemplateGoldenHarnessTest extends TestCase
{
    use BuildsCompiledCds;

    private function harness(): CompiledTemplateGoldenHarness
    {
        return new CompiledTemplateGoldenHarness();
    }

    public function test_catalog_derives_named_combinations_from_the_mandate_cds(): void
    {
        $enum = (new CombinationCatalog())->for($this->mandateCdsDto());

        // seller ∈ {1,2} × mandate_type ∈ {sole, open} = 4 named combinations.
        $this->assertCount(4, $enum['combinations']);
        $labels = array_map(fn ($c) => $c->label, $enum['combinations']);

        $joined = implode(' || ', $labels);
        $this->assertStringContainsString('seller×1', $joined);
        $this->assertStringContainsString('seller×2', $joined);
        $this->assertStringContainsString('fld_mandate_type=sole', $joined);
        $this->assertStringContainsString('fld_mandate_type=open', $joined);
    }

    public function test_base_cds_structurally_certifies_but_render_tier_is_pending_without_a_probe(): void
    {
        $report = $this->harness()->certify($this->validCdsDto(), $this->validDictionary());

        $this->assertTrue($report->structurallyCertified(), 'Structural tier should be clean. Blocking: ' . json_encode(array_map(fn (GoldenFinding $f) => $f->code, $report->blocking())));
        $this->assertTrue($report->renderPending(), 'Render tier must be PENDING without a WS2 probe.');
        $this->assertFalse($report->certifiable(), 'A template with unproven render parity must NOT be certifiable.');
    }

    public function test_base_cds_is_fully_certifiable_with_a_faithful_probe(): void
    {
        $report = $this->harness()->certify($this->validCdsDto(), $this->validDictionary(), CallbackGoldenRenderProbe::faithful());

        $this->assertTrue($report->certifiable(), 'Blocking: ' . json_encode(array_map(fn (GoldenFinding $f) => $f->combinationLabel . '/' . $f->code, $report->blocking())));
        $this->assertFalse($report->renderPending());
    }

    public function test_mandate_cds_certifies_across_all_four_combinations(): void
    {
        $report = $this->harness()->certify($this->mandateCdsDto(), $this->validDictionary(), CallbackGoldenRenderProbe::faithful());

        $this->assertSame(4, $report->combinationCount());
        $this->assertTrue($report->certifiable(), 'Blocking: ' . json_encode(array_map(fn (GoldenFinding $f) => $f->combinationLabel . '/' . $f->code, $report->blocking())));
    }

    public function test_lease_lessor_variant_certifies(): void
    {
        $report = $this->harness()->certify($this->leaseCdsDto(), $this->validDictionary(), CallbackGoldenRenderProbe::faithful());

        $this->assertTrue($report->certifiable(), 'Blocking: ' . json_encode(array_map(fn (GoldenFinding $f) => $f->combinationLabel . '/' . $f->code, $report->blocking())));
    }

    public function test_harness_catches_a_per_combination_anchor_bug_the_whole_template_gate_misses(): void
    {
        // Move the seller's signature anchor into a SOLE-only block. Whole-template L3 still
        // passes (the seller HAS an anchor somewhere), but in the OPEN combination that block
        // never renders → the seller has nowhere to sign. Only the golden harness catches this.
        $arr = $this->mandateCdsDto()->toArray();
        foreach ($arr['blocks'] as &$block) {
            if ($block['block_id'] === 'blk_sign') {
                $block['anchors'] = array_values(array_filter($block['anchors'], fn ($a) => $a['party_key'] !== 'seller'));
            }
        }
        unset($block);
        $arr['blocks'][] = [
            'block_id' => 'blk_seller_sign_sole',
            'type' => 'signature',
            'condition' => ['kind' => 'field_equals', 'field_id' => 'fld_mandate_type', 'value' => 'sole'],
            'anchors' => [['anchor_id' => 'anc_seller', 'kind' => 'signature', 'party_key' => 'seller']],
        ];

        $report = $this->harness()->certify(Cds::fromArray($arr), $this->validDictionary(), CallbackGoldenRenderProbe::faithful());

        $this->assertFalse($report->certifiable());
        $this->assertFalse($report->structurallyCertified(), 'The per-combination anchor gap must fail the structural tier.');

        // The failure is pinned to the OPEN combinations, addressing the seller.
        $offenders = array_filter($report->blocking(), fn (GoldenFinding $f) => $f->code === 'party_without_rendered_anchor');
        $this->assertNotEmpty($offenders);
        foreach ($offenders as $f) {
            $this->assertStringContainsString('open', $f->combinationLabel);
            $this->assertStringStartsWith('seller', $f->target); // the present instance, e.g. seller_1 / seller_2
        }
    }

    public function test_render_tier_fails_when_probe_drops_a_bound_field(): void
    {
        $report = $this->harness()->certify(
            $this->validCdsDto(),
            $this->validDictionary(),
            CallbackGoldenRenderProbe::dropsField('fld_seller_id'),
        );

        $this->assertFalse($report->certifiable());
        $this->assertTrue($report->structurallyCertified(), 'Structural tier is fine; only the render tier fails.');
        $codes = array_map(fn (GoldenFinding $f) => $f->code, $report->blocking());
        $this->assertContains('field_not_rendered', $codes);
    }

    public function test_render_tier_fails_on_web_pdf_parity_mismatch(): void
    {
        $report = $this->harness()->certify(
            $this->validCdsDto(),
            $this->validDictionary(),
            CallbackGoldenRenderProbe::parityBroken(),
        );

        $this->assertFalse($report->certifiable());
        $codes = array_map(fn (GoldenFinding $f) => $f->code, $report->blocking());
        $this->assertContains('web_pdf_parity_mismatch', $codes);
    }

    public function test_report_serializes_for_the_lint_report_column(): void
    {
        $array = $this->harness()->certify($this->validCdsDto(), $this->validDictionary(), CallbackGoldenRenderProbe::faithful())->toArray();

        $this->assertArrayHasKey('certifiable', $array);
        $this->assertArrayHasKey('combinations', $array);
        $this->assertNotEmpty($array['combinations']);
    }
}
