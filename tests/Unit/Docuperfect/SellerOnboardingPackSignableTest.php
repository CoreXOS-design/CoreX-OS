<?php

namespace Tests\Unit\Docuperfect;

use App\Services\Docuperfect\SignatureSurfaceNormalizer;
use Tests\TestCase;

/**
 * BL-1 regression guard (Group B).
 *
 * The pack model is MERGE: a web pack is rendered into ONE merged document
 * (one signing ceremony), then auto-filed into separate Documents on
 * completion. BL-1's "doc 2 unreachable" was really BL-5 — the second
 * template's signature blocks were unsignable in the merged HTML.
 *
 * This asserts every Seller Onboarding pack template, after the shared
 * SignatureSurfaceNormalizer pass, exposes >=1 surface matching the exact
 * selector the signing engine uses
 * ([data-marker-party][data-marker-type="signature"] —
 * sign.blade.php / external/sign.blade.php / embedSignaturesIntoHtml),
 * both standalone and inside the merged pack document.
 *
 * No DB: renders blades directly, so it is unaffected by the pre-existing
 * ccfa6cb SQLite MODIFY migration baseline.
 */
class SellerOnboardingPackSignableTest extends TestCase
{
    /** Seller Onboarding pack, in slot order (see SellerOnboardingPackSeeder). */
    private const PACK_BLADES = [
        'docuperfect.web-templates.marketing-permission-v6',
        'docuperfect.web-templates.sales-mandatory-disclosure',
    ];

    /**
     * Stub passed as $previewAgency so the shared company-header component
     * takes its documented preview bypass instead of querying the agencies
     * table — keeps this guard DB-free (the suite's migration baseline,
     * ccfa6cb, breaks RefreshDatabase on SQLite).
     */
    private function previewAgencyStub(): object
    {
        return new class {
            public function __get($k)
            {
                return '';
            }
        };
    }

    private function render(string $blade): string
    {
        return view($blade, ['previewAgency' => $this->previewAgencyStub()])->render();
    }

    private function countSignableSurfaces(string $html): int
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML(
            '<?xml encoding="utf-8"?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
        );
        $xpath = new \DOMXPath($dom);

        return $xpath->query('//*[@data-marker-party][@data-marker-type="signature"]')->length;
    }

    public function test_each_seller_onboarding_pack_template_has_a_signable_surface(): void
    {
        foreach (self::PACK_BLADES as $blade) {
            $html = $this->render($blade);
            $normalised = SignatureSurfaceNormalizer::normalize($html);

            $this->assertGreaterThanOrEqual(
                1,
                $this->countSignableSurfaces($normalised),
                "Pack template [{$blade}] has no signable "
                . '[data-marker-party][data-marker-type="signature"] surface '
                . 'after normalisation — it would be unsignable in the pack (BL-1/BL-5).'
            );
        }
    }

    public function test_merged_pack_document_keeps_a_signable_surface_per_template(): void
    {
        $merged = '';
        foreach (self::PACK_BLADES as $idx => $blade) {
            $body = SignatureSurfaceNormalizer::normalize($this->render($blade));
            $pageBreak = $idx < count(self::PACK_BLADES) - 1
                ? '<div style="page-break-after:always;"></div>'
                : '';
            $merged .= $body . $pageBreak;
        }

        $this->assertGreaterThanOrEqual(
            count(self::PACK_BLADES),
            $this->countSignableSurfaces($merged),
            'Merged Seller Onboarding pack document lost a signable surface — '
            . 'each pack template must remain signable in the single merged '
            . 'ceremony (BL-1 MERGE model).'
        );
    }
}
