<?php

namespace App\Models\Docuperfect;

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
        'phrasing_template',
        'signature_caption',
        'proxy_phrasing_template',
        'proxy_signature_caption',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
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
    public static function substitute(string $template, Contact $entity, Contact $rep, ?string $capacity): string
    {
        $out = strtr($template, [
            '{entity_name}'   => (string) ($entity->entity_name ?: $entity->full_name),
            '{rep_name}'      => (string) $rep->full_name,
            '{capacity}'      => (string) ($capacity ?: 'Representative'),
            '{entity_reg_no}' => (string) ($entity->entity_reg_no ?? ''),
        ]);

        // Collapse an empty "()" left by a missing capacity/reg-no token, and tidy spaces.
        $out = preg_replace('/\(\s*\)/', '', $out);

        return trim(preg_replace('/\s{2,}/', ' ', $out));
    }

    /**
     * Party-name phrasing. A PROXY signer ALWAYS renders with distinct proxy
     * wording — an explicit proxy_phrasing_template when the agency set one, else
     * CoreX's standard proxy phrasing. It never silently falls back to the
     * ordinary phrasing, because a proxy that reads identically to a plain
     * representative is a legal-distinctness hole (covers presets that predate
     * the proxy columns and so carry NULL proxy fields).
     */
    public function renderPhrase(Contact $entity, Contact $rep, ?string $capacity, bool $isProxy = false): string
    {
        if ($isProxy) {
            $template = filled($this->proxy_phrasing_template)
                ? $this->proxy_phrasing_template
                : self::DEFAULT_PROXY_PHRASING;
        } else {
            $template = $this->phrasing_template ?: self::DEFAULT_PHRASING;
        }

        return self::substitute($template, $entity, $rep, $capacity);
    }

    /** Signature caption. Proxy signers always get distinct proxy wording (see renderPhrase). */
    public function renderCaption(Contact $entity, Contact $rep, ?string $capacity, bool $isProxy = false): string
    {
        if ($isProxy) {
            $template = filled($this->proxy_signature_caption)
                ? $this->proxy_signature_caption
                : self::DEFAULT_PROXY_CAPTION;
        } else {
            $template = $this->signature_caption ?: self::DEFAULT_CAPTION;
        }

        return self::substitute($template, $entity, $rep, $capacity);
    }
}
