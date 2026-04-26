<?php

namespace App\Jobs;

use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncPropertyToWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60; // seconds between retries

    public function __construct(
        public readonly Property $property,
        public readonly string   $event = 'upsert', // 'upsert' or 'delete'
    ) {}

    public function handle(): void
    {
        if (! config('integrations.website_sync_enabled')) {
            return;
        }

        $baseUrl = rtrim((string) config('integrations.website_sync_url'), '/');
        $token   = config('integrations.website_sync_token');

        if (empty($baseUrl) || empty($token)) {
            Log::warning('SyncPropertyToWebsite: website_sync_url or token not configured.');
            return;
        }

        $http = Http::withToken($token)->timeout(15)->withoutVerifying();

        if ($this->event === 'delete') {
            $response = $http->delete("{$baseUrl}/api/listings/{$this->property->external_id}");
        } else {
            $response = $http->post("{$baseUrl}/api/listings/sync", $this->buildPayload());
        }

        if (! $response->successful()) {
            Log::error('SyncPropertyToWebsite: HTTP ' . $response->status() . ' for property ' . $this->property->external_id, [
                'event' => $this->event,
                'body'  => $response->body(),
            ]);
            $this->fail(new \RuntimeException('Website sync returned HTTP ' . $response->status()));
        }
    }

    private function buildPayload(): array
    {
        $p      = $this->property;
        $agent  = $p->agent;
        $branch = $p->branch;
        $agency = $p->agency;

        return [
            'external_id'    => $p->external_id,
            'title'          => $p->title,
            'excerpt'        => $p->excerpt,
            'description'    => $p->description,
            'listing_type'   => $p->listing_type,         // 'sale' | 'rental'
            'mandate_type'   => $p->mandate_type,
            'status'         => $p->status,
            'price'          => (int) $p->price,
            'category'       => $p->category,
            'property_type'  => $p->property_type,

            // Location — website should auto-create suburb/town/region rows if missing
            'location' => [
                'unit_number'   => $p->unit_number,
                'street_number' => $p->street_number,
                'street_name'   => $p->street_name,
                'complex_name'  => $p->complex_name,
                'suburb'        => $p->suburb,
                'town'          => $p->town ?? $p->city,
                'city'          => $p->city,
                'region'        => $p->region,
                'province'      => $p->province,
                'postal_code'   => $p->postal_code,
                'latitude'      => $p->latitude,
                'longitude'     => $p->longitude,
            ],

            // Sizes & rooms
            'beds'           => (int) $p->beds,
            'baths'          => (float) $p->baths,
            'garages'        => (int) $p->garages,
            'size_m2'        => $p->size_m2,
            'erf_size_m2'    => $p->erf_size_m2,

            // Dates
            'listed_date'    => $p->listed_date?->toDateString(),
            'expiry_date'    => $p->expiry_date?->toDateString(),
            'published_at'   => $p->published_at?->toIso8601String(),

            // Media
            'images'         => $p->allImages(),
            'dawn_images'    => $p->dawn_images_json    ?? [],
            'noon_images'    => $p->noon_images_json    ?? [],
            'dusk_images'    => $p->dusk_images_json    ?? [],
            'gallery_images' => $p->gallery_images_json ?? [],
            'youtube_video_id' => $p->youtube_video_id,
            'matterport_id'    => $p->matterport_id,

            // Features (full feature/amenity blob from listing)
            'features'       => $p->features_json ?? [],

            // Agent — website should upsert by external_id (or email) and download photo
            'agent'          => $agent ? [
                'external_id' => (string) $agent->id,
                'name'        => $agent->name,
                'email'       => $agent->email,
                'phone'       => $agent->phone ?? null,
                'bio'         => $agent->bio ?? null,
                'photo_url'   => method_exists($agent, 'profilePhotoUrl') ? $agent->profilePhotoUrl() : null,
            ] : null,

            // Agency / branch
            'agency'         => $agency ? [
                'external_id' => (string) $agency->id,
                'name'        => $agency->name,
                'branch'      => $branch?->name,
                'logo_url'    => $agency->logo_url ?? null,
            ] : null,
        ];
    }
}
