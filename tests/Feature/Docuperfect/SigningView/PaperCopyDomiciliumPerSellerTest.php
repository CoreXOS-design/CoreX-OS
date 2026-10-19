<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HOTFIX 2026-10-19 (Johan, HFC live) — Open / Exclusive Authority to Sell with MORE THAN ONE
 * seller. The electronic route renders a domicilium (address / contact) block per seller; the
 * download-and-sign copy (prepareDownloadOnly) printed ONE block, because merged_html — what the
 * PDF prints — is the template output before any role-block looping. Covers
 * ESignWizardController::expandRoleBlocksForPaper(), the step-6-preview normalize+expand pair
 * now run before a download-only document's merged_html is stored.
 *
 * The HTML below is the exact seller-block shape stored in QA1 document 572 (esign flow 37).
 */
final class PaperCopyDomiciliumPerSellerTest extends TestCase
{
    private function rawMergedHtml(string $mandate): string
    {
        return '<div class="corex-h2">Seller</div>'
            . '<div class="corex-clause corex-clause-indent-1" data-role-block="seller" data-role-block-segment="address">'
            . '<span class="corex-clause-text">Physical address: <span class="corex-field-value" data-field="seller_address"></span></span></div>'
            . '<div class="corex-clause corex-clause-indent-1" data-role-block="seller" data-role-block-segment="contact">'
            . '<span class="corex-clause-text">Tel: <span class="corex-field-value" data-field="seller_phone">0825346546 and 0813230105</span> '
            . 'Email: <span class="corex-field-value" data-field="seller_email">a@example.test and b@example.test</span></span></div>'
            . '<div class="corex-clause">2. TERMS AND CONDITIONS OF THE ' . $mandate . '</div>';
    }

    /** @return list<array<string, mixed>> */
    private function sellers(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'role' => 'seller',
                'name' => "Seller{$i} Person",
                'email' => "seller{$i}@example.test",
                'cell' => '08200000' . $i . $i,
                'address' => "{$i} Domicilium Road",
            ];
        }

        return $rows;
    }

    private function expand(string $html, array $recipients): string
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'expandRoleBlocksForPaper');
        $m->setAccessible(true);

        return $m->invoke(
            app(ESignWizardController::class),
            new Template(),
            new Flow(),
            $html,
            $recipients,
            new User(),
            [],
        );
    }

    /** @return array<string, array{0:int,1:string}> */
    public static function sellerCountsAndMandates(): array
    {
        return [
            '2 sellers, exclusive' => [2, 'EXCLUSIVE AUTHORITY TO SELL'],
            '3 sellers, exclusive' => [3, 'EXCLUSIVE AUTHORITY TO SELL'],
            '2 sellers, open' => [2, 'OPEN AUTHORITY TO SELL'],
            '3 sellers, open' => [3, 'OPEN AUTHORITY TO SELL'],
        ];
    }

    /**
     * @dataProvider sellerCountsAndMandates
     */
    public function test_every_seller_gets_their_own_domicilium_block_on_the_paper_copy(int $n, string $mandate): void
    {
        $out = $this->expand($this->rawMergedHtml($mandate), $this->sellers($n));

        $this->assertSame($n, substr_count($out, 'data-field="seller_address'), "{$n} sellers must print {$n} address blocks");
        $this->assertSame($n, substr_count($out, 'data-field="seller_phone'), "{$n} sellers must print {$n} contact blocks");
        for ($i = 1; $i <= $n; $i++) {
            $this->assertStringContainsString("data-recipient-identity=\"seller_{$i}\"", $out, "seller {$i} must have a block");
            $this->assertStringContainsString("seller{$i}@example.test", $out, "seller {$i} must carry their own email");
        }
        $this->assertStringNotContainsString('0825346546 and 0813230105', $out, 'the joined "A and B" phone line must be gone');
        $this->assertStringContainsString($mandate, $out, 'the rest of the document is untouched');
    }

    public function test_a_single_seller_still_prints_exactly_one_block(): void
    {
        $out = $this->expand($this->rawMergedHtml('EXCLUSIVE AUTHORITY TO SELL'), $this->sellers(1));

        $this->assertSame(1, substr_count($out, 'data-field="seller_address'));
        $this->assertSame(1, substr_count($out, 'data-field="seller_phone'));
    }

    public function test_no_recipients_leaves_the_html_untouched(): void
    {
        $html = $this->rawMergedHtml('EXCLUSIVE AUTHORITY TO SELL');

        $this->assertSame($html, $this->expand($html, []));
    }
}
