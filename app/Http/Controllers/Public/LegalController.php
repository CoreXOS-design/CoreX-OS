<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementCompany;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
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

    // ── CoreX Subscription Agreement — Parts B, C, D (spec §11.13) ─────────

    /** The Parts that are published. Never Part A (the signed form), the cover or the debit order mandate. */
    private const PUBLISHED_PARTS = ['part_b' => 'Part B', 'part_c' => 'Part C', 'part_d' => 'Part D'];

    /** /legal — the current published version of Parts B, C and D. */
    public function agreementTerms(Request $request)
    {
        $current = AgreementContent::current();
        if (!$current) {
            try {
                $current = app(AgreementContent::class)->ensureSeeded(); // first ever request on a fresh environment
            } catch (\Throwable) {
                abort(404);
            }
        }

        return $this->agreementPage($request, $current, $current);
    }

    /** /legal/v/{version} — any published version; the current one redirects to /legal. */
    public function agreementTermsVersion(Request $request, string $version)
    {
        $current = AgreementContent::current() ?? abort(404);
        $v = WordingVersion::published()->where('template_id', $current->template_id)->where('version', $version)->first();
        abort_unless($v, 404);
        if ($v->id === $current->id) {
            return redirect()->route('public.agreement-terms', [], 301);
        }

        return $this->agreementPage($request, $v, $current);
    }

    private function agreementPage(Request $request, WordingVersion $v, WordingVersion $current)
    {
        $published = WordingVersion::published()->where('template_id', $current->template_id)->orderByDesc('published_at')->orderByDesc('id')->get(['id', 'version', 'version_date', 'published_at']);
        $company = PlatformCompany::current();
        // Published versions never change, so the page only varies with: which version, which one is current, and the letterhead.
        $etag = '"' . md5(implode('|', [$v->id, $current->id, $published->count(), $company->version, (string) $company->logo_id, $company->updated_at?->timestamp])) . '"';
        $headers = ['Cache-Control' => 'public, max-age=300', 'ETag' => $etag, 'Vary' => 'Accept-Encoding'];
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
            'letterhead' => $co->letterhead(), 'logoUrl' => $co->logoUrl(), 'brand' => $co->brand(),
        ], 200, $headers);
    }
}
