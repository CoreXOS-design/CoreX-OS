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

        $link = $link->refresh();

        // Phase 5 — teaser mode branches here. If lead is captured this
        // session, render the full view; otherwise render the teaser view
        // with locked sections + capture form.
        if ($link->mode === 'teaser' && !$this->teaserLeadCaptured($request, $link)) {
            return response()->view('presentations.public.teaser', [
                'link'             => $link,
                'presentation'     => $link->presentation,
                'version'          => $link->presentationVersion,
                'teaserVisibility' => $this->teaserVisibility($link),
            ]);
        }

        return response()->view('presentations.public.show', [
            'link'         => $link,
            'presentation' => $link->presentation,
            'version'      => $link->presentationVersion,
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
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
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
