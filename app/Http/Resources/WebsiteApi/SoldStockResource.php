<?php

namespace App\Http\Resources\WebsiteApi;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public shape of one frozen sale (sold_stock row) for the agency website's Sold showcase.
 * Spec: .ai/specs/sold-stock.md section 6. Reads the snapshot only - never properties.status.
 *
 * @mixin \App\Models\SoldStock
 */
class SoldStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $agents = [];
        foreach (User::query()->whereIn('id', (array) ($this->agent_ids ?: [$this->agent_id]))->where('is_active', true)->get() as $agent) {
            $agents[] = (new AgentResource($agent))->resolve();
        }

        return [
            'id'             => $this->id,
            'property_id'    => $this->property_id,
            'cycle'          => (int) $this->cycle_no,
            'title'          => $this->title,
            'description'    => $this->description,
            'features'       => $this->features ?? [],
            'property_type'  => $this->property_type,
            'listing_type'   => $this->listing_type,
            'address'        => $this->suburb ? trim(($this->suburb) . ($this->city ? ', ' . $this->city : '')) : $this->city,
            'suburb'         => $this->suburb,
            'city'           => $this->city,
            'beds'           => $this->beds !== null ? (int) $this->beds : null,
            'baths'          => $this->baths !== null ? (int) $this->baths : null,
            'size_m2'        => $this->size_m2 !== null ? (int) $this->size_m2 : null,
            'asking_price'   => $this->asking_price !== null ? (float) $this->asking_price : null,
            'sale_price'     => $this->sale_price !== null ? (float) $this->sale_price : null,
            'sold_at'        => optional($this->sale_date)->toDateString(),
            'days_on_market' => $this->days_on_market,
            'images'         => $this->mapImages((array) ($this->photos ?? [])),
            'agents'         => $agents,
        ];
    }

    /** Same path/URL normalisation as ListingResource::mapImages. */
    private function mapImages(array $raw): array
    {
        $out = [];
        foreach ($raw as $val) {
            if (! is_string($val) || $val === '') {
                continue;
            }
            if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
                $out[] = $val;
                continue;
            }
            $relative = ltrim($val, '/');
            if (str_starts_with($relative, 'storage/')) {
                $relative = substr($relative, strlen('storage/'));
            }
            $out[] = Storage::disk('public')->url($relative);
        }

        return array_values(array_unique($out));
    }
}
