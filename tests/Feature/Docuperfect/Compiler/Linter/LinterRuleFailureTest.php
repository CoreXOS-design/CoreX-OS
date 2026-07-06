<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LintReport;
use App\Services\Docuperfect\Compiler\Support\CallbackRenderParityVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsCompiledCds;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * The WS1 gate proof against the canonical WS0 CDS: start from a structure that passes ALL
 * rules, inject exactly ONE defect into its stored-JSON form, and assert the linter
 * (a) blocks publish, (b) fails on the EXACT rule, (c) addresses the EXACT block/target,
 * and (d) does not misfire any OTHER rule. A render-parity verifier that always matches is
 * supplied so L6 passes and rule isolation is meaningful.
 */
final class LinterRuleFailureTest extends TestCase
{
    use BuildsCompiledCds;

    private function lint(array $cds, $verifier = 'match'): LintReport
    {
        $v = $verifier === 'match' ? CallbackRenderParityVerifier::alwaysMatches() : $verifier;

        return (new CompiledTemplateLinter())->lint($cds, $this->validDictionary(), $v);
    }

    private function assertOnlyRuleFails(LintReport $report, string $rule, string $code, ?string $target = null): void
    {
        $this->assertFalse($report->publishable(), 'Expected the injected defect to block publish.');
        $this->assertSame([$rule], $report->failedRules(), 'Exactly one rule should fail. Got: ' . json_encode(array_map(fn (LintFinding $f) => $f->rule . ':' . $f->code, $report->blocking())));

        $codes = array_map(fn (LintFinding $f) => $f->code, $report->findingsForRule($rule));
        $this->assertContains($code, $codes, "Rule {$rule} should report code {$code}. Got: " . json_encode($codes));

        if ($target !== null) {
            $hit = array_filter($report->findingsForRule($rule), fn (LintFinding $f) => $f->code === $code && $f->target === $target);
            $this->assertNotEmpty($hit, "Rule {$rule} finding {$code} should address target '{$target}'.");
        }
    }

    public function test_l1_unbound_field(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['fields'][1]['binding'] = '';

        $this->assertOnlyRuleFails($this->lint($cds), 'L1', 'field_unbound', 'blk_parties');
    }

    public function test_l2_binding_does_not_resolve(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['fields'][1]['binding'] = 'ghost_entry_not_in_dictionary';

        $this->assertOnlyRuleFails($this->lint($cds), 'L2', 'binding_unresolved', 'blk_parties');
    }

    public function test_l2_duplicate_field_id(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['fields'][1]['field_id'] = 'fld_seller_name';

        $this->assertOnlyRuleFails($this->lint($cds), 'L2', 'duplicate_field_id', 'blk_parties');
    }

    public function test_l3_signing_party_without_anchor(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_sign');
        $cds['blocks'][$i]['anchors'] = array_values(array_filter(
            $cds['blocks'][$i]['anchors'],
            fn ($a) => $a['party_key'] !== 'agent'
        ));

        $this->assertOnlyRuleFails($this->lint($cds), 'L3', 'party_without_anchor', 'agent');
    }

    public function test_l3_anchor_references_undeclared_party(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_sign');
        $cds['blocks'][$i]['anchors'][2]['party_key'] = 'ghost_9';

        $report = $this->lint($cds);
        $this->assertSame(['L3'], $report->failedRules());
        $codes = array_map(fn (LintFinding $f) => $f->code, $report->findingsForRule('L3'));
        $this->assertContains('anchor_orphan_party', $codes);
    }

    public function test_l4_dangling_block_party_absent_of_required_party(): void
    {
        // A required party is always present → "party_absent: seller" can never be true.
        $cds = $this->validCdsArray();
        $cds['blocks'][] = [
            'block_id' => 'blk_impossible',
            'type' => 'prose',
            'condition' => ['kind' => 'party_absent', 'party_key' => 'seller'],
        ];

        $this->assertOnlyRuleFails($this->lint($cds), 'L4', 'dangling_block', 'blk_impossible');
    }

    public function test_l4_condition_references_undeclared_party(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_witness_clause');
        $cds['blocks'][$i]['condition'] = ['kind' => 'party_present', 'party_key' => 'buyer_9'];

        $report = $this->lint($cds);
        $this->assertSame(['L4'], $report->failedRules());
        $codes = array_map(fn (LintFinding $f) => $f->code, $report->findingsForRule('L4'));
        $this->assertContains('condition_unknown_party', $codes);
    }

    public function test_l4_editability_exceeds_visibility(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['visibility'] = ['mode' => 'only', 'party_keys' => ['seller']];
        $cds['blocks'][$i]['editability'] = ['mode' => 'only', 'party_keys' => ['agent']];

        $this->assertOnlyRuleFails($this->lint($cds), 'L4', 'editability_exceeds_visibility', 'blk_parties');
    }

    public function test_l4_unreachable_required_field(): void
    {
        $cds = $this->validCdsArray();
        $cds['blocks'][] = [
            'block_id' => 'blk_hidden',
            'type' => 'field_group',
            'visibility' => ['mode' => 'none', 'party_keys' => []],
            'fields' => [[
                'field_id' => 'fld_hidden',
                'label' => 'Hidden Required',
                'binding' => 'seller_full_name',
                'source' => 'party_input',
                'required' => true,
            ]],
        ];

        $this->assertOnlyRuleFails($this->lint($cds), 'L4', 'unreachable_required_field', 'blk_hidden');
    }

    public function test_l5_validation_loosened(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        // Entry caps sa_id at 13; the override tries to allow 50 — a loosening.
        $cds['blocks'][$i]['fields'][1]['validation_override'] = ['max_length' => 50];

        $this->assertOnlyRuleFails($this->lint($cds), 'L5', 'validation_loosened', 'blk_parties');
    }

    public function test_l5_validation_type_conflict(): void
    {
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['fields'][1]['validation_override'] = ['type' => 'text'];

        $this->assertOnlyRuleFails($this->lint($cds), 'L5', 'validation_type_conflict', 'blk_parties');
    }

    public function test_l6_render_parity_mismatch(): void
    {
        $cds = $this->validCdsArray();
        // Force a mismatch on the all-required (witness absent) combination.
        $verifier = CallbackRenderParityVerifier::mismatchOn(['seller', 'agent'], 'seller anchor shifts on PDF');

        $report = $this->lint($cds, $verifier);

        $this->assertSame(['L6'], $report->failedRules());
        $codes = array_map(fn (LintFinding $f) => $f->code, $report->findingsForRule('L6'));
        $this->assertContains('render_parity_mismatch', $codes);
    }

    public function test_l7_esign_forbidden_for_alienation_of_land(): void
    {
        $cds = $this->validCdsArray();
        $cds['legal_class'] = 'alienation_of_land'; // OTP / sale of land — wet-ink only

        $this->assertOnlyRuleFails($this->lint($cds), 'L7', 'esign_forbidden_for_legal_class');
    }

    public function test_l7_alienation_of_land_without_esign_is_coherent(): void
    {
        $cds = $this->validCdsArray();
        $cds['legal_class'] = 'alienation_of_land';
        $cds['delivery_modes'] = ['pdf_wetink', 'download']; // wet-ink / download only — lawful

        $report = $this->lint($cds);
        $this->assertFalse($report->ruleFailed('L7'));
    }
}
