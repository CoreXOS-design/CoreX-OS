<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationSnapshotView;
use App\Models\PresentationTeaserLead;
use App\Notifications\Presentations\PresentationFirstViewedNotification;
use App\Notifications\Presentations\PresentationFlaggedAccessNotification;
use App\Notifications\Presentations\TeaserLeadCapturedNotification;
use App\Services\Presentations\RefreshNotAllowedException;
use App\Services\Presentations\RefreshRateLimitException;
use App\Services\Presentations\RefreshRequestService;
use App\Support\Presentations\StalenessCalculator;
use App\Support\Presentations\StalenessState;
use Illuminate\Http\JsonResponse;
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
        // Phase 9a hardening — a snapshot link whose parent presentation has
        // been soft-deleted must NOT render. The eager-load above doesn't
        // respect SoftDeletes when the relationship root was looked up via
        // withoutGlobalScopes() on the link, so we check explicitly.
        if (!$link->presentation || $link->presentation->trashed()) {
            return $this->renderUnavailable('not_found');
        }
        if ($link->isRevoked()) {
            return $this->renderUnavailable('revoked', $link);
        }
        // Phase 7 — if this link was superseded by a refresh, redirect to the
        // new one so the seller doesn't see stale data.
        if ($link->isSuperseded() && $link->superseded_by_link_id) {
            $newer = PresentationSnapshotLink::find($link->superseded_by_link_id);
            if ($newer && $newer->isUsable()) {
                return redirect()->route('presentation.public.show', $newer->token);
            }
            return $this->renderUnavailable('revoked', $link);
        }
        if ($link->isExpired()) {
            // Phase 7 — expired links route to the "request a refresh" page
            // instead of a flat 404, so the seller stays on the agent.
            return response()->view('presentations.public.expired-with-refresh', [
                'link'  => $link,
                'agent' => $link->creator,
            ], 410);
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

        $link = $link->refresh();

        // Phase 7 — classify staleness so the view can render the right banner.
        $calc            = app(StalenessCalculator::class);
        $agency          = Agency::find($link->agency_id);
        $stalenessState  = $calc->classify($link, $agency);
        $windowDays      = $calc->resolveWindowDays($agency);
        $bannerMessage   = $calc->bannerMessage($stalenessState, $windowDays);

        // Phase 5 — teaser mode branches here. If lead is captured this
        // session, render the full view; otherwise render the teaser view
        // with locked sections + capture form.
        if ($link->mode === 'teaser' && !$this->teaserLeadCaptured($request, $link)) {
            return response()->view('presentations.public.teaser', [
                'link'             => $link,
                'presentation'     => $link->presentation,
                'version'          => $link->presentationVersion,
                'teaserVisibility' => $this->teaserVisibility($link),
                'stalenessState'   => $stalenessState,
                'stalenessBanner'  => $bannerMessage,
            ]);
        }

        return response()->view('presentations.public.show', [
            'link'            => $link,
            'presentation'    => $link->presentation,
            'version'         => $link->presentationVersion,
            'stalenessState'  => $stalenessState,
            'stalenessBanner' => $bannerMessage,
        ]);
    }

    /**
     * Phase 5 Part C — POST /p/{token}/capture-lead.
     *
     * Validates + honeypot-check + tries to match an existing Contact by
     * email or phone. On match → just link the lead row. On miss → create
     * a new Contact in the agency and link. Always retro-attributes the
     * lead's pre-conversion view rows.
     */
    public function captureLead(Request $request, string $token): JsonResponse
    {
        $link = PresentationSnapshotLink::with('presentation.property')
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if (!$link) {
            return response()->json(['error' => 'Link unavailable.'], 404);
        }
        if ($link->mode !== 'teaser') {
            return response()->json(['error' => 'This link does not require lead capture.'], 422);
        }

        // Honeypot — bots populate hidden fields. company_name should be empty.
        if ($request->filled('company_name')) {
            return response()->json(['error' => 'Invalid submission.'], 422);
        }

        $data = $request->validate([
            'first_name'         => 'required|string|min:2|max:100',
            'last_name'          => 'required|string|min:2|max:100',
            'email'              => 'nullable|email|max:200',
            'phone'              => 'nullable|string|max:30',
            'relationship'       => 'required|in:owner,considering_selling,agent,researcher,other',
            'intent'             => 'required|in:sell_now,sell_soon,just_curious,other',
            'consent_marketing'  => 'sometimes|boolean',
            'notes'              => 'nullable|string|max:2000',
        ]);

        // At least one of email/phone required.
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $phone = $this->normaliseSaPhone(trim((string) ($data['phone'] ?? '')));
        if ($email === '' && $phone === '') {
            return response()->json([
                'error'  => 'Provide email or phone so we can send your report.',
                'fields' => ['email' => ['Provide email or mobile.']],
            ], 422);
        }

        $agencyId = (int) $link->agency_id;
        $assignedAgent = $this->resolveAssignedAgent($link);

        // 1. Try to match an existing Contact by exact email OR phone.
        $matchedContact = null;
        if ($email !== '') {
            $matchedContact = Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();
        }
        if (!$matchedContact && $phone !== '') {
            $matchedContact = Contact::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('phone', $phone)
                ->first();
        }

        // 2. If same lead has already submitted for THIS link via fingerprint OR
        //    email, treat it as an update rather than a new row.
        $serverFp = $this->serverFingerprint($request);
        $existingLead = PresentationTeaserLead::where('snapshot_link_id', $link->id)
            ->where(function ($q) use ($email, $phone) {
                if ($email !== '') $q->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                if ($phone !== '') $q->orWhere('phone', $phone);
            })
            ->first();

        $leadAttrs = [
            'first_name'        => $data['first_name'],
            'last_name'         => $data['last_name'],
            'email'             => $email ?: null,
            'phone'             => $phone ?: null,
            'relationship'      => $data['relationship'],
            'intent'            => $data['intent'],
            'consent_marketing' => (bool) ($data['consent_marketing'] ?? false),
            'consent_contact'   => true,
            'notes'             => $data['notes'] ?? null,
            'captured_at'       => now(),
            'assigned_agent_id' => $assignedAgent?->id,
        ];

        $lead = DB::transaction(function () use (
            $link, $existingLead, $leadAttrs, $matchedContact, $agencyId, $assignedAgent, $serverFp
        ) {
            if ($existingLead) {
                $existingLead->forceFill($leadAttrs)->save();
                $lead = $existingLead;
            } else {
                $lead = PresentationTeaserLead::create(array_merge($leadAttrs, [
                    'snapshot_link_id' => $link->id,
                    'agency_id'        => $agencyId,
                    'presentation_id'  => $link->presentation_id,
                ]));
            }

            // 3. Link to existing Contact or create one.
            if ($matchedContact) {
                $lead->forceFill(['contact_id' => $matchedContact->id])->save();
            } elseif (!$lead->contact_id) {
                $newContact = $this->createContactFromLead($lead, $link, $assignedAgent);
                if ($newContact) {
                    $lead->forceFill([
                        'contact_id'              => $newContact->id,
                        'converted_to_contact_at' => now(),
                    ])->save();
                }
            }

            // 4. Retro-attribute view rows sharing the server fingerprint.
            PresentationSnapshotView::where('snapshot_link_id', $link->id)
                ->where('fingerprint', $serverFp)
                ->whereNull('teaser_lead_id')
                ->update(['teaser_lead_id' => $lead->id]);

            return $lead;
        });

        // 5. Mark session so subsequent views render full mode.
        $request->session()->put('teaser_lead_id_' . $link->id, $lead->id);

        // 6. Notify the assigned agent (queued, outside transaction).
        try {
            if ($assignedAgent) {
                $assignedAgent->notify(new TeaserLeadCapturedNotification($lead->id));
            }
        } catch (\Throwable $e) {
            Log::warning('Teaser lead notify dispatch failed', [
                'lead_id' => $lead->id, 'err' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok'       => true,
            'lead_id'  => $lead->id,
            'redirect' => route('presentation.public.show', $link->token),
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

    /**
     * GET /p/{token}/refresh — render the seller-facing "ask for an update" form.
     *
     * Reachable when the link is expired OR when the staleness banner offers
     * a "Request refresh" CTA. Revoked / not-found links 404 as usual.
     */
    public function refreshForm(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::where('token', $token)->with('creator')->first();
        if (!$link || $link->isRevoked()) {
            return $this->renderUnavailable('not_found');
        }
        // If superseded, redirect to the new link rather than offer a refresh
        // — the seller already has the answer they're asking for.
        if ($link->isSuperseded() && $link->superseded_by_link_id) {
            $newer = PresentationSnapshotLink::find($link->superseded_by_link_id);
            if ($newer && $newer->isUsable()) {
                return redirect()->route('presentation.public.show', $newer->token);
            }
        }

        // Prefill from recipient contact when we have one + we're inside an
        // authenticated session of the lead-capture flow.
        $prefill = [
            'requester_name'  => $link->refresh_requested_by_name
                ?? ($link->recipientContact?->first_name && $link->recipientContact?->last_name
                    ? trim($link->recipientContact->first_name . ' ' . $link->recipientContact->last_name)
                    : ''),
            'requester_email' => $link->recipientContact?->email ?? '',
            'requester_phone' => $link->recipientContact?->phone ?? '',
        ];

        return response()->view('presentations.public.refresh-form', [
            'link'    => $link,
            'agent'   => $link->creator,
            'prefill' => $prefill,
        ]);
    }

    /**
     * POST /p/{token}/refresh — record the request via RefreshRequestService.
     *
     * Honeypot + validation; the service handles rate-limit + DB writes +
     * notification dispatch.
     */
    public function refreshSubmit(Request $request, string $token): Response
    {
        $link = PresentationSnapshotLink::where('token', $token)->first();
        if (!$link || $link->isRevoked()) {
            return $this->renderUnavailable('not_found');
        }
        if ($link->isSuperseded() && $link->superseded_by_link_id) {
            $newer = PresentationSnapshotLink::find($link->superseded_by_link_id);
            if ($newer && $newer->isUsable()) {
                return redirect()->route('presentation.public.show', $newer->token);
            }
        }

        // Honeypot — bots populate the hidden company_name field.
        if ($request->filled('company_name')) {
            return response()->view('presentations.public.refresh-thanks', ['link' => $link]);
        }

        $data = $request->validate([
            'requester_name'  => 'required|string|min:2|max:120',
            'requester_email' => 'nullable|email|max:160',
            'requester_phone' => 'nullable|string|max:40',
            'message'         => 'nullable|string|max:2000',
        ]);

        try {
            app(RefreshRequestService::class)->submitRequest($link, [
                'requester_name'   => $data['requester_name'],
                'requester_email'  => $data['requester_email'] ?? null,
                'requester_phone'  => $data['requester_phone'] ?? null,
                'message'          => $data['message'] ?? null,
                'fingerprint_hash' => $this->serverFingerprint($request),
                'ip_masked'        => $this->ipForStorage($request, $link),
                'user_agent'       => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            ]);
        } catch (RefreshRateLimitException $e) {
            return response()->view('presentations.public.refresh-thanks', [
                'link'           => $link,
                'rate_limited'   => true,
                'rate_limit_msg' => $e->getMessage(),
            ], 429);
        } catch (RefreshNotAllowedException $e) {
            return $this->renderUnavailable('revoked', $link);
        }

        return response()->view('presentations.public.refresh-thanks', [
            'link'  => $link->refresh(),
            'agent' => $link->creator,
        ]);
    }

    /**
     * Build 5 — POST /p/{token}/request-revision.
     *
     * One-click variant of the full /refresh form. Designed for the
     * freshness CTA panel on the public view: the seller taps a button,
     * we silently submit a refresh request using whatever we already
     * know about them from the share link (recipient_contact_id +
     * recipient_label) and the request context (IP, UA, fingerprint).
     *
     * Idempotent — if there's already an open request for this link,
     * we return that one rather than creating a duplicate. The
     * RefreshRequestService rate-limit still applies as the underlying
     * safety net.
     */
    public function requestRevision(Request $request, string $token): JsonResponse
    {
        $link = PresentationSnapshotLink::where('token', $token)->first();
        if (!$link || $link->isRevoked()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        if ($link->isSuperseded() && $link->superseded_by_link_id) {
            $newer = PresentationSnapshotLink::find($link->superseded_by_link_id);
            if ($newer && $newer->isUsable()) {
                return response()->json([
                    'ok'       => true,
                    'redirect' => route('presentation.public.show', $newer->token),
                ]);
            }
        }

        // Idempotency — if the link already has an open request (pending
        // or acknowledged), return its state rather than create a duplicate.
        $existing = \App\Models\PresentationRefreshRequest::where('snapshot_link_id', $link->id)
            ->whereIn('status', [
                \App\Models\PresentationRefreshRequest::STATUS_PENDING,
                \App\Models\PresentationRefreshRequest::STATUS_ACKNOWLEDGED,
            ])
            ->latest('created_at')
            ->first();
        if ($existing) {
            return response()->json([
                'ok'                 => true,
                'already_requested'  => true,
                'requested_at'       => $existing->created_at?->toIso8601String(),
                'requested_ago'      => $existing->created_at?->diffForHumans() ?? 'recently',
                'agent_name'         => $link->creator?->name ?? 'your agent',
            ]);
        }

        // Derive a requester name. Order of preference:
        //   1. seller-supplied (some sellers paste a note when forwarding)
        //   2. recipient_contact's name (we sent them the link directly)
        //   3. link.recipient_label (free-text label set by the agent)
        //   4. "Anonymous viewer"
        $payloadName = trim((string) $request->input('requester_name', ''));
        if ($payloadName === '') {
            $payloadName = optional(
                $link->recipient_contact_id ? \App\Models\Contact::find($link->recipient_contact_id) : null
            )->display_name
                ?? $link->recipient_label
                ?? 'Anonymous viewer';
        }
        $message = trim((string) $request->input('message', ''));

        try {
            app(RefreshRequestService::class)->submitRequest($link, [
                'requester_name'   => $payloadName,
                'requester_email'  => null,
                'requester_phone'  => null,
                'message'          => $message !== '' ? mb_substr($message, 0, 2000) : null,
                'fingerprint_hash' => $this->serverFingerprint($request),
                'ip_masked'        => $this->ipForStorage($request, $link),
                'user_agent'       => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            ]);
        } catch (RefreshRateLimitException $e) {
            return response()->json([
                'ok'           => false,
                'rate_limited' => true,
                'message'      => $e->getMessage(),
            ], 429);
        } catch (RefreshNotAllowedException $e) {
            return response()->json(['ok' => false, 'error' => 'not_allowed'], 422);
        }

        return response()->json([
            'ok'         => true,
            'agent_name' => $link->creator?->name ?? 'your agent',
        ]);
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

    /**
     * Phase 9a hardening — normalise an SA phone for lead capture.
     * 082 123 4567 → 27821234567, +27 82 123 4567 → 27821234567.
     * Returns '' when input doesn't look like a real phone number, so the
     * controller's email-OR-phone-required check still fires.
     *
     * Mirrors the rule used by PresentationDeliveryService::whatsappUrl()
     * but kept local — extracting a shared helper isn't worth the indirection
     * for a 6-line normaliser.
     */
    private function normaliseSaPhone(string $raw): string
    {
        if ($raw === '') return '';
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') return '';
        if (str_starts_with($digits, '27')) {
            return '27' . preg_replace('/^0+/', '', substr($digits, 2));
        }
        if (str_starts_with($digits, '0')) {
            return '27' . preg_replace('/^0+/', '', $digits);
        }
        if (strlen($digits) === 9) {
            return '27' . $digits;
        }
        // Doesn't look SA — keep the digits but don't reject.
        return $digits;
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

    /**
     * Phase 5 — has this session already captured a lead for this link?
     * Session key is namespaced by link.id so multiple teaser links in the
     * same browser don't bleed.
     */
    private function teaserLeadCaptured(Request $request, PresentationSnapshotLink $link): bool
    {
        $key = 'teaser_lead_id_' . $link->id;
        $leadId = $request->session()->get($key);
        if (!$leadId) return false;
        return PresentationTeaserLead::where('id', $leadId)
            ->where('snapshot_link_id', $link->id)
            ->exists();
    }

    /**
     * Resolve which sections are visible in the teaser. Agency-level toggles
     * decide; the view reads this array directly.
     *
     * @return array<string, bool>
     */
    private function teaserVisibility(PresentationSnapshotLink $link): array
    {
        $agency = Agency::find($link->agency_id);
        return [
            'suburb_stats'         => (bool) ($agency?->teaser_default_show_suburb_stats         ?? true),
            'market_position'      => (bool) ($agency?->teaser_default_show_market_position      ?? false),
            'asking_range'         => (bool) ($agency?->teaser_default_show_asking_range         ?? true),
            'holding_cost_summary' => (bool) ($agency?->teaser_default_show_holding_cost_summary ?? false),
        ];
    }

    /**
     * Default lead owner = presentation creator. Future: property listing_agent
     * round-robin (out of scope for V1).
     */
    private function resolveAssignedAgent(PresentationSnapshotLink $link): ?\App\Models\User
    {
        return $link->creator;
    }

    /**
     * Create a Contact for an un-matched lead. branch_id required; pick the
     * presentation's branch when set, else the agency's first branch.
     */
    private function createContactFromLead(
        PresentationTeaserLead $lead,
        PresentationSnapshotLink $link,
        ?\App\Models\User $assignedAgent
    ): ?Contact {
        $branchId = $link->presentation?->branch_id
            ?? \DB::table('branches')->where('agency_id', $link->agency_id)->value('id');
        if (!$branchId) {
            Log::warning('Cannot create Contact from teaser lead — no branch available', [
                'lead_id' => $lead->id, 'agency_id' => $link->agency_id,
            ]);
            return null;
        }

        try {
            return Contact::create([
                'agency_id'           => $link->agency_id,
                'branch_id'           => $branchId,
                'created_by_user_id'  => $assignedAgent?->id,
                'first_name'          => $lead->first_name,
                'last_name'           => $lead->last_name,
                'email'               => $lead->email,
                'phone'               => $lead->phone ?: '',     // Contact.phone is NOT NULL
                'notes'               => 'Created from teaser-presentation lead capture · '
                    . optional($link->presentation)->property_address,
                'opt_out_email'       => !$lead->consent_marketing,
                'opt_out_sms'         => !$lead->consent_marketing,
                'opt_out_whatsapp'    => !$lead->consent_marketing,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Contact create from teaser lead failed', [
                'lead_id' => $lead->id, 'err' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
