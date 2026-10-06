<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementCompany;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Public, logged-out CoreX OS platform legal + support pages.
 *
 * These are the platform-level (not per-agency) Privacy Policy, Data
 * Deletion and Support pages required by Meta/Facebook and Apple App Review.
 * Reviewer crawlers fetch them with no authentication, so they MUST stay
 * outside the auth + agency middleware. The per-agency, token-gated privacy
 * policy lives separately at /legal/privacy/{token} (PrivacyPolicyController).
 */
final class LegalController extends Controller
{
    /** Support / privacy contact address surfaced on both pages. */
    private const CONTACT_EMAIL = 'support@corexos.co.za';

    public function privacy()
    {
        return view('public.legal.privacy', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => '17 August 2026',
        ]);
    }

    public function terms()
    {
        return view('public.legal.terms', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => '18 August 2026',
        ]);
    }

    public function dataDeletion()
    {
        return view('public.legal.data-deletion', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => 'June 2026',
        ]);
    }

    public function support()
    {
        return view('public.legal.support', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => 'August 2026',
        ]);
    }

    // ── CoreX Subscription Agreement — Parts B, C, D (spec §11.14) ─────────

    /** The Parts that are published. Never Part A (the signed form), the cover or the debit order mandate. */
    private const PUBLISHED_PARTS = ['part_b' => 'Part B', 'part_c' => 'Part C', 'part_d' => 'Part D'];

    /** The one inline script on the page (the print button). Its hash — not a blanket 'unsafe-inline' — is what the CSP allows. */
    private const PRINT_JS = "document.addEventListener('DOMContentLoaded',function(){var b=document.querySelector('.printbtn');if(b){b.addEventListener('click',function(){window.print();});}});";

    /**
     * Locked-down policy for the public wording page: no script except the print button's exact hash, inline styles only (the page's own
     * CSS), images from this site or inline, nothing may be framed, no base tag, no form posts. Even if wording markup ever got past the
     * sanitiser, it could not run or phone home from this page.
     */
    private static function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; script-src 'sha256-" . base64_encode(hash('sha256', self::PRINT_JS, true)) . "'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    /** /legal — the current published version of Parts B, C and D. */
    public function agreementTerms(Request $request)
    {
        try {
            $current = AgreementContent::current();
            if (!$current) {
                try {
                    $current = app(AgreementContent::class)->ensureSeeded(); // first ever request on a fresh environment (locked — see ensureSeeded)
                } catch (\Throwable) {
                    abort(404);
                }
            }
        } catch (QueryException) {
            abort(503, 'These terms are being set up. Please try again shortly.'); // wording tables not migrated yet — a clean 503, never a stack trace
        }

        return $this->agreementPage($request, $current, $current);
    }

    /** /legal/v/{version} — any published version; the current one redirects (temporarily) to /legal. */
    public function agreementTermsVersion(Request $request, string $version)
    {
        try {
            $current = AgreementContent::current();
        } catch (QueryException) {
            abort(503, 'These terms are being set up. Please try again shortly.');
        }
        abort_unless($current, 404);
        $v = WordingVersion::published()->where('template_id', $current->template_id)->where('version', $version)->first();
        abort_unless($v, 404);
        if ($v->id === $current->id) {
            // 302, never 301: "current" changes the day a newer version is published, and a cached permanent redirect would send
            // everyone who ever followed this link to the newer text instead of the version they agreed to.
            return redirect()->route('public.agreement-terms')->header('Cache-Control', 'no-store');
        }

        return $this->agreementPage($request, $v, $current);
    }

    private function agreementPage(Request $request, WordingVersion $v, WordingVersion $current)
    {
        $published = WordingVersion::published()->where('template_id', $current->template_id)->orderByDesc('published_at')->orderByDesc('id')->get(['id', 'version', 'version_date', 'published_at']);
        $company = PlatformCompany::current();
        // Published versions never change, so the page only varies with: which version, which one is current, the letterhead and the
        // deployed page/sanitiser code. Revalidated on every visit (no shared-cache lifetime): this response also carries the visitor's
        // session cookies, so it must never be stored by a shared cache.
        $etag = '"' . md5(implode('|', [$v->id, $current->id, $published->count(), $company->version, (string) $company->logo_id, $company->updated_at?->timestamp,
            @filemtime(resource_path('views/public/legal/agreement-terms.blade.php')), @filemtime(resource_path('views/platform-esign/agreement/_css.blade.php')), AgreementRenderer::SANITISER_REV])) . '"';
        $headers = ['Cache-Control' => 'no-cache, private', 'ETag' => $etag, 'Vary' => 'Accept-Encoding'] + self::securityHeaders();
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, $headers);
        }

        $renderer = app(AgreementRenderer::class);
        $parts = [];
        foreach (self::PUBLISHED_PARTS as $key => $label) {
            $parts[$key] = ['label' => $label, 'html' => implode("\n", $renderer->blocks($v, $key, 'text'))];
        }
        $co = app(AgreementCompany::class);

        return response()->view('public.legal.agreement-terms', [
            'v' => $v, 'isCurrent' => $v->id === $current->id, 'current' => $current, 'published' => $published, 'parts' => $parts,
            'letterhead' => $co->letterhead(), 'logoUrl' => $co->logoUrl(), 'brand' => $co->brand(), 'printJs' => self::PRINT_JS,
        ], 200, $headers);
    }
}
