<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Support\CallbackRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsCompiledCds;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Input-space coverage (BUILD_STANDARD §5): the lazy-but-valid shortcuts and the
 * genuinely-different branches the per-rule failure suite doesn't exercise — an
 * initial-kind signing surface, `except`-mode visibility, a data-gated conditional whose
 * required field is still reachable, an advisory (non-blocking) L5 warning, and the
 * optional-party-absent path. These are the "each optional path / each edge" cases, all
 * against the canonical WS0 CDS.
 */
final class LinterInputSpaceTest extends TestCase
{
    use BuildsCompiledCds;

    private function match(): CallbackRenderParityVerifier
    {
        return CallbackRenderParityVerifier::alwaysMatches();
    }

    public function test_l3_initial_anchor_counts_as_a_signing_surface(): void
    {
        // AnchorKind::Initial::isSigningSurface() is true — a party who only initials still
        // has "a place to sign" per the canonical model.
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_sign');
        $cds['blocks'][$i]['anchors'][1]['kind'] = 'initial'; // agent initials instead of signs

        $report = (new CompiledTemplateLinter())->lint($cds, $this->validDictionary(), $this->match());

        $this->assertFalse($report->ruleFailed('L3'), 'An initial anchor should satisfy L3. Blocking: ' . json_encode(array_map(fn (LintFinding $f) => $f->code . ':' . $f->target, $report->blocking())));
    }

    public function test_l4_except_mode_visibility_keeps_required_field_reachable(): void
    {
        // Visible to everyone EXCEPT the witness — seller/agent still see the required fields.
        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['visibility'] = ['mode' => 'except', 'party_keys' => ['witness']];

        $report = (new CompiledTemplateLinter())->lint($cds, $this->validDictionary(), $this->match());

        $this->assertTrue($report->publishable(), 'except-mode visibility should keep required fields reachable. Blocking: ' . json_encode(array_map(fn (LintFinding $f) => $f->code, $report->blocking())));
    }

    public function test_l4_data_gated_block_with_required_field_is_reachable(): void
    {
        // A block gated on a data predicate (field_truthy) is treated as renderable — some
        // data makes it true — so its required field is reachable, not stranded.
        $cds = $this->validCdsArray();
        $cds['blocks'][] = [
            'block_id' => 'blk_bond',
            'type' => 'field_group',
            'condition' => ['kind' => 'field_truthy', 'field_id' => 'fld_seller_name'],
            'fields' => [[
                'field_id' => 'fld_bond_amount',
                'label' => 'Bond Amount',
                'binding' => 'seller_full_name',
                'source' => 'party_input',
                'required' => true,
            ]],
        ];

        $report = (new CompiledTemplateLinter())->lint($cds, $this->validDictionary(), $this->match());

        $this->assertFalse($report->ruleFailed('L4'), 'A data-gated block is reachable. Blocking: ' . json_encode(array_map(fn (LintFinding $f) => $f->code . ':' . $f->target, $report->blocking())));
    }

    public function test_l5_regex_change_is_advisory_and_does_not_block_publish(): void
    {
        // A changed regex cannot be statically proven a subset → WARNING, not a blocker.
        $dict = InMemoryDataDictionaryResolver::atVersion(1, [
            'seller_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => true, 'max_length' => 120]],
            'seller_id_number' => ['category' => 'identity', 'type' => 'sa_id', 'validation' => ['required' => true, 'regex' => '^\d{13}$']],
            'witness_full_name' => ['category' => 'party', 'type' => 'string', 'validation' => ['required' => false]],
        ]);

        $cds = $this->validCdsArray();
        $i = $this->blockIndex($cds, 'blk_parties');
        $cds['blocks'][$i]['fields'][1]['validation_override'] = ['regex' => '^\d{13}[A-Z]?$'];

        $report = (new CompiledTemplateLinter())->lint($cds, $dict, $this->match());

        $this->assertTrue($report->publishable(), 'A regex change is advisory and must not block publish.');
        $codes = array_map(fn (LintFinding $f) => $f->code, $report->findingsForRule('L5'));
        $this->assertContains('validation_regex_changed', $codes);
        $this->assertNotEmpty($report->warnings());
    }

    public function test_optional_witness_absent_path_lints_clean(): void
    {
        // Drop the optional witness entirely (and its clause + anchor): the lazy, valid
        // two-signer document must lint clean.
        $cds = $this->validCdsArray();
        $cds['parties'] = array_values(array_filter($cds['parties'], fn ($p) => $p['key'] !== 'witness'));
        $cds['blocks'] = array_values(array_filter($cds['blocks'], fn ($b) => $b['block_id'] !== 'blk_witness_clause'));
        $si = $this->blockIndex($cds, 'blk_sign');
        $cds['blocks'][$si]['anchors'] = array_values(array_filter(
            $cds['blocks'][$si]['anchors'],
            fn ($a) => $a['party_key'] !== 'witness'
        ));

        $report = (new CompiledTemplateLinter())->lint($cds, $this->validDictionary(), $this->match());

        $this->assertTrue($report->publishable(), 'A two-signer (no witness) document should lint clean. Blocking: ' . json_encode(array_map(fn (LintFinding $f) => $f->code, $report->blocking())));
    }
}
