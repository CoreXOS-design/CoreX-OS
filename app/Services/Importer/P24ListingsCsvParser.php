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

            // Areas are stored in m². P24 can express them in ha/acres, so
            // normalise against the unit column — otherwise a "2 ha" erf lands as
            // "2 m²". Keep the raw unit so the stored m² is auditable.
            $erfAreaUnit   = trim((string)($raw['ErfAreaUnit'] ?? '')) ?: null;
            $floorAreaUnit = trim((string)($raw['FloorAreaAreaUnit'] ?? '')) ?: null;
            $erfSize   = $this->normaliseAreaToM2($raw['ErfSize'] ?? null, $erfAreaUnit);
            $floorArea = $this->normaliseAreaToM2($raw['FloorArea'] ?? null, $floorAreaUnit);

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
                // Raw P24 SuburbId — always preserved here. The FK-constrained
                // properties.p24_suburb_id is only set when that suburb exists in
                // our p24_suburbs reference (see ConfirmP24PropertyRowJob), so a
                // suburb we haven't seeded never loses its source id.
                'p24_source_suburb_id' => is_numeric($raw['SuburbId'] ?? null) ? (int)$raw['SuburbId'] : null,
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
                'reception_rooms_description' => trim((string)($raw['ReceptionRoomsDescription'] ?? '')) ?: null,
                'studies_description'         => trim((string)($raw['StudiesDescription'] ?? '')) ?: null,
                'kitchens_description'        => trim((string)($raw['KitchensDescription'] ?? '')) ?: null,
                'domestic_rooms_description'  => trim((string)($raw['DomesticRoomsDescription'] ?? '')) ?: null,
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
                'erf_area_unit'        => $erfAreaUnit,
                'size_m2'              => $floorArea,
                'floor_area_unit'      => $floorAreaUnit,
                'property_type'        => $typeResolution['type'],
                'category'             => $typeResolution['category'] ?? null,
                'p24_suburb_id'        => is_numeric($raw['SuburbId'] ?? null) ? (int)$raw['SuburbId'] : null,
                'lightstone_id'        => trim((string)($raw['LightstoneId'] ?? '')) ?: null,
                'development_id'       => trim((string)($raw['DevelopmentId'] ?? '')) ?: null,
                'eyespy_360_id'        => trim((string)($raw['EyeSpy360Id'] ?? '')) ?: null,
                // Blank dates must be NULL, not '' — MySQL rejects '' for a DATE column.
                'occupation_date'      => trim((string)($raw['OccupationDate'] ?? '')) ?: null,
                'expiry_date'          => trim((string)($raw['ExpiryDate'] ?? '')) ?: null,
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

    /**
     * Normalise a P24 area value to m². P24 usually gives m², but erf/floor areas
     * can come as hectares or acres; storing the raw number in an "_m2" column
     * would silently under-report a farm's erf by 10,000×. Unknown/blank units are
     * treated as m² (the P24 default). The raw unit is kept separately for audit.
     */
    private function normaliseAreaToM2($value, ?string $unit): ?float
    {
        if (!is_numeric($value)) return null;
        $v = (float) $value;
        $u = strtolower(trim((string) $unit));
        $factor = match (true) {
            str_contains($u, 'hectare') || $u === 'ha'                 => 10000.0,
            str_contains($u, 'acre')                                   => 4046.8564224,
            str_contains($u, 'km') || str_contains($u, 'kilomet')      => 1_000_000.0,
            default                                                    => 1.0, // m² / sqm / blank
        };
        return round($v * $factor, 2);
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

    private function normaliseStatus(string $s): string
    {
        return match (strtolower(trim($s))) {
            'newlisting', 'new' => 'Active',
            'active'            => 'Active',
            'reduced'           => 'Active',
            'rented'            => 'Rented',
            'sold'              => 'Sold',
            default             => $s ?: 'Active',
        };
    }
}
