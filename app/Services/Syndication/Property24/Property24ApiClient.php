<?php

namespace App\Services\Syndication\Property24;

use App\Models\Agency;
use App\Models\P24SyndicationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Property24ApiClient
{
    /**
     * Cross-request cache (Redis/DB) key prefix + TTL for the agent list. TTL is
     * long (25h) because the list is kept current two ways regardless: every
     * CoreX create/adopt/update patches the cache in place (cacheUpsertAgent), and
     * a nightly warmer (p24:warm-agents-cache, 22:00) re-fetches it. A long TTL is
     * what makes that nightly warm actually keep manual Refresh fast all day —
     * a short TTL would expire mid-morning and put the ~90s cold fetch right back
     * in the user's path. Only an agent changed DIRECTLY on the P24 portal (never
     * via CoreX) is stale, and only until the next nightly warm.
     */
    private const AGENTS_CACHE_PREFIX = 'p24:agents:';
    private const AGENTS_CACHE_TTL    = 90000; // seconds (25h — see note above)

    private string $baseUrl;
    private string $username;
    private string $password;
    private string $agencyId;
    private string $apiVersion;
    private bool $sandbox;
    private ?string $userGroupId;
    /** Per-agency HTTP read timeout (seconds); AT-101. */
    private int $readTimeout;

    /**
     * In-process memo of the P24 agent list per agency id, SHARED across client
     * instances for the life of the request/command. getAgents() is otherwise
     * called 2–3× per listing (ensureAgentRegistered + resolveContactAgentIds,
     * each on its own client instance); against a slow/rate-limited P24 those
     * repeated full-list fetches time out (~45s each) — the dominant cost of a
     * sync AND the cause of spurious "must have one or more agents" rejections.
     * Only SUCCESSFUL fetches are cached. This is the FAST in-process layer; a
     * cross-request cache (see getAgents) sits behind it so a fresh request — e.g.
     * a manual Refresh — doesn't pay P24's ~90s /agents fetch every time. Agent
     * create/update patches BOTH layers in place (cacheUpsertAgent) so a manual
     * refresh, which always PUTs the agent, keeps the cache warm. See AT-P24.
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

        // AT-101: per-agency HTTP read timeout; falls back to the canonical
        // default (120s) when the agency has no override / no agency is bound.
        $this->readTimeout = $agency?->p24HttpReadTimeout() ?? Agency::P24_DEFAULT_HTTP_READ_TIMEOUT;
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
    public function getAgents(?string $agencyIdOverride = null, bool $forceRefresh = false): array
    {
        $agencyId = $agencyIdOverride !== null && $agencyIdOverride !== ''
            ? $agencyIdOverride
            : $this->agencyId;

        // Guard: never call /agencies//agents (empty id → 404, wasted ~35s).
        if ($agencyId === null || $agencyId === '') {
            return ['success' => false, 'message' => 'No P24 agency ID for agent lookup', 'data' => []];
        }

        $key = (string) $agencyId;
        $cacheKey = self::AGENTS_CACHE_PREFIX . $key;

        // $forceRefresh (used by the nightly warmer) skips both cache layers to
        // pull a genuinely fresh list, but still writes the result back below.
        if (! $forceRefresh) {
            // 1. In-process memo — instant, and reflects any create/adopt upserts
            //    made earlier in this same request/command.
            if (isset(self::$agentsCache[$key])) {
                return self::$agentsCache[$key];
            }

            // 2. Cross-request cache — P24's GET /agencies/{id}/agents takes ~90s,
            //    so a cold manual Refresh would otherwise pay that every time. The
            //    long shared TTL (kept warm nightly) means the rest are instant.
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                self::$agentsCache[$key] = $cached;
                return $cached;
            }
        }

        $result = $this->request('GET', "/agencies/{$agencyId}/agents", [], null, 'fetch_agents');

        // Cache ONLY a successful fetch — never memoize a timeout/connection
        // error, so a transient failure can't poison the whole run.
        if ($result['success'] ?? false) {
            self::$agentsCache[$key] = $result;
            Cache::put($cacheKey, $result, self::AGENTS_CACHE_TTL);
        }

        return $result;
    }

    /**
     * Register a new agent on P24.
     */
    public function createAgent(array $agentData): array
    {
        $result = $this->request('POST', '/agents', $agentData, null, 'create_agent');
        // Patch the just-created agent into the warm cache (id from the response)
        // rather than busting — busting forces a full re-fetch that can time out
        // and strand the next listings with no resolvable agent.
        $id = $result['data']['id'] ?? $result['data'] ?? null;
        if (($result['success'] ?? false) && is_numeric($id)) {
            $this->cacheUpsertAgent(array_merge($agentData, ['id' => (int) $id]));
        } else {
            self::$agentsCache = [];
        }
        return $result;
    }

    /**
     * Update an existing agent on P24.
     */
    public function updateAgent(array $agentData): array
    {
        $result = $this->request('PUT', '/agents', $agentData, null, 'update_agent');
        // Adopt/update path carries id + sourceReference — patch it into the warm
        // cache in place so resolveContactAgentIds finds it WITHOUT a re-fetch.
        if (($result['success'] ?? false) && ! empty($agentData['id'])) {
            $this->cacheUpsertAgent($agentData);
        } else {
            self::$agentsCache = [];
        }
        return $result;
    }

    /**
     * Insert-or-update an agent record inside the getAgents cache so a create/
     * adopt/update is reflected immediately without re-fetching the whole list.
     * Patches BOTH the in-process memo AND the cross-request cache so a manual
     * refresh (which always PUTs the agent) keeps the ~90s fetch from recurring.
     * Matches by agent id; scoped to the agent's agencyId.
     */
    private function cacheUpsertAgent(array $agent): void
    {
        if (empty($agent['id'])) {
            return;
        }
        $agentAgency = isset($agent['agencyId']) ? (string) $agent['agencyId'] : null;
        if ($agentAgency === null || $agentAgency === '') {
            // Agency unknown — can't target a key; drop the in-process memo so the
            // next read re-fetches rather than serving a list missing this agent.
            self::$agentsCache = [];
            return;
        }

        // Base the patch on whichever layer already holds the list (in-process
        // first, else the cross-request cache) so we never trigger a full
        // ~90s re-fetch just to record one agent change.
        $entry = self::$agentsCache[$agentAgency]
            ?? Cache::get(self::AGENTS_CACHE_PREFIX . $agentAgency);

        if (! is_array($entry) || ! isset($entry['data']) || ! is_array($entry['data'])) {
            // Nothing cached for this agency yet — nothing to patch. The next
            // getAgents will fetch fresh and naturally include this agent.
            return;
        }

        $list = $entry['data'];
        $found = false;
        foreach ($list as $idx => $existing) {
            if ((int) ($existing['id'] ?? 0) === (int) $agent['id']) {
                $list[$idx] = array_merge($existing, $agent);
                $found = true;
                break;
            }
        }
        if (! $found) {
            $list[] = $agent;
        }
        $entry['data'] = $list;

        self::$agentsCache[$agentAgency] = $entry;
        Cache::put(self::AGENTS_CACHE_PREFIX . $agentAgency, $entry, self::AGENTS_CACHE_TTL);
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
            // photo-heavy listing — observed ~120s in p24_syndication_logs. The
            // read timeout is per-agency (AT-101, default 120s); connectTimeout(15)
            // still fast-fails a dead host. SubmitListingToProperty24 derives its
            // job timeout as read + 60 so the HTTP layer always times out FIRST and
            // the catch below marks the property 'error' gracefully, instead of
            // Laravel hard-killing the worker mid-request.
            $http = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders($headers)
                ->timeout($this->readTimeout)
                ->connectTimeout(15);

            $startedAt = microtime(true);
            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url),
                'POST'   => $http->post($url, $payload),
                'PUT'    => $http->put($url, $payload ?: null),
                'DELETE' => $http->delete($url),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
            $roundTripMs = (int) round((microtime(true) - $startedAt) * 1000);

            $statusCode = $response->status();

            $contentType = $response->header('Content-Type') ?? '';
            if (str_contains($contentType, 'application/json')) {
                $responseData = $response->json() ?? [];
            } else {
                $responseData = ['raw' => $response->body()];
            }

            $this->logToDb($propertyId, $action, $payload ?: null, $responseData, $statusCode, $roundTripMs);

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
            $roundTripMs = isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null;
            $this->logToDb($propertyId, $action, $payload ?: null, ['error' => $error], null, $roundTripMs);

            return ['success' => false, 'message' => $error, 'data' => []];
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $this->log('error', "P24 {$action} error: {$error}", ['property_id' => $propertyId]);
            $roundTripMs = isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null;
            $this->logToDb($propertyId, $action, $payload ?: null, ['error' => $error], null, $roundTripMs);

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

    private function logToDb(?int $propertyId, string $action, ?array $request, mixed $response, ?int $statusCode, ?int $roundTripMs = null): void
    {
        if ($propertyId === null) return;

        // Ensure response is array for JSON column
        if (!is_array($response)) {
            $response = ['raw' => (string) $response];
        }

        P24SyndicationLog::create([
            'property_id'      => $propertyId,
            'action'           => $action,
            'request_payload'  => $this->stripPhotoBytes($request),
            'response_payload' => $response,
            'status_code'      => $statusCode,
            'round_trip_ms'    => $roundTripMs,
        ]);
    }

    /**
     * AT-101 — never persist the heavy photos[].bytes base64 to the log. Replace
     * the photos array with a compact summary (count, approx total bytes, a short
     * fingerprint of the set) and leave every other payload field intact. This
     * keeps p24_syndication_logs rows small regardless of the photo cap and fixes
     * the `SELECT *` sort-buffer OOM. Nothing reads the stored bytes (the log is
     * only written here and surfaced as error text / metadata).
     */
    private function stripPhotoBytes(?array $request): ?array
    {
        if (!is_array($request) || empty($request['photos']) || !is_array($request['photos'])) {
            return $request;
        }

        $count = 0;
        $totalBytes = 0;
        $hashes = [];
        foreach ($request['photos'] as $photo) {
            $b64 = is_array($photo) ? (string) ($photo['bytes'] ?? '') : '';
            $count++;
            // Approx raw byte size from base64 length — avoids decoding every image.
            $totalBytes += (int) (strlen($b64) * 3 / 4);
            $hashes[] = md5($b64);
        }

        $request['photos'] = [
            '_summary'          => true,
            'photo_count'       => $count,
            'total_photo_bytes' => $totalBytes,
            'fingerprint'       => substr(md5(implode('|', $hashes)), 0, 16),
        ];

        return $request;
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
