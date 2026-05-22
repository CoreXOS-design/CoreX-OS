<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationSnapshotView;
use App\Notifications\Presentations\PresentationFirstViewedNotification;
use App\Notifications\Presentations\PresentationFlaggedAccessNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 4 Part C — public-facing controller for tokenised snapshot URLs.
 *
 * No auth middleware. The token IS the credential. Reads are scoped by
 * token + revoked_at + expires_at; agency scoping is implicit because the
 * token resolves a single link to a single presentation/version.
 */
final class PublicPresentationController extends Controller
{
    /** Cooldown between flagged-access notifications (per link). */
    private const FLAG_NOTIFY_COOLDOWN_HOURS = 24;

    /**
     * GET /p/{token}
     */
    public function show(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::withoutGlobalScopes()
            ->with([
                'presentation.property',
                'presentation.fields',
                'presentation.soldComps',
                'presentation.activeListings',
                'presentationVersion',
                'creator',
            ])
            ->where('token', $token)
            ->first();

        if (!$link) {
            return $this->renderUnavailable('not_found');
        }
        if ($link->isRevoked()) {
            return $this->renderUnavailable('revoked', $link);
        }
        if ($link->isExpired()) {
            return $this->renderUnavailable('expired', $link);
        }

        // Fingerprint the request server-side. The track beacon (POST below)
        // extends this with client-side screen + timezone data.
        $fingerprint = $this->serverFingerprint($request);
        $isFirstView = $link->first_fingerprint === null;
        $fingerprintMismatch = !$isFirstView && $link->first_fingerprint !== $fingerprint;

        DB::transaction(function () use ($link, $request, $fingerprint, $isFirstView, $fingerprintMismatch) {
            // Insert view row.
            PresentationSnapshotView::create([
                'snapshot_link_id'             => $link->id,
                'viewed_at'                    => now(),
                'ip_address'                   => $this->ipForStorage($request, $link),
                'user_agent'                   => mb_substr((string) $request->userAgent(), 0, 500),
                'fingerprint'                  => $fingerprint,
                'referrer_url'                 => mb_substr((string) $request->headers->get('referer', ''), 0, 500) ?: null,
                'is_first_view'                => $isFirstView,
                'flagged_fingerprint_mismatch' => $fingerprintMismatch,
                'created_at'                   => now(),
            ]);

            // Update link aggregates.
            $updates = [
                'last_viewed_at' => now(),
                'view_count'     => $link->view_count + 1,
            ];
            if ($isFirstView) {
                $updates['first_viewed_at']   = now();
                $updates['first_fingerprint'] = $fingerprint;
            }
            if ($fingerprintMismatch && !$link->flagged_at) {
                $updates['flagged_at']     = now();
                $updates['flagged_reason'] = 'fingerprint mismatch — link may have been forwarded';
            }
            $link->forceFill($updates)->save();
        });

        // Notifications (queued).
        try {
            if ($isFirstView && $link->creator) {
                $link->creator->notify(new PresentationFirstViewedNotification($link->id));
            }
            if ($fingerprintMismatch && $this->shouldDispatchFlagNotice($link)) {
                $link->creator?->notify(new PresentationFlaggedAccessNotification($link->id));
                $link->forceFill(['last_flag_notified_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            // Notification failure must NOT block the seller's page render.
            Log::warning('Snapshot link notify dispatch failed', [
                'link_id' => $link->id,
                'err'     => $e->getMessage(),
            ]);
        }

        return response()->view('presentations.public.show', [
            'link'         => $link->refresh(),
            'presentation' => $link->presentation,
            'version'      => $link->presentationVersion,
        ]);
    }

    /**
     * POST /p/{token}/track  — engagement beacon.
     *
     * Updates the most-recent view row that matches the calling fingerprint.
     * Returns 204 always (silent, even on validation failure — beacons
     * shouldn't surface errors to the client).
     */
    public function track(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::where('token', $token)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if (!$link) {
            return response()->noContent(204);
        }

        $data = $request->validate([
            'duration_seconds' => 'sometimes|nullable|integer|min:0|max:86400',
            'scroll_depth_pct' => 'sometimes|nullable|integer|min:0|max:100',
            'sections_viewed'  => 'sometimes|array',
            'sections_viewed.*'=> 'string|max:60',
            'client_fingerprint' => 'sometimes|nullable|string|max:128',
        ]);

        // Row lookup is always by SERVER fingerprint — that's what the
        // initial GET stored. The client_fingerprint is more precise (screen
        // + timezone) but isn't the row-key; we don't change row identity
        // mid-session. Find the most-recent view row for this link from this
        // server-identified session and stamp the new engagement metrics.
        $serverFp = $this->serverFingerprint($request);
        $view = PresentationSnapshotView::where('snapshot_link_id', $link->id)
            ->where('fingerprint', $serverFp)
            ->orderByDesc('id')
            ->first();
        if (!$view) {
            return response()->noContent(204);
        }

        $view->forceFill(array_filter([
            'duration_seconds'      => $data['duration_seconds'] ?? null,
            'scroll_depth_pct'      => $data['scroll_depth_pct'] ?? null,
            'sections_viewed_json'  => isset($data['sections_viewed']) ? array_values(array_unique($data['sections_viewed'])) : null,
        ], fn ($v) => $v !== null))->save();

        return response()->noContent(204);
    }

    /** GET /p/{token}/refresh  — Phase 7 placeholder. */
    public function refreshForm(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::where('token', $token)->first();
        if (!$link || $link->isRevoked() || $link->isExpired()) {
            return $this->renderUnavailable('not_found');
        }
        return response()->view('presentations.public.refresh-form', ['link' => $link]);
    }

    /** POST /p/{token}/refresh  — Phase 7 placeholder. */
    public function refreshSubmit(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::where('token', $token)->first();
        if (!$link || $link->isRevoked() || $link->isExpired()) {
            return $this->renderUnavailable('not_found');
        }
        $data = $request->validate([
            'requester_name' => 'required|string|max:200',
            'message'        => 'nullable|string|max:2000',
        ]);
        $link->forceFill([
            'refresh_requested_at'      => now(),
            'refresh_requested_by_name' => $data['requester_name'],
            'refresh_requested_message' => $data['message'] ?? null,
        ])->save();
        // Phase 7 will dispatch the notification to the agent.
        return response()->view('presentations.public.refresh-thanks', ['link' => $link]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Server-side fingerprint: SHA-256 of normalised UA (major version only)
     * + Accept-Language. NO IP (mobile IPs change), NO PII. The track beacon
     * extends this with screen + timezone client-side.
     */
    private function serverFingerprint(Request $request): string
    {
        $ua = (string) $request->userAgent();
        // Strip version digits (X.Y.Z → X) so a browser auto-update doesn't
        // trip the flag. Keeps the engine, OS, and major version.
        $normalisedUa = preg_replace('/(\d+)\.\d+(\.\d+)?(\.\d+)?/u', '$1', $ua) ?? $ua;
        $acceptLang = (string) $request->headers->get('accept-language', '');
        return hash('sha256', $normalisedUa . '|' . $acceptLang);
    }

    /**
     * IP for storage: masked to /24 (IPv4) or /48 (IPv6) when the agency has
     * snapshot_link_ip_masking=true (the default). Otherwise full IP.
     */
    private function ipForStorage(Request $request, PresentationSnapshotLink $link): ?string
    {
        $ip = $request->ip();
        if (!$ip) return null;
        $masking = (bool) \App\Models\Agency::find($link->agency_id)?->snapshot_link_ip_masking ?? true;
        if (!$masking) return $ip;
        if (str_contains($ip, ':')) {
            // IPv6 — mask to /48 (first 3 groups).
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . '::/48';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return $ip;
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
    }

    private function shouldDispatchFlagNotice(PresentationSnapshotLink $link): bool
    {
        if (!$link->last_flag_notified_at) return true;
        return $link->last_flag_notified_at->diffInHours(now()) >= self::FLAG_NOTIFY_COOLDOWN_HOURS;
    }

    private function renderUnavailable(string $reason, ?PresentationSnapshotLink $link = null): Response
    {
        return response()->view('presentations.public.unavailable', [
            'reason' => $reason,
            'agent'  => $link?->creator,
        ], 404);
    }
}
