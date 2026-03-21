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
        'page_count',
        'fields_json',
        'is_global',
        'is_esign',
        'party_mode',
        'wizard_config',
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

    public function isSalesDocument(): bool
    {
        $name = strtolower($this->name ?? '');
        return str_contains($name, 'sell') || str_contains($name, 'sale')
            || str_contains($name, 'authority') || str_contains($name, 'otp')
            || str_contains($name, 'purchase');
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
