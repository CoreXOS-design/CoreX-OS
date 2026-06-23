<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Role extends Model
{
    use HasFactory, SoftDeletes;

    /** @var array<string, Collection> Cached roles per agency context for the request */
    protected static array $cachedRoles = [];

    protected $fillable = [
        'name',
        'label',
        'description',
        'color',
        'sort_order',
        'agency_id',
        'oversight_scope',
    ];

    protected $casts = [
        'is_owner'       => 'boolean',
        'can_be_deleted'  => 'boolean',
        'sort_order'     => 'integer',
    ];

    // ── Relationships ──

    public function users()
    {
        return $this->hasMany(User::class, 'role', 'name');
    }

    public function permissions()
    {
        return $this->hasMany(RolePermission::class, 'role', 'name');
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    // ── Scopes ──

    public function scopeForAgency($query, ?int $agencyId)
    {
        return $query->where(function ($q) use ($agencyId) {
            $q->whereNull('agency_id');
            if ($agencyId) {
                $q->orWhere('agency_id', $agencyId);
            }
        });
    }

    // ── Helpers ──

    public function isOwnerRole(): bool
    {
        return (bool) $this->is_owner;
    }

    /**
     * Get the single owner role.
     */
    public static function ownerRole(): ?self
    {
        return static::allRoles()->firstWhere('is_owner', true);
    }

    /**
     * Get the roles visible in a given agency context (cached per agency for
     * the request). Roles are agency-scoped (.ai/specs/roles-permissions.md):
     *
     * - When $agencyId is set AND that agency owns its own roles, return the
     *   global owner roles + that agency's roles. This makes a name lookup
     *   (e.g. firstWhere('name', 'admin')) resolve unambiguously to the
     *   agency's own copy.
     * - Otherwise (no agency context, or an agency that has not been
     *   provisioned yet) fall back to the global template/owner rows
     *   (agency_id IS NULL). This keeps owner accounts and fresh/test DBs
     *   working unchanged.
     *
     * Pass $agencyId from $user->effectiveAgencyId() at every call site that
     * resolves a specific user's role.
     */
    public static function allRoles(?int $agencyId = null): Collection
    {
        $key = $agencyId === null ? 'null' : (string) $agencyId;

        if (!isset(static::$cachedRoles[$key])) {
            $roles = null;

            if ($agencyId !== null) {
                $agencyRoles = static::where('agency_id', $agencyId)
                    ->orderBy('sort_order')->get();

                if ($agencyRoles->isNotEmpty()) {
                    $ownerRoles = static::whereNull('agency_id')
                        ->where('is_owner', true)->get();

                    $roles = $ownerRoles->concat($agencyRoles)
                        ->sortBy('sort_order')->values();
                }
            }

            // Fallback: global template + owner rows (agency_id IS NULL).
            if ($roles === null) {
                $roles = static::whereNull('agency_id')->orderBy('sort_order')->get();
            }

            static::$cachedRoles[$key] = $roles;
        }

        return static::$cachedRoles[$key];
    }

    /**
     * Clear the static cache (useful after role CRUD operations).
     */
    public static function clearCache(): void
    {
        static::$cachedRoles = [];
    }

    /**
     * Get all role names for validation rules, scoped to an agency context.
     */
    public static function roleNames(?int $agencyId = null): array
    {
        return static::allRoles($agencyId)->pluck('name')->all();
    }
}
