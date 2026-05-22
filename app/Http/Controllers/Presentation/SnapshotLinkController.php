<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationSnapshotLink;
use App\Services\Presentations\SnapshotLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 4 Part E — agent-side CRUD for snapshot share links.
 *
 * Same auth + agency scoping as the rest of the presentations module.
 * Cross-agency safety: every action verifies the link belongs to the
 * presentation in the URL AND that the presentation belongs to the
 * authenticated user's effective agency.
 */
final class SnapshotLinkController extends Controller
{
    public function __construct(private readonly SnapshotLinkService $svc = new SnapshotLinkService()) {}

    /** POST /presentations/{presentation}/snapshot-links */
    public function store(Request $request, Presentation $presentation): RedirectResponse
    {
        $this->guardAgency($request, $presentation);

        $data = $request->validate([
            'mode'                 => 'sometimes|nullable|in:full,teaser',
            'recipient_contact_id' => 'nullable|integer|exists:contacts,id',
            'recipient_label'      => 'nullable|string|max:200',
            'expires_days'         => 'nullable|integer|min:1|max:365',
        ]);

        $expiresAt = isset($data['expires_days'])
            ? now()->addDays((int) $data['expires_days'])
            : null;

        try {
            $link = $this->svc->createLink($presentation, [
                'mode'                 => $data['mode'] ?? 'full',
                'recipient_contact_id' => $data['recipient_contact_id'] ?? null,
                'recipient_label'      => $data['recipient_label']      ?? null,
                'expires_at'           => $expiresAt,
                'created_by_user_id'   => (int) $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['snapshot_link' => $e->getMessage()]);
        }

        return back()->with('status', 'Share link created: ' . $this->svc->publicUrl($link->token));
    }

    /** POST /presentations/{presentation}/snapshot-links/{link}/revoke */
    public function revoke(Request $request, Presentation $presentation, PresentationSnapshotLink $link): RedirectResponse
    {
        $this->guardAgency($request, $presentation);
        $this->guardLinkBelongsToPresentation($link, $presentation);

        $this->svc->revokeLink($link, $request->user());
        return back()->with('status', 'Share link revoked.');
    }

    /** POST /presentations/{presentation}/snapshot-links/{link}/extend */
    public function extend(Request $request, Presentation $presentation, PresentationSnapshotLink $link): RedirectResponse
    {
        $this->guardAgency($request, $presentation);
        $this->guardLinkBelongsToPresentation($link, $presentation);

        $days = (int) $request->validate(['days' => 'required|integer|min:1|max:365'])['days'];
        $this->svc->extendExpiry($link, $days, $request->user());
        return back()->with('status', 'Share link expiry extended by ' . $days . ' days.');
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function guardAgency(Request $request, Presentation $presentation): void
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective || (int) $presentation->agency_id !== (int) $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }

    private function guardLinkBelongsToPresentation(PresentationSnapshotLink $link, Presentation $presentation): void
    {
        if ((int) $link->presentation_id !== (int) $presentation->id) {
            abort(404);
        }
    }
}
