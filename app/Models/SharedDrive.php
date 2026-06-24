<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A top-level Shared Drive container.
 *
 * Every agency has exactly one default "General" drive (is_default) that all
 * members see and which can never be deleted or restricted. Additional drives
 * are either Open (whole agency) or Restricted — visible only to the creator,
 * the explicit access list, owners, and `shared_drive.drives.manage` holders.
 *
 * Tenant isolation is structural (BelongsToAgency); access control is layered
 * ON TOP of tenancy — a restricted drive is still only ever within one agency.
 */
class SharedDrive extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'name',
        'is_restricted',
        'is_default',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_restricted' => 'boolean',
        'is_default'    => 'boolean',
    ];

    public function folders(): HasMany
    {
        return $this->hasMany(SharedDriveFolder::class, 'drive_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SharedDriveFile::class, 'drive_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Members explicitly granted access to a restricted drive. */
    public function accessUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shared_drive_access', 'drive_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * A user with the manage permission (admins) or the Owner role sees every
     * drive in the agency, regardless of restriction. Used for both the read
     * scope and single-drive authorization.
     */
    public static function userSeesAll(User $user): bool
    {
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return true;
        }
        return $user->hasPermission('shared_drive.drives.manage');
    }

    /**
     * Restrict a query to drives the given user may see:
     * open drives, drives they created, or drives they're a member of.
     * Bypassed entirely for owners / manage-permission holders.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (static::userSeesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_restricted', false)
                ->orWhere('created_by_user_id', $user->id)
                ->orWhereHas('accessUsers', fn (Builder $a) => $a->where('users.id', $user->id));
        });
    }

    /** True if this specific drive is visible to the user. */
    public function isVisibleTo(User $user): bool
    {
        if (!$this->is_restricted) {
            return true;
        }
        if ((int) $this->created_by_user_id === (int) $user->id) {
            return true;
        }
        if (static::userSeesAll($user)) {
            return true;
        }
        return $this->accessUsers()->where('users.id', $user->id)->exists();
    }

    /** Only the creator or a manage-permission holder may rename/delete/re-share. */
    public function canManage(User $user): bool
    {
        return (int) $this->created_by_user_id === (int) $user->id
            || static::userSeesAll($user);
    }
}
