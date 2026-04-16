<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Presentation extends Model
{
    use SoftDeletes, BelongsToAgency;

    // Status values: draft | presented | locked
    protected $fillable = [
        'agency_id',
        'branch_id',
        'created_by_user_id',
        'listing_id',
        'title',
        'property_address',
        'suburb',
        'property_type',
        'bedrooms',
        'bathrooms',
        'garages_parking',
        'erf_size_m2',
        'floor_area_m2',
        'seller_name',
        'seller_email',
        'asking_price_inc',
        'status',
        'currency',
        'monthly_bond',
        'monthly_rates',
        'monthly_levies',
        'monthly_insurance',
        'monthly_utilities',
        'monthly_opportunity_cost',
        'cma_selected_range',
        'vicinity_selected_range',
        'excluded_active_listing_indices',
        'simulator_config_json',
        'seller_live_capture_json',
    ];

    protected $casts = [
        'bedrooms'                 => 'integer',
        'bathrooms'                => 'integer',
        'garages_parking'          => 'integer',
        'erf_size_m2'              => 'integer',
        'floor_area_m2'            => 'integer',
        'asking_price_inc'         => 'integer',
        'monthly_bond'             => 'float',
        'monthly_rates'            => 'float',
        'monthly_levies'           => 'float',
        'monthly_insurance'        => 'float',
        'monthly_utilities'        => 'float',
        'monthly_opportunity_cost' => 'float',
        'excluded_active_listing_indices' => 'array',
        'simulator_config_json'          => 'array',
        'seller_live_capture_json'       => 'array',
    ];

    public function uploads()
    {
        return $this->hasMany(PresentationUpload::class);
    }

    public function fields()
    {
        return $this->hasMany(PresentationField::class);
    }

    public function sections()
    {
        return $this->hasMany(PresentationSection::class);
    }

    public function snapshots()
    {
        return $this->hasMany(PresentationSnapshot::class);
    }

    public function links()
    {
        return $this->hasMany(PresentationLink::class);
    }

    public function portalCaptures()
    {
        return $this->hasMany(PortalCapture::class);
    }

    public function soldComps()
    {
        return $this->hasMany(PresentationSoldComp::class);
    }

    public function activeListings()
    {
        return $this->hasMany(PresentationActiveListing::class);
    }

    public function versions()
    {
        return $this->hasMany(PresentationVersion::class);
    }

    public function articles()
    {
        return $this->hasMany(PresentationArticle::class);
    }

    public function documentLibraryAttachments()
    {
        return $this->hasMany(PresentationDocumentLibraryItem::class);
    }

    public function documentLibraryItems()
    {
        return $this->belongsToMany(DocumentLibraryItem::class, 'presentation_document_library_items')
            ->withPivot('attached_by_user_id', 'note', 'created_at')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'presentations');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('created_by_user_id', $user->id);

        return $query->whereRaw('1 = 0');
    }

    /**
     * Deterministic readiness check — delegates to PresentationReadinessService.
     * Returns true when all required evidence items are present (same as can_compile).
     */
    public function isAnalysisReady(): bool
    {
        return (new \App\Services\Presentations\PresentationReadinessService())
            ->evaluate($this)['can_compile'];
    }
}
