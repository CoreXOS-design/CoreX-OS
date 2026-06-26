<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SharedDriveFolder extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'drive_id',
        'parent_id',
        'name',
        'created_by_user_id',
    ];

    public function drive(): BelongsTo
    {
        return $this->belongsTo(SharedDrive::class, 'drive_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SharedDriveFolder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SharedDriveFolder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SharedDriveFile::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Walk up the parent chain to build a breadcrumb trail (root → this).
     *
     * @return \Illuminate\Support\Collection<int, SharedDriveFolder>
     */
    public function breadcrumb(): \Illuminate\Support\Collection
    {
        $trail = collect();
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 100) {
            $trail->prepend($node);
            $node = $node->parent;
        }
        return $trail;
    }
}
