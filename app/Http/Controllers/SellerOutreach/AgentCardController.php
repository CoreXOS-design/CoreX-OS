<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SellerOutreach\AgentCardImageService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-83 — public (unauthenticated) endpoint serving the composite agent
 * business-card JPEG used as the WhatsApp link-preview og:image on the
 * communication-preferences page.
 *
 * Public by design: WhatsApp's preview crawler fetches this with no session,
 * and Johan eyeballs it directly in a browser. It exposes only the agent's
 * public business-card facts (name, title, FFC, photo, agency logo) — the same
 * information an agency publishes on its website — so no permission gate
 * applies, mirroring the other unauthenticated outreach routes (opt-in /
 * opt-out / landing). Generate-on-miss; cached on the public disk thereafter.
 *
 * The route is registered WITHOUT session/cookie middleware: Facebook/WhatsApp's
 * crawler refuses an og:image that responds with a Set-Cookie header, so this
 * endpoint must answer cookie-free (see routes/web.php).
 */
final class AgentCardController extends Controller
{
    public function __construct(
        private readonly AgentCardImageService $cards,
    ) {}

    /** GET /outreach/agent-card/{user}.jpg */
    public function show(int $user): Response
    {
        $agent = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($user);

        // 2026-08-25 (Johan) — this endpoint is consumed as image bytes
        // (an <img> tag, WhatsApp's og:image crawler), never as a page a
        // human navigates to directly. An abort(404) here used to hand
        // that consumer a full branded HTML error page (once the generic
        // 404 handler started branding everything) — a broken-image icon
        // in an email or a shared card, not a graceful failure. Both
        // failure points below now serve the agency-neutral fallback JPEG
        // instead: correct content-type, correct 1200x630 dimensions,
        // cached identically to a real card. Never HTML.
        $path = $agent ? $this->cards->resolve($agent) : null;

        if (!$path || !is_file($path)) {
            $path = $this->cards->resolveFallback();
        }

        $response = (new BinaryFileResponse($path))
            ->setMaxAge(86400)          // 1 day; URL carries the content hash, so a
            ->setPublic()               // changed card has a new URL (cache-safe)
            ->setAutoEtag()
            ->setAutoLastModified();
        $response->headers->set('Content-Type', 'image/jpeg');

        return $response;
    }
}
