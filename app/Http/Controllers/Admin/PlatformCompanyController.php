<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformCompany;

/**
 * Platform Company Profile (RR Technologies / CoreX OS). Owner-only screens; the logo stream is the one public action.
 * Spec: .ai/specs/platform-company-profile.md.
 */
class PlatformCompanyController extends Controller
{
    /** Public asset stream — the current logo (or the built-in CoreX OS asset). Never anything but the logo. */
    public function logo()
    {
        $f = PlatformCompany::current()->logoFile();

        return response($f['bytes'], 200, [
            'Content-Type'            => $f['mime'],
            'Cache-Control'           => 'public, max-age=3600',
            'X-Content-Type-Options'  => 'nosniff',
            // An SVG opened directly can never run script or load anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
            'Content-Disposition'     => 'inline; filename="logo"',
        ]);
    }
}
