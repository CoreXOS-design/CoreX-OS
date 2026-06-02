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
            'spaces'  => array_values((array) ($this->spaces_json ?? [])),

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

            'features' => array_values(array_filter((array) ($this->features_json ?? []))),

            // Media — allImages() merges dawn/noon/dusk/gallery/images JSON buckets,
            // mirroring what CoreX syndicates to P24.
            'images'  => $this->mapImages($this->allImages()),
            'gallery' => $this->mapGallery(),
            'video'   => [
                'youtube_id'       => $this->youtube_video_id,
                'matterport_id'    => $this->matterport_id,
                'virtual_tour_url' => $this->virtual_tour_url,
            ],

            // Upcoming show days (open houses).
            'show_days' => $this->relationLoaded('activeShowdays')
                ? $this->activeShowdays->map(fn ($s) => [
                    'start' => optional($s->start_date)->toIso8601String(),
                    'end'   => optional($s->end_date)->toIso8601String(),
                    'note'  => $s->description,
                ])->values()
                : [],

            'agent' => $this->when(
                $this->relationLoaded('agent') && $this->agent,
                fn () => new AgentResource($this->agent)
            ),

            'published_at' => optional($this->published_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
        ];
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
            return Storage::disk('public')->url(ltrim($val, '/'));
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
