<?php

namespace App\Models\Docuperfect;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESIGN recipient builder (Johan, 2026-08-15) — an agency's phrasing template
 * for entity/company recipients. v1: ONE default per agency (agent-pickable at
 * compose); consumed by ESignWizardController::expandEntityRecipients().
 *
 * Renders "{entity_name}, herein represented by {rep_name} ({capacity})" and a
 * signature caption "on behalf of {entity_name} ({capacity})" from the tokens.
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
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public const DEFAULT_PHRASING = '{entity_name}, herein represented by {rep_name} ({capacity})';
    public const DEFAULT_CAPTION  = 'on behalf of {entity_name}';

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
                'agency_id'         => $agencyId,
                'name'              => 'Company / entity representation',
                'applies_to'        => 'entity',
                'phrasing_template' => self::DEFAULT_PHRASING,
                'signature_caption' => self::DEFAULT_CAPTION,
                'is_default'        => true,
            ]);
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

    public function renderPhrase(Contact $entity, Contact $rep, ?string $capacity): string
    {
        return self::substitute($this->phrasing_template ?: self::DEFAULT_PHRASING, $entity, $rep, $capacity);
    }

    public function renderCaption(Contact $entity, Contact $rep, ?string $capacity): string
    {
        return self::substitute($this->signature_caption ?: self::DEFAULT_CAPTION, $entity, $rep, $capacity);
    }
}
