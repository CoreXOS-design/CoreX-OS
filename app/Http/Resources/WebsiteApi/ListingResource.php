<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public listing shape for agency websites — P24-parity marketing data, decoupled
 * from the internal Property schema so models can change without breaking agency
 * sites. Exposes the same rich field set CoreX syndicates to Property24 (location
 * detail, costs, the rental block, spaces, categorised gallery, video/tour, show
 * days, features) but NEVER owner contact, internal notes, or portal credentials.
 *
 * Spec: .ai/specs/agency-public-api.md §5
 */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isRental = in_array((string) $this->listing_type, ['rental', 'to_let', 'to-let', 'lease'], true);

        return [
            'id'            => $this->id,
            'reference'     => $this->external_id ?: (string) $this->id,
            // Cosmetic SEO slug + canonical public URL. The website resolves a
            // property by the trailing id in public_url, so the slug is purely
            // for readable links and may change with the title without breaking.
            'slug'          => $this->slug,
            'public_url'    => $this->public_url,
            'title'         => $this->title,
            'headline'      => $this->headline,
            'description'   => $this->description,
            'property_type' => $this->property_type,
            'title_type'    => $this->title_type,
            'listing_type'  => $this->listing_type,
            'category'      => $this->category,
            'mandate_type'  => $this->mandate_type,
            'status'        => $this->status,

            'price'                => $this->price,
            'price_display'        => $this->price_on_application ? 'POA' : ($this->price !== null ? 'R ' . number_format((int) $this->price, 0, '.', ',') : null),
            'price_on_application' => (bool) $this->price_on_application,

            // Recurring costs (sale + rental).
            'costs' => [
                'rates_taxes'  => $this->rates_taxes,
                'levy'         => $this->levy,
                'special_levy' => $this->special_levy,
            ],

            // Rental block — only populated for rental/to-let listings.
            'rental' => $isRental ? [
                'rental_amount'    => $this->rental_amount,
                'deposit_amount'   => $this->deposit_amount,
                'has_deposit'      => (bool) $this->has_deposit,
                'lease_period'     => $this->lease_period,
                'lease_start_date' => optional($this->lease_start_date)->toDateString(),
                'lease_end_date'   => optional($this->lease_end_date)->toDateString(),
                'rental_price_type' => $this->rental_price_type,
                'price_per_day'    => $this->price_per_day,
                'price_per_week'   => $this->price_per_week,
                'price_per_year'   => $this->price_per_year,
            ] : null,

            // Dimensions / rooms.
            'beds'    => $this->beds !== null ? (int) $this->beds : null,
            'baths'   => $this->baths !== null ? (float) $this->baths : null,
            'garages' => $this->garages !== null ? (int) $this->garages : null,
            'size_m2'     => $this->size_m2 !== null ? (int) $this->size_m2 : null,
            'erf_size_m2' => $this->erf_size_m2 !== null ? (int) $this->erf_size_m2 : null,
            'pet_friendly' => (bool) $this->pet_friendly,
            // Every space the agent captured — Bedroom, Bathroom, Garage,
            // Parking, Pool, Flatlet, etc. — each with its count, space-wide
            // features and per-unit detail. Unwrapped from the canonical
            // {spaces:[...], features:{...}} editor shape (see mapSpaces()).
            'spaces'  => $this->mapSpaces(),

            // Location.
            'address'       => $this->address,
            'street_number' => $this->street_number,
            'street_name'   => $this->street_name,
            'complex_name'  => $this->complex_name,
            'unit_number'   => $this->unit_number,
            'floor_number'  => $this->floor_number !== null ? (int) $this->floor_number : null,
            'stand_number'  => $this->stand_number,
            'suburb'        => $this->suburb,
            'town'          => $this->town,
            'city'          => $this->city,
            'province'      => $this->province,
            'latitude'      => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude'     => $this->longitude !== null ? (float) $this->longitude : null,

            // Flat feature list — kept for backward compatibility.
            'features' => array_values(array_filter((array) ($this->features_json ?? []))),
            // Same features, grouped by the catalog category they belong to
            // (The Property / Security / Connectivity / Sustainability) so the
            // website can render "Security: Intercom, CCTV" instead of one
            // undifferentiated chip wall. Anything not in the catalog lands in
            // an "Other" group. See groupFeatures().
            'features_grouped' => $this->groupFeatures(),

            // Media — allImages() merges dawn/noon/dusk/gallery/images JSON buckets,
            // mirroring what CoreX syndicates to P24.
            'images'  => $this->mapImages($this->allImages()),
            'gallery' => $this->mapGallery(),
            'video'   => [
                // Canonical 11-char YouTube id (CoreX extracts it from any URL
                // the agent pastes) PLUS a ready-to-use watch URL so the site
                // can embed without re-deriving it.
                'youtube_id'       => $this->youtube_video_id ?: null,
                'youtube_url'      => $this->youtube_video_id
                    ? 'https://www.youtube.com/watch?v=' . $this->youtube_video_id
                    : null,
                'matterport_id'    => $this->matterport_id ?: null,
                // "Other Virtual Tour / Video URL" from the editor — iPanorama,
                // Kuula, findaholiday, any embeddable 360/video host.
                'virtual_tour_url' => $this->virtual_tour_url ?: null,
            ],

            // Upcoming show days (open houses).
            'show_days' => $this->relationLoaded('activeShowdays')
                ? $this->activeShowdays->map(fn ($s) => [
                    'start' => optional($s->start_date)->toIso8601String(),
                    'end'   => optional($s->end_date)->toIso8601String(),
                    'note'  => $s->description,
                ])->values()
                : [],

            // Primary agent — kept as a single object for backward compatibility
            // with existing website consumers that read `agent`.
            'agent' => $this->when(
                $this->relationLoaded('agent') && $this->agent,
                fn () => new AgentResource($this->agent)
            ),

            // All agents working this listing, primary first. A co-listed
            // property carries two entries so it appears on BOTH agents'
            // profiles. Single-agent listings return a one-element array.
            // Each entry adds `is_primary` so the site can label the lead agent.
            'agents' => $this->buildAgents(),

            'published_at' => optional($this->published_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Build the agents list for this listing — primary first, then the
     * co-listing agent if one is set. Each entry is the public AgentResource
     * shape plus `is_primary` so the website can mark the lead agent and link
     * the property onto every agent's profile. De-dupes if the same user is
     * recorded in both slots. Returns [] if no agent relationships are loaded.
     */
    private function buildAgents(): array
    {
        $out = [];
        $seen = [];

        $candidates = [
            [$this->relationLoaded('agent') ? $this->agent : null, true],
            [$this->relationLoaded('secondAgent') ? $this->secondAgent : null, false],
        ];

        foreach ($candidates as [$agent, $isPrimary]) {
            if (!$agent || isset($seen[$agent->id])) {
                continue;
            }
            $seen[$agent->id] = true;
            $out[] = array_merge(
                (new AgentResource($agent))->resolve(),
                ['is_primary' => $isPrimary],
            );
        }

        return $out;
    }

    /**
     * Normalise the property's spaces into a clean public list.
     *
     * The editor persists spaces_json in the canonical wrapped shape
     * {spaces:[{type,count,featuresAll,descriptionAll,units:[...]}], features:{...}}.
     * Older / imported records may carry a flat [{type,count}] array, or even a
     * bare ["Covered parking"] string list. All three collapse to the same
     * output: one object per space with its type, count, space-wide features,
     * optional description and per-unit breakdown. The sibling `features` key in
     * the wrapped shape is intentionally ignored here — those global features
     * are surfaced via features / features_grouped, not as spaces.
     */
    private function mapSpaces(): array
    {
        $raw = $this->spaces_json ?? [];

        // Canonical wrapped shape stores the list under `spaces`; legacy shapes
        // ARE the list. is_string keys (the wrapped form) → use ['spaces'].
        $list = (is_array($raw) && array_key_exists('spaces', $raw) && is_array($raw['spaces']))
            ? $raw['spaces']
            : (array) $raw;

        $out = [];
        foreach ($list as $sp) {
            // Bare string entry (oldest shape) → treat as a typed space, no count.
            if (is_string($sp)) {
                $sp = trim($sp);
                if ($sp !== '') {
                    $out[] = ['type' => $sp, 'count' => null, 'features' => [], 'description' => null];
                }
                continue;
            }
            if (!is_array($sp) || empty($sp['type'])) {
                continue;
            }

            $count = $sp['count'] ?? null;
            if (is_numeric($count)) {
                // Whole numbers stay ints (3), halves keep the fraction (2.5).
                $count = ((float) $count === (float) (int) $count) ? (int) $count : (float) $count;
            } else {
                $count = null;
            }

            $entry = [
                'type'        => (string) $sp['type'],
                'count'       => $count,
                'features'    => array_values(array_filter((array) ($sp['featuresAll'] ?? []))),
                'description' => isset($sp['descriptionAll']) && $sp['descriptionAll'] !== ''
                    ? (string) $sp['descriptionAll']
                    : null,
            ];

            // Per-unit detail (e.g. "Bedroom 1" with its own features) — only
            // emitted when at least one unit carries a label or features.
            $units = [];
            foreach ((array) ($sp['units'] ?? []) as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $uFeatures = array_values(array_filter((array) ($u['features'] ?? [])));
                $uLabel    = isset($u['label']) && $u['label'] !== '' ? (string) $u['label'] : null;
                if ($uLabel === null && empty($uFeatures)) {
                    continue;
                }
                $units[] = ['label' => $uLabel, 'features' => $uFeatures];
            }
            if (!empty($units)) {
                $entry['units'] = $units;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Group the flat features_json list by the catalog category each feature
     * belongs to, so the website can label them (Security, Connectivity, …).
     *
     * Source of truth for the category → features map is
     * config/property-spaces.php `feature_categories`. Groups are emitted in
     * catalog order; any feature not found in the catalog falls into a trailing
     * "Other" group. Empty groups are dropped. Returns [] when no features.
     */
    private function groupFeatures(): array
    {
        $flat = array_values(array_filter((array) ($this->features_json ?? [])));
        if (empty($flat)) {
            return [];
        }

        $categories = (array) config('property-spaces.feature_categories', []);

        // Build a lowercase lookup: feature => category key.
        $lookup = [];
        foreach ($categories as $key => $cat) {
            foreach ((array) ($cat['features'] ?? []) as $f) {
                $lookup[mb_strtolower((string) $f)] = $key;
            }
        }

        // Seed buckets in catalog order, with "Other" reserved for the tail.
        $groups = [];
        foreach ($categories as $key => $cat) {
            $groups[$key] = ['group' => $key, 'label' => (string) ($cat['label'] ?? $key), 'items' => []];
        }
        $groups['other'] = ['group' => 'other', 'label' => 'Other', 'items' => []];

        foreach ($flat as $feature) {
            $bucket = $lookup[mb_strtolower((string) $feature)] ?? 'other';
            $groups[$bucket]['items'][] = $feature;
        }

        return array_values(array_filter($groups, fn ($g) => !empty($g['items'])));
    }

    /**
     * Normalise an images JSON column (mix of strings / {url|path} arrays) into a
     * flat list of absolute URLs. Relative paths resolve against the public disk.
     */
    private function mapImages($raw): array
    {
        $mapped = array_filter(array_map(function ($item) {
            $val = is_array($item) ? ($item['url'] ?? $item['path'] ?? null) : $item;
            if (!is_string($val) || $val === '') {
                return null;
            }
            if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                return $val;
            }
            // Stored values arrive in two shapes: bare disk-relative paths
            // (`properties/123/x.jpg`) and the already-public URL that
            // Storage::url() produced at upload time (`/storage/properties/...`).
            // Strip a leading `storage/` segment so we resolve from the bare
            // path and never emit `/storage/storage/...` — which 403s for the
            // website (and any other API consumer). Idempotent for both shapes.
            $relative = ltrim($val, '/');
            if (str_starts_with($relative, 'storage/')) {
                $relative = substr($relative, strlen('storage/'));
            }
            return Storage::disk('public')->url($relative);
        }, (array) $raw));

        return array_values(array_unique($mapped));
    }

    /**
     * Categorised gallery: { category => [urls] }. Handles both the canonical
     * CoreX shape gallery_categories_json = {categories:[{name, images:[]}]} and
     * a plain {name: [images]} map.
     */
    private function mapGallery(): array
    {
        $raw = $this->gallery_categories_json ?? [];
        $out = [];

        if (isset($raw['categories']) && is_array($raw['categories'])) {
            foreach ($raw['categories'] as $cat) {
                $urls = $this->mapImages($cat['images'] ?? []);
                if (!empty($urls)) {
                    $out[(string) ($cat['name'] ?? 'Gallery')] = $urls;
                }
            }
            return $out;
        }

        foreach ((array) $raw as $name => $images) {
            if (!is_array($images)) {
                continue;
            }
            $urls = $this->mapImages($images);
            if (!empty($urls)) {
                $out[(string) $name] = $urls;
            }
        }
        return $out;
    }
}
