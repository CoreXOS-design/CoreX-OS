<?php

namespace App\Models;

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
 * party_slots shape: [{"key": "executor", "label": "Executor",
 * "kind": "signing"}, ...]. kind is 'named' (rendered as text only — never
 * a recipient, never signs) or 'signing' (binds to a recipient-screen row,
 * signs exactly as any recipient does today).
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

    public const KIND_NAMED = 'named';
    public const KIND_SIGNING = 'signing';

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function scopeStandard(Builder $query): Builder
    {
        return $query->whereNull('agency_id');
    }

    /** The signing slots this template declares — the only ones that ever bind to a recipient row. */
    public function signingSlots(): array
    {
        return array_values(array_filter(
            $this->party_slots ?? [],
            fn (array $slot) => ($slot['kind'] ?? null) === self::KIND_SIGNING,
        ));
    }

    /** The named-only slots this template declares — rendered as text, never a recipient, never sign. */
    public function namedSlots(): array
    {
        return array_values(array_filter(
            $this->party_slots ?? [],
            fn (array $slot) => ($slot['kind'] ?? null) === self::KIND_NAMED,
        ));
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
}
