<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * System Developer → Integration hub. Owner-only control panel that surfaces
 * the configuration values an operator must paste into the Meta (Facebook)
 * App dashboard, plus the live status of the Meta integration. Read-only:
 * the actual credentials live in .env (META_APP_ID / META_APP_SECRET /
 * META_REDIRECT_URI) and are never editable from the browser.
 */
class IntegrationsController extends Controller
{
    public function index()
    {
        $appId       = (string) config('services.meta.app_id', '');
        $appSecret   = (string) config('services.meta.app_secret', '');
        $redirectUri = (string) config('services.meta.redirect_uri', '');

        // App domain Facebook needs whitelisted = host of the app URL.
        $appUrl    = (string) config('app.url', '');
        $appDomain = parse_url($appUrl, PHP_URL_HOST) ?: '';

        return view('admin.integrations.index', [
            'metaConfigured'  => $appId !== '' && $appSecret !== '' && $redirectUri !== '',
            'appId'           => $appId,
            'redirectUri'     => $redirectUri,
            'appDomain'       => $appDomain,
            'privacyUrl'      => route('public.platform-privacy'),
            'dataDeletionUrl' => route('public.data-deletion'),
            'redirectIsHttps' => str_starts_with($redirectUri, 'https://'),
        ]);
    }
}
