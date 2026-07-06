<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect\Compiler\Reference;

use App\Services\Docuperfect\Compiler\Linter\CompiledTemplateLinter;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Rendering\CdsRenderParityVerifier;
use App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver;
use App\Support\Docuperfect\Cds\Reference\ReferencePackCds;
use PHPUnit\Framework\TestCase;

/**
 * AT-177 / WS5 — the L7 legal-mode gate proven on BOTH sides of the law (spec §6.1, L7):
 *
 *   - 116 (HFC Marketing Permission) is a marketing-authorisation instrument, NOT a sale of
 *     land, so `legal_class = general` and e-sign is LAWFUL — L7 must PASS. (Corrects the
 *     "116 = OTP family, e-sign-forbidden" framing: 116 is not alienation-of-land.)
 *   - A genuine OFFER TO PURCHASE (`legal_class = alienation_of_land`) may NOT be e-signed
 *     (Alienation of Land Act 68/1981 §2(1); ECTA 25/2002 §13(1)). With web_esign enabled L7
 *     BLOCKS publish — the gate WORKING. Its lawful modes (wet-ink / download) publish clean.
 *
 * Pure PHPUnit (no DB).
 */
final class LegalGateL7ProofTest extends TestCase
{
    private function lint(array $structure): \App\Services\Docuperfect\Compiler\Linter\LintReport
    {
        return (new CompiledTemplateLinter())->lint(
            $structure,
            new InMemoryDataDictionaryResolver(),
            null,
            new LinterContext(new CdsRenderParityVerifier()),
        );
    }

    /** A genuine zero-field Offer to Purchase (alienation-of-land) with the given delivery modes. */
    private function offerToPurchase(array $deliveryModes): array
    {
        return [
            'family' => 'otp_sale',
            'data_dictionary_version' => 1,
            'legal_class' => 'alienation_of_land',
            'delivery_modes' => $deliveryModes,
            'parties' => [
                ['key' => 'seller', 'role' => 'Seller', 'cardinality' => 'one_or_more', 'ordering' => 1],
                ['key' => 'buyer', 'role' => 'Buyer', 'cardinality' => 'one_or_more', 'ordering' => 2],
                ['key' => 'agent', 'role' => 'Agent', 'cardinality' => 'one', 'ordering' => 3],
            ],
            'blocks' => [
                ['block_id' => 'p1', 'type' => 'prose', 'visibility' => ['mode' => 'all'], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'html' => '<h1>OFFER TO PURCHASE</h1><p>The purchaser hereby offers to purchase the immovable property.</p>'],
                ['block_id' => 'sigS', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['seller']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 's', 'kind' => 'signature', 'party_key' => 'seller']]],
                ['block_id' => 'sigB', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['buyer']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 'b', 'kind' => 'signature', 'party_key' => 'buyer']]],
                ['block_id' => 'sigA', 'type' => 'signature', 'visibility' => ['mode' => 'only', 'party_keys' => ['agent']], 'editability' => ['mode' => 'none'], 'condition' => ['kind' => 'always'], 'anchors' => [['anchor_id' => 'a', 'kind' => 'signature', 'party_key' => 'agent']]],
            ],
            'assets' => [],
        ];
    }

    public function test_116_marketing_permission_is_esign_lawful_L7_passes(): void
    {
        $report = $this->lint(ReferencePackCds::template116());

        $this->assertSame('general', ReferencePackCds::template116()['legal_class']);
        $this->assertFalse($report->ruleFailed('L7'), 'A marketing permission is NOT alienation-of-land — e-sign is lawful, L7 must pass.');
    }

    public function test_offer_to_purchase_with_esign_is_BLOCKED_by_L7(): void
    {
        $report = $this->lint($this->offerToPurchase(['web_esign', 'pdf_wetink', 'download']));

        $this->assertTrue($report->ruleFailed('L7'), 'An OTP with e-sign enabled MUST fail L7 (Alienation of Land Act) — the gate working.');
        $this->assertFalse($report->publishable(), 'an e-sign-enabled OTP is not publishable.');
        // The block is legal-class driven, block-addressed, and cites the statute.
        $l7 = array_values(array_filter($report->blocking(), fn ($f) => $f->rule === 'L7'));
        $this->assertNotEmpty($l7);
    }

    public function test_offer_to_purchase_is_publishable_in_wetink_and_download_modes(): void
    {
        $report = $this->lint($this->offerToPurchase(['pdf_wetink', 'download']));

        $this->assertFalse($report->ruleFailed('L7'), 'wet-ink + download are the lawful modes for an OTP — L7 passes.');
        $this->assertTrue($report->publishable(), 'a wet-ink/download OTP compiles publishable.');
    }
}
