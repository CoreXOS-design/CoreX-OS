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

        if (!$agent) {
            abort(404);
        }

        $path = $this->cards->resolve($agent);

        // Defensive: if the card could not be written (e.g. the cache dir is not
        // writable), degrade to a clean 404 instead of a 500 stack trace to the
        // public crawler. The OG pre-warm path already falls back to the agency
        // logo, so a missing card never breaks the preference page itself.
        if (!is_file($path)) {
            abort(404);
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
