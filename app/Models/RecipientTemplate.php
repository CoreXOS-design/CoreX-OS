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

    private function resolveSlotDisplayName(SignatureRequest $selfRecipient, string $key, string $label, array $binding): string
    {
        $type = $binding['type'] ?? null;

        if ($type === 'self') {
            return (string) $selfRecipient->signer_name;
        }

        if ($type === 'contact') {
            $contact = \App\Models\Contact::withoutGlobalScopes()->find($binding['contact_id'] ?? null);
            if ($contact === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            return (string) ($contact->entity_name ?: $contact->full_name);
        }

        if ($type === 'recipient') {
            $recipient = SignatureRequest::where('signature_template_id', $selfRecipient->signature_template_id)
                ->where('recipient_local_key', $binding['recipient_local_key'] ?? null)
                ->first();
            if ($recipient === null) {
                throw DanglingSlotBindingException::forSlot($key, $label);
            }

            return (string) $recipient->signer_name;
        }

        throw DanglingSlotBindingException::forSlot($key, $label);
    }

    private static function displayNameFromRecipientArray(array $recipient): string
    {
        $full = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));

        return $full !== '' ? $full : (string) ($recipient['name'] ?? '');
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

            return (string) ($contact->entity_name ?: $contact->full_name);
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

            return self::displayNameFromRecipientArray($match);
        }

        throw DanglingSlotBindingException::forSlot($key, $label);
    }
}
