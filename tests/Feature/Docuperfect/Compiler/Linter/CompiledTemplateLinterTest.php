<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintReport;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\LintSeverity;
use App\Services\Docuperfect\Compiler\Support\CallbackRenderParityVerifier;
use App\Support\Docuperfect\Cds\Cds;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsCompiledCds;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * The linter is a PURE function over the canonical WS0 {@see Cds} DTO (no DB), so these are
 * pure PHPUnit tests — they never touch the schema and therefore sidestep the known-failing
 * test-DB baseline entirely. They pin the engine contract against the REAL canonical
 * structure: a good CDS is publishable through BOTH entrypoints (DTO and stored-JSON array);
 * every rule reports it ran; L6 is honestly PENDING without a renderer; a malformed
 * structure is absorbed into a blocking report rather than crashing the gate.
 */
final class CompiledTemplateLinterTest extends TestCase
{
    use BuildsCompiledCds;

    private function linter(): CompiledTemplateLinter
    {
        return new CompiledTemplateLinter();
    }

    public function test_valid_cds_dto_passes_all_rules_and_is_publishable(): void
    {
        $report = $this->linter()->lint(
            $this->validCdsDto(),
            $this->validDictionary(),
            CallbackRenderParityVerifier::alwaysMatches(),
        );

        $this->assertTrue($report->publishable(), 'Valid CDS should be publishable. Blocking: ' . json_encode(array_map(fn ($f) => $f->code, $report->blocking())));
        $this->assertSame([], $report->failedRules());
    }

    public function test_valid_cds_stored_array_form_also_passes(): void
    {
        // The compiled_templates.structure JSON round-trips through Cds::fromArray() and lints clean.
        $report = $this->linter()->lint(
            $this->validCdsArray(),
            $this->validDictionary(),
            CallbackRenderParityVerifier::alwaysMatches(),
        );

        $this->assertTrue($report->publishable(), 'Blocking: ' . json_encode(array_map(fn ($f) => $f->code, $report->blocking())));
    }

    public function test_every_rule_reports_it_ran(): void
    {
        $report = $this->linter()->lint($this->validCdsDto(), $this->validDictionary(), CallbackRenderParityVerifier::alwaysMatches());

        foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'L6', 'L7'] as $rule) {
            $this->assertNotEmpty($report->findingsForRule($rule), "Rule {$rule} produced no finding — coverage gap.");
        }
    }

    public function test_l6_is_pending_not_passing_without_a_verifier(): void
    {
        $report = $this->linter()->lint($this->validCdsDto(), $this->validDictionary());

        $this->assertFalse($report->publishable(), 'A template with unproven web↔PDF parity must NOT be publishable.');
        $this->assertSame([], $report->errors(), 'The only blocker should be the PENDING L6, not an error.');
        $this->assertCount(1, $report->pending());
        $this->assertSame('render_parity_unverified', $report->pending()[0]->code);
        $this->assertSame(['L6'], $report->failedRules());
    }

    public function test_engine_absorbs_a_throwing_rule_instead_of_crashing(): void
    {
        $exploding = new class implements LintRule {
            public function code(): string
            {
                return 'LX';
            }

            public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $report = (new CompiledTemplateLinter([$exploding]))->lint($this->validCdsDto(), $this->validDictionary());

        $this->assertFalse($report->publishable());
        $this->assertTrue($report->ruleFailed('LX'));
        $this->assertSame('rule_threw', $report->findingsForRule('LX')[0]->code);
    }

    public function test_unhydratable_structure_is_absorbed_never_fatal(): void
    {
        // An invalid block type cannot hydrate into the canonical DTO — must yield a
        // blocking finding, not a 500.
        $report = $this->linter()->lint(
            ['family' => 'x', 'blocks' => [['block_id' => 'b1', 'type' => 'not_a_real_type']]],
            $this->validDictionary(),
        );

        $this->assertInstanceOf(LintReport::class, $report);
        $this->assertFalse($report->publishable());
        $this->assertSame('structure_malformed', $report->findings()[0]->code);
    }

    public function test_report_serializes_to_array(): void
    {
        $array = $this->linter()->lint($this->validCdsDto(), $this->validDictionary())->toArray();

        $this->assertArrayHasKey('publishable', $array);
        $this->assertArrayHasKey('failed_rules', $array);
        $this->assertIsArray($array['findings']);
    }

    public function test_pass_findings_do_not_block_publish(): void
    {
        $report = $this->linter()->lint($this->validCdsDto(), $this->validDictionary(), CallbackRenderParityVerifier::alwaysMatches());

        $passFindings = array_filter($report->findings(), fn (LintFinding $f) => $f->severity === LintSeverity::PASS);
        $this->assertNotEmpty($passFindings);
        foreach ($passFindings as $f) {
            $this->assertFalse($f->blocksPublish());
        }
    }

    public function test_seller_cardinality_one_to_many_passes(): void
    {
        // Joint sellers (1..n) must lint clean — the enumerator materialises representative
        // instance counts without exploding.
        $cds = $this->validCdsArray();
        $cds['parties'][0]['cardinality'] = 'one_or_more';

        $report = $this->linter()->lint($cds, $this->validDictionary(), CallbackRenderParityVerifier::alwaysMatches());

        $this->assertTrue($report->publishable(), 'A 1..n seller should lint clean. Blocking: ' . json_encode(array_map(fn ($f) => $f->code, $report->blocking())));
    }

    public function test_party_count_gte_condition_is_satisfiable_for_one_or_more(): void
    {
        // A block gated on "≥ 2 sellers" must NOT be flagged dangling when seller is 1..n.
        $cds = $this->validCdsArray();
        $cds['parties'][0]['cardinality'] = 'one_or_more';
        $cds['blocks'][] = [
            'block_id' => 'blk_joint',
            'type' => 'prose',
            'condition' => ['kind' => 'party_count_gte', 'party_key' => 'seller', 'value' => 2],
        ];

        $report = $this->linter()->lint($cds, $this->validDictionary(), CallbackRenderParityVerifier::alwaysMatches());

        $this->assertFalse($report->ruleFailed('L4'), 'count_gte(2) on a 1..n seller is satisfiable. Blocking: ' . json_encode(array_map(fn ($f) => $f->code . ':' . $f->target, $report->blocking())));
    }
}
