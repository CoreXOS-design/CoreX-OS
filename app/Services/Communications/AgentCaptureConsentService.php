<?php

namespace App\Services\Communications;

use App\Models\Communications\AgentCaptureConsent;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
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

        return AgentCaptureConsent::create([
            'agency_id'     => $agencyId,
            'agent_user_id' => $agentUserId,
            'contact_id'    => $contactId,
            'status'        => AgentCaptureConsent::STATUS_PENDING,
        ]);
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
            }
            return $row;
        }

        return AgentCaptureConsent::create([
            'agency_id'          => $agencyId,
            'agent_user_id'      => $agentUserId,
            'contact_id'         => $contactId,
            'status'             => AgentCaptureConsent::STATUS_OPTED_IN,
            'decided_at'         => now(),
            'decided_by_user_id' => $agentUserId,
        ]);
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

        return $row;
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
