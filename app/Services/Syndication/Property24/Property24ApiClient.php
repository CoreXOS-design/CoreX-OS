<?php

namespace App\Services\Syndication\Property24;

use App\Models\P24SyndicationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Property24ApiClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $agencyId;
    private string $apiVersion;
    private bool $sandbox;

    public function __construct()
    {
        $config = config('services.property24_syndication');

        $this->baseUrl    = rtrim($config['api_url'] ?? '', '/');
        $this->username   = $config['username'] ?? '';
        $this->password   = $config['password'] ?? '';
        $this->agencyId   = $config['agency_id'] ?? '';
        $this->apiVersion = $config['api_version'] ?? 'v53';
        $this->sandbox    = (bool) ($config['sandbox'] ?? true);
    }

    /**
     * Save a listing (create new or update existing).
     * For new listings, listingNumber in payload must be null.
     * For updates, include listingNumber in the payload.
     */
    public function saveListing(int $propertyId, array $payload): array
    {
        return $this->request('POST', '/listings', $payload, $propertyId, 'submit');
    }

    /**
     * Update listing status (e.g. Withdrawn, Sold, Active, BackOnMarket).
     */
    public function setListingStatus(int $propertyId, int $listingNumber, string $status): array
    {
        return $this->request('PUT', "/listings/{$listingNumber}/status?listingStatus={$status}", [], $propertyId, 'status_update');
    }

    /**
     * Check if a listing is on the portal.
     */
    public function isOnPortal(int $propertyId, int $listingNumber): array
    {
        return $this->request('GET', "/listings/{$listingNumber}/is-on-portal", [], $propertyId, 'status_check');
    }

    /**
     * Get listing updates since a given time.
     */
    public function getListingUpdates(?string $since = null): array
    {
        $query = $since ? "?updatedAfter={$since}" : '';
        return $this->request('GET', "/listings/updates{$query}", [], null, 'list_updates');
    }

    /**
     * Fetch suburbs by city ID.
     */
    public function getSuburbs(?int $cityId = null): array
    {
        $query = $cityId ? "?cityId={$cityId}" : '';
        return $this->request('GET', "/suburbs{$query}", [], null, 'fetch_suburbs');
    }

    /**
     * Find a suburb by name/qualification data.
     */
    public function findSuburb(string $suburbName, ?string $cityName = null, ?string $provinceName = null): array
    {
        $params = ['suburbName' => $suburbName];
        if ($cityName) $params['cityName'] = $cityName;
        if ($provinceName) $params['provinceName'] = $provinceName;

        $query = '?' . http_build_query($params);
        return $this->request('GET', "/suburbs/find{$query}", [], null, 'find_suburb');
    }

    /**
     * Fetch all property types.
     */
    public function getPropertyTypes(): array
    {
        return $this->request('GET', '/property-types', [], null, 'fetch_property_types');
    }

    /**
     * Fetch agents for the agency.
     */
    public function getAgents(): array
    {
        return $this->request('GET', "/agencies/{$this->agencyId}/agents", [], null, 'fetch_agents');
    }

    /**
     * Get agency details.
     */
    public function getAgency(): array
    {
        return $this->request('GET', "/agencies/{$this->agencyId}", [], null, 'fetch_agency');
    }

    /**
     * Smoke test: echo-authenticated to verify credentials.
     */
    public function smokeTest(): array
    {
        return $this->request('GET', '/echo-authenticated?stringToEcho=CoreX+OS+smoke+test', [], null, 'smoke_test');
    }

    /**
     * Execute an HTTP request to the P24 ExDev API.
     * Uses Basic Authentication per P24 docs.
     */
    private function request(string $method, string $endpoint, array $payload, ?int $propertyId, string $action): array
    {
        $path = "/listing/{$this->apiVersion}" . $endpoint;
        $url  = $this->baseUrl . $path;

        $this->log('info', "P24 {$method} {$path}", [
            'property_id' => $propertyId,
            'action'      => $action,
        ]);

        try {
            $http = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(120)
                ->connectTimeout(15);

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url),
                'POST'   => $http->post($url, $payload),
                'PUT'    => $http->put($url, $payload ?: null),
                'DELETE' => $http->delete($url),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            $statusCode = $response->status();

            $contentType = $response->header('Content-Type') ?? '';
            if (str_contains($contentType, 'application/json')) {
                $responseData = $response->json() ?? [];
            } else {
                $responseData = ['raw' => $response->body()];
            }

            $this->logToDb($propertyId, $action, $payload ?: null, $responseData, $statusCode);

            if ($response->successful()) {
                $this->log('info', "P24 {$action} succeeded", [
                    'property_id' => $propertyId,
                    'status'      => $statusCode,
                ]);

                return [
                    'success'     => true,
                    'status_code' => $statusCode,
                    'data'        => $responseData,
                ];
            }

            $errorMessage = $this->extractErrorMessage($responseData, $statusCode);

            $this->log('error', "P24 {$action} failed: {$errorMessage}", [
                'property_id' => $propertyId,
                'status'      => $statusCode,
                'response'    => $responseData,
            ]);

            return [
                'success'     => false,
                'status_code' => $statusCode,
                'message'     => $errorMessage,
                'data'        => $responseData,
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $error = 'Connection failed: ' . $e->getMessage();
            $this->log('error', "P24 {$action} connection error", ['property_id' => $propertyId, 'error' => $error]);
            $this->logToDb($propertyId, $action, $payload ?: null, ['error' => $error], null);

            return ['success' => false, 'message' => $error, 'data' => []];
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $this->log('error', "P24 {$action} error: {$error}", ['property_id' => $propertyId]);
            $this->logToDb($propertyId, $action, $payload ?: null, ['error' => $error], null);

            return ['success' => false, 'message' => $error, 'data' => []];
        }
    }

    private function extractErrorMessage(array $data, int $statusCode): string
    {
        if (!empty($data['message'])) return $data['message'];
        if (!empty($data['Message'])) return $data['Message'];
        if (!empty($data['title'])) return $data['title'];

        // P24 v53 returns validation errors as arrays
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $parts = [];
            foreach ($data['errors'] as $field => $messages) {
                if (is_array($messages)) {
                    $parts[] = $field . ': ' . implode(', ', $messages);
                } else {
                    $parts[] = is_string($messages) ? $messages : json_encode($messages);
                }
            }
            if ($parts) return 'Validation errors — ' . implode('; ', $parts);
        }
        if (!empty($data['Errors'])) {
            $errs = is_array($data['Errors']) ? json_encode($data['Errors']) : $data['Errors'];
            return 'API errors: ' . $errs;
        }

        if (!empty($data['raw']) && strlen($data['raw']) < 500) return $data['raw'];

        return "HTTP {$statusCode} — check P24 syndication log for full response";
    }

    private function logToDb(?int $propertyId, string $action, ?array $request, mixed $response, ?int $statusCode): void
    {
        if ($propertyId === null) return;

        // Ensure response is array for JSON column
        if (!is_array($response)) {
            $response = ['raw' => (string) $response];
        }

        P24SyndicationLog::create([
            'property_id'      => $propertyId,
            'action'           => $action,
            'request_payload'  => $request,
            'response_payload' => $response,
            'status_code'      => $statusCode,
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('property24')->{$level}($message, $context);
    }

    public function getAgencyId(): string
    {
        return $this->agencyId;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }
}
