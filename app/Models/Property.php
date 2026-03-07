<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'external_id',
        'title',
        'excerpt',
        'description',
        'price',
        'rates_taxes',
        'levy',
        'special_levy',
        'rental_amount',
        'deposit_amount',
        'commission_percent',
        'admin_fee',
        'marketing_fee',
        'city',
        'suburb',
        'address',
        'region',
        'district',
        'beds',
        'baths',
        'garages',
        'size_m2',
        'erf_size_m2',
        'property_number',
        'complex_name',
        'unit_number',
        'property_type',
        'category',
        'mandate_type',
        'status',
        'features_json',
        'spaces_json',
        'images_json',
        'dawn_images_json',
        'noon_images_json',
        'dusk_images_json',
        'gallery_images_json',
        'agent_id',
        'branch_id',
        'agency_id',
        'published_at',
        'listed_date',
        'expiry_date',
        'lease_start_date',
        'lease_end_date',
    ];

    protected $casts = [
        'images_json'         => 'array',
        'dawn_images_json'    => 'array',
        'noon_images_json'    => 'array',
        'dusk_images_json'    => 'array',
        'gallery_images_json' => 'array',
        'features_json'       => 'array',
        'spaces_json'         => 'array',
        'published_at'        => 'datetime',
        'price'               => 'integer',
        'rates_taxes'         => 'integer',
        'levy'                => 'integer',
        'special_levy'        => 'integer',
        'listed_date'         => 'date',
        'expiry_date'         => 'date',
        'lease_start_date'    => 'date',
        'lease_end_date'      => 'date',
        'rental_amount'       => 'float',
        'deposit_amount'      => 'float',
        'commission_percent'  => 'float',
        'admin_fee'           => 'float',
        'marketing_fee'       => 'float',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Property $property) {
            if (empty($property->external_id)) {
                $property->external_id = (string) Str::uuid();
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyNote::class)->latest();
    }

    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyFile::class)->latest();
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    // ── Scopes ──

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('agent_id', $user->id);

        return $query->whereRaw('1 = 0');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function formattedPrice(): string
    {
        return 'R ' . number_format((int) $this->price, 0, '.', ' ');
    }

    /** All images flattened into one array for convenience */
    public function allImages(): array
    {
        return array_merge(
            $this->dawn_images_json    ?? [],
            $this->noon_images_json    ?? [],
            $this->dusk_images_json    ?? [],
            $this->gallery_images_json ?? [],
            $this->images_json         ?? [],
        );
    }
}
