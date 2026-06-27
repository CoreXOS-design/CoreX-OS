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
     * Off-market / terminal listing statuses — the single source of truth for
     * "this listing is NOT live on the market". Everything else (for_sale, incl.
     * Reduced Price / Pending sub-labels, under_offer, on_show, on_auction,
     * to_let, the legacy 'active', …) is considered ON MARKET. Use
     * Property::OFF_MARKET_STATUSES / scopeOnMarket() everywhere instead of
     * re-listing these literals — see BUILD_STANDARD §6 (fix the class).
     *
     * NOTE: distinct from scopeTransactionLive()'s mandate-liveness check, which
     * is about mandate expiry, not on-market display.
     */
    public const OFF_MARKET_STATUSES = [
        'sold', 'transferred', 'withdrawn', 'expired',
        'cancelled', 'let_out', 'draft', 'archived', 'unavailable',
    ];

    /**
     * On-market listings = base status NOT in OFF_MARKET_STATUSES. This is the
     * canonical definition of "active"/live stock for dashboards and filters.
     */
    public function scopeOnMarket($query)
    {
        return $query->whereNotIn('status', self::OFF_MARKET_STATUSES);
    }

    /**
     * Instance mirror of scopeOnMarket() — true when this property is live on
     * the market. Same single source of truth (OFF_MARKET_STATUSES) so the
     * row-level check can never drift from the query scope. Used by the MIC
     * stock matcher: only on-market stock may carry an "IN STOCK" badge / be
     * suppressed from the prospectable pool (BUILD_STANDARD §6 — fix the class).
     */
    public function isOnMarket(): bool
    {
        return ! in_array((string) $this->status, self::OFF_MARKET_STATUSES, true);
    }

    /**
     * Whether this property type is a habitable dwelling that is normally
     * listed with bedroom/bathroom counts. Land, farms, commercial and
     * industrial stock are not — so readiness/completeness gates must not
     * demand Beds/Baths for them. Classification mirrors the same
     * normalised-token matching used by the P24 type resolver
     * (Property24ListingMapper::resolvePropertyTypeId) so the two never
     * disagree on what counts as "land/commercial".
     */
    public function requiresBedsBaths(): bool
    {
        $type = (string) $this->property_type;
        if ($type === '') {
            // Unknown type: assume a dwelling so we never silently drop the
            // Beds/Baths gate for a normal residential listing.
            return true;
        }

        // Normalise: lowercase, non-alphanum → single space, collapse, trim,
        // then pad so whole-token matching ("land" not "highland") works.
        $norm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/i', ' ', strtolower($type))));
        $padded = " {$norm} ";
        $contains = static function (string ...$needles) use ($padded): bool {
            foreach ($needles as $n) {
                if (str_contains($padded, " {$n} ")) {
                    return true;
                }
            }
            return false;
        };

        // Land / farm / commercial / industrial → no Beds/Baths expected.
        if ($contains('industrial')) return false;
        if ($contains('commercial', 'office', 'retail', 'hospitality')) return false;
        if ($contains('farm', 'smallholding', 'small holding', 'agricultural')) return false;
        if ($contains('vacant land', 'land', 'plot', 'stand', 'erf')) return false;

        return true;
    }

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
        'half_baths',
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
        'status_label',
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
        // agency_id is the tenant key. It stays fillable so trusted non-auth ingress
        // (promoteToStock, sold-import, P24 jobs) can stamp it — but a request from
        // an AUTHENTICATED user can never spoof it: BelongsToAgency::creating()
        // force-overrides agency_id to the user's effective agency. See that trait.
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
        'p24_hide_address',
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
        'p24_image_signature',
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
        'rental_images_json',
    ];

    protected $casts = [
        'images_json'         => 'array',
        'dawn_images_json'    => 'array',
        'noon_images_json'    => 'array',
        'dusk_images_json'    => 'array',
        'gallery_images_json' => 'array',
        'gallery_categories_json' => 'array',
        'gallery_custom_tags'     => 'array',
        'rental_images_json'      => 'array',
        'features_json'       => 'array',
        'features_json_meta'  => 'array',
        'pet_friendly'        => 'boolean',
        'spaces_json'         => 'array',
        'published_at'        => 'datetime',
        'price'               => 'integer',
        'price_on_application' => 'boolean',
        'has_deposit'         => 'boolean',
        // Money columns are decimal(12,2) in the schema (storage precision is
        // preserved there regardless of cast). They are cast to float — NOT
        // decimal:2 — on purpose: the decimal cast returns STRINGS, and every
        // consumer in this codebase (P24/PP mappers, website + mobile JSON APIs,
        // document merge fields) treats these as numbers. A string cast silently
        // changes API/serialization contracts ("8500.00" vs 8500). Keep float.
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
        'half_baths'          => 'integer',
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
        'p24_hide_address'          => 'boolean',
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

    /**
     * AT-105 — the seller/owner-side contact for this property, used when the
     * PDF Splitter files contact-destined documents (FICA, ID, Proof of
     * Residence) and when pre-populating a wet-ink FICA verification.
     *
     * Priority: a contact whose pivot role is on the seller side
     * (seller/owner/landlord/lessor — canon from BackfillContactPropertyRoles).
     * Falls back to the sole linked contact when there is exactly one, so a
     * property whose single contact has a NULL/odd role still resolves. Returns
     * null when ambiguous (multiple contacts, none seller-side) — the caller
     * then files to the property only (no orphan, no wrong guess).
     */
    public function sellerOwnerContact(): ?Contact
    {
        $contacts = $this->contacts()->get();

        if ($contacts->isEmpty()) {
            return null;
        }

        $sellerSide = ['seller', 'owner', 'landlord', 'lessor'];
        $match = $contacts->first(function ($c) use ($sellerSide) {
            $role = strtolower(trim((string) ($c->pivot->role ?? '')));
            return in_array($role, $sellerSide, true);
        });

        if ($match) {
            return $match;
        }

        return $contacts->count() === 1 ? $contacts->first() : null;
    }

    /**
     * AT-105 enhancement — the canonical pivot-role SET a routing contact_role
     * resolves across. 'seller_owner' deliberately spans BOTH seller and owner
     * (esign auto-link writes 'owner' for sellers — investigation §3). Returns
     * [] for 'none' / unknown so callers skip contact resolution cleanly.
     *
     * @return string[]
     */
    public static function pivotRolesForContactRole(?string $contactRole): array
    {
        return [
            'seller_owner' => ['seller', 'owner'],
            'buyer'        => ['buyer'],
            'tenant'       => ['tenant'],
            'landlord'     => ['landlord'],
            'lessor'       => ['lessor'],
        ][$contactRole] ?? [];
    }

    /**
     * AT-105 enhancement — ALL contacts attached to this property in a given
     * routing role, in pivot order. Unlike sellerOwnerContact() this is
     * multi-valued (joint sellers / joint buyers) and never collapses to a
     * single guess. Case-/whitespace-insensitive on the stored pivot role.
     * Returns an empty collection for role 'none'/unknown or no matches.
     */
    public function contactsForRole(?string $contactRole): \Illuminate\Support\Collection
    {
        $set = self::pivotRolesForContactRole($contactRole);
        if (empty($set)) {
            return collect();
        }

        return $this->contacts()->get()->filter(function ($c) use ($set) {
            $role = strtolower(trim((string) ($c->pivot->role ?? '')));
            return in_array($role, $set, true);
        })->values();
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

    /**
     * AT-50 — a property is "transaction-live" (its owner/seller is in an active
     * business relationship that must keep receiving comms) when EITHER:
     *
     *   (b) LIVE MANDATE — expiry_date IS NULL OR expiry_date >= today, AND the
     *       status is not a concluded/dead one. We test expiry_date DIRECTLY
     *       against today rather than trusting status='expired' (the expiry cron
     *       flips status only once a day, so it can be stale). Status comparison
     *       is case-insensitive because the stored values are inconsistent
     *       (e.g. 'Sold' vs 'sold' — see the AT-50 investigation).
     *
     *   (c) CURRENTLY ADVERTISED — any syndication channel is active, including
     *       the agency's OWN website (property_website_syndication) per Johan's
     *       call: P24, Private Property, or own-website all count.
     *
     * Single source of truth for the live-mandate-OR-advertised predicate; the
     * caller is responsible for agency isolation (BelongsToAgency when run as the
     * agency user, or an explicit agency_id filter on public/unauthenticated
     * routes where the global scope no-ops).
     */
    public function scopeTransactionLive($query)
    {
        $dead = ['expired', 'sold', 'withdrawn', 'cancelled'];

        return $query->where(function ($outer) use ($dead) {
            // (b) live mandate
            $outer->where(function ($m) use ($dead) {
                $m->where(function ($e) {
                    $e->whereNull('expiry_date')
                      ->orWhereDate('expiry_date', '>=', now()->toDateString());
                })
                  // whereNotIn() with a DB::raw() first arg binds the expression as
                  // a VALUE, not a column — it never lowercases `status`, so dead
                  // listings (e.g. 'Sold') leaked into transaction-live. Use a
                  // parameterised raw NOT IN so the LOWER(status) is a real column expr.
                  ->whereRaw('LOWER(status) NOT IN (?, ?, ?, ?)', $dead);
            })
            // (c) currently advertised (P24 / PP / own website)
            ->orWhereRaw('LOWER(p24_syndication_status) = ?', ['active'])
            ->orWhereRaw('LOWER(pp_syndication_status) = ?', ['active'])
            ->orWhereExists(function ($sub) {
                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('property_website_syndication as pws')
                    ->whereColumn('pws.property_id', 'properties.id')
                    ->whereRaw('LOWER(pws.status) = ?', ['active']);
            });
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

    /**
     * The single source of truth for a listing's price across display,
     * syndication payloads, and readiness gates. Rentals carry the amount in
     * `rental_amount` (the sale `price` column is 0/null on a rental); sales use
     * `price`. EVERY price consumer (formattedPrice, the P24 + PP mappers, the
     * readiness checks) reads this so they can never diverge — a rental never
     * needs the sale-price field filled manually.
     */
    public function effectivePrice(): float
    {
        return strtolower((string) $this->listing_type) === 'rental'
            ? (float) ($this->rental_amount ?? 0)
            : (float) ($this->price ?? 0);
    }

    public function formattedPrice(): string
    {
        return 'R ' . number_format((int) $this->effectivePrice(), 0, '.', ' ');
    }

    /**
     * Normalised rental inspection galleries. The `rental_images_json` column is
     * null until the first save, so this returns the canonical default shape
     * (empty in/out/custom) and back-fills any missing keys/sub-keys on partial
     * data. The controller and the property show view both read through this so
     * they never have to defend against missing keys.
     * Spec: .ai/specs/rental-images.md
     */
    public function rentalImagesStructure(): array
    {
        $raw = $this->rental_images_json;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $section = function ($value): array {
            $value = is_array($value) ? $value : [];

            return [
                'date'   => $value['date'] ?? null,
                'images' => array_values(array_filter(
                    is_array($value['images'] ?? null) ? $value['images'] : [],
                    'is_string'
                )),
            ];
        };

        $custom = [];
        foreach (is_array($raw['custom'] ?? null) ? $raw['custom'] : [] as $sec) {
            if (!is_array($sec) || !isset($sec['id'])) {
                continue;
            }
            $custom[] = array_merge(
                $section($sec),
                ['id' => (string) $sec['id'], 'name' => (string) ($sec['name'] ?? '')]
            );
        }

        return [
            'in_inspection'  => $section($raw['in_inspection'] ?? null),
            'out_inspection' => $section($raw['out_inspection'] ?? null),
            'custom'         => $custom,
        ];
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
            // P24 suburb id segment — must be the P24 id, not PP's. (The
            // listing resolves by the trailing p24_ref regardless, but the
            // segment should still be correct.)
            $this->p24_suburb_id ?? '0',
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
     * Canonical, extensible list of public portal links for this listing.
     *
     * This is the SINGLE source of truth for "where can the public see this
     * listing" — consumed by the web "Open listing" CTAs and by the mobile
     * app's property Overview screen. Each entry is normalised to a fixed
     * shape so any client renders any portal — including future ones —
     * without code changes:
     *
     *   [
     *     'portal' => 'property24',     // stable machine key
     *     'label'  => 'Property24',     // human label
     *     'status' => 'live'|'not_published',
     *     'url'    => 'https://…'|null, // present only when it resolves live
     *     'ref'    => '…'|null,         // the portal's listing reference
     *   ]
     *
     * Adding a portal here makes it appear everywhere (web + mobile) at once —
     * that is the whole point. "Any other portal that comes along" plugs in
     * by adding one block below.
     *
     * @return array<int, array{portal:string,label:string,status:string,url:?string,ref:?string}>
     */
    public function portalLinks(): array
    {
        $links = [];

        // ── Company website ───────────────────────────────────────
        // Live when the listing is enabled on at least one agency website
        // (the property_website_syndication pivot — same check the mobile
        // Overview placement used). The public URL is composed by the
        // config-driven public_url accessor (never hardcoded).
        $websiteLive = \App\Models\PropertyWebsiteSyndication::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('property_id', $this->id)
            ->where('enabled', true)
            ->exists();
        $links[] = [
            'portal' => 'website',
            'label'  => 'Company Website',
            'status' => $websiteLive ? 'live' : 'not_published',
            'url'    => $websiteLive ? $this->public_url : null,
            'ref'    => $this->external_id,
        ];

        // ── Property24 ─────────────────────────────────────────────
        $p24Url = $this->buildP24Url();
        $links[] = [
            'portal' => 'property24',
            'label'  => 'Property24',
            'status' => $p24Url ? 'live' : 'not_published',
            'url'    => $p24Url,
            'ref'    => $this->p24_ref,
        ];

        // ── Private Property ───────────────────────────────────────
        $ppUrl = $this->buildPpUrl();
        $links[] = [
            'portal' => 'private_property',
            'label'  => 'Private Property',
            'status' => $ppUrl ? 'live' : 'not_published',
            'url'    => $ppUrl,
            'ref'    => $this->pp_ref,
        ];

        return $links;
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

    /**
     * All images flattened into one array for convenience.
     *
     * De-duplicated on purpose: gallery_images_json and images_json
     * intentionally hold the SAME ordered set (internal UI reads gallery,
     * the public website / mobile API / readiness read images_json), so a
     * naive merge double-counts every photo. That doubling previously pushed
     * duplicate images into the Property24 payload (buildPhotos slices the
     * first 30 of this list) and inflated every photo count. array_filter
     * drops empty slots; array_unique keeps the first occurrence so order is
     * preserved.
     */
    public function allImages(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $this->dawn_images_json    ?? [],
            $this->noon_images_json    ?? [],
            $this->dusk_images_json    ?? [],
            $this->gallery_images_json ?? [],
            $this->images_json         ?? [],
        ))));
    }

    /**
     * The exact image set the agent sees in the property gallery UI
     * (`gallery_images_json` — the tag-based gallery on the property page).
     * This is the single source of truth for outbound syndication photos:
     * what we send to a portal must equal what the user curated and sees.
     *
     * allImages() is deliberately NOT used here — it also merges images_json
     * (a divergent public/website mirror) and the dawn/noon/dusk variant sets,
     * which over-counts photos the gallery never showed (e.g. property #1322:
     * gallery 45 vs allImages 68). Falls back to allImages() ONLY when the
     * gallery is empty, so a property whose photos live solely in a legacy
     * column never silently syndicates zero images.
     *
     * @return string[]
     */
    public function syndicationImages(): array
    {
        $gallery = array_values(array_unique(array_filter(
            (array) ($this->gallery_images_json ?? [])
        )));

        return !empty($gallery) ? $gallery : $this->allImages();
    }

    /**
     * Cheap fingerprint of the image set that goes to a portal (the ordered
     * syndication gallery + its caption/category map). Path-list based — NO file
     * reads — so it is safe to call on every submit. Changes when an image is
     * added, deleted, reordered, or recaptioned. Compared against the stored
     * `p24_image_signature` so a P24 re-submit only re-uploads photos when the
     * gallery actually changed (otherwise it sends `photos: null` and P24 keeps
     * the existing set — the swagger-recommended path). See AT-P24.
     */
    public function p24ImageSignature(): string
    {
        return md5(json_encode([
            $this->syndicationImages(),
            $this->gallery_categories_json,
        ]));
    }

    /**
     * The variable set the `_ad-templates` Blade partial expects, derived from
     * adData(). Lets any caller (the single-property ad page AND the bulk Ad
     * Manager) server-render a pre-built template for this property from one
     * source of truth.
     *
     * @return array<string,mixed>
     */
    public function adTemplateVars(): array
    {
        $d = $this->adData();

        return [
            'img1'        => $d['image_1'],
            'img2'        => $d['image_2'],
            'img3'        => $d['image_3'],
            'img4'        => $d['image_4'],
            'img5'        => $d['image_5'],
            'price'       => $d['price'],
            'title'       => $d['title'],
            'suburb'      => $d['suburb'],
            'type'        => $d['property_type'],
            'beds'        => $d['beds'],
            'baths'       => $d['baths'],
            'garages'     => $d['garages'],
            'size'        => $d['size_m2'],
            'initial'     => strtoupper(mb_substr((string) ($this->agent?->name ?? 'A'), 0, 1)),
            'agentName'   => $d['agent_name'],
            'agentEmail'  => $d['agent_email'],
            'agentDesig'  => $d['agent_designation'],
            'agencyName'  => strtoupper((string) $d['agency_name']),
            'website'     => strtoupper((string) $d['website']),
            'logoUrl'     => $d['logo'],
            'statusBadge' => $d['status_badge'],
        ];
    }

    /**
     * Normalise a stored image URL to one that actually loads in the browser
     * (and that Meta can fetch when publishing). Our public storage is always
     * served at `/storage/...`; stored URLs can carry a stale/localhost host or
     * the wrong scheme (from seeding/import), which then fail on staging/prod.
     * So we re-home any of-our-storage URL onto the CURRENT app host, and leave
     * genuinely external URLs untouched.
     */
    public static function publicImageUrl(?string $u): ?string
    {
        if ($u === null) {
            return null;
        }
        $u = trim($u);
        if ($u === '') {
            return null;
        }

        // Host-relative → absolute on the current host.
        if (str_starts_with($u, '/')) {
            return asset(ltrim($u, '/'));
        }

        // CONSERVATIVE: only re-home URLs baked with a local/dev host (common after
        // copying a dev DB to staging/prod). Every other working absolute URL —
        // staging, prod, a CDN, or an external source — is left exactly as stored,
        // so we never rewrite a URL that already loads.
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            $path = parse_url($u, PHP_URL_PATH) ?: '';
            return $path !== '' ? asset(ltrim($path, '/')) : $u;
        }

        return $u;
    }

    /**
     * All property images normalised for browser display (Photos picker, ad
     * previews, publishing). See publicImageUrl().
     *
     * @return array<int,string>
     */
    public function displayImages(): array
    {
        return array_values(array_filter(array_map(
            static fn ($u) => static::publicImageUrl($u),
            $this->allImages(),
        )));
    }

    /**
     * Single source of truth for the data the Ad Manager injects into a
     * template — used by both the generator (ad.blade.php) and the
     * property-linked builder live preview (ad-builder.blade.php).
     * Spec: ad-manager.md §3, §7. Keys match the builder field catalogue.
     *
     * Image URLs are stripped to a host-relative path then re-`asset()`ed so
     * html2canvas' crossorigin="anonymous" works regardless of which hostname
     * the app is reached on.
     */
    public function adData(): array
    {
        $imgs = $this->allImages();
        $img  = fn (int $i) => self::publicImageUrl($imgs[$i] ?? null);

        $agent   = $this->agent;
        $branch  = $this->branch;
        $agency  = $this->agency;

        $logoPath = $branch?->logo_path ?: $agency?->logo_path;
        $logoUrl  = $logoPath ? asset('storage/' . $logoPath) : null;

        $beds    = $this->beds;
        $baths   = $this->baths;
        $garages = $this->garages;
        $size    = $this->size_m2 ? number_format($this->size_m2) . ' M²' : null;

        // Status badge — honest label derived from the listing, never fabricated.
        // NOTE: 'pending' is NOT "under offer". Under the two-tier model Pending is
        // a SUB-LABEL on a For-Sale base (For Sale + Pending banner), so a pending
        // listing is still actively FOR SALE on the marketing card. Only a genuine
        // 'under_offer' base status reads "UNDER OFFER".
        $statusBadge = match (true) {
            in_array($this->status, ['sold', 'transferred'], true)       => 'SOLD',
            $this->status === 'under_offer'                              => 'UNDER OFFER',
            ($this->listing_type === 'rental' || $this->listing_type === 'to_let') => 'TO LET',
            default                                                      => 'FOR SALE',
        };

        return [
            'image_1'           => $img(0),
            'image_2'           => $img(1),
            'image_3'           => $img(2),
            'image_4'           => $img(3),
            'image_5'           => $img(4),
            'price'             => $this->formattedPrice(),
            'title'             => strtoupper((string) $this->title),
            'suburb'            => strtoupper((string) $this->suburb) . ($this->city ? ', ' . strtoupper((string) $this->city) : ''),
            'property_type'     => strtoupper(str_replace('_', ' ', (string) $this->property_type)),
            'features'          => trim(($beds ? $beds . ' Bed' : '') . ($baths ? ' · ' . $baths . ' Bath' : '') . ($garages ? ' · ' . $garages . ' Garage' : ''), ' · '),
            'beds'              => (string) ($beds ?? ''),
            'baths'             => (string) ($baths ?? ''),
            'garages'           => (string) ($garages ?? ''),
            'size_m2'           => $size,
            'reference'         => $this->external_id ?: ('REF ' . $this->id),
            'address'           => $this->address ?: null,
            'status_badge'      => $statusBadge,
            'agent_name'        => strtoupper((string) ($agent?->name ?? '')),
            'agent_email'       => $agent?->email ?? '',
            // User has no `mobile` column — the mobile number lives in `cell`,
            // with `phone` as the landline fallback. (Was `$agent?->mobile` which
            // was always null, so the cell number never reached the ad.)
            'agent_phone'       => $agent?->cell ?: $agent?->phone ?: '',
            'agent_designation' => $agent?->designation ?? 'Property Practitioner',
            // User has no `avatar_url` column — the photo URL comes from
            // profilePhotoUrl() (user_documents → legacy agent_photo_path).
            'agent_avatar'      => $agent?->profilePhotoUrl(),
            'agency_name'       => $agency?->name ?? '',
            'website'           => $agency?->website_url ?: '',
            'logo'              => $logoUrl,
            'watermark'         => strtoupper((string) ($agency?->name ?? '')),
        ];
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
