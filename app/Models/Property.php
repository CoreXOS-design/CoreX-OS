<?php

namespace App\Models;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Property extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    /**
     * Derived public-website fields surfaced on every serialisation so the
     * listing's cosmetic slug and canonical public URL are available
     * everywhere CoreX shows the property. Both are computed (never stored),
     * so they can never go stale when the title changes.
     */
    protected $appends = ['slug', 'public_url'];

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
        'suburb_normalised',
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
        'title_type',
        'category',
        'condition_level_id',
        'mandate_type',
        'listing_type',
        'status',
        'features_json',
        'features_json_meta',
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
        'is_demo',
        'published_at',
        'listed_date',
        'expiry_date',
        'lease_start_date',
        'lease_end_date',
        'headline',
        'street_name',
        'street_name_normalised',
        'street_number',
        'province',
        'town',
        'latitude',
        'longitude',
        'geo_source',
        'geo_confidence',
        'geo_resolved_at',
        'pp_suburb_id',
        'p24_suburb_id',
        'p24_city_id',
        'p24_province_id',
        'p24_suburb_mismatch',
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
        'compliance_snapshot_at',
        'compliance_snapshot_data',
        'compliance_evidence_flags',
        'first_marketed_at',
        'erf_number',
        'title_deed_number',
        'municipal_valuation',
        'municipal_valuation_year',
        'cma_gps_lat',
        'cma_gps_lng',
        'last_cma_at',
        'last_cma_presentation_id',
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
        'features_json_meta'  => 'array',
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
        'geo_resolved_at'         => 'datetime',
        'pp_suburb_id'            => 'integer',
        'p24_suburb_id'           => 'integer',
        'p24_city_id'             => 'integer',
        'p24_province_id'         => 'integer',
        'p24_suburb_mismatch'     => 'boolean',
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
        'compliance_snapshot_at'      => 'datetime',
        'compliance_snapshot_data'    => 'array',
        'compliance_evidence_flags'   => 'array',
        'first_marketed_at'           => 'datetime',
        'municipal_valuation'         => 'decimal:2',
        'municipal_valuation_year'    => 'integer',
        'cma_gps_lat'                 => 'decimal:7',
        'cma_gps_lng'                 => 'decimal:7',
        'last_cma_at'                 => 'datetime',
        'last_cma_presentation_id'    => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Property $property) {
            if (empty($property->external_id)) {
                $property->external_id = (string) Str::uuid();
            }
        });

        // Dedup foundation Q4 Phase B Step 2 — keep the normalised-address
        // cache in sync with the raw source columns on every save. The
        // cache lets cross-source dedup match this Property against TPs +
        // portal-scrape rows + LocationGrouper composites via the same
        // composite key shape they all share (see PropertyAddressKey).
        static::saving(function (Property $property) {
            if ($property->isDirty('suburb') || $property->suburb_normalised === null) {
                $property->suburb_normalised = \App\Models\Prospecting\TrackedPropertyAddress::normaliseSuburb($property->suburb);
            }
            if ($property->isDirty('street_name') || $property->street_name_normalised === null) {
                $property->street_name_normalised = \App\Models\Prospecting\TrackedPropertyAddress::normaliseStreet($property->street_name);
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Co-listing agent. A listing may be worked by two agents — the secondary
     * is stored on `pp_second_agent_id` (originally added for P24/PrivateProperty
     * dual-agent syndication) and is surfaced to agency websites alongside the
     * primary so a co-listed property appears on BOTH agents' profiles.
     */
    public function secondAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pp_second_agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Build 3 — the property's recorded condition level (drives CMA
     *  Middle band adjustment). Nullable: a property without a recorded
     *  condition gets no adjustment, baseline valuation only. */
    public function conditionLevel(): BelongsTo
    {
        return $this->belongsTo(PropertySettingItem::class, 'condition_level_id');
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

    /** Per-(property × website) syndication rows. Spec: agency-public-api.md §6.5.2. */
    public function websiteSyndication(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\PropertyWebsiteSyndication::class);
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

    // ── Presentations V2 ──

    public function presentations(): HasMany
    {
        return $this->hasMany(Presentation::class, 'property_id')->latest();
    }

    /** Phase 3j — SG documents referenced for this property. */
    public function sgDocuments(): HasMany
    {
        return $this->hasMany(\App\Models\PropertySgDocument::class, 'property_id')->latest();
    }

    public function latestPresentation(): ?Presentation
    {
        return $this->presentations()->first();
    }

    // ── Address Helpers ──

    /**
     * Build the best human-readable address from available fields.
     * Priority: structured parts (unit_number, complex_name, street_*)
     *           ↳ legacy `address` column only when NO structured parts
     *             produced anything
     *           ↳ title as last resort.
     *
     * Build 7 fix — the legacy `address` column on many older rows is a
     * stale pre-concatenation of complex_name + unit_number (e.g.
     * property 909: address="Brock Manor, 17", complex_name="Brock Manor",
     * unit_number="17"). The pre-fix elseif chain appended the legacy
     * `address` whenever street_* was missing, re-adding content the
     * structured branch already supplied. The new chain only falls
     * through to `address` when NO structured part landed in $parts.
     * Adjacent-duplicate guard at the bottom is belt-and-braces for any
     * other overlap pattern (case-insensitive, trimmed).
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

        $usedStructuredStreet = false;
        if (!empty($this->street_number) && !empty($this->street_name)) {
            $parts[] = $this->street_number . ' ' . $this->street_name;
            $usedStructuredStreet = true;
        } elseif (!empty($this->street_name)) {
            $parts[] = $this->street_name;
            $usedStructuredStreet = true;
        }

        // Legacy `address` fallback fires ONLY when nothing structural
        // landed in $parts. Unit/complex/street are all considered
        // structural — once any one of them populated $parts, the
        // legacy column would just re-add overlapping content.
        if (empty($parts) && !empty($this->address)) {
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

        // Belt-and-braces — collapse adjacent duplicates after trimming
        // + case-folding. Guards against any future pattern that lands
        // two equivalent parts side-by-side.
        $cleaned = [];
        foreach ($parts as $piece) {
            $piece = trim((string) $piece);
            if ($piece === '') continue;
            if (!empty($cleaned) && mb_strtolower(end($cleaned)) === mb_strtolower($piece)) {
                continue;
            }
            $cleaned[] = $piece;
        }

        return implode(', ', $cleaned);
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

    /**
     * Cosmetic/SEO slug for the public website — the title run through the
     * exact same transform as Laravel's Str::slug() (lowercase, accents
     * stripped, every run of non-alphanumeric characters collapsed to a
     * single hyphen, leading/trailing hyphens trimmed). Empty string when the
     * property has no title.
     *
     * The slug is purely cosmetic: the website resolves a property by the
     * trailing id in its public URL, so the title may change without breaking
     * any link. See getPublicUrlAttribute().
     */
    public function getSlugAttribute(): string
    {
        return Str::slug((string) ($this->title ?? ''));
    }

    /**
     * Canonical public-website URL for this listing:
     *   {base}/property/{slug}-{id}      (titled listing)
     *   {base}/property/{id}             (no title — bare id fallback)
     *
     * Base URL lives in config('integrations.public_website_url') — never
     * hardcode it. Resolution on the website is by the trailing id, so the
     * slug segment is SEO-only and a changing title never breaks the link.
     */
    public function getPublicUrlAttribute(): string
    {
        $base = rtrim((string) config('integrations.public_website_url'), '/');
        $slug = $this->slug;
        $path = $slug !== '' ? "{$slug}-{$this->id}" : (string) $this->id;

        return "{$base}/property/{$path}";
    }

    public function formattedPrice(): string
    {
        return 'R ' . number_format((int) $this->price, 0, '.', ' ');
    }

    /**
     * Phase A.2.1 — public-facing ad URLs across the portals we syndicate to.
     * Returns one slot per portal; null when that portal isn't currently
     * activated or doesn't have a working URL pattern.
     *
     * URL composition lives here (single source of truth) — see the legacy
     * inline Alpine helpers in resources/views/corex/properties/show.blade.php
     * which used to compute these client-side. Map "Open listing →" and any
     * future "View on portal" CTA pull from this accessor.
     *
     * @return array{p24:?string, pp:?string, hfc:?string}
     */
    public function publicListingUrls(): array
    {
        return [
            'p24' => $this->buildP24Url(),
            'pp'  => $this->buildPpUrl(),
            'hfc' => $this->isOnHfcWebsite() ? $this->buildHfcUrl() : null,
        ];
    }

    /**
     * PLACEHOLDER (A.2.3 Item 4) — until the HFC website integration writes
     * back a per-listing syndication status, assume any active mandate for
     * agency_id=1 is published on hfcoastal.co.za.
     *
     * TODO post-PropCon takeover: replace with an
     * `hfc_website_syndication_status === 'active'` check on the model.
     */
    public function isOnHfcWebsite(): bool
    {
        return $this->status === 'active' && (int) $this->agency_id === 1;
    }

    /**
     * Compose the canonical hfcoastal.co.za listing URL. Pattern (live):
     *   https://www.hfcoastal.co.za/listing/{listing_id}/{type}-{transaction}-in-{suburb}-{city}-{province}
     *
     * `listing_id` falls back to the CoreX property id when the HFC website
     * hasn't written back its own ref yet — same placeholder approach as
     * isOnHfcWebsite() above.
     */
    public function buildHfcUrl(): string
    {
        $listingId   = $this->hfc_website_ref ?? $this->id;
        $type        = \Illuminate\Support\Str::slug($this->property_type ?? 'property');
        $transaction = $this->listing_type === 'rental' ? 'to-let' : 'for-sale';
        $suburb      = \Illuminate\Support\Str::slug($this->suburb ?? '');
        $city        = \Illuminate\Support\Str::slug($this->city ?? $this->town ?? '');
        $province    = \Illuminate\Support\Str::slug($this->province ?? 'kwazulu-natal');

        $slug = "{$type}-{$transaction}-in-{$suburb}-{$city}-{$province}";
        return "https://www.hfcoastal.co.za/listing/{$listingId}/{$slug}";
    }

    /**
     * Pick the best public URL for "Open listing" actions. Priority:
     * P24 active > PP active > company website > null.
     */
    public function preferredPublicListingUrl(): ?string
    {
        $urls = $this->publicListingUrls();
        return $urls['p24'] ?? $urls['pp'] ?? $urls['hfc'] ?? null;
    }

    /**
     * P24 slug-composed direct listing URL. Returns null unless we have an
     * activated p24_ref. Sandbox vs production picked from p24_syndication_status
     * to stay consistent with the legacy inline JS — only 'active' listings
     * earn a real URL; in-flight states (submitted, pending) don't yet point
     * at a live page on P24.
     */
    private function buildP24Url(): ?string
    {
        if (empty($this->p24_ref) || $this->p24_syndication_status !== 'active') {
            return null;
        }
        $slugify = static function (?string $s): string {
            $s = (string) ($s ?? '');
            $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '');
            return trim($s, '-') ?: 'property';
        };
        $section = $this->listing_type === 'rental' ? 'to-rent' : 'for-sale';
        $domain  = 'www.property24.com';
        return sprintf(
            'https://%s/%s/%s/%s/%s/%s/%s',
            $domain,
            $section,
            $slugify($this->suburb),
            $slugify($this->city),
            $slugify($this->province),
            $this->pp_suburb_id ?? '0',
            $this->p24_ref,
        );
    }

    /**
     * Private Property search-by-ref fallback. PP doesn't return a direct
     * listing URL from syndication, so we hop through their search page.
     * Returns null unless the listing is activated.
     */
    private function buildPpUrl(): ?string
    {
        if (empty($this->pp_ref) || $this->pp_syndication_status !== 'active') {
            return null;
        }
        return 'https://www.privateproperty.co.za/search?q=' . urlencode((string) $this->pp_ref);
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

    // ── Whistleblower complaints ──

    public function whistleblowComplaints(): HasMany
    {
        return $this->hasMany(\App\Models\Compliance\WhistleblowComplaint::class);
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
