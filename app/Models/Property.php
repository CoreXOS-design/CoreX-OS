<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Property extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'external_id',
        'p24_listing_number',
        'title',
        'excerpt',
        'description',
        'price',
        'price_on_application',
        'has_deposit',
        'lease_period',
        'price_per_day',
        'price_per_week',
        'price_per_year',
        'lease_type',
        'gross_price',
        'net_price',
        'yard_price',
        'primary_price_display',
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
        'listing_type',
        'status',
        'features_json',
        'pet_friendly',
        'spaces_json',
        'images_json',
        'dawn_images_json',
        'noon_images_json',
        'dusk_images_json',
        'gallery_images_json',
        'gallery_categories_json',
        'gallery_custom_tags',
        'agent_id',
        'branch_id',
        'agency_id',
        'published_at',
        'listed_date',
        'expiry_date',
        'lease_start_date',
        'lease_end_date',
        'headline',
        'street_name',
        'street_number',
        'province',
        'town',
        'latitude',
        'longitude',
        'pp_suburb_id',
        'pp_syndication_enabled',
        'pp_syndication_status',
        'pp_ref',
        'pp_listing_feed_ref',
        'pp_last_submitted_at',
        'pp_activated_at',
        'pp_exclusive_days',
        'pp_delay_until',
        'pp_last_error',
        'pp_images_last_synced_at',
        'pp_listing_last_synced_at',
        'floor_number',
        'unit_section_block',
        'stand_number',
        'zone_type',
        'address_internal_note',
        'pp_second_agent_id',
        'pp_agent_image_path',
        'pp_second_agent_image_path',
        'pp_hide_street_name',
        'pp_hide_street_number',
        'pp_hide_complex_name',
        'pp_hide_unit_number',
        'youtube_video_id',
        'matterport_id',
        'virtual_tour_url',
        'rental_price_type',
        'p24_syndication_enabled',
        'p24_syndication_status',
        'p24_ref',
        'p24_last_submitted_at',
        'p24_activated_at',
        'p24_last_error',
        'p24_images_last_synced_at',
        'p24_listing_last_synced_at',
    ];

    protected $casts = [
        'images_json'         => 'array',
        'dawn_images_json'    => 'array',
        'noon_images_json'    => 'array',
        'dusk_images_json'    => 'array',
        'gallery_images_json' => 'array',
        'gallery_categories_json' => 'array',
        'gallery_custom_tags'     => 'array',
        'features_json'       => 'array',
        'pet_friendly'        => 'boolean',
        'spaces_json'         => 'array',
        'published_at'        => 'datetime',
        'price'               => 'integer',
        'price_on_application' => 'boolean',
        'has_deposit'         => 'boolean',
        'price_per_day'       => 'float',
        'price_per_week'      => 'float',
        'price_per_year'      => 'float',
        'gross_price'         => 'float',
        'net_price'           => 'float',
        'yard_price'          => 'float',
        'rates_taxes'         => 'integer',
        'levy'                => 'integer',
        'special_levy'        => 'integer',
        'listed_date'         => 'date',
        'expiry_date'         => 'date',
        'lease_start_date'    => 'date',
        'lease_end_date'      => 'date',
        'baths'               => 'decimal:1',
        'rental_amount'       => 'float',
        'deposit_amount'      => 'float',
        'commission_percent'  => 'float',
        'admin_fee'           => 'float',
        'marketing_fee'       => 'float',
        'latitude'                => 'decimal:7',
        'longitude'               => 'decimal:7',
        'pp_suburb_id'            => 'integer',
        'pp_syndication_enabled'  => 'boolean',
        'pp_last_submitted_at'    => 'datetime',
        'pp_activated_at'         => 'datetime',
        'pp_exclusive_days'       => 'integer',
        'pp_delay_until'          => 'datetime',
        'pp_images_last_synced_at'  => 'datetime',
        'pp_listing_last_synced_at' => 'datetime',
        'pp_hide_street_name'       => 'boolean',
        'pp_hide_street_number'     => 'boolean',
        'pp_hide_complex_name'      => 'boolean',
        'pp_hide_unit_number'       => 'boolean',
        'p24_syndication_enabled'     => 'boolean',
        'p24_last_submitted_at'       => 'datetime',
        'p24_activated_at'            => 'datetime',
        'p24_images_last_synced_at'   => 'datetime',
        'p24_listing_last_synced_at'  => 'datetime',
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

    public function showdays(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyShowday::class)->orderBy('start_date');
    }

    public function activeShowdays(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyShowday::class)->where('active', true)->where('end_date', '>=', now())->orderBy('start_date');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Resolve the Property24 agency ID this listing should be submitted under.
     * Branch override wins; falls back to the agency default. Null when neither
     * is configured — callers must treat null as "not syndicatable".
     */
    public function resolveP24AgencyId(): ?string
    {
        if ($this->branch) {
            $resolved = $this->branch->resolveP24AgencyId();
            if ($resolved !== null) {
                return $resolved;
            }
        }
        $agencyId = $this->agency?->p24_agency_id;
        return $agencyId !== null && $agencyId !== '' ? (string) $agencyId : null;
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyNote::class)->latest();
    }

    /** @deprecated Use documents() instead. Kept for backward compat during transition. */
    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PropertyFile::class)->latest();
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_properties')
            ->withTimestamps()
            ->latest('documents.created_at');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    // ── Address Helpers ──

    /**
     * Build the best human-readable address from available fields.
     * Priority: street_number + street_name > address > title (last resort).
     */
    public function buildDisplayAddress(): string
    {
        $parts = [];

        if (!empty($this->unit_number)) {
            $parts[] = 'Unit ' . $this->unit_number;
        }
        if (!empty($this->complex_name)) {
            $parts[] = $this->complex_name;
        }

        // Prefer street_number + street_name over legacy address field
        if (!empty($this->street_number) && !empty($this->street_name)) {
            $parts[] = $this->street_number . ' ' . $this->street_name;
        } elseif (!empty($this->street_name)) {
            $parts[] = $this->street_name;
        } elseif (!empty($this->address)) {
            $parts[] = $this->address;
        }

        if (!empty($this->suburb)) {
            $parts[] = $this->suburb;
        }

        if (!empty($this->city) && strtolower($this->city) !== strtolower($this->suburb ?? '')) {
            $parts[] = $this->city;
        }

        if (empty($parts)) {
            return $this->title ?? 'Unknown Property';
        }

        return implode(', ', $parts);
    }

    // ── Scopes ──

    /**
     * Scope: search across all address-related fields.
     */
    public function scopeSearchAddress($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('address', 'like', "%{$term}%")
              ->orWhere('street_name', 'like', "%{$term}%")
              ->orWhere('street_number', 'like', "%{$term}%")
              ->orWhere('title', 'like', "%{$term}%")
              ->orWhere('suburb', 'like', "%{$term}%")
              ->orWhere('city', 'like', "%{$term}%")
              ->orWhere('complex_name', 'like', "%{$term}%")
              ->orWhere('unit_number', 'like', "%{$term}%")
              ->orWhere('property_number', 'like', "%{$term}%")
              ->orWhere('p24_ref', 'like', "%{$term}%");
        });
    }

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

    /**
     * The list of gallery tags currently available on this property.
     *
     * Tags are derived from the property's `spaces_json` (preferred) or
     * the legacy beds/baths/garages columns. ONLY spaces the user has
     * actually added (count >= 1) produce tags — no hardcoded defaults.
     *
     * Used by:
     *   - Web gallery tagger (resources/views/corex/properties/show.blade.php)
     *   - Mobile API (App\Http\Controllers\Api\MobilePropertyController)
     *
     * @return string[]
     */
    public function getAvailableGalleryTags(): array
    {
        $allowed = ['Bedroom','Bathroom','Kitchen','Lounge','Dining Room','Study','Patio','Garden','Pool','Flatlet','Garage'];

        // Prefer spaces_json — it's the canonical source after the
        // user has touched the Spaces editor.
        $spacesData = $this->spaces_json ?? [];
        $spacesList = $spacesData['spaces'] ?? [];
        if (empty($spacesList) && !empty($spacesData) && isset($spacesData[0]['type'])) {
            $spacesList = $spacesData; // legacy flat shape
        }

        $tags = [];

        if (!empty($spacesList)) {
            foreach ($spacesList as $sp) {
                $type  = $sp['type'] ?? '';
                $count = (int) ($sp['count'] ?? 0);
                if ($count < 1 || !in_array($type, $allowed, true)) continue;

                if ($count > 1) {
                    for ($i = 1; $i <= $count; $i++) $tags[] = $type . ' ' . $i;
                } else {
                    $tags[] = $type;
                }
            }
        } else {
            // Fallback: derive from legacy columns
            for ($i = 1; $i <= (int) ($this->beds ?? 0); $i++)  $tags[] = 'Bedroom ' . $i;
            for ($i = 1; $i <= (int) ($this->baths ?? 0); $i++) $tags[] = 'Bathroom ' . $i;
            if ((int) ($this->garages ?? 0) > 0) $tags[] = 'Garage';
        }

        // Merge user-defined custom tags (case-insensitive de-dupe).
        foreach (($this->gallery_custom_tags ?? []) as $custom) {
            if (!is_string($custom)) continue;
            $custom = trim($custom);
            if ($custom === '') continue;
            $exists = false;
            foreach ($tags as $t) {
                if (strcasecmp($t, $custom) === 0) { $exists = true; break; }
            }
            if (!$exists) $tags[] = $custom;
        }

        return $tags;
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

    // ── Calendar event links (M2.2) ──

    public function calendarEventLinks(): MorphMany
    {
        return $this->morphMany(CalendarEventLink::class, 'linkable');
    }

    public function calendarEvents()
    {
        return $this->morphToMany(CalendarEvent::class, 'linkable', 'calendar_event_links', null, 'calendar_event_id');
    }
}
