<?php

namespace App\Models\PlatformEsign;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Platform E-Sign template (spec §3A). Platform-owned: no agency scoping. */
class Template extends Model
{
    use SoftDeletes;

    protected $table = 'platform_esign_templates';

    public const KINDS = [
        'subscription_agreement' => 'Subscription agreement',
        'debit_order'            => 'Debit-order form',
        'other'                  => 'Other',
    ];

    protected $fillable = ['name', 'kind', 'source', 'body', 'pdf_path', 'page_count', 'roles_json', 'version', 'is_active', 'created_by'];

    protected $casts = ['roles_json' => 'array', 'is_active' => 'boolean'];

    public function fields(): HasMany
    {
        return $this->hasMany(TemplateField::class, 'template_id')->orderBy('page_index')->orderBy('sort_order');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<int,array{key:string,label:string,order:int}> sorted by order */
    public function roles(): array
    {
        $roles = $this->roles_json ?: [];
        usort($roles, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $roles;
    }

    public function isPdf(): bool
    {
        return $this->source === 'pdf';
    }

    /** A "web document": typeset contract the recipient fills in on screen (spec §11). */
    public function isWebdoc(): bool
    {
        return $this->source === 'webdoc';
    }

    public function wordingVersions(): HasMany
    {
        return $this->hasMany(WordingVersion::class, 'template_id')->orderByDesc('id');
    }
}
