<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Services\Docuperfect\RoleBlockNormalizer;
use Tests\TestCase;

/**
 * AT-295 — agent fill&sign PRE-SEND duplicate seller block.
 *
 * Root cause: the wizard Step-5 preview (ESignWizardController::templatePages)
 * fed RAW blade HTML — which carries no data-role-block contract — into
 * expandWithLooping, so $hasContract=false and it took the LEGACY clustering
 * path where AT-291 ⑥'s same-party dedup never runs (the recipient ceremony,
 * fed contract-stamped merged_html, took the contract path and deduped). Fix:
 * normalize the preview HTML to the contract BEFORE expandWithLooping so BOTH
 * surfaces go through the one corrected renderer.
 *
 * This test pins the fix's mechanism: RoleBlockNormalizer stamps the
 * data-role-block contract onto raw seller HTML, so the preview now enters the
 * SAME contract path the NestedRoleBlockDuplicateTest already proves dedups.
 */
final class AgentPresendContractTest extends TestCase
{
    public function test_normalizer_stamps_role_block_contract_on_raw_preview_html(): void
    {
        // Raw web-template-shape HTML (as the wizard preview renders it): a
        // seller detail field with NO data-role-block. This is exactly the
        // input that previously fell to the legacy (non-deduped) path.
        $raw = '<div class="seller-block">'
             . '<p>Seller: <span data-contact-type="Seller" data-field="seller_first_name">Thandeka</span></p>'
             . '</div>';

        $this->assertStringNotContainsString('data-role-block', $raw, 'precondition: raw preview HTML has no contract');

        $normalized = app(RoleBlockNormalizer::class)->normalize($raw);

        // After normalize the contract is present → expandWithLooping now takes
        // the $hasContract=true branch (the deduped contract path), identical to
        // the recipient ceremony. Without this the preview duplicated the seller.
        $this->assertStringContainsString(
            'data-role-block',
            $normalized,
            'the wizard preview HTML must be contract-stamped so it takes the deduped contract path, not the legacy duplicating path',
        );
    }
}
