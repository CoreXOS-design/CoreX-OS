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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'docuperfect_template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
