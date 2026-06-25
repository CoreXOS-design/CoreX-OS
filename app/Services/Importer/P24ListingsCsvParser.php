<?php

namespace App\Services\Importer;

class P24ListingsCsvParser
{
    /**
     * Returns array of rows with: external_id (listing number), payload, mapped, errors, primary_agent_p24_id.
     * fgetcsv handles multi-line quoted descriptions natively.
     */
    public function parse(string $path): array
    {
        $rows = [];
        $seen = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open listings CSV: {$path}");
        }
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return $rows;
        }

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) continue;
            $raw = array_combine($header, array_pad($data, count($header), null)) ?: [];

            $errors = [];
            $listingNumber = $raw['ListingNumber'] ?? null;
            if (!is_numeric($listingNumber)) {
                $errors[] = 'Invalid ListingNumber';
            } else {
                if (isset($seen[$listingNumber])) {
                    $errors[] = "Duplicate ListingNumber in file: {$listingNumber}";
                } else {
                    $seen[$listingNumber] = true;
                }
            }

            $contactAgentIds = array_filter(array_map('trim', explode(',', (string)($raw['ContactAgentIds'] ?? ''))));
            $primaryAgentP24 = $contactAgentIds[0] ?? null;

            $listingType = (string)($raw['ListingType'] ?? '');
            $status = (string)($raw['Status'] ?? '');

            $price = is_numeric($raw['Price'] ?? null) ? (float)$raw['Price'] : null;
            $rentalRate = is_numeric($raw['RentalRate'] ?? null) ? (float)$raw['RentalRate'] : null;

            $typeResolution = P24PropertyTypeMap::resolve(
                is_numeric($raw['PropertyTypeId'] ?? null) ? (int)$raw['PropertyTypeId'] : null
            );
            if (!$typeResolution['known'] && isset($raw['PropertyTypeId']) && $raw['PropertyTypeId'] !== '') {
                $errors[] = "Unknown PropertyTypeId: {$raw['PropertyTypeId']}";
            }

            $erfSize = is_numeric($raw['ErfSize'] ?? null) ? (float)$raw['ErfSize'] : null;
            $floorArea = is_numeric($raw['FloorArea'] ?? null) ? (float)$raw['FloorArea'] : null;

            $isRental = strcasecmp($listingType, 'Rental') === 0;

            $streetNumber = trim((string)($raw['StreetNumber'] ?? ''));
            $streetName = trim((string)($raw['StreetName'] ?? ''));
            $address = trim($streetNumber . ' ' . $streetName);

            $features = array_filter([
                'pool'              => $this->boolish($raw['Pool'] ?? null),
                'flatlet'           => $this->boolish($raw['Flatlet'] ?? null),
                'garden'            => $this->boolish($raw['Garden'] ?? null),
                'furnished'         => $this->boolish($raw['Furnished'] ?? null),
                'pets_allowed'      => $this->boolish($raw['PetsAllowed'] ?? null),
                'repossessed'       => $this->boolish($raw['Repossessed'] ?? null),
                'show_location'     => $this->boolish($raw['ShowLocation'] ?? null),
                'pool_description'  => trim((string)($raw['PoolDescription'] ?? '')) ?: null,
                'flat_description'  => trim((string)($raw['FlatDescription'] ?? '')) ?: null,
                'parking_description' => trim((string)($raw['ParkingDescription'] ?? '')) ?: null,
                'beds_description'  => trim((string)($raw['BedroomsDescription'] ?? '')) ?: null,
                'baths_description' => trim((string)($raw['BathroomsDescription'] ?? '')) ?: null,
                'garages_description' => trim((string)($raw['GaragesDescription'] ?? '')) ?: null,
                'deposit_requirements' => trim((string)($raw['DepositRequirementsComments'] ?? '')) ?: null,
                'auction_date'      => trim((string)($raw['AuctionDate'] ?? '')) ?: null,
                'auction_venue'     => trim((string)($raw['AuctionVenue'] ?? '')) ?: null,
                'auction_description' => trim((string)($raw['AuctionDescription'] ?? '')) ?: null,
                'age'               => is_numeric($raw['Age'] ?? null) ? (int)$raw['Age'] : null,
                'listing_visibility' => trim((string)($raw['ListingVisibility'] ?? '')) ?: null,
            ], fn ($v) => $v !== null && $v !== false && $v !== '');
            // Re-stamp the boolean features so explicit `false` is kept (array_filter drops them).
            $features = array_merge([
                'pool'         => $this->boolish($raw['Pool'] ?? null),
                'flatlet'      => $this->boolish($raw['Flatlet'] ?? null),
                'garden'       => $this->boolish($raw['Garden'] ?? null),
                'furnished'    => $this->boolish($raw['Furnished'] ?? null),
                'pets_allowed' => $this->boolish($raw['PetsAllowed'] ?? null),
            ], $features);

            $spaces = array_filter([
                'reception_rooms'  => is_numeric($raw['ReceptionRooms'] ?? null) ? (int)$raw['ReceptionRooms'] : null,
                'studies'          => is_numeric($raw['Studies'] ?? null) ? (int)$raw['Studies'] : null,
                'kitchens'         => is_numeric($raw['Kitchens'] ?? null) ? (int)$raw['Kitchens'] : null,
                'secure_parkings'  => is_numeric($raw['SecureParkings'] ?? null) ? (int)$raw['SecureParkings'] : null,
                'parking_spaces'   => is_numeric($raw['NumberOfParkingSpaces'] ?? null) ? (int)$raw['NumberOfParkingSpaces'] : null,
                'domestic_rooms'   => is_numeric($raw['DomesticRooms'] ?? null) ? (int)$raw['DomesticRooms'] : null,
                'domestic_bathrooms' => is_numeric($raw['DomesticBathrooms'] ?? null) ? (int)$raw['DomesticBathrooms'] : null,
                'outside_toilets'  => is_numeric($raw['OutsideToilets'] ?? null) ? (int)$raw['OutsideToilets'] : null,
                'parking_bay_number' => trim((string)($raw['ParkingBayNumber'] ?? '')) ?: null,
            ], fn ($v) => $v !== null && $v !== '');

            $mapped = [
                'external_id'          => (string)$listingNumber,
                'p24_listing_number'   => (string)$listingNumber,
                'title'                => $raw['DescriptionHeader'] ?? null,
                'headline'             => $raw['DescriptionHeader'] ?? null,
                'description'          => $raw['Description'] ?? null,
                'listing_type'         => $isRental ? 'Rental' : 'Sale',
                'status'               => $this->normaliseStatus($status),
                'price'                => $isRental ? null : $price,
                'rental_amount'        => $isRental ? ($rentalRate ?: $price) : null,
                'address'              => $address ?: null,
                'street_number'        => $streetNumber ?: null,
                'street_name'          => $streetName ?: null,
                'stand_number'         => trim((string)($raw['StandNumber'] ?? '')) ?: null,
                'unit_number'          => trim((string)($raw['ComplexUnitNumber'] ?? '')) ?: null,
                'beds'                 => is_numeric($raw['Bedrooms'] ?? null) ? (int)$raw['Bedrooms'] : null,
                'baths'                => is_numeric($raw['Bathrooms'] ?? null) ? (float)$raw['Bathrooms'] : null,
                'garages'              => is_numeric($raw['Garages'] ?? null) ? (int)$raw['Garages'] : null,
                'erf_size_m2'          => $erfSize,
                'size_m2'              => $floorArea,
                'property_type'        => $typeResolution['type'],
                'category'             => $typeResolution['category'] ?? null,
                // Carry the raw P24 SuburbId so the confirm step can resolve
                // suburb/town/province from p24_suburbs → p24_cities →
                // p24_provinces (the import previously ignored location data
                // entirely, leaving every property with NULL town/suburb).
                'p24_suburb_id'        => is_numeric($raw['SuburbId'] ?? null) ? (int)$raw['SuburbId'] : null,
                'occupation_date'      => $raw['OccupationDate'] ?? null,
                'expiry_date'          => $raw['ExpiryDate'] ?? null,
                'levy'                 => is_numeric($raw['MonthlyLevy'] ?? null) ? (float)$raw['MonthlyLevy'] : null,
                'special_levy'         => is_numeric($raw['ComplexSpecialLevy'] ?? null) ? (float)$raw['ComplexSpecialLevy'] : null,
                'rates_taxes'          => is_numeric($raw['MunicipalRatesAndTaxes'] ?? null) ? (float)$raw['MunicipalRatesAndTaxes'] : null,
                'source_reference'     => $raw['SourceReference'] ?? null,
                'latitude'             => is_numeric($raw['Latitude'] ?? null) ? (float)$raw['Latitude'] : null,
                'longitude'            => is_numeric($raw['Longitude'] ?? null) ? (float)$raw['Longitude'] : null,
                'youtube_video_id'     => trim((string)($raw['YouTubeVideoId'] ?? '')) ?: null,
                'matterport_id'        => trim((string)($raw['MatterportSpaceId'] ?? '')) ?: null,
                'features_json'        => $features,
                'spaces_json'          => $spaces,
                'pet_friendly'         => $this->tribool($raw['PetsAllowed'] ?? null),
                'lease_period'         => $raw['LeasePeriod'] ?? null,
                'primary_agent_p24_id' => is_numeric($primaryAgentP24) ? (int)$primaryAgentP24 : null,
                'contact_agent_p24_ids'=> array_map('intval', array_filter($contactAgentIds, 'is_numeric')),
            ];

            $rows[] = [
                'external_id'       => (string)($listingNumber ?? ''),
                'payload'           => $raw,
                'mapped'            => $mapped,
                'errors'            => $errors,
                'primary_agent_p24' => $mapped['primary_agent_p24_id'],
                'action'            => 'create',
            ];
        }
        fclose($handle);
        return $rows;
    }

    private function boolish($v): bool
    {
        if ($v === null) return false;
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * Tri-state boolean: true / false / null (unknown).
     * Used for fields like pet_friendly where "not specified" matters.
     */
    private function tribool($v): ?bool
    {
        if ($v === null) return null;
        $s = strtolower(trim((string)$v));
        if ($s === '') return null;
        if (in_array($s, ['1', 'true', 'yes', 'y'], true)) return true;
        if (in_array($s, ['0', 'false', 'no', 'n'], true)) return false;
        return null;
    }

    /**
     * Map a P24 source status → CoreX canonical property_status (the slugified
     * `property_setting_items` value the edit form stores). The original
     * importer emitted capitalised, non-canonical strings ("Active", "Rented",
     * or the raw P24 word) which never matched the form select and rendered as
     * "— None —". This mapping mirrors the agreed P24→canonical decision:
     *   on-market (Active/NewListing/Reduced/Pending) → for_sale
     *   off-market (Withdrawn/Expired/Cancelled)       → withdrawn
     *   Sold                                            → sold
     *   Rented (concluded rental)                       → let_out
     * Unknown words fall back to for_sale (a live, visible default) rather than
     * persisting a non-canonical string.
     */
    private function normaliseStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            'newlisting', 'new', 'active', 'reduced', 'pending' => 'for_sale',
            'withdrawn', 'expired', 'cancelled'                 => 'withdrawn',
            'sold'                                              => 'sold',
            'rented'                                            => 'let_out',
            default                                             => 'for_sale',
        };
    }
}
