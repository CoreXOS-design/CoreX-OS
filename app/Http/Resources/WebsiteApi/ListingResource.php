<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public listing shape for agency websites. Decoupled from the internal
 * Property schema so models can change without breaking agency sites.
 * Exposes ONLY public marketing data — never owner contact, internal notes,
 * or portal credentials.
 *
 * Spec: .ai/specs/agency-public-api.md §5
 */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'reference'     => $this->external_id ?: (string) $this->id,
            'title'         => $this->title,
            'headline'      => $this->headline,
            'description'   => $this->description,
            'property_type' => $this->property_type,
            'listing_type'  => $this->listing_type,
            'status'        => $this->status,

            'price'         => $this->price,
            'price_display' => $this->price_on_application ? 'POA' : ($this->price !== null ? 'R ' . number_format((int) $this->price, 0, '.', ',') : null),
            'price_on_application' => (bool) $this->price_on_application,

            'beds'    => $this->beds,
            'baths'   => $this->baths !== null ? (float) $this->baths : null,
            'garages' => $this->garages,
            'size_m2'     => $this->size_m2,
            'erf_size_m2' => $this->erf_size_m2,

            'address'  => $this->address,
            'suburb'   => $this->suburb,
            'town'     => $this->town,
            'city'     => $this->city,
            'province' => $this->province,
            'latitude'  => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,

            'features' => array_values(array_filter((array) ($this->features_json ?? []))),
            'images'   => $this->mapImages(),

            'agent' => $this->when(
                $this->relationLoaded('agent') && $this->agent,
                fn () => new AgentResource($this->agent)
            ),

            'published_at' => optional($this->published_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Normalise the images JSON (mix of strings / {url|path} arrays) into a
     * flat list of absolute URLs. Relative paths resolve against the public disk.
     */
    private function mapImages(): array
    {
        $raw = (array) ($this->images_json ?: $this->gallery_images_json ?? []);

        return array_values(array_filter(array_map(function ($item) {
            $val = is_array($item) ? ($item['url'] ?? $item['path'] ?? null) : $item;
            if (!is_string($val) || $val === '') {
                return null;
            }
            if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                return $val;
            }
            return Storage::disk('public')->url(ltrim($val, '/'));
        }, $raw)));
    }
}
