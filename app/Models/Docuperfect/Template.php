<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Template extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_templates';

    protected $fillable = [
        'name',
        'template_type',
        'document_type_id',
        'category',
        'page_count',
        'fields_json',
        'is_global',
        'is_esign',
        'party_mode',
        'wizard_config',
        'sections',
        'render_type',
        'blade_view',
        'signing_parties',
        'header_display',
        'editor_state',
        'cds_json',
        'field_mappings',
        'allowed_delivery_modes',
        'security_tier',
        'owner_id',
        'archived_at',
    ];

    protected $casts = [
        'fields_json' => 'array',
        'wizard_config' => 'array',
        'sections' => 'array',
        'signing_parties' => 'array',
        'editor_state' => 'array',
        'cds_json' => 'array',
        'field_mappings' => 'array',
        'is_global' => 'boolean',
        'is_esign' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'docuperfect_template_branches', 'template_id', 'branch_id');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'template_id');
    }

    public function flows()
    {
        return $this->hasMany(Flow::class, 'template_id');
    }

    public function signatureZones()
    {
        return $this->hasMany(TemplateSignatureZone::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'templates');

        if ($scope === 'all') return $query;

        $branchId = $user->effectiveBranchId();

        return $query->where(function ($q) use ($branchId) {
            $q->where('is_global', true);
            if ($branchId) {
                $q->orWhereHas('branches', function ($bq) use ($branchId) {
                    $bq->where('branches.id', $branchId);
                });
            }
        });
    }

    public function isPerParty(): bool
    {
        return $this->party_mode === 'per_party';
    }

    /**
     * Detect if this is a sales-context document.
     * Layered: explicit signing_parties roles > name pattern matching.
     * Accepts optional $propertySource ('properties' or 'rental_properties') for step-data context.
     */
    public function isSalesDocument(?string $propertySource = null): bool
    {
        // Layer 1: check signing_parties for explicit sales/rental roles
        $parties = $this->signing_parties ?? [];
        if (is_array($parties) && !empty($parties)) {
            $roles = array_map('strtolower', $parties);
            $hasSales = !empty(array_intersect($roles, ['seller', 'buyer']));
            $hasRental = !empty(array_intersect($roles, ['landlord', 'tenant', 'lessor', 'lessee']));
            if ($hasSales && !$hasRental) return true;
            if ($hasRental && !$hasSales) return false;
        }

        // Layer 2: property source table
        if ($propertySource === 'properties') return true;
        if ($propertySource === 'rental_properties') return false;

        // Layer 3: template name pattern matching (fallback)
        $name = strtolower($this->name ?? '');
        return str_contains($name, 'sell') || str_contains($name, 'sale')
            || str_contains($name, 'authority') || str_contains($name, 'otp')
            || str_contains($name, 'purchase');
    }

    /**
     * Check if this template type is legally blocked from e-signing.
     * Sale agreements and OTPs must be signed with wet ink per Alienation of Land Act.
     */
    public function isEsignBlocked(): bool
    {
        $type = strtolower($this->template_type ?? '');
        if (in_array($type, ['sale_agreement', 'otp'])) {
            return true;
        }
        // Also check by name for safety
        $name = strtolower($this->name ?? '');
        return str_contains($name, 'agreement of sale')
            || str_contains($name, 'deed of sale')
            || str_contains($name, 'offer to purchase');
    }

    /**
     * Get allowed delivery modes as an array.
     */
    public function getAllowedDeliveryModesArray(): array
    {
        $modes = $this->allowed_delivery_modes ?? 'esign,wet_ink,download';
        return array_filter(array_map('trim', explode(',', $modes)));
    }

    /**
     * Check if a specific delivery mode is allowed.
     */
    public function allowsDeliveryMode(string $mode): bool
    {
        // Sale agreements can NEVER use e-sign
        if ($mode === 'esign' && $this->isEsignBlocked()) {
            return false;
        }
        return in_array($mode, $this->getAllowedDeliveryModesArray());
    }

    /**
     * Get effective delivery modes (enforcing legal restrictions).
     */
    public function getEffectiveDeliveryModes(): array
    {
        $modes = $this->getAllowedDeliveryModesArray();
        if ($this->isEsignBlocked()) {
            $modes = array_values(array_diff($modes, ['esign']));
            if (empty($modes)) {
                $modes = ['wet_ink', 'download'];
            }
        }
        return $modes;
    }

    /**
     * Map generic signing party keys to display names based on document context.
     */
    public static function mapSigningPartyKeys(array $keys, bool $isSales): array
    {
        $map = $isSales
            ? ['owner_party' => 'Seller', 'acquiring_party' => 'Buyer', 'agent' => 'Agent']
            : ['owner_party' => 'Lessor', 'acquiring_party' => 'Lessee', 'agent' => 'Agent'];

        return array_values(array_map(
            fn($k) => $map[$k] ?? ucfirst(str_replace('_', ' ', $k)),
            $keys
        ));
    }

    public function getPageImagesAttribute(): array
    {
        $urls = [];
        for ($n = 0; $n < $this->page_count; $n++) {
            $urls[] = route('docuperfect.page.image', ['id' => $this->id, 'page' => $n]);
        }
        return $urls;
    }
}
