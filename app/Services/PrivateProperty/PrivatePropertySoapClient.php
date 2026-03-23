<?php

namespace App\Services\PrivateProperty;

use Illuminate\Support\Facades\Log;

class PrivatePropertySoapClient
{
    private PrivatePropertyTokenService $tokenService;
    private ?\SoapClient $client = null;

    public function __construct(PrivatePropertyTokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    private function soapClient(): \SoapClient
    {
        if ($this->client === null) {
            $wsdl = config('services.private_property.wsdl');

            $this->client = new \SoapClient($wsdl, [
                'trace'      => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => stream_context_create([
                    'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false,
                    ],
                ]),
            ]);
        }

        return $this->client;
    }

    private function buildToken(): array
    {
        return $this->tokenService->generate();
    }

    /**
     * Execute a raw SOAP call. Params must already include Token.
     */
    public function call(string $method, array $params): array
    {
        $this->log('info', "SOAP call: {$method}", ['params' => $this->sanitizeForLog($params)]);

        try {
            $response = $this->soapClient()->__soapCall($method, [$params]);

            $result = json_decode(json_encode($response), true) ?? [];

            $this->log('info', "SOAP response: {$method}", ['response' => $result]);

            return $result;
        } catch (\SoapFault $e) {
            $this->log('error', "SOAP fault: {$method}", [
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
                'request' => $this->soapClient()->__getLastRequest(),
            ]);

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get branch details from PP.
     * WSDL: GetBranchDetails { guid BranchId, SecurityToken Token }
     */
    public function getBranchDetails(): array
    {
        return $this->call('GetBranchDetails', [
            'BranchId' => config('services.private_property.branch_guid'),
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
            'BranchId'   => config('services.private_property.branch_guid'),
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
            'BranchId'       => config('services.private_property.branch_guid'),
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
            'UniqueBranchId'  => config('services.private_property.branch_guid'),
            'Token'           => $this->buildToken(),
            'continuationKey' => $continuationKey ?? '',
            'startDateTime'   => $startDateTime ?? now()->subDay()->toIso8601String(),
        ];

        return $this->call('GetListingEventFeedByBranch', $params);
    }

    /**
     * Get PP reference number by listing.
     * WSDL: GetReferenceNumberByListing { guid BranchId, string UniqueListingID, ListingType listingType, SecurityToken Token }
     */
    public function getReferenceNumber(string $propertyId, string $listingType = 'Sale'): array
    {
        return $this->call('GetReferenceNumberByListing', [
            'BranchId'        => config('services.private_property.branch_guid'),
            'UniqueListingID' => $propertyId,
            'listingType'     => $listingType,
            'Token'           => $this->buildToken(),
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
