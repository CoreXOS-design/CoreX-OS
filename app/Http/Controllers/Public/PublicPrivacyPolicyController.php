<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Compliance\InformationOfficerAppointment;
use Illuminate\Support\Str;

/**
 * Phase 9c (AT-16) — finding #1: canonical public privacy policy.
 *
 * Unlike the token-gated /legal/privacy/{token} page (operator-authored
 * markdown, private share link), this page is ALWAYS available at a stable
 * URL and is the one linked from every public footer. Its body is GENERATED
 * from structured agency fields so a POPIA-aligned notice exists even before
 * the agency authors any custom markdown. If the agency HAS authored markdown,
 * it is appended as an additional agency-specific section.
 *
 * No auth, no permission gate — a privacy notice is intentionally public
 * (POPIA s18 requires it be discoverable at the point of collection).
 */
final class PublicPrivacyPolicyController extends Controller
{
    /** /privacy-policy — resolve the primary live agency (no slug context). */
    public function index()
    {
        $agency = Agency::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->orderBy('id')
            ->first()
            ?? Agency::query()->orderBy('id')->first();

        abort_if($agency === null, 404);

        return $this->render($agency);
    }

    /** /privacy-policy/{agencySlug} — agency-specific notice. */
    public function show(string $agencySlug)
    {
        $agency = Agency::where('slug', $agencySlug)->first();
        abort_if($agency === null, 404);

        return $this->render($agency);
    }

    private function render(Agency $agency)
    {
        // Information Officer — read from the existing appointment system
        // (single source of truth). Null when none appointed yet.
        $ioAppointment = InformationOfficerAppointment::currentPrimary($agency->id);

        // Retention — per-agency governance setting (default 5y). forAgency()
        // creates the row with sane defaults if it doesn't exist.
        $retentionYears = AgencyContactSettings::forAgency($agency->id)->contact_retention_years ?? 5;

        // The agency's own authored privacy markdown, if any — appended below
        // the generated POPIA baseline. Only when actually published.
        $agencyMarkdownHtml = null;
        if (!empty($agency->privacy_policy_markdown) && $agency->privacy_policy_published_at !== null) {
            $agencyMarkdownHtml = (string) Str::markdown((string) $agency->privacy_policy_markdown);
        }

        return view('public.privacy-policy-structured', [
            'agency'             => $agency,
            'ioAppointment'      => $ioAppointment,
            'retentionYears'     => (int) $retentionYears,
            'agencyMarkdownHtml' => $agencyMarkdownHtml,
            'draftBanner'        => (bool) config('corex.privacy_policy.draft_banner_enabled', true),
            'logoUrl'            => $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
        ]);
    }
}
