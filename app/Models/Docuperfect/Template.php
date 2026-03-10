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
        'wizard_config',
        'render_type',
        'blade_view',
        'owner_id',
        'archived_at',
    ];

    protected $casts = [
        'fields_json' => 'array',
        'wizard_config' => 'array',
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

    public function getPageImagesAttribute(): array
    {
        $urls = [];
        for ($n = 0; $n < $this->page_count; $n++) {
            $urls[] = route('docuperfect.page.image', ['id' => $this->id, 'page' => $n]);
        }
        return $urls;
    }
}
