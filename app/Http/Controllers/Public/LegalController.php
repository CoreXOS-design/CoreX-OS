<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;

/**
 * Public, logged-out CoreX OS platform legal pages.
 *
 * These are the platform-level (not per-agency) Privacy Policy and Data
 * Deletion pages required by Meta/Facebook App Review. Meta's crawler fetches
 * them with no authentication, so they MUST stay outside the auth + agency
 * middleware. The per-agency, token-gated privacy policy lives separately at
 * /legal/privacy/{token} (PrivacyPolicyController).
 */
final class LegalController extends Controller
{
    /** Support / privacy contact address surfaced on both pages. */
    private const CONTACT_EMAIL = 'support@corexos.co.za';

    public function privacy()
    {
        return view('public.legal.privacy', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => 'June 2026',
        ]);
    }

    public function dataDeletion()
    {
        return view('public.legal.data-deletion', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => 'June 2026',
        ]);
    }

    /**
     * Public support page.
     *
     * Referenced as the Support URL in App Store Connect, so it must stay
     * reachable with no authentication — App Review fetches it logged out and
     * rejects under guideline 1.5 if it errors.
     */
    public function support()
    {
        return view('public.legal.support', [
            'contactEmail' => self::CONTACT_EMAIL,
            'lastUpdated'  => 'August 2026',
        ]);
    }
}
