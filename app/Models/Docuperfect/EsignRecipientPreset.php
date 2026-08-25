<?php

namespace App\Models\Docuperfect;

use App\Exceptions\UnresolvableRepresentativeChainException;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESIGN recipient builder (Johan, 2026-08-15/16) — an agency's phrasing preset
 * for entity/company recipients, DEFINED on the E-Sign → Recipient Presets setup
 * screen and CONSUMED by ESignWizardController::expandEntityRecipients().
 *
 * Renders "{entity_name}, herein represented by {rep_name} ({capacity})" and a
 * signature caption "on behalf of {entity_name} ({capacity})" from the tokens.
 * A PROXY signer (representative with signs_as_proxy) renders with the distinct
 * proxy wording ("as duly authorised representative of {entity_name} …") when
 * the preset defines it, else falls back to the ordinary phrasing/caption.
 *
 * applies_to: 'entity' (only entity recipients) | 'all' (any recipient). The
 * agency picks a default (is_default) — one per agency, enforced at save.
 */
class EsignRecipientPreset extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'name',
        'applies_to',
        'is_system',
        'phrasing_template',
        'signature_caption',
        'proxy_phrasing_template',
        'proxy_signature_caption',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_system'  => 'boolean',
    ];

    public const APPLIES_TO = ['entity', 'all'];

    public const DEFAULT_PHRASING = '{entity_name}, herein represented by {rep_name} ({capacity})';
    public const DEFAULT_CAPTION  = 'on behalf of {entity_name} ({capacity})';

    // Proxy defaults — a proxy signs on the representative's behalf under a POA,
    // so it reads as a "duly authorised representative" rather than the entity's
    // own director/executor/trustee.
    public const DEFAULT_PROXY_PHRASING = '{entity_name}, herein represented by {rep_name}, duly authorised representative ({capacity})';
    public const DEFAULT_PROXY_CAPTION  = 'as duly authorised representative of {entity_name} ({capacity})';

    /**
     * The default preset for an agency, created on first use (idempotent). The
     * BelongsToAgency global scope + creating hook keep it agency-scoped; we set
     * agency_id explicitly so this is safe from any acting-agency ambiguity.
     */
    public static function defaultFor(int $agencyId): self
    {
        return static::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->first()
            ?? static::create([
                'agency_id'               => $agencyId,
                'name'                    => 'Company / entity representation',
                'applies_to'              => 'entity',
                'phrasing_template'       => self::DEFAULT_PHRASING,
                'signature_caption'       => self::DEFAULT_CAPTION,
                'proxy_phrasing_template' => self::DEFAULT_PROXY_PHRASING,
                'proxy_signature_caption' => self::DEFAULT_PROXY_CAPTION,
                'is_default'              => true,
            ]);
    }

    /**
     * The APPLICABLE preset for a recipient in this agency. Prefers a preset
     * whose applies_to fits the context (an 'entity' preset for entity
     * recipients), then the agency default, then auto-seeds one. $context is
     * 'entity' or 'all' (extendable to per-role later).
     */
    public static function resolveFor(int $agencyId, string $context = 'entity'): self
    {
        $base = static::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at');

        // 1. A default preset that applies to this context (or to 'all').
        $preset = (clone $base)->where('is_default', true)
            ->whereIn('applies_to', [$context, 'all'])
            ->orderByRaw("applies_to = ? DESC", [$context]) // exact-context default wins over 'all'
            ->first();
        if ($preset) {
            return $preset;
        }

        // 2. ANY preset that applies to this context (most-recent).
        $preset = (clone $base)->whereIn('applies_to', [$context, 'all'])
            ->orderByRaw("applies_to = ? DESC", [$context])
            ->orderByDesc('is_default')->orderByDesc('id')
            ->first();
        if ($preset) {
            return $preset;
        }

        // 3. Fall back to (and seed) the agency default.
        return static::defaultFor($agencyId);
    }

    /**
     * CoreX Standard presets — the shipped SA conveyancing phrasing library
     * (Johan, 2026-08-25). agency_id IS NULL is the deliberate "global row"
     * convention this codebase already uses (.ai/specs/multi-tenancy.md §2a,
     * same pattern as automation_rules): AgencyScope structurally filters
     * these OUT of every agency-scoped query, so an agency's own preset
     * CRUD — always agency_id-scoped — can never match, edit, or delete one.
     * is_system is the explicit label on top of that structural protection;
     * bypasses the scope deliberately (mirrors queryWithoutAgencyScope()),
     * since a NULL agency_id row is invisible to it by design, not a bug.
     *
     * Deliberately NOT wired into resolveFor()/defaultFor() — those resolve
     * an AGENCY's own active preset for live document generation and are
     * left untouched. This scope is for a setup screen (or any future
     * consumer) to list/copy-from the standard library on demand.
     */
    public function scopeCoreXStandard($query)
    {
        return $query->withoutGlobalScopes()->whereNull('agency_id')->where('is_system', true);
    }

    /**
     * Enforce a single default per agency: when this preset is (being) saved as
     * default, demote every other default in the same agency. Call after save.
     */
    public function enforceSingleDefault(): void
    {
        if (! $this->is_default) {
            return;
        }
        static::withoutGlobalScopes()
            ->where('agency_id', $this->agency_id)
            ->where('id', '!=', $this->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /** Substitute the four representation tokens into a template string. */
    /**
     * Johan, 2026-08-24 (fault 3, round 4) — the representative renders with
     * their own ID number, the SAME convention every natural-person party
     * already uses ("HA Pretorius (ID: 7004065141082)"), not the word
     * "(Representative)" — a role label that identifies nobody. That word
     * was {capacity}'s fallback when a rep's capacity is unset (the common
     * case today — capacity has a real UI, on the contact's own
     * Representatives section, but is frequently left blank) — conflating
     * "no capacity on file" with "no identity" was the actual bug. ID
     * belongs to {rep_name} now, not the capacity fallback.
     *
     * When a rep genuinely has no ID on file either: render the bare name,
     * nothing in brackets — never a label implying information that isn't
     * there (Johan's own instinct, matching RecipientTemplate::withIdSuffix()
     * — the equivalent choke point for the Late Estate clause).
     *
     * {capacity} now collapses to '' when unset (was 'Representative') — the
     * existing empty-"()" cleanup below removes it cleanly either way.
     *
     * {party_id_number} (Johan, 2026-08-25) — the PARTY's (not the
     * representative's) own ID suffix, e.g. for a Power-of-Attorney
     * grantor or a minor: "{entity_name}{party_id_number}, herein
     * represented by …". Self-contained like {rep_name}'s ID suffix — bakes
     * its own " (ID: x)" (leading space, parens, label) or resolves to ''
     * when the party has none on file, so a template never has to wrap it
     * in literal parens itself (that would leave a dangling "(ID: )" when
     * empty — exactly the empty-bracket bug class Johan flagged). Both this
     * and {rep_name}'s suffix now share ONE formatting rule —
     * Contact::idNumberSuffix() — rather than each re-implementing it;
     * see that method's docblock for why RoleBlockExpansionService's
     * separate document-body clause composer should call the same method.
     */
    public static function substitute(string $template, Contact $entity, Contact $rep, ?string $capacity): string
    {
        $out = strtr($template, [
            '{entity_name}'      => (string) ($entity->entity_name ?: $entity->full_name),
            '{rep_name}'         => (string) $rep->full_name . $rep->idNumberSuffix(),
            '{capacity}'         => (string) ($capacity ?: ''),
            '{entity_reg_no}'    => (string) ($entity->entity_reg_no ?? ''),
            '{party_id_number}'  => $entity->idNumberSuffix(),
        ]);

        // Collapse an empty "()" left by a missing capacity/reg-no token, and tidy spaces.
        $out = preg_replace('/\(\s*\)/', '', $out);

        return trim(preg_replace('/\s{2,}/', ' ', $out));
    }

    /**
     * Fault 3, round 5 (Johan, 2026-08-24) — "display and signing are not
     * being treated as separate questions... every representative is named,
     * regardless of whether a proxy exists." Names EVERY representative
     * passed in, joined "A, B and C" (comma between, "and" before the
     * last) — never collapses to one, unlike substitute()/{rep_name}, which
     * is deliberately single-slot and stays that way for its own callers
     * (the recipient-search preview, each expanded signer's own label).
     *
     * A proxy's own entry carries ", duly authorised representative" —
     * Johan confirmed this wording is right — attached to THAT person only,
     * never the whole clause; every other representative renders plainly.
     * Capacity, when present, joins the ID inside the SAME bracket
     * ("(ID: x, Capacity)") — the established single-rep convention this
     * whole system already used (EntityRepresentativePartyRenderingTest's
     * "Acme Ltd, herein represented by John Director (Director)"), extended
     * rather than replaced: a rep with no ID keeps showing "(Capacity)"
     * exactly as before; ID is additive, not a second bracket.
     *
     * Nested representatives (Johan, 2026-08-25 — "Piet herein represented
     * by Estate Pty Ltd, herein represented by Koos, and Sannie"): $reps'
     * 4th tuple slot carries a representative's OWN representatives
     * (resolved and depth/cycle-guarded by RoleBlockExpansionService::
     * resolveDocumentRepresentatives() — this method trusts that array is
     * already finite and safe to walk, it does no bounding of its own).
     * formatRepresentativeEntry() recurses back into THIS method for a
     * nested entity representative, so a nested list gets the identical
     * join rule and ID-suffix treatment as the top level — one join
     * implementation (joinWithAnd(), below), never a second for "the
     * nested case."
     *
     * @param array<int, array{0: Contact, 1: ?string, 2: bool, 3?: array}> $reps [rep, capacity, isProxy, nestedReps] per rep
     */
    public static function composePartyClause(string $entityName, array $reps): string
    {
        $entries = array_map(
            fn (array $item) => self::formatRepresentativeEntry($item[0], $item[1], $item[2], $item[3] ?? []),
            $reps
        );

        $repList = self::joinWithAnd($entries);

        return trim(preg_replace('/\s{2,}/', ' ', "{$entityName}, herein represented by {$repList}"));
    }

    /**
     * @param array<int, array{0: Contact, 1: ?string, 2: bool, 3?: array}> $nestedReps this rep's OWN representatives, if it is itself an entity
     */
    private static function formatRepresentativeEntry(Contact $rep, ?string $capacity, bool $isProxy, array $nestedReps = []): string
    {
        if ($rep->isEntity()) {
            // A representative that is itself an entity MUST have its own
            // representative(s) by the time this runs — the producer
            // (RoleBlockExpansionService::resolveDocumentRepresentatives())
            // already refuses to hand back an entity leaf with none.
            // Checked again here rather than trusted blindly: a caller
            // that reaches this method some other way must not silently
            // fall through to the bare-company-name bug this whole change
            // exists to fix.
            if (empty($nestedReps)) {
                throw UnresolvableRepresentativeChainException::entityWithNoRepresentative($rep);
            }

            $repRegNo = trim((string) ($rep->entity_reg_no ?? ''));
            $repName = (string) $rep->entity_name;
            if ($repRegNo !== '') {
                $repName .= ' (Reg: ' . $repRegNo . ')';
            }

            $entry = self::composePartyClause($repName, $nestedReps);

            return $isProxy ? "{$entry}, duly authorised representative" : $entry;
        }

        $id = trim((string) ($rep->id_number ?? ''));
        $cap = trim((string) ($capacity ?? ''));
        $name = (string) $rep->full_name;

        $bracket = implode(', ', array_filter([
            $id !== '' ? "ID: {$id}" : null,
            $cap !== '' ? $cap : null,
        ]));

        $entry = $bracket !== '' ? "{$name} ({$bracket})" : $name;

        return $isProxy ? "{$entry}, duly authorised representative" : $entry;
    }

    /** Johan's join rule (2026-08-24): comma between, "and" before the last one. */
    private static function joinWithAnd(array $items): string
    {
        $items = array_values(array_filter($items, fn ($i) => trim((string) $i) !== ''));
        if (count($items) === 0) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

    /** Party-name phrasing. $isProxy selects the proxy phrasing when defined. */
    public function renderPhrase(Contact $entity, Contact $rep, ?string $capacity, bool $isProxy = false): string
    {
        $template = $isProxy && filled($this->proxy_phrasing_template)
            ? $this->proxy_phrasing_template
            : ($this->phrasing_template ?: self::DEFAULT_PHRASING);

        return self::substitute($template, $entity, $rep, $capacity);
    }

    /** Signature caption. $isProxy selects the proxy caption when defined. */
    public function renderCaption(Contact $entity, Contact $rep, ?string $capacity, bool $isProxy = false): string
    {
        $template = $isProxy && filled($this->proxy_signature_caption)
            ? $this->proxy_signature_caption
            : ($this->signature_caption ?: self::DEFAULT_CAPTION);

        return self::substitute($template, $entity, $rep, $capacity);
    }
}
