<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Compiler\Reference;

use App\Services\Docuperfect\Compiler\Reference\ReferenceProof;
use App\Services\Docuperfect\Compiler\Reference\ReferenceProofRunner;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use PHPUnit\Framework\TestCase;

/**
 * E-Sign Document Compiler — WS5 (reference proofs).
 *
 * The full-chain proof for the campaign's reference pack — 117 & 119 (cc2's hand-compiled
 * zero-field pair) and 116 (this lane's field-bearing hand-compile). Pure PHPUnit: it runs
 * the LIVE linter (incl. L6 render-parity via cc2's CdsRenderParityVerifier + legal-class L7),
 * the LIVE golden harness (render tier via cc2's CdsGoldenRenderProbe), and the side-by-side
 * truth test — no DB, so it sidesteps the known-failing baseline. Persisting these as
 * published `compiled_templates` versions is the separate DB-backed gate.
 */
final class ReferencePackProofTest extends TestCase
{
    /**
     * The dictionary the reference pack binds against: the WS0-seeded standard entries 116 uses
     * PLUS the six WS5-surfaced standard entries (suburb/district/cell/email/transaction/comm%)
     * that the seeded dictionary did not yet carry (added to the real seed in Gate B).
     */
    private function dictionary(): InMemoryDataDictionaryResolver
    {
        $t = fn (string $cat, string $type = 'text') => ['category' => $cat, 'type' => $type, 'validation' => []];

        return InMemoryDataDictionaryResolver::atVersion(1, [
            'erf_number' => $t('property', 'erf_number'),
            'property_address' => $t('property'),
            'suburb' => $t('property'),
            'district' => $t('property'),
            'seller_full_name' => $t('party', 'full_name'),
            'seller_id_number' => $t('identity', 'sa_id'),
            'contact_cell' => $t('party'),
            'contact_email' => $t('party'),
            'marketing_transaction_type' => $t('other'),
            'purchase_price' => $t('money', 'zar_money'),
            'commission_pct' => $t('money'),
        ]);
    }

    private function runner(): ReferenceProofRunner
    {
        return new ReferenceProofRunner();
    }

    public function test_117_mandatory_disclosure_is_fully_proven(): void
    {
        $proof = $this->runner()->run(
            ReferencePackCds::template117(),
            $this->dictionary(),
            ['IMMOVABLE PROPERTY CONDITION REPORT', 'Disclaimer', 'THUS DONE AND SIGNED', 'Buyer'],
        );

        $this->assertProven($proof, ['agent', 'buyer', 'seller']);
        $this->assertSame('general', $proof->legalClass);
    }

    public function test_119_addendum_b_is_fully_proven(): void
    {
        $proof = $this->runner()->run(
            ReferencePackCds::template119(),
            $this->dictionary(),
            ['ADDENDUM B', 'EXTRA INFORMATION', 'Electrical Compliance Certificate', 'THUS DONE AND SIGNED'],
        );

        $this->assertProven($proof, ['agent', 'seller']);
    }

    public function test_116_marketing_permission_is_fully_proven_and_esign_lawful(): void
    {
        $proof = $this->runner()->run(
            ReferencePackCds::template116(),
            $this->dictionary(),
            ['MARKETING PERMISSION', 'OPEN MARKETING PERMISSION', 'effective cause', 'Fidelity Fund Certificate'],
        );

        $this->assertProven($proof, ['agent', 'owner_party']);

        // L7 outcome: a marketing mandate is NOT an alienation-of-land instrument, so e-sign is
        // lawful — the gate must PASS (not block) with web_esign enabled.
        $this->assertSame('general', $proof->legalClass);
        $this->assertFalse($proof->lint->ruleFailed('L7'), 'Marketing Permission must lint e-sign-lawful.');
    }

    public function test_116_binds_all_fifteen_fill_points(): void
    {
        // Every one of the 15 blade `data-field` fill-points is a bound Field in the CDS.
        $structure = ReferencePackCds::template116();
        $fieldIds = [];
        foreach ($structure['blocks'] as $block) {
            foreach (($block['fields'] ?? []) as $field) {
                $fieldIds[] = $field['field_id'];
                $this->assertNotSame('', trim((string) $field['binding']), "Field {$field['field_id']} must be bound.");
            }
        }

        $this->assertCount(15, $fieldIds);
    }

    /**
     * @param string[] $expectedSigners role-bases expected to sign (sorted-compared)
     */
    private function assertProven(ReferenceProof $proof, array $expectedSigners): void
    {
        $this->assertTrue($proof->lint->publishable(), sprintf('[%s] lint not publishable: %s', $proof->family, json_encode($proof->lint->failedRules())));
        $this->assertTrue($proof->golden->certifiable(), sprintf('[%s] golden not certifiable: %s', $proof->family, json_encode(array_map(fn ($f) => $f->combinationLabel . '/' . $f->code, $proof->golden->blocking()))));
        $this->assertTrue($proof->sideBySide->signersMatch(), sprintf('[%s] signer topology mismatch: declared=%s rendered=%s', $proof->family, json_encode($proof->sideBySide->declaredSigners), json_encode($proof->sideBySide->renderedSigners)));
        $this->assertSame([], $proof->sideBySide->missingPhrases, sprintf('[%s] compiled render dropped content: %s', $proof->family, json_encode($proof->sideBySide->missingPhrases)));
        $this->assertTrue($proof->proven(), "[{$proof->family}] not fully proven.");

        $rendered = $proof->sideBySide->renderedSigners;
        sort($rendered);
        $this->assertSame($expectedSigners, $rendered);
    }
}
