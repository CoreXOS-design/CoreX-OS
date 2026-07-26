<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
class CommercialEvaluation extends Model
{
    use BelongsToBranch, BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'created_by_user_id',
        'branch_id',
        'status',
        'property_type',
        'property_name',
        'address',
        'suburb',
        'town',
        'province',
        'erf_number',
        'zoning',
        'total_land_size_m2',
        'total_land_size_ha',
        'total_building_size_m2',
        'year_built',
        'condition',
        'asking_price',
        'municipal_evaluation',
        'seller_name',
        'notes',
        'evaluation_json',
        'recommended_range_low',
        'recommended_range_mid',
        'recommended_range_high',
        'primary_method',
        'evaluated_at',
    ];

    protected $casts = [
        'asking_price'           => 'integer',
        'municipal_evaluation'   => 'integer',
        'recommended_range_low'  => 'integer',
        'recommended_range_mid'  => 'integer',
        'recommended_range_high' => 'integer',
        'year_built'             => 'integer',
        'evaluation_json'        => 'array',
        'evaluated_at'           => 'datetime',
        'total_land_size_m2'     => 'decimal:2',
        'total_land_size_ha'     => 'decimal:4',
        'total_building_size_m2' => 'decimal:2',
    ];

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'commercial_evals');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        if ($scope === 'own') return $query->whereIn('created_by_user_id', $user->dataIdentityIds());

        return $query->whereRaw('1 = 0');
    }

    // ── Relationships ──

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function financials()
    {
        return $this->hasMany(CommercialEvaluationFinancial::class);
    }

    public function comparables()
    {
        return $this->hasMany(CommercialEvaluationComparable::class);
    }

    public function assets()
    {
        return $this->hasMany(CommercialEvaluationAsset::class);
    }

    public function units()
    {
        return $this->hasMany(CommercialEvaluationUnit::class);
    }

    public function crops()
    {
        return $this->hasMany(CommercialEvaluationCrop::class);
    }

    public function livestock()
    {
        return $this->hasMany(CommercialEvaluationLivestock::class);
    }

    // ── ZAR Display Helpers ──

    public static function formatZar(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }
        return 'R ' . number_format($cents / 100, 0, '.', ' ');
    }

    public function getAskingPriceDisplayAttribute(): string
    {
        return self::formatZar($this->asking_price);
    }

    public function getMunicipalEvaluationDisplayAttribute(): string
    {
        return self::formatZar($this->municipal_evaluation);
    }

    public function getRecommendedRangeDisplayAttribute(): string
    {
        if ($this->recommended_range_low === null) {
            return '—';
        }
        return self::formatZar($this->recommended_range_low)
            . ' – ' . self::formatZar($this->recommended_range_high);
    }

    // ── Property Type Labels ──

    public static function propertyTypeLabel(string $type): string
    {
        return match ($type) {
            'commercial'   => 'Commercial Retail/Office',
            'industrial'   => 'Industrial/Warehouse',
            'hospitality'  => 'B&B / Guest House / Lodge',
            'agricultural' => 'Farm / Smallholding',
            default        => ucfirst($type),
        };
    }

    public function getPropertyTypeLabelAttribute(): string
    {
        return self::propertyTypeLabel($this->property_type);
    }

    public static function propertyTypeBadgeColor(string $type): string
    {
        return match ($type) {
            'commercial'   => 'ds-badge-info',
            'industrial'   => 'ds-badge-warning',
            'hospitality'  => 'ds-badge-ahead',
            'agricultural' => 'ds-badge-success',
            default        => 'ds-badge-default',
        };
    }

    public static function statusBadgeColor(string $status): string
    {
        return match ($status) {
            'completed' => 'ds-badge-success',
            'archived'  => 'ds-badge-default',
            default     => 'ds-badge-warning',
        };
    }
}
