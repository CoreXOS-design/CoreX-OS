<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 6 (M6.1) — activity definition for the points engine.
 *
 * Two flavours via `scope`:
 *  - `system` — universal definition available to every agency. `agency_id`
 *               MUST be NULL.
 *  - `agency` — agency-private definition. `agency_id` MUST be set.
 *
 * Constraint enforced in the `saving` hook below; mirroring the DB enum/
 * check-constraint pattern means accidentally writing an inconsistent row
 * from a controller or Tinker raises a clear exception at write time.
 */
final class ActivityDefinition extends Model
{
    public const SCOPE_SYSTEM = 'system';
    public const SCOPE_AGENCY = 'agency';

    protected $table = 'activity_definitions';

    protected $fillable = [
        'name',
        'weight',
        'sort_order',
        'scope',
        'agency_id',
        'branch_id',
        'scoring_mode',
        'is_enabled',
    ];

    protected $casts = [
        'weight'     => 'decimal:2',
        'sort_order' => 'integer',
        'is_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $def): void {
            if ($def->scope === self::SCOPE_SYSTEM && $def->agency_id !== null) {
                throw new \DomainException(
                    "ActivityDefinition scope='system' must have agency_id=NULL (got {$def->agency_id})."
                );
            }
            if ($def->scope === self::SCOPE_AGENCY && $def->agency_id === null) {
                throw new \DomainException(
                    "ActivityDefinition scope='agency' requires a non-null agency_id."
                );
            }
            if (!in_array($def->scope, [self::SCOPE_SYSTEM, self::SCOPE_AGENCY], true)) {
                throw new \DomainException(
                    "ActivityDefinition scope must be 'system' or 'agency' (got '{$def->scope}')."
                );
            }
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function scopeSystem(Builder $q): Builder
    {
        return $q->where('scope', self::SCOPE_SYSTEM);
    }

    public function scopeForAgency(Builder $q, int $agencyId): Builder
    {
        return $q->where('scope', self::SCOPE_AGENCY)->where('agency_id', $agencyId);
    }

    /**
     * Returns definitions an agent in this agency may pick from:
     * universal system definitions + this agency's private definitions.
     */
    public function scopeAvailableTo(Builder $q, int $agencyId): Builder
    {
        return $q->where(function (Builder $sub) use ($agencyId) {
            $sub->where('scope', self::SCOPE_SYSTEM)
                ->orWhere(function (Builder $inner) use ($agencyId) {
                    $inner->where('scope', self::SCOPE_AGENCY)
                          ->where('agency_id', $agencyId);
                });
        });
    }

    public function isSystem(): bool
    {
        return $this->scope === self::SCOPE_SYSTEM;
    }

    public function isAgencyScoped(): bool
    {
        return $this->scope === self::SCOPE_AGENCY;
    }
}
