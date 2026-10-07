<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Docuperfect\Template;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-renewals.md §5(b) — GATE 1. Which of an agency's own
 * imported DocuPerfect templates it treats as a rental lease/renewal
 * document. Full CRUD under Rentals → Settings.
 */
class RentalLeaseTemplate extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const CATEGORY_RESIDENTIAL = 'residential';
    public const CATEGORY_COMMERCIAL = 'commercial';
    public const CATEGORY_RENEWAL_ADDENDUM = 'renewal_addendum';

    public const CATEGORIES = [
        self::CATEGORY_RESIDENTIAL,
        self::CATEGORY_COMMERCIAL,
        self::CATEGORY_RENEWAL_ADDENDUM,
    ];

    protected $fillable = [
        'agency_id',
        'name',
        'docuperfect_template_id',
        'category',
        'is_active',
        // LEASE-AGREEMENT BEGIN (leases.md §15.10 M4 — Build L1)
        'field_map',
        'is_default',
        'validated_at',
        'validation_problems',
        // LEASE-AGREEMENT END
    ];

    protected $casts = [
        'is_active' => 'boolean',
        // LEASE-AGREEMENT BEGIN (leases.md §15.10 M4 — Build L1)
        'field_map' => 'array',
        'is_default' => 'boolean',
        'validated_at' => 'datetime',
        'validation_problems' => 'array',
        // LEASE-AGREEMENT END
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'docuperfect_template_id');
    }

    // LEASE-AGREEMENT BEGIN (leases.md §15.10 — Build L1)
    /**
     * Whether this row may be used by $agencyId for the lease process (§15.12.4): an agency only ever
     * uses a row it owns. The template-level checks (owned, e-sign, mapped, signing places) are the
     * guard's — see LeaseAgreementTemplateGuard; Build L0 fills it.
     */
    public function isUsableBy(int $agencyId): bool
    {
        return $this->is_active
            && (int) $this->agency_id === $agencyId
            && $this->template !== null
            && app(\App\Services\Rentals\LeaseAgreementTemplateGuard::class)
                ->problemsFor($this->template, $agencyId) === [];
    }
    // LEASE-AGREEMENT END

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
