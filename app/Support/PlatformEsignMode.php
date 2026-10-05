<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * AT-447 — "Platform E-Sign" mode: CoreX's own contracts run in the SAME e-sign
 * (template creator, wizard, signing) but belong to no agency.
 *
 * The mode is on only when ALL of:
 *   - the user is an owner-role account,
 *   - they entered it from Dev Settings (session flag), and
 *   - the request is an e-sign page (docuperfect/*).
 * So it can never leak into the rest of CoreX, and customer agencies never see it:
 * everything created in the mode has agency_id NULL (orphan semantics for agency
 * scoped models) or is_platform = 1 (templates, where NULL means "shared").
 *
 * Public signing links (sign/{token}) have no session user, so they are untouched.
 */
final class PlatformEsignMode
{
    public const SESSION_KEY = 'platform_esign_mode';

    private const MODEL_NAMESPACE = 'App\\Models\\Docuperfect\\';

    public static function active(): bool
    {
        $request = request();
        if (!$request || !$request->hasSession() || !$request->session()->isStarted()) {
            return false;
        }
        if (!$request->session()->get(self::SESSION_KEY)) {
            return false;
        }
        if (!$request->is('docuperfect', 'docuperfect/*')) {
            return false;
        }
        $user = $request->user();

        return (bool) ($user && method_exists($user, 'isOwnerRole') && $user->isOwnerRole());
    }

    /**
     * External e-sign links reached by TOKEN — the signer's page, the signed-document download and the sales
     * return link. The token is the authorisation, so a CoreX (agency-less) contract must stay reachable even
     * if the visitor happens to be logged in (an agency principal, or the owner testing in his own browser).
     * Without this the agency / platform scopes would hide the document and the page would 500.
     */
    public static function onTokenRoute(): bool
    {
        $request = request();

        return (bool) ($request && $request->is('sign/*', 'documents/download/*', 'sales-documents/return/*'));
    }

    /** Only e-sign models are re-scoped; users, branches, contacts etc. behave as before. */
    public static function appliesTo(Model|string $model): bool
    {
        $class = is_string($model) ? $model : get_class($model);

        return str_starts_with($class, self::MODEL_NAMESPACE);
    }

    public static function enter(): void
    {
        session([self::SESSION_KEY => true]);
    }

    public static function leave(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function isEntered(): bool
    {
        return (bool) session(self::SESSION_KEY);
    }
}
