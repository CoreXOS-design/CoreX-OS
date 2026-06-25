<?php

namespace App\Services\Syndication\Property24;

use App\Models\Agency;
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
    private ?string $userGroupId;

    /**
     * In-process memo of the P24 agent list per agency id, SHARED across client
     * instances for the life of the request/command. getAgents() is otherwise
     * called 2–3× per listing (ensureAgentRegistered + resolveContactAgentIds,
     * each on its own client instance); against a slow/rate-limited P24 those
     * repeated full-list fetches time out (~45s each) — the dominant cost of a
     * sync AND the cause of spurious "must have one or more agents" rejections.
     * Only SUCCESSFUL fetches are cached; busted on any agent create/update so a
     * newly-registered agent is picked up on the next lookup. See AT-P24.
     */
    private static array $agentsCache = [];

    public function __construct(?Agency $agency = null)
    {
        $config = config('services.property24_syndication');

        $this->baseUrl    = rtrim($config['api_url'] ?? '', '/');
        $this->apiVersion = $config['api_version'] ?? 'v53';
        $this->sandbox    = (bool) ($config['sandbox'] ?? true);

        if ($agency && !empty($agency->p24_username) && !empty($agency->p24_password)) {
            $this->username    = (string) $agency->p24_username;
            $this->password    = (string) $agency->p24_password;
            $this->agencyId    = (string) ($agency->p24_agency_id ?? '');
            $this->userGroupId = $agency->p24_user_group_id ?: null;
        } else {
            $this->username    = $config['username'] ?? '';
            $this->password   = $config['password'] ?? '';
            $this->agencyId   = $config['agency_id'] ?? '';
            $this->userGroupId = $config['user_group_id'] ?? null;
        }
    }

    /** Fetch supported countries. */
    public function getCountries(): array
    {
        return $this->request('GET', '/countries', [], null, 'fetch_countries');
    }

    /** Fetch supported provinces (optionally filtered by country ID). */
    public function getProvinces(?int $countryId = null): array
    {
        $query = $countryId ? "?countryId={$countryId}" : '';
        return $this->request('GET', "/provinces{$query}", [], null, 'fetch_provinces');
    }

    /** Fetch supported cities (optionally filtered by province ID). */
    public function getCities(?int $provinceId = null): array
    {
        $query = $provinceId ? "?provinceId={$provinceId}" : '';
        return $this->request('GET', "/cities{$query}", [], null, 'fetch_cities');
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
    public function findSuburb(string $suburbName, ?string $cityName = null, ?string $provinceName = null, string $countryName = 'South Africa'): array
    {
        $params = [
            'countryName'  => $countryName,
            'provinceName' => $provinceName ?: '',
            'cityName'     => $cityName ?: '',
            'suburbName'   => $suburbName,
        ];

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
     * Fetch agents for the given P24 agency (defaults to the configured one
     * in .env). Always pass an explicit agency ID when operating under a
     * CoreX tenant other than the config default, otherwise the result is
     * scoped to the wrong P24 profile and sourceReference lookups fail.
     */
    public function getAgents(?string $agencyIdOverride = null): array
    {
        $agencyId = $agencyIdOverride !== null && $agencyIdOverride !== ''
            ? $agencyIdOverride
            : $this->agencyId;

        // Guard: never call /agencies//agents (empty id → 404, wasted ~35s).
        if ($agencyId === null || $agencyId === '') {
            return ['success' => false, 'message' => 'No P24 agency ID for agent lookup', 'data' => []];
        }

        $key = (string) $agencyId;
        if (isset(self::$agentsCache[$key])) {
            return self::$agentsCache[$key];
        }

        $result = $this->request('GET', "/agencies/{$agencyId}/agents", [], null, 'fetch_agents');

        // Cache ONLY a successful fetch — never memoize a timeout/connection
        // error, so a transient failure can't poison the whole run.
        if ($result['success'] ?? false) {
            self::$agentsCache[$key] = $result;
        }

        return $result;
    }

    /**
     * Register a new agent on P24.
     */
    public function createAgent(array $agentData): array
    {
        $result = $this->request('POST', '/agents', $agentData, null, 'create_agent');
        self::$agentsCache = []; // a new agent must appear in the next lookup
        return $result;
    }

    /**
     * Update an existing agent on P24.
     */
    public function updateAgent(array $agentData): array
    {
        $result = $this->request('PUT', '/agents', $agentData, null, 'update_agent');
        self::$agentsCache = []; // sourceReference/details changed — refresh next lookup
        return $result;
    }

    /**
     * Upload an agent's profile picture.
     */
    public function uploadAgentPhoto(int $agentId, array $imageData): array
    {
        return $this->request('PUT', "/agents/{$agentId}/profile-picture", $imageData, null, 'upload_agent_photo');
    }

    /**
     * Get a specific agent by ID.
     */
    public function getAgent(int $agentId): array
    {
        return $this->request('GET', "/agents/{$agentId}", [], null, 'fetch_agent');
    }

    /**
     * Get agency details.
     */
    public function getAgency(): array
    {
        return $this->request('GET', "/agencies/{$this->agencyId}", [], null, 'fetch_agency');
    }

    /**
     * Fetch buyer-enquiry leads received via P24 since the given ISO-8601 timestamp.
     *
     * Endpoint per Listing Service v53: GET /listings/leads?after=<iso8601>
     * Returns the raw P24 envelope under data; caller is responsible for parsing
     * individual lead records (P24LeadService).
     */
    public function getLeads(?string $after = null): array
    {
        $query = $after ? '?after=' . rawurlencode($after) : '';
        return $this->request('GET', "/listings/leads{$query}", [], null, 'fetch_leads');
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
            $headers = [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ];
            if (!empty($this->userGroupId)) {
                $headers['P24-UserGroupId'] = $this->userGroupId;
            }

            // P24's saveListing legitimately takes 1-2 minutes to ingest a
            // photo-heavy listing (up to 30 base64 images) — observed 200s in
            // p24_syndication_logs reach ~120s. So the read timeout stays
            // generous (120s); connectTimeout(15) still fast-fails a dead host.
            // The fix for the old 2-minute SIGKILL is to keep this BELOW the
            // job timeout (SubmitListingToProperty24::$timeout = 180), so the
            // HTTP layer times out first and the catch below marks the property
            // 'error' gracefully instead of Laravel hard-killing the worker.
            $http = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders($headers)
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

        // Last resort: dump the entire response so the error is visible
        $dump = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($dump && strlen($dump) < 1000) return "HTTP {$statusCode}: {$dump}";

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
