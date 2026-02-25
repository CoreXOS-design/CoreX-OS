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

        $payload = $this->buildPayload();

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("{$baseUrl}/api/listings/sync", $payload);

        if (! $response->successful()) {
            Log::error('SyncPropertyToWebsite: HTTP ' . $response->status() . ' for property ' . $this->property->external_id, [
                'body' => $response->body(),
            ]);
            $this->fail(new \RuntimeException('Website sync returned HTTP ' . $response->status()));
        }
    }

    private function buildPayload(): array
    {
        $p = $this->property;

        if ($this->event === 'delete') {
            return [
                'event'       => 'delete',
                'external_id' => $p->external_id,
            ];
        }

        $agent  = $p->agent;
        $branch = $p->branch;
        $agency = $p->agency;

        return [
            'event'         => 'upsert',
            'external_id'   => $p->external_id,
            'title'         => $p->title,
            'description'   => $p->description,
            'price'         => (int) $p->price,
            'suburb'        => $p->suburb,
            'region'        => $p->region,
            'beds'          => (int) $p->beds,
            'baths'         => (int) $p->baths,
            'garages'       => (int) $p->garages,
            'size_m2'       => $p->size_m2,
            'erf_size_m2'   => $p->erf_size_m2,
            'property_type' => $p->property_type,
            'mandate_type'  => $p->mandate_type,
            'status'        => $p->status,
            'images'        => $p->images_json ?? [],
            'agent'         => $agent ? [
                'name'  => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone ?? null,
            ] : null,
            'agency'        => $agency ? [
                'name'   => $agency->name,
                'branch' => $branch?->name,
            ] : null,
            'published_at'  => $p->published_at?->toIso8601String(),
        ];
    }
}
