<?php

namespace App\Models;

use App\Exceptions\DanglingSlotBindingException;
use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Johan, 2026-08-24 — the recipient template library. agency_id NULL =
 * CoreX-standard default (seeded by RecipientTemplateSeeder, e.g. Elize's
 * standard wordings). A row with agency_id set OVERRIDES that role+key for
 * the agency — scoped strictly, never visible to another agency.
 *
 * Deliberately does NOT use BelongsToAgency, unlike the clause library
 * (Clause) this is authored like — see the migration's docblock for why
 * that combination (a hard global scope + is_global) would make the
 * NULL-agency system defaults invisible to everyone. Resolution is explicit
 * in {@see resolveFor()}, mirroring
 * App\Models\Docuperfect\DataDictionaryEntry.
 *
 * party_slots shape: [{"key": "deceased", "label": "Deceased"},
 * {"key": "executor", "label": "Executor"}, ...] — just the names a
 * template's sentence needs filled in, each bound to a Contact or a
 * recipient-screen row at compose time. NO "kind" here (Elize's rule,
 * 2026-08-24): whether a bound party displays-only or signs is never a
 * template-authoring decision — it is computed uniformly, per recipient,
 * from that recipient's own is_deceased/is_proxy flags (see SignatureRequest
 * ::isSigningParticipant()). Every party always displays with full details;
 * everyone signs unless deceased or collapsed by a proxy flag elsewhere in
 * their group. A template just supplies the sentence and the slot names —
 * the same two slots (say, "executor") work identically whether that
 * particular executor ends up signing or not.
 */
class RecipientTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'role_token',
        'key',
        'name',
        'text_template',
        'party_slots',
        'is_default',
    ];

    protected $casts = [
        'party_slots' => 'array',
        'is_default' => 'boolean',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function scopeStandard(Builder $query): Builder
    {
        return $query->whereNull('agency_id');
    }

    /**
     * The templates available for a given agency + role token: the agency's
     * own templates for that role, plus CoreX's NULL-agency defaults for it.
     * Used by the picker on the recipient screen — the agent chooses one of
     * these, or "stays normal" (binds nothing).
     */
    public static function availableFor(?int $agencyId, string $roleToken): \Illuminate\Support\Collection
    {
        return static::query()
            ->where(function (Builder $q) use ($roleToken) {
                $q->where('role_token', $roleToken)->orWhere('role_token', 'any');
            })
            ->where(function (Builder $q) use ($agencyId) {
                $q->whereNull('agency_id');
                if ($agencyId !== null) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderByDesc('is_default')
            ->get();
    }

    /**
     * A single named template by its key — agency's own override wins, else
     * CoreX's NULL-agency default, else null (caller's own safe fallback
     * applies; an unmatched key is not an error).
     */
    public static function resolveFor(?int $agencyId, string $roleToken, string $key): ?self
    {
        $pick = static function (?int $scopeAgencyId) use ($roleToken, $key): ?self {
            return static::query()
                ->where('role_token', $roleToken)
                ->where('key', $key)
                ->when(
                    $scopeAgencyId === null,
                    fn (Builder $b) => $b->whereNull('agency_id'),
                    fn (Builder $b) => $b->where('agency_id', $scopeAgencyId),
                )
                ->first();
        };

        if ($agencyId !== null) {
            $override = $pick($agencyId);
            if ($override !== null) {
                return $override;
            }
        }

        return $pick(null);
    }

    /** Substitute named tokens into a template string; collapse a dangling "()" left by a missing token. */
    public static function substitute(string $template, array $tokens): string
    {
        $out = strtr($template, $tokens);
        $out = preg_replace('/\(\s*\)/', '', $out);

        return trim(preg_replace('/\s{2,}/', ' ', $out));
    }

    /**
     * Resolve this template's text against a concrete set of slot bindings —
     * the ONE-TIME computation that becomes a recipient's frozen
     * party_clause_text at generation time (mirrors
     * RoleBlockExpansionService::composeEntityPartyText()'s "resolve once,
     * caller snapshots it" contract exactly).
     *
     * $slotBindings shape, keyed by this template's own party_slots keys:
     *   ['deceased' => ['type' => 'self']]                                    — the recipient IS this slot
     *   ['entity'   => ['type' => 'contact', 'contact_id' => 91]]             — a named-only Contact (never a recipient, never signs)
     *   ['executor' => ['type' => 'recipient', 'recipient_local_key' => '…']] — another recipient on this SAME document — a signing link in the chain
     *
     * $selfRecipient is the recipient this template is attached to (Piet) —
     * resolves a 'self' binding without a redundant lookup.
     *
     * @throws DanglingSlotBindingException if a bound recipient/contact no
     *   longer resolves — the recipient was removed or moved to a different
     *   role after binding, before finalisation. Never silently renders a
     *   blank or half-built sentence.
     */
    public function resolveBoundText(SignatureRequest $selfRecipient, array $slotBindings): string
    {
        return $this->resolveBoundTextTokens(
            $slotBindings,
            fn (string $key, string $label, array $binding) => $this->resolveSlotDisplayName($selfRecipient, $key, $label, $binding)
        );
    }

    /**
     * cc2, 2026-08-26 (cc4's stranger-rebind finding, corrected twice the
     * same night). First version only ever validated slot_bindings[0] — the
     * "deceased" slot on the one template that exists. cc4's real
     * reproduction (document 959, signature_request 1578) proved that
     * wrong: "deceased" was bound to self (Signature Request 1578 IS the
     * deceased party's own row), which the first version treated as
     * "nothing to check" — because $partyContactId resolved to
     * $selfRecipient's own id, matching the self-exemption — and NEVER
     * looked at "executor", the slot actually naming the stranger. Checking
     * position 0 "because it happens to be first" was the bug Johan named
     * directly.
     *
     * The party_slots declared order IS the template's own chain of
     * custody — "Late Estate of {deceased} herein represented by
     * {executor}" — slot i is represented by slot i+1, for every adjacent
     * pair, regardless of which one $selfRecipient itself occupies. A slot
     * bound to type=self needs no existence check ($selfRecipient obviously
     * exists) — but that says NOTHING about whether the OTHER slot's bound
     * contact legitimately represents it, and that pair is checked here
     * exactly the same as any other. This validates the CLAUSE's declared
     * relationships, not "is $selfRecipient legitimate" — the row asking
     * doesn't matter; the chain it's part of has to be real all the way
     * through, not just at the one slot that happens to be this row's own.
     *
     * @throws \App\Exceptions\DanglingSlotBindingException
     * @throws \App\Exceptions\PartyClauseSignerMismatchException
     */
    public function assertChainIsLegitimate(SignatureRequest $selfRecipient, array $slotBindings): void
    {
        $slots = $this->party_slots ?? [];
        if (count($slots) < 2) {
            return; // a single-slot template names nobody as anybody's representative.
        }

        $resolved = [];
        foreach ($slots as $slot) {
            $key = $slot['key'] ?? null;
            $label = $slot['label'] ?? $key;
            if ($key === null) {
                continue;
            }
            $binding = $slotBindings[$key] ?? null;
            if ($binding === null) {
                throw DanglingSlotBindingException::forSlot($key, (string) $label);
            }
            $resolved[$key] = $this->resolveSlotContactId($selfRecipient, $key, (string) $label, $binding);
        }

        for ($i = 0; $i < count($slots) - 1; $i++) {
            $partyId = $resolved[$slots[$i]['key'] ?? null] ?? null;
            $repId = $resolved[$slots[$i + 1]['key'] ?? null] ?? null;

            if ($partyId === null || $repId === null || $partyId === $repId) {
                continue; // nothing to verify, or the same identity claiming no representation at all.
            }

            SignatureRequest::assertSignerIsCurrentRepresentative($repId, $partyId);
        }
    }

    /**
     * Johan, 2026-08-26 — "picking someone in 'Replace this party' CREATES
     * the relationship." assertChainIsLegitimate() demanded a
     * contact_representatives row that no screen in the product could ever
     * create — Johan marked Anine deceased, picked Elize, and was refused
     * for exactly that reason (Elize genuinely does hold executorship; there
     * was just nowhere to record it). The agent binding a slot here IS the
     * real-world act of asserting that relationship — a letter of
     * executorship, a POA, a director appointment — so this records it,
     * using the SAME adjacent-slot-pair walk assertChainIsLegitimate() uses
     * (never a second way of deciding who represents whom). The caller MUST
     * still run assertChainIsLegitimate() immediately after this — the
     * ordinary identity check keeps running, it now just passes because the
     * record genuinely exists, not because it was skipped.
     *
     * firstOrCreate is deliberate: an already-legitimate pair (relinking the
     * same executor a second time, or any pair that already has a real
     * link) creates nothing new and asserted_by_user_id on an existing row
     * is left untouched — this only ever fills a gap, never overwrites who
     * originally asserted a relationship that's already on file.
     *
     * Also persists represented_contact_id onto the REPRESENTATIVE'S own
     * SignatureRequest row for each pair (found separately, see below) — the
     * row that actually opens a link and signs, not just $selfRecipient's.
     */
    public function ensureChainRelationshipsExist(SignatureRequest $selfRecipient, array $slotBindings, ?int $assertingUserId): void
    {
        $slots = $this->party_slots ?? [];
        if (count($slots) < 2) {
            return;
        }

        $resolved = [];
        foreach ($slots as $slot) {
            $key = $slot['key'] ?? null;
            $label = $slot['label'] ?? $key;
            if ($key === null) {
                continue;
            }
            $binding = $slotBindings[$key] ?? null;
            if ($binding === null) {
                continue; // dangling — assertChainIsLegitimate() (called right after) reports this properly.
            }
            try {
                $resolved[$key] = $this->resolveSlotContactId($selfRecipient, $key, (string) $label, $binding);
            } catch (DanglingSlotBindingException) {
                $resolved[$key] = null;
            }
        }

        for ($i = 0; $i < count($slots) - 1; $i++) {
            $partyId = $resolved[$slots[$i]['key'] ?? null] ?? null;
            $repId = $resolved[$slots[$i + 1]['key'] ?? null] ?? null;
            $capacity = $slots[$i + 1]['label'] ?? null;

            if ($partyId === null || $repId === null || $partyId === $repId) {
                continue;
            }

            if (! \App\Models\Contact::query()->whereKey($partyId)->exists()
                || ! \App\Models\Contact::query()->whereKey($repId)->exists()) {
                continue; // dangling contact id — nothing genuine to record.
            }

            \App\Models\ContactRepresentative::firstOrCreate(
                ['entity_contact_id' => $partyId, 'representative_contact_id' => $repId],
                ['capacity' => $capacity, 'asserted_by_user_id' => $assertingUserId]
            );

            // Pre-existing gap, found while proving "revoke after send still
            // refuses" for Johan's real case: resolveChainBindings() in
            // ESignWizardController only ever persists represented_contact_id
            // onto $selfRecipient's OWN row (the deceased party's row here) —
            // never onto the representative's OWN SignatureRequest row, which
            // is the one that actually opens a link and signs. Without this,
            // SignatureRequest::authorityRevoked() re-checks against a NULL
            // represented_contact_id and never refuses anyone — proven false
            // on document 959/signature_request 1578, a case that predates
            // tonight. Same document, found by contact identity (never by
            // name) — the representative may be $selfRecipient itself (the
            // deceased row, harmless no-op) or a sibling recipient row.
            \App\Models\Docuperfect\SignatureRequest::where('signature_template_id', $selfRecipient->signature_template_id)
                ->where('contact_id', $repId)
                ->update(['represented_contact_id' => $partyId]);
        }
    }

    /**
     * Who $selfRecipient represents, for persisting onto
     * SignatureRequest::represented_contact_id (so
     * SignatureRequest::isSigningBlocked() has something to re-check at
     * sign time — see that column's migration). Finds WHICH slot
     * $selfRecipient itself occupies (by identity, not position — the same
     * principle assertChainIsLegitimate() applies) and returns the
     * PRECEDING slot's contact — the party they stand in for. Null when
     * $selfRecipient occupies the first slot (they ARE the party, not a
     * representative of one) or isn't found in the binding at all.
     */
    public function resolveRepresentedContactIdFor(SignatureRequest $selfRecipient, array $slotBindings): ?int
    {
        $slots = $this->party_slots ?? [];
        $resolved = [];
        foreach ($slots as $slot) {
            $key = $slot['key'] ?? null;
            $label = $slot['label'] ?? $key;
            if ($key === null) {
                continue;
            }
            $binding = $slotBindings[$key] ?? null;
            if ($binding === null) {
                continue;
            }
            try {
                $resolved[$key] = $this->resolveSlotContactId($selfRecipient, $key, (string) $label, $binding);
            } catch (DanglingSlotBindingException) {
                $resolved[$key] = null;
            }
        }

        foreach ($slots as $i => $slot) {
            $key = $slot['key'] ?? null;
            if ($key !== null && ($resolved[$key] ?? null) === $selfRecipient->contact_id) {
                if ($i === 0) {
                    return null;
                }
                $prevKey = $slots[$i - 1]['key'] ?? null;

                return $prevKey !== null ? ($resolved[$prevKey] ?? null) : null;
            }
        }

        return null;
    }

    private function resolveSlotContactId(SignatureRequest $selfRecipient, string $key, string $label, array $binding): ?int
    {
        $type = $binding['type'] ?? null;

        if ($type === 'self') {
            return $selfRecipient->contact_id;
        }

        if ($type === 'contact') {
            $contactId = $binding['contact_id'] ?? null;
            if ($contactId === null || ! \App\Models\Contact::withoutGlobalScopes()->where('id', $contactId)->exists()) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            return (int) $contactId;
        }

        if ($type === 'recipient') {
            $recipient = SignatureRequest::where('signature_template_id', $selfRecipient->signature_template_id)
                ->where('recipient_local_key', $binding['recipient_local_key'] ?? null)
                ->first();
            if ($recipient === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            return $recipient->contact_id;
        }

        throw DanglingSlotBindingException::forSlot($key, $label);
    }

    /**
     * Johan, 2026-08-24 (fault B) — the SAME resolution this class already
     * did at generation time (resolveBoundText, frozen onto
     * SignatureRequest::party_clause_text), run instead against the wizard's
     * in-memory recipients array. Before generation there is no
     * SignatureRequest row for anyone yet — the wizard preview, step 4, and
     * fill & review all render from step_data — so this is what "resolve
     * once, render everywhere" actually resolves against pre-generation.
     * Same party_slots, same text_template, same substitute() — one
     * algorithm, the only difference is which store a slot's display name
     * comes from (this array vs a persisted SignatureRequest/Contact row).
     *
     * @throws DanglingSlotBindingException if a bound recipient/contact
     *   doesn't resolve yet — callers rendering a LIVE preview should catch
     *   this and fall back to the raw name (the agent may still be
     *   mid-edit); only generation-time resolution should let it propagate.
     */
    public function resolveBoundTextFromArray(array $selfRecipient, array $allRecipients, array $slotBindings): string
    {
        return $this->resolveBoundTextTokens(
            $slotBindings,
            fn (string $key, string $label, array $binding) => $this->resolveSlotDisplayNameFromArray($selfRecipient, $allRecipients, $key, $label, $binding)
        );
    }

    private function resolveBoundTextTokens(array $slotBindings, \Closure $resolveSlot): string
    {
        $tokens = [];

        foreach ($this->party_slots ?? [] as $slot) {
            $key = $slot['key'];
            $label = $slot['label'] ?? $key;
            $binding = $slotBindings[$key] ?? null;

            if ($binding === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            $tokens['{' . $key . '}'] = $resolveSlot($key, $label, $binding);
        }

        return self::substitute($this->text_template, $tokens);
    }

    /**
     * Fault 2 (Johan, 2026-08-24) — "Elize's rule, which is settled: every
     * party displays with full details — name, surname, ID... regardless of
     * who signs." A slot resolves to whoever is bound to it (self/recipient/
     * contact), and every one of those is "a party" the rule applies to —
     * this is the ONE place a slot's display name is built, for every slot
     * type, so the rule is enforced uniformly rather than only where a bug
     * report happened to point. Guarded on a non-empty ID: a bound Contact
     * or in-progress recipient with no ID on file renders exactly as before
     * (matches substitute()'s own empty-"()" collapse), so this can never
     * introduce a dangling "()" or an empty ID label.
     *
     * Public (Johan, 2026-08-25) — Contact::idNumberSuffix() delegates here
     * (call with $name = '') rather than carrying its own parallel copy of
     * this exact rule. Two independent bodies that happened to compute the
     * same string is agreement by convergence, not a shared rule — nothing
     * stopped a future edit to one from silently drifting from the other.
     * One formatting rule, one place it lives.
     */
    public static function withIdSuffix(string $name, ?string $idNumber): string
    {
        $id = trim((string) $idNumber);

        return $id !== '' ? "{$name} (ID: {$id})" : $name;
    }

    /** Same shape as withIdSuffix() but for a COMPANY's registration number, never a person's ID. */
    public static function withRegSuffix(string $name, ?string $registrationNumber): string
    {
        $reg = trim((string) $registrationNumber);

        return $reg !== '' ? "{$name} (Reg: {$reg})" : $name;
    }

    private function resolveSlotDisplayName(SignatureRequest $selfRecipient, string $key, string $label, array $binding): string
    {
        $type = $binding['type'] ?? null;

        if ($type === 'self') {
            return self::withIdSuffix((string) $selfRecipient->signer_name, $selfRecipient->signer_id_number);
        }

        if ($type === 'contact') {
            $contact = \App\Models\Contact::withoutGlobalScopes()->find($binding['contact_id'] ?? null);
            if ($contact === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            // A company has no personal ID number — entity_reg_no is a
            // separate concept, already handled by the composed-clause
            // representation elsewhere; only a natural-person contact gets
            // the ID suffix here.
            return self::withIdSuffix(
                (string) ($contact->entity_name ?: $contact->full_name),
                $contact->isEntity() ? null : $contact->id_number
            );
        }

        if ($type === 'recipient') {
            $recipient = SignatureRequest::where('signature_template_id', $selfRecipient->signature_template_id)
                ->where('recipient_local_key', $binding['recipient_local_key'] ?? null)
                ->first();
            if ($recipient === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            $repText = self::withIdSuffix((string) $recipient->signer_name, $recipient->signer_id_number);

            // Johan, 2026-08-26 — the three-part chain: "late estate of piet
            // (id) herein represented by exec pty ltd (reg) represented by
            // Koos (id)." A supplier-sourced representative (frozen at
            // generation time onto THIS bound recipient's own row — see
            // 2026_08_29_000008) inserts the COMPANY between the slot's own
            // label and the person actually signing. A contact-sourced
            // representative has no company in the middle — collapses to
            // deceased -> person exactly as before.
            $firmName = trim((string) $recipient->supplier_firm_name);
            if ($firmName !== '') {
                return self::withRegSuffix($firmName, $recipient->supplier_firm_registration_number) . " represented by {$repText}";
            }

            return $repText;
        }

        throw DanglingSlotBindingException::forSlot($key, $label);
    }

    private static function displayNameFromRecipientArray(array $recipient): string
    {
        $full = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
        $full = $full !== '' ? $full : (string) ($recipient['name'] ?? '');

        return self::withIdSuffix($full, $recipient['id_number'] ?? null);
    }

    private function resolveSlotDisplayNameFromArray(array $selfRecipient, array $allRecipients, string $key, string $label, array $binding): string
    {
        $type = $binding['type'] ?? null;

        if ($type === 'self') {
            return self::displayNameFromRecipientArray($selfRecipient);
        }

        if ($type === 'contact') {
            $contact = \App\Models\Contact::withoutGlobalScopes()->find($binding['contact_id'] ?? null);
            if ($contact === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            return self::withIdSuffix(
                (string) ($contact->entity_name ?: $contact->full_name),
                $contact->isEntity() ? null : $contact->id_number
            );
        }

        if ($type === 'recipient') {
            $localKey = $binding['recipient_local_key'] ?? null;
            $match = null;
            foreach ($allRecipients as $r) {
                if (($r['_recipient_local_key'] ?? null) === $localKey) {
                    $match = $r;
                    break;
                }
            }
            if ($match === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            $repText = self::displayNameFromRecipientArray($match);

            // Mirrors resolveSlotDisplayName()'s type:'recipient' branch
            // above — must never drift from it (Johan's standing rule for
            // this preview/generation pair). Reads the wizard array's own
            // _supplier_firm_name/_supplier_firm_registration_number, the
            // SAME fields the generation-time path freezes onto the row
            // from at send.
            $firmName = trim((string) ($match['_supplier_firm_name'] ?? ''));
            if ($firmName !== '') {
                return self::withRegSuffix($firmName, $match['_supplier_firm_registration_number'] ?? null) . " represented by {$repText}";
            }

            return $repText;
        }

        throw DanglingSlotBindingException::forSlot($key, $label);
    }
}
