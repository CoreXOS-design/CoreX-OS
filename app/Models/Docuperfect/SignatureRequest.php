<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureRequest extends Model
{
    use SoftDeletes;

    protected $table = 'signature_requests';

    protected $fillable = [
        'signature_template_id',
        'party_role',
        'role_index',
        'signing_order',
        'signing_group',
        'signer_name',
        'signer_caption',
        'party_clause_text',
        'supplier_firm_name',
        'supplier_firm_registration_number',
        'is_deceased',
        'is_proxy',
        'recipient_local_key',
        'recipient_template_id',
        'slot_bindings',
        'signer_email',
        'signer_id_number',
        'token',
        'token_expires_at',
        'status',
        'sent_at',
        'invite_send_status',
        'invite_send_error',
        'completion_send_status',
        'completion_send_error',
        'viewed_at',
        'completed_at',
        'reminder_sent_at',
        'reminder_count',
        'ip_address',
        'user_agent',
        'sent_by',
        'message',
        'signing_method',
        'wet_ink_upload_path',
        'wet_ink_status',
        'wet_ink_rejection_note',
        'reviewed_by',
        'reviewed_at',
        'returned_notes',
        'team_alerted_at',
        'authorised_by',
        'authorised_at',
        'fica_required',
        'contact_id',
        'represented_contact_id',
        'fica_submission_id',
    ];

    protected $casts = [
        'role_index' => 'integer',
        // HD-5 — NULL is meaningful: "a group of one" (checkpoints on its own, today's behaviour).
        'signing_group' => 'integer',
        'token_expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'team_alerted_at' => 'datetime',
        'authorised_at' => 'datetime',
        'fica_required' => 'boolean',
        'is_deceased' => 'boolean',
        'is_proxy' => 'boolean',
        'slot_bindings' => 'array',
    ];

    // Status constants
    const STATUS_WAITING = 'waiting';
    const STATUS_PENDING = 'pending';
    const STATUS_VIEWED = 'viewed';
    const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DECLINED = 'declined';
    const STATUS_DEFERRED = 'deferred';
    // Displayed on the document, never signs — deceased, or collapsed out by a
    // proxy elsewhere in their group. Never entered via the normal
    // waiting->pending->...->completed flow; set once at generation time.
    const STATUS_NOT_REQUIRED = 'not_required';

    const NON_SIGNING_REASON_DECEASED = 'deceased';
    const NON_SIGNING_REASON_PROXY_COLLAPSED = 'proxy_collapsed';

    // Wet ink status constants
    const WET_INK_PENDING_UPLOAD = 'pending_upload';
    const WET_INK_UPLOADED_PENDING_REVIEW = 'uploaded_pending_review';
    const WET_INK_APPROVED = 'approved';
    const WET_INK_REJECTED = 'rejected';

    /**
     * THE single predicate (Elize's rule via Johan, 2026-08-24): does this
     * row need to sign? Every party always displays; this only ever gates
     * whether an invitation is ever sent. Two reasons a party doesn't sign,
     * checked in order — deceased is absolute; proxy is relative to the
     * GROUP (every other same-role party on this same document):
     *
     *   1. This row itself is marked deceased — never signs, full stop.
     *   2. ANY row sharing this document + party_role is marked proxy, and
     *      this row is NOT that one — the proxy signs, everyone else in
     *      the group does not (they still display).
     *
     * This is the ONLY place this decision is made. SignatureService::
     * sendSigningRequest() — the single choke point every invitation email
     * flows through, regardless of which caller reaches it — checks this
     * before ever sending, never a separate check re-derived per caller.
     * Both is_deceased and is_proxy are frozen at generation time (plain
     * columns on this row, not looked up from live Contact/representative
     * state), so this predicate's answer never changes after the fact.
     */
    public function isSigningParticipant(): bool
    {
        return $this->nonSigningReason() === null;
    }

    /** Why this row doesn't sign, or null if it does. See {@see isSigningParticipant()}. */
    public function nonSigningReason(): ?string
    {
        if ($this->is_deceased) {
            return self::NON_SIGNING_REASON_DECEASED;
        }

        if ($this->is_proxy) {
            return null; // the proxy itself always signs
        }

        $groupHasProxy = static::query()
            ->where('signature_template_id', $this->signature_template_id)
            ->where('party_role', $this->party_role)
            ->where('is_proxy', true)
            ->where('id', '!=', $this->id)
            ->exists();

        return $groupHasProxy ? self::NON_SIGNING_REASON_PROXY_COLLAPSED : null;
    }

    /**
     * THE single guard (cc2, 2026-08-25 — Flow 409, corrected twice the same
     * night). First correction, by cc4's reproduction (row 1506): the
     * original version compared the signer's NAME against clause TEXT — a
     * substring match ("Chris" inside "Christopher") let a wrong person
     * through. Fixed to compare Contact identity via the live
     * `contact_representatives` relationship.
     *
     * Second correction, by cc4's regression (Anna → Ben → Chris): checking
     * only the DIRECT link (Contact::representatives(), one hop) refused a
     * genuinely correct multi-hop chain — Chris is Anna's real, ultimate
     * signer via Ben, and a one-hop check can never see past Ben. "Who
     * represents this party" is not this guard's own question to answer a
     * second way; Contact::signingRepresentatives() is ALREADY the one place
     * that question is resolved correctly for the whole codebase (full
     * recursive chain, natural-person intermediaries included, proxy
     * collapse applied) — reused here directly rather than re-walked. If
     * this guard and the recompute in ESignWizardController each did their
     * own walk, that would be the exact two-implementations problem tonight
     * exists to close, one level down.
     *
     * No-ops when $representedContactId is null — a plain party with no
     * representative (the overwhelming majority of rows) never reaches
     * this at all.
     *
     * @throws \App\Exceptions\PartyClauseSignerMismatchException
     */
    public static function assertSignerIsCurrentRepresentative(int $signerContactId, ?int $representedContactId): void
    {
        if ($representedContactId === null) {
            return;
        }

        $party = \App\Models\Contact::withoutGlobalScopes()->find($representedContactId);
        if (! $party) {
            return; // dangling reference — nothing to check identity against.
        }

        $currentSignerIds = $party->signingRepresentatives()->pluck('id');
        if (! $currentSignerIds->contains($signerContactId)) {
            $signer = \App\Models\Contact::withoutGlobalScopes()->find($signerContactId);
            throw \App\Exceptions\PartyClauseSignerMismatchException::forParty(
                $signer?->full_name ?? "contact #{$signerContactId}",
                ($party->entity_name ?: $party->full_name) ?: "contact #{$representedContactId}",
            );
        }
    }

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function contact()
    {
        return $this->belongsTo(\App\Models\Contact::class);
    }

    public function ficaSubmission()
    {
        return $this->belongsTo(\App\Models\FicaSubmission::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function authoriser()
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function inspections()
    {
        return $this->hasMany(WetInkInspection::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function sectionAcceptances()
    {
        return $this->hasMany(SectionAcceptance::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_EXPIRED, self::STATUS_DECLINED, self::STATUS_COMPLETED]);
    }

    public function scopeNeedsReminder($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->whereNotNull('sent_at');
    }

    public function scopeExpirable($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_VIEWED, self::STATUS_PARTIALLY_SIGNED])
            ->where('token_expires_at', '<', now());
    }

    // --- Helpers ---

    public function isExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }

    /**
     * Track C (HD-10) — may this request accept a signature RIGHT NOW?
     *
     * Two independent clocks stop the pen: the 14-day link TTL (`isExpired()`) and the ceremony's
     * LEGAL deadline (`template->isLapsed()`). A mark blocked by either is worthless, so the signing
     * pipeline gates on this, not on `isExpired()` alone. `isExpired()` is left as pure link-TTL —
     * its other callers (reminders, sales-doc flow) must not start treating a lapse as a dead link.
     *
     * cc6's public-link audit, escalated by Johan 2026-08-24 — a cancelled ceremony was NOT one of
     * the two clocks above, so it fell through every one of this method's ~26 callers (every write
     * action in SigningController — verify, consent, capture, saveFields, completeWeb, complete,
     * ...) with nothing blocking it: a recipient could be walked through ID verification and
     * consent, and actually sign a document the agency had already withdrawn. One check here closes
     * every one of those call sites at once — this is deliberately NOT re-checked per-method.
     */
    public function isSigningBlocked(): bool
    {
        return $this->isExpired()
            || (bool) $this->template?->isLapsed()
            || $this->template?->status === SignatureTemplate::STATUS_CANCELLED
            || $this->authorityRevoked();
    }

    /**
     * cc4's finding, cc2 2026-08-26 — "a revoked representative can still
     * sign." Every guard tonight ran at CREATION; nothing re-checked the
     * relationship at the moment of SIGNING — the window between send and
     * sign is exactly where a real revocation (a family dispute, a
     * cancelled power of attorney) happens. Re-verifies live, every time
     * this link is opened or acted on (isSigningBlocked() is already the
     * one check every write action in SigningController gates on — this
     * rides that same single choke point, not a new one), using the SAME
     * identity resolution the create-time guard and the rebind both already
     * use: is the signer still, right now, a genuine representative of the
     * party recorded when this row was created or last rebound?
     *
     * Only ever true for a row that was claiming to represent someone
     * (represented_contact_id is null for the overwhelming majority of
     * rows — an ordinary party signing for themselves — and this returns
     * false immediately for all of them). A legitimate substitution (the
     * party now represented by someone else) does not strand the
     * document: "Replace this party" rebinds contact_id AND
     * represented_contact_id together to the new, current, correct pair —
     * this check only ever flags the STALE link that was never rebound,
     * never a document that's been properly corrected.
     */
    public function authorityRevoked(): bool
    {
        if ($this->represented_contact_id === null || $this->contact_id === null) {
            return false;
        }

        try {
            self::assertSignerIsCurrentRepresentative($this->contact_id, $this->represented_contact_id);
        } catch (\App\Exceptions\PartyClauseSignerMismatchException) {
            return true;
        }

        return false;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * AT-324/AT-325 — the ONE canonical per-recipient key.
     *
     * N same-role recipients are stored as N SignatureRequest rows sharing the
     * base party_role ("seller") but carrying a distinct role_index (1..N). Every
     * OTHER surface — signing_order_json, parties_json, partyProgress(),
     * signed_initials — identifies them by the composite key ("seller",
     * "seller_2", "seller_3", …: bare = index 1). This method is the single place
     * that maps a request back to that key, so a completed 2nd-same-role recipient
     * is never misread as the next signer. Consumers comparing a request against
     * the signing order MUST key through this, never raw party_role.
     */
    public function canonicalPartyKey(): string
    {
        $index = (int) ($this->role_index ?? 1);

        return $index > 1
            ? $this->party_role . '_' . $index
            : (string) $this->party_role;
    }

    public function isWetInk(): bool
    {
        return $this->signing_method === 'wet_ink';
    }

    public function isDeferred(): bool
    {
        return $this->status === self::STATUS_DEFERRED;
    }

    public function daysUntilExpiry(): int
    {
        if (!$this->token_expires_at) {
            return 0;
        }
        return max(0, (int) now()->diffInDays($this->token_expires_at, false));
    }

    public function daysSinceSent(): int
    {
        if (!$this->sent_at) {
            return 0;
        }
        return (int) $this->sent_at->diffInDays(now());
    }

    // ── Recipient Loop Engine B1 — indexed identity ──

    /**
     * Indexed identity token in `{party_role}_{role_index}` form. Used by
     * downstream renderer / signing view layers to address a specific
     * recipient instance distinct from siblings sharing the same role.
     *
     *   party_role=seller, role_index=2 → 'seller_2'
     *   party_role=agent,  role_index=1 → 'agent_1'
     *
     * Note: pre-B1 the suffixed form was stored ON party_role itself.
     * The B1 migration split it into the dedicated column; this accessor
     * reconstructs the legacy shape when callers need it for matching
     * against template metadata that still uses the suffixed form.
     */
    public function getRoleIdentityAttribute(): string
    {
        return $this->party_role . '_' . ((int) ($this->role_index ?? 1));
    }

    /**
     * Johan, 2026-08-27 (Anine/Andre/Piet flow — signature blocks showing
     * the same place/time for two different sellers) — the identity
     * ACTUALLY stamped onto DOM markers (data-recipient-identity) by
     * RoleBlockExpansionService::expandAttestationBlocksPerRecipient() and
     * expandViaContract() is a POSITION count among this role's NON-DECEASED
     * siblings, in role_index order — not the raw role_index itself. The two
     * agree only when no same-role sibling ahead of this one is deceased/
     * excluded. The moment one is (a deceased seller at role_index 1, two
     * real signers at role_index 2 and 3), the DOM compacts them to
     * "seller_1"/"seller_2" while role_identity/canonicalPartyKey still say
     * "seller_2"/"seller_3" — and by coincidence of the exact offset,
     * role_index=2's OWN role_identity ("seller_2") collides with role_index=
     * 3's DOM position ("seller_2" too), so the FIRST signer's own
     * currentRoleIdentity looks like it names the SECOND signer's marker.
     * Anything that must match a signer against a DOM stamp — the
     * currentRoleIdentity sent to a signing view, viewer-editability
     * stamping, per-field write authorisation, ink/ceremony ownership —
     * MUST use THIS, not role_identity/canonicalPartyKey (which stay
     * correct for everything keyed independently of the DOM: signing order,
     * parties_json, partyProgress, signed_initials).
     */
    public function attestationIdentity(): string
    {
        if ((bool) $this->is_deceased) {
            return $this->role_identity; // never stamped in the DOM; value is moot
        }
        $position = 1 + static::query()
            ->where('signature_template_id', $this->signature_template_id)
            ->where('party_role', $this->party_role)
            ->where('is_deceased', false)
            ->where('role_index', '<', (int) ($this->role_index ?? 1))
            ->count();

        return $this->party_role . '_' . $position;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    public function scopeForRoleInstance($query, string $roleToken, int $index)
    {
        return $query->where('party_role', $roleToken)->where('role_index', $index);
    }
}
