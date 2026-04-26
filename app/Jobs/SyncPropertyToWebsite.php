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
use Illuminate\Support\Facades\Storage;

class SyncPropertyToWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60; // seconds between retries
    public int $timeout = 120;

    /** Max images to embed per bucket. P24 caps at 30; we mirror that. */
    private const MAX_IMAGES_PER_BUCKET = 30;

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

        $http = Http::withToken($token)
            ->timeout(120)
            ->withoutVerifying()
            ->acceptJson()
            ->asJson();

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

            // Media — base64-embedded bytes so the website does not need to reach the dash
            // Each item: { bytes: <base64>, mime: 'image/jpeg', filename: 'foo.jpg', sort_order: int }
            'images'         => $this->embedImages($p->allImages()),
            'dawn_images'    => $this->embedImages($p->dawn_images_json    ?? []),
            'noon_images'    => $this->embedImages($p->noon_images_json    ?? []),
            'dusk_images'    => $this->embedImages($p->dusk_images_json    ?? []),
            'gallery_images' => $this->embedImages($p->gallery_images_json ?? []),
            'youtube_video_id' => $p->youtube_video_id,
            'matterport_id'    => $p->matterport_id,

            // Features (full feature/amenity blob from listing)
            'features'       => $p->features_json ?? [],

            // Agent — website upserts by external_id (or email) and saves the embedded photo bytes
            'agent'          => $agent ? [
                'external_id' => (string) $agent->id,
                'name'        => $agent->name,
                'email'       => $agent->email,
                'phone'       => $agent->phone ?? null,
                'bio'         => $agent->bio ?? null,
                'photo'       => $this->embedAgentPhoto($agent),
            ] : null,

            // Agency / branch — receiver stores name + branch on the agent row
            'agency'         => $agency ? [
                'external_id' => (string) $agency->id,
                'name'        => $agency->name,
                'branch'      => $branch?->name,
            ] : null,
        ];
    }

    /**
     * Embed an agent's profile photo as { filename, mime, bytes }.
     * Resolves via the User model's profilePhotoUrl(), then falls back to agent_photo_path on disk.
     */
    private function embedAgentPhoto($agent): ?array
    {
        $disk = Storage::disk('public');

        // Prefer raw disk path on legacy column (avoids re-downloading our own URL)
        if (! empty($agent->agent_photo_path) && $disk->exists($agent->agent_photo_path)) {
            $bytes = $disk->get($agent->agent_photo_path);
            return [
                'filename' => basename($agent->agent_photo_path),
                'mime'     => $disk->mimeType($agent->agent_photo_path) ?: 'image/jpeg',
                'bytes'    => base64_encode($bytes),
            ];
        }

        // Fall back to whatever profilePhotoUrl() resolves to (e.g. user_documents profile_photo)
        $url = method_exists($agent, 'profilePhotoUrl') ? $agent->profilePhotoUrl() : null;
        if (empty($url)) return null;

        try {
            $resp = Http::timeout(20)->withoutVerifying()->get($url);
            if (! $resp->successful() || empty($resp->body())) return null;
            return [
                'filename' => basename(parse_url($url, PHP_URL_PATH) ?: 'agent.jpg'),
                'mime'     => $resp->header('Content-Type') ?: 'image/jpeg',
                'bytes'    => base64_encode($resp->body()),
            ];
        } catch (\Throwable $e) {
            Log::warning('SyncPropertyToWebsite: failed to fetch agent photo', ['url' => $url, 'err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Read each stored image off the public disk and base64-embed it for the website.
     * Accepts disk paths ("properties/40/abc.jpg"), Storage::url() paths ("/storage/..."),
     * or remote https URLs (downloaded inline).
     */
    private function embedImages(array $entries): array
    {
        $disk = Storage::disk('public');
        $out  = [];
        $i    = 0;

        foreach ($entries as $entry) {
            if (empty($entry) || ! is_string($entry)) continue;
            if (count($out) >= self::MAX_IMAGES_PER_BUCKET) break;

            $bytes    = null;
            $mime     = null;
            $filename = basename(parse_url($entry, PHP_URL_PATH) ?: $entry);

            if (str_starts_with($entry, 'http://') || str_starts_with($entry, 'https://')) {
                try {
                    $resp = Http::timeout(20)->withoutVerifying()->get($entry);
                    if ($resp->successful()) {
                        $bytes = $resp->body();
                        $mime  = $resp->header('Content-Type') ?: null;
                    }
                } catch (\Throwable $e) {
                    Log::warning('SyncPropertyToWebsite: failed to download remote image', ['url' => $entry, 'err' => $e->getMessage()]);
                }
            } else {
                $diskPath = ltrim(str_starts_with($entry, '/storage/') ? substr($entry, 9) : $entry, '/');
                if ($disk->exists($diskPath)) {
                    $bytes = $disk->get($diskPath);
                    $mime  = $disk->mimeType($diskPath) ?: null;
                }
            }

            if (empty($bytes)) continue;

            $out[] = [
                'filename'   => $filename ?: ('image-' . $i . '.jpg'),
                'mime'       => $mime ?: 'image/jpeg',
                'bytes'      => base64_encode($bytes),
                'sort_order' => $i,
            ];
            $i++;
        }

        return $out;
    }
}
