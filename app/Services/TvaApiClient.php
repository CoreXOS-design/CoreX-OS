<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TVA (Transfer Verification Authority) API Client — stub.
 * Activates when TVA_API_KEY is configured in .env.
 * Until then: returns empty results gracefully.
 */
class TvaApiClient
{
    private ?string $apiKey;
    private ?string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.tva.api_key', env('TVA_API_KEY'));
        $this->baseUrl = config('services.tva.base_url', env('TVA_API_BASE_URL', 'https://api.tva.co.za/v1'));
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Search sold properties by area within a date range.
     */
    public function searchSoldByArea(string $area, int $rangeMonths = 6): array
    {
        if (!$this->isConfigured()) return [];

        try {
            $response = Http::withToken($this->apiKey)
                ->get($this->baseUrl . '/sold/search', [
                    'area' => $area,
                    'from_date' => now()->subMonths($rangeMonths)->toDateString(),
                    'to_date' => now()->toDateString(),
                ]);

            return $response->successful() ? $response->json('data', []) : [];
        } catch (\Throwable $e) {
            Log::warning("TvaApiClient: searchSoldByArea failed: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get details of a specific sold record.
     */
    public function getSoldDetails(string $externalId): ?array
    {
        if (!$this->isConfigured()) return null;

        try {
            $response = Http::withToken($this->apiKey)
                ->get($this->baseUrl . '/sold/' . $externalId);

            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable $e) {
            Log::warning("TvaApiClient: getSoldDetails failed: {$e->getMessage()}");
            return null;
        }
    }
}
