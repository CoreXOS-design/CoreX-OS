<?php

namespace App\Services\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * AT-136 — per-agent WhatsApp capture consent. The gate AT-135's body ingestion
 * routes through: bodies flow ONLY for status='opted_in'. The envelope (FICA
 * floor, AT-133) is unaffected. SEPARATE from the AT-125 contact marketing opt-out.
 *
 * The ingest path runs under a device Bearer (no Auth::user()), so reads/writes
 * here bypass AgencyScope and pass agency_id explicitly (mirrors
 * ContactIdentifierResolver — a system-level gate, tenancy via explicit agency_id).
 */
class AgentCaptureConsentService
{
    /**
     * Register a WA↔CoreX match as a PENDING capture decision (idempotent). Called
     * on every matched ingest → a contact the agent starts talking to later
     * surfaces as pending automatically (the periodic re-check). No CoreX match →
     * this is never called → never ingested (the hard floor lives in the ingestor).
     */
    public function ensurePending(int $agencyId, int $agentUserId, int $contactId): AgentCaptureConsent
    {
        $row = AgentCaptureConsent::withoutGlobalScope(AgencyScope::class)
            ->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('agent_user_id', $agentUserId)
            ->where('contact_id', $contactId)
            ->first();

        if ($row) {
            if ($row->trashed()) {
                $row->restore();
            }
            return $row;
        }

        try {
            return AgentCaptureConsent::create([
                'agency_id'     => $agencyId,
                'agent_user_id' => $agentUserId,
                'contact_id'    => $contactId,
                'status'        => AgentCaptureConsent::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // AT-149 — concurrent delivery already created the pairing; re-fetch.
            return $this->refetchPairing($agencyId, $agentUserId, $contactId);
        }
    }

    /**
     * AT-156 — a SELF-LINKED WhatsApp device (the agent linked their OWN number via
     * My Portal → Tools) IS the agent's explicit consent to capture their own client
     * threads. So the per-contact default here is OPTED-IN, not pending — otherwise
     * every body is withheld the moment an agent self-links, which defeats the
     * purpose of linking. The agent keeps the per-contact OPT-OUT (POPIA exclusion):
     * an explicit opt-out is NEVER overridden here; only a bare pending default is
     * promoted. Idempotent. (Extension-provisioned devices keep `ensurePending`.)
     */
    public function ensureSelfLinkedConsent(int $agencyId, int $agentUserId, int $contactId): AgentCaptureConsent
    {
        $row = AgentCaptureConsent::withoutGlobalScope(AgencyScope::class)
            ->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('agent_user_id', $agentUserId)
            ->where('contact_id', $contactId)
            ->first();

        if ($row) {
            if ($row->trashed()) {
                $row->restore();
            }
            // Promote a bare pending → opted_in; never touch an explicit opt-out/opt-in.
            if ($row->status === AgentCaptureConsent::STATUS_PENDING) {
                $row->status = AgentCaptureConsent::STATUS_OPTED_IN;
                $row->decided_at = now();
                $row->decided_by_user_id = $agentUserId; // the agent's own act (self-link)
                $row->save();
                // AT-168 Part B — self-link opt-in releases any embargoed bodies too.
                $this->releaseEmbargoedBodies($agencyId, $agentUserId, $contactId);
            }
            return $row;
        }

        try {
            return AgentCaptureConsent::create([
                'agency_id'          => $agencyId,
                'agent_user_id'      => $agentUserId,
                'contact_id'         => $contactId,
                'status'             => AgentCaptureConsent::STATUS_OPTED_IN,
                'decided_at'         => now(),
                'decided_by_user_id' => $agentUserId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // AT-149 — a racing self-link created the pairing first; re-fetch the
            // winner's row (it set the correct opted_in status).
            return $this->refetchPairing($agencyId, $agentUserId, $contactId);
        }
    }

    /** Re-fetch a consent pairing after a concurrent-create unique collision. */
    private function refetchPairing(int $agencyId, int $agentUserId, int $contactId): AgentCaptureConsent
    {
        return AgentCaptureConsent::withoutGlobalScope(AgencyScope::class)
            ->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('agent_user_id', $agentUserId)
            ->where('contact_id', $contactId)
            ->firstOrFail();
    }

    /** THE GATE — bodies flow only when the agent opted IN to this contact. */
    public function isCaptureOptedIn(int $agentUserId, int $contactId): bool
    {
        return AgentCaptureConsent::withoutGlobalScope(AgencyScope::class)
            ->where('agent_user_id', $agentUserId)
            ->where('contact_id', $contactId)
            ->where('status', AgentCaptureConsent::STATUS_OPTED_IN)
            ->exists();
    }

    /**
     * AT-183 — is this pairing an EXPLICIT opt-out? Distinct from "not opted-in" (which also
     * covers pending). An explicit opt-out is a POPIA exclusion: the ingestor drops the message
     * entirely (no envelope, no storage), whereas pending keeps the envelope + embargoes the body.
     */
    public function isOptedOut(int $agentUserId, int $contactId): bool
    {
        return AgentCaptureConsent::withoutGlobalScope(AgencyScope::class)
            ->where('agent_user_id', $agentUserId)
            ->where('contact_id', $contactId)
            ->where('status', AgentCaptureConsent::STATUS_OPTED_OUT)
            ->exists();
    }

    /** Agent decision: opt_in (bodies flow) or opt_out (bodies withheld; logged). */
    public function setDecision(int $agentUserId, Contact $contact, string $status, ?string $reason, User $decidedBy): AgentCaptureConsent
    {
        $row = $this->ensurePending((int) $contact->agency_id, $agentUserId, $contact->id);
        $row->status = $status;
        $row->reason = $status === AgentCaptureConsent::STATUS_OPTED_OUT ? $reason : null;
        $row->decided_at = now();
        $row->decided_by_user_id = $decidedBy->id;
        $row->save();

        // FICA defensibility — the decision is recorded immutably in the log too.
        Log::info('AT-136 capture consent decision', [
            'agency_id'     => $contact->agency_id,
            'agent_user_id' => $agentUserId,
            'contact_id'    => $contact->id,
            'status'        => $status,
            'reason'        => $row->reason,
            'decided_by'    => $decidedBy->id,
            'decided_at'    => now()->toIso8601String(),
        ]);

        // AT-168 Part B — opting IN releases every embargoed body for this
        // (agent, contact) instantly. Guarded: a release hiccup must never break
        // the consent decision itself. Lazy-resolved to avoid a service cycle.
        if ($status === AgentCaptureConsent::STATUS_OPTED_IN) {
            $this->releaseEmbargoedBodies((int) $contact->agency_id, $agentUserId, (int) $contact->id);
        }

        // AT-183 — opting OUT is a POPIA exclusion: retroactively PURGE the body content of
        // every already-archived WA message for this (agent, contact) — including bodies that
        // were captured (not just embargoed), which the daily embargo purge never reaches. The
        // envelope stays; an immutable audit event records that the purge happened. Guarded and
        // lazy-resolved so a purge hiccup never breaks the consent decision itself.
        if ($status === AgentCaptureConsent::STATUS_OPTED_OUT) {
            $this->purgeCapturedBodies((int) $contact->agency_id, $agentUserId, (int) $contact->id, $row->reason, $decidedBy->id);
        }

        return $row;
    }

    /**
     * AT-183 — purge already-archived WA bodies for this (agent, contact) on opt-out.
     * Lazy-resolved + fully guarded — a purge failure must never roll back or block the
     * consent decision (the opt-out declaration itself is the priority record).
     */
    private function purgeCapturedBodies(int $agencyId, int $agentUserId, int $contactId, ?string $declaration, ?int $actorUserId): void
    {
        try {
            $purged = app(WaCapturePurgeService::class)
                ->purgeForAgentContact($agencyId, $agentUserId, $contactId, $declaration, $actorUserId);
            if ($purged > 0) {
                Log::info('AT-183 WA bodies purged on capture opt-out', [
                    'agency_id' => $agencyId, 'agent_user_id' => $agentUserId,
                    'contact_id' => $contactId, 'purged' => $purged,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('AT-183 WA opt-out purge failed (non-fatal — opt-out still recorded)', [
                'agency_id' => $agencyId, 'agent_user_id' => $agentUserId,
                'contact_id' => $contactId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * AT-168 Part B — release embargoed WhatsApp bodies for this (agent, contact)
     * on opt-in. Lazy-resolved (WaEmbargoReleaseService depends on THIS service,
     * so constructor injection would cycle) and fully guarded — a release failure
     * must never roll back or block the consent decision.
     */
    private function releaseEmbargoedBodies(int $agencyId, int $agentUserId, int $contactId): void
    {
        try {
            $tally = app(WaEmbargoReleaseService::class)
                ->releaseForAgentContact($agencyId, $agentUserId, $contactId);
            if (($tally['released'] ?? 0) > 0 || ($tally['recovered'] ?? 0) > 0) {
                Log::info('AT-168 embargoed bodies released on opt-in', array_merge($tally, [
                    'agency_id' => $agencyId, 'agent_user_id' => $agentUserId, 'contact_id' => $contactId,
                ]));
            }
        } catch (\Throwable $e) {
            Log::warning('AT-168 embargo release on opt-in failed (non-fatal)', [
                'agency_id' => $agencyId, 'agent_user_id' => $agentUserId,
                'contact_id' => $contactId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin/CO flags a contact they judge to be BUSINESS for the agent to opt-in.
     * Sees, does NOT override (the agent keeps the decision). Recorded.
     */
    public function flagForOptIn(AgentCaptureConsent $row, User $admin, ?string $note): void
    {
        $row->update([
            'admin_flagged'         => true,
            'admin_flag_note'       => $note,
            'admin_flag_by_user_id' => $admin->id,
            'admin_flagged_at'      => now(),
        ]);

        Log::info('AT-136 admin flagged contact for opt-in (business call)', [
            'agency_id'     => $row->agency_id,
            'agent_user_id' => $row->agent_user_id,
            'contact_id'    => $row->contact_id,
            'flagged_by'    => $admin->id,
            'note'          => $note,
        ]);
    }
}
