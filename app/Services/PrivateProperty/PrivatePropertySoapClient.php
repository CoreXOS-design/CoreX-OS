<?php

namespace App\Services\PrivateProperty;

use App\Models\Agency;
use Illuminate\Support\Facades\Log;

class PrivatePropertySoapClient
{
    private PrivatePropertyTokenService $tokenService;
    private ?\SoapClient $client = null;
    private ?Agency $agency = null;
    private ?array $cfgCache = null;

    public function __construct(PrivatePropertyTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    public function forAgency(?Agency $agency): self
    {
        $this->agency = $agency;
        $this->client = null;
        $this->cfgCache = null;
        return $this;
    }

    private function cfg(): array
    {
        if ($this->cfgCache !== null) {
            return $this->cfgCache;
        }
        // If no agency was explicitly bound, fall back to the authenticated
        // user's agency (controllers / web requests). Env fallback handles
        // CLI / queue contexts where there is no auth.
        $agency = $this->agency ?? \Illuminate\Support\Facades\Auth::user()?->agency;
        return $this->cfgCache = PrivatePropertyConfig::for($agency);
    }

    private function branchGuid(): ?string
    {
        return $this->cfg()['branch_guid'];
    }

    private function soapClient(): \SoapClient
    {
        if ($this->client === null) {
            $wsdl = $this->cfg()['wsdl'];

            $this->client = new \SoapClient($wsdl, [
                'trace'              => true,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_BOTH,
                'connection_timeout' => 30,
                'default_socket_timeout' => 60,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ],
                    'http' => [
                        'timeout' => 60,
                    ],
                ]),
            ]);
        }

        return $this->client;
    }

    private function buildToken(): array
    {
        return $this->tokenService->generate($this->agency);
    }

    /**
     * Execute a raw SOAP call with retry on timeout.
     */
    public function call(string $method, array $params): array
    {
        $this->log('info', "SOAP call: {$method}", ['params' => $this->sanitizeForLog($params)]);

        $maxAttempts = 2;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                // Set PHP's global socket timeout for this call
                $oldTimeout = ini_get('default_socket_timeout');
                ini_set('default_socket_timeout', 120);

                $response = $this->soapClient()->__soapCall($method, [$params]);

                ini_set('default_socket_timeout', $oldTimeout);

                $result = json_decode(json_encode($response), true) ?? [];

                $this->log('info', "SOAP response: {$method}", ['response' => $result]);

                return $result;
            } catch (\SoapFault $e) {
                ini_set('default_socket_timeout', $oldTimeout ?? 60);
                $lastError = $e;

                // Retry only on timeout errors
                $isTimeout = str_contains($e->getMessage(), 'Error Fetching http headers')
                          || str_contains($e->getMessage(), 'Could not connect')
                          || str_contains($e->getMessage(), 'timed out');

                if ($isTimeout && $attempt < $maxAttempts) {
                    $this->log('warning', "SOAP timeout on {$method}, retrying (attempt {$attempt}/{$maxAttempts})");
                    $this->client = null; // Force fresh connection
                    sleep(3);
                    continue;
                }

                $this->log('error', "SOAP fault: {$method}", [
                    'code'    => $e->getCode(),
                    'message' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                // Surface a clean, human message to callers/UI; keep the raw
                // .NET fault in logs (above) and under 'raw' for debugging.
                return [
                    'error'   => true,
                    'message' => PpFaultTranslator::friendly($e->getMessage()),
                    'raw'     => $e->getMessage(),
                ];
            }
        }

        // Should never reach here, but just in case
        return [
            'error'   => true,
            'message' => PpFaultTranslator::friendly($lastError?->getMessage()),
            'raw'     => $lastError?->getMessage() ?? 'Unknown error',
        ];
    }

    /**
     * Get branch details from PP.
     * WSDL: GetBranchDetails { guid BranchId, SecurityToken Token }
     */
    public function getBranchDetails(): array
    {
        return $this->call('GetBranchDetails', [
            'BranchId' => $this->branchGuid(),
            'Token'    => $this->buildToken(),
        ]);
    }

    /**
     * Update/create an agent record on PP.
     * WSDL: UpdateAgent { Agent Agent, SecurityToken Token }
     */
    public function updateAgent(array $agentData): array
    {
        return $this->call('UpdateAgent', [
            'Agent' => $agentData,
            'Token' => $this->buildToken(),
        ]);
    }

    /**
     * Submit/update a listing on PP.
     * WSDL: UpdateListing { Listing ListingImport, SecurityToken Token }
     */
    public function updateListing(array $listingData): array
    {
        return $this->call('UpdateListing', [
            'ListingImport' => $listingData,
            'Token'         => $this->buildToken(),
        ]);
    }

    /**
     * Get listing status from PP.
     * WSDL: GetListingStatus { guid BranchId, string PropertyId, SecurityToken Token }
     */
    public function getListingStatus(string $propertyId): array
    {
        return $this->call('GetListingStatus', [
            'BranchId'   => $this->branchGuid(),
            'PropertyId' => $propertyId,
            'Token'      => $this->buildToken(),
        ]);
    }

    /**
     * Deactivate a listing on PP.
     * WSDL: ListingStatusUpdate { guid BranchId, string PropertyId, ListingType ListingType, PropertyStatus PropertyStatus, SecurityToken Token }
     */
    public function deactivateListing(string $propertyId, string $listingType = 'Sale'): array
    {
        return $this->call('ListingStatusUpdate', [
            'BranchId'       => $this->branchGuid(),
            'PropertyId'     => $propertyId,
            'ListingType'    => $listingType,
            'PropertyStatus' => 'Inactive',
            'Token'          => $this->buildToken(),
        ]);
    }

    /**
     * Get listing event feed for the branch.
     * WSDL: GetListingEventFeedByBranch { guid UniqueBranchId, SecurityToken Token, string continuationKey, dateTime startDateTime }
     */
    public function getListingEventFeed(?string $continuationKey = null, ?string $startDateTime = null): array
    {
        $params = [
            'UniqueBranchId'  => $this->branchGuid(),
            'Token'           => $this->buildToken(),
            'continuationKey' => $continuationKey ?? '',
            'startDateTime'   => $startDateTime ?? now()->subDay()->toIso8601String(),
        ];

        return $this->call('GetListingEventFeedByBranch', $params);
    }

    /**
     * Pull buyer-enquiry LEADS for the branch since $startDate.
     * WSDL: ListingLeadDetailsFeed { dateTime StartDate, SecurityToken Token }
     *   → ArrayOfListingLeadDetail (LeadId, Date, BranchId, UniqueListingId,
     *     PPRef, FromEmail, FromName, FromContactNumber, ToEmail, Message,
     *     PropertyRefs).
     *
     * Read-only reporting call — the P24-parity intake channel. Returns the
     * decoded SOAP array (or the client's {error:true,...} envelope on fault;
     * PpLeadService treats that as a clean skip so the scheduler never breaks).
     */
    public function listingLeadDetailsFeed(?string $startDate = null): array
    {
        return $this->call('ListingLeadDetailsFeed', [
            // PP expects a .NET dateTime; unqualified local time matches the
            // format the feed/status calls already send successfully.
            'StartDate' => $startDate ?? now()->subDays(7)->format('Y-m-d\TH:i:s'),
            'Token'     => $this->buildToken(),
        ]);
    }

    /**
     * Pull per-listing engagement STATS for one date.
     * WSDL: ListingPerformanceStats { ArrayOfString PropertyRefs, dateTime Date, SecurityToken Token }
     *   → ArrayOfListingPerformanceStatsOnDate (Date, Messages, TelLeads, Views,
     *     Alerts, PropertyRef).
     *
     * $propertyRefs is optional — pass a batch of PP refs to scope the response.
     * Read-only reporting call; a SOAP fault returns the client's {error:true}
     * envelope so PpStatsService skips cleanly. AT-201.
     */
    public function listingPerformanceStats(array $propertyRefs, string $date): array
    {
        return $this->call('ListingPerformanceStats', [
            'PropertyRefs' => ['string' => array_values($propertyRefs)],
            'Date'         => $date,
            'Token'        => $this->buildToken(),
        ]);
    }

    /**
     * Get PP reference number by listing.
     * WSDL: GetReferenceNumberByListing { guid BranchId, string UniqueListingID, ListingType listingType, SecurityToken Token }
     */
    public function getReferenceNumber(string $propertyId, string $listingType = 'Sale'): array
    {
        return $this->call('GetReferenceNumberByListing', [
            'BranchId'        => $this->branchGuid(),
            'UniqueListingID' => $propertyId,
            'listingType'     => $listingType,
            'Token'           => $this->buildToken(),
        ]);
    }

    /**
     * AT-68 — set ANY PropertyStatus on a listing. The general form of the call
     * that deactivateListing()/reactivateListing() were hardcoded special cases of.
     *
     * PP's PropertyStatus enum (live WSDL): ForSale · ToLet · PendingOffer · Sold ·
     * Inactive · Archived. We previously only ever sent three of the six.
     *
     * ⚠️ The result string is NOT proof. PP answers
     * `ListingStatusUpdateResult: "Successful"` even when it applies nothing — a
     * live probe pushed Sold to an Inactive listing, got "Successful", and the
     * status did not move (.ai/audits/2026-07-11-at68-pp-status-parity.md §7.1;
     * same bug-class as AT-221 on P24, where HTTP 200 + isOnPortal:false meant
     * rejected). ALWAYS verify with getListingStatus() — the syndication service
     * does exactly that. Never treat a successful call as a successful change.
     *
     * WSDL: ListingStatusUpdate { guid BranchId, string PropertyId, ListingType ListingType, PropertyStatus PropertyStatus, SecurityToken Token }
     */
    public function setListingStatus(string $propertyId, string $listingType, string $propertyStatus): array
    {
        return $this->call('ListingStatusUpdate', [
            'BranchId'       => $this->branchGuid(),
            'PropertyId'     => $propertyId,
            'ListingType'    => $listingType,
            'PropertyStatus' => $propertyStatus,
            'Token'          => $this->buildToken(),
        ]);
    }

    /**
     * Reactivate a listing on PP (set status back to on-market).
     *
     * AT-68: this used to hardcode 'ForSale' regardless of listing type — so
     * reactivating a RENTAL pushed it back onto PP as a property FOR SALE. The
     * on-market status now follows the listing type.
     *
     * WSDL: ListingStatusUpdate { guid BranchId, string PropertyId, ListingType ListingType, PropertyStatus PropertyStatus, SecurityToken Token }
     */
    public function reactivateListing(string $propertyId, string $listingType = 'Sale'): array
    {
        return $this->setListingStatus(
            $propertyId,
            $listingType,
            $listingType === 'Rental' ? 'ToLet' : 'ForSale'
        );
    }

    /**
     * Submit/update a showday event for a listing.
     * WSDL: ListingShowdayUpdate { guid BranchId, ShowdayEvent Showday, SecurityToken Token }
     */
    public function updateShowday(array $showdayData): array
    {
        return $this->call('ListingShowdayUpdate', [
            'BranchId' => $this->branchGuid(),
            'Showday'  => $showdayData,
            'Token'    => $this->buildToken(),
        ]);
    }

    /**
     * Upload an agent's profile image to PP.
     * WSDL: UpdateAgentImage { Agent Agent, string imgurl, SecurityToken Token }
     */
    public function updateAgentImage(array $agentData, string $imageUrl): array
    {
        return $this->call('UpdateAgentImage', [
            'Agent'  => $agentData,
            'imgurl' => $imageUrl,
            'Token'  => $this->buildToken(),
        ]);
    }

    /**
     * Get all agents registered on PP for this branch.
     * WSDL: GetAllAgentsForBranch { guid BranchId, SecurityToken Token }
     */
    public function getAllAgentsForBranch(): array
    {
        return $this->call('GetAllAgentsForBranch', [
            'BranchId' => $this->branchGuid(),
            'Token'    => $this->buildToken(),
        ]);
    }

    /**
     * Get a specific agent from PP.
     * WSDL: GetAgent { guid BranchId, SecurityToken Token, string agentID }
     */
    public function getAgent(string $agentId): array
    {
        return $this->call('GetAgent', [
            'BranchId' => $this->branchGuid(),
            'Token'    => $this->buildToken(),
            'agentID'  => $agentId,
        ]);
    }

    /**
     * Get listing summary (includes moderation status, activation date, ref).
     * WSDL: ListingSummary { guid BranchId, string UniqueListingId, SecurityToken Token }
     */
    public function getListingSummary(string $propertyId): array
    {
        return $this->call('ListingSummary', [
            'BranchId'        => $this->branchGuid(),
            'UniqueListingId' => $propertyId,
            'Token'           => $this->buildToken(),
        ]);
    }

    /**
     * Get active listings for the branch.
     * WSDL: GetActiveListings { guid BranchId, SecurityToken Token }
     */
    public function getActiveListings(): array
    {
        return $this->call('GetActiveListings', [
            'BranchId' => $this->branchGuid(),
            'Token'    => $this->buildToken(),
        ]);
    }

    /**
     * Claim/update PP's internal agent record to map to a CoreX user ID.
     * WSDL: UpdateUniqueAgentID { string PrivatePropertyAgentId, string AgentId, SecurityToken Token }
     */
    public function updateUniqueAgentId(string $ppAgentId, string $coreXUserId): array
    {
        return $this->call('UpdateUniqueAgentID', [
            'PrivatePropertyAgentId' => $ppAgentId,
            'AgentId'                => $coreXUserId,
            'Token'                  => $this->buildToken(),
        ]);
    }

    /**
     * Claim/update PP's internal listing record to map to a CoreX property ID.
     * WSDL: UpdateUniqueListingID { string PrivatePropertyListingId, string PropertyId, string ListingType, SecurityToken Token }
     */
    public function updateUniqueListingId(string $ppListingId, string $coreXPropertyId, string $listingType = 'Sale'): array
    {
        return $this->call('UpdateUniqueListingID', [
            'PrivatePropertyListingId' => $ppListingId,
            'PropertyId'               => $coreXPropertyId,
            'ListingType'              => $listingType,
            'Token'                    => $this->buildToken(),
        ]);
    }

    /**
     * Add YouTube video ID and/or Matterport ID to an active PP listing.
     * WSDL: UpdateListingVideoOrMatterport { guid BranchId, string UniqueListingId, string MatterportId, string YoutubeVideoId, string ListingType, SecurityToken Token }
     */
    public function updateListingVideoOrMatterport(
        string $uniqueListingId,
        string $listingType,
        ?string $youtubeVideoId = null,
        ?string $matterportId = null
    ): array {
        if (!empty($youtubeVideoId) && strlen($youtubeVideoId) !== 11) {
            return [
                'error'   => true,
                'message' => 'YoutubeVideoId must be exactly 11 characters. Received: ' . strlen($youtubeVideoId) . ' chars.',
            ];
        }

        if (empty($youtubeVideoId) && empty($matterportId)) {
            return [
                'error'   => true,
                'message' => 'No video or Matterport ID supplied — no-op prevented.',
            ];
        }

        return $this->call('UpdateListingVideoOrMatterport', [
            'BranchId'        => $this->branchGuid(),
            'UniqueListingId' => $uniqueListingId,
            'MatterportId'    => $matterportId ?? '',
            'YoutubeVideoId'  => $youtubeVideoId ?? '',
            'ListingType'     => $listingType,
            'Token'           => $this->buildToken(),
        ]);
    }

    /**
     * Get the list of countries PP supports.
     * WSDL: GetCountries { SecurityToken Token }
     */
    public function getCountries(): array
    {
        return $this->call('GetCountries', [
            'Token' => $this->buildToken(),
        ]);
    }

    /**
     * Get the list of provinces for a country.
     * WSDL: GetProvinces { int CountryId, SecurityToken Token }
     */
    public function getProvinces(int $countryId): array
    {
        return $this->call('GetProvinces', [
            'CountryId' => $countryId,
            'Token'     => $this->buildToken(),
        ]);
    }

    /**
     * Get the list of cities for a province.
     * WSDL: GetCities { int ProvinceID, SecurityToken Token }
     */
    public function getCities(int $provinceId): array
    {
        return $this->call('GetCities', [
            'ProvinceID' => $provinceId,
            'Token'      => $this->buildToken(),
        ]);
    }

    /**
     * Get the list of suburbs for a city.
     * WSDL: GetSuburbs { int CityID, SecurityToken Token }
     */
    public function getSuburbs(int $cityId): array
    {
        return $this->call('GetSuburbs', [
            'CityID' => $cityId,
            'Token'  => $this->buildToken(),
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        Log::channel('private_property')->{$level}($message, $context);
    }

    private function sanitizeForLog(array $params): array
    {
        if (isset($params['Token']['Digest'])) {
            $params['Token']['Digest'] = '***';
        }
        return $params;
    }
}
