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

            $features = [
                'pool'          => $this->boolish($raw['Pool'] ?? null),
                'flatlet'       => $this->boolish($raw['Flatlet'] ?? null),
                'garden'        => $this->boolish($raw['Garden'] ?? null),
                'furnished'     => $this->boolish($raw['Furnished'] ?? null),
                'pets_allowed'  => $this->boolish($raw['PetsAllowed'] ?? null),
            ];

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
                'beds'                 => is_numeric($raw['Bedrooms'] ?? null) ? (int)$raw['Bedrooms'] : null,
                'baths'                => is_numeric($raw['Bathrooms'] ?? null) ? (float)$raw['Bathrooms'] : null,
                'garages'              => is_numeric($raw['Garages'] ?? null) ? (int)$raw['Garages'] : null,
                'erf_size_m2'          => $erfSize,
                'size_m2'              => $floorArea,
                'property_type'        => $typeResolution['type'],
                'occupation_date'      => $raw['OccupationDate'] ?? null,
                'expiry_date'          => $raw['ExpiryDate'] ?? null,
                'levy'                 => is_numeric($raw['MonthlyLevy'] ?? null) ? (float)$raw['MonthlyLevy'] : null,
                'rates_taxes'          => is_numeric($raw['MunicipalRatesAndTaxes'] ?? null) ? (float)$raw['MunicipalRatesAndTaxes'] : null,
                'source_reference'     => $raw['SourceReference'] ?? null,
                'latitude'             => is_numeric($raw['Latitude'] ?? null) ? (float)$raw['Latitude'] : null,
                'longitude'            => is_numeric($raw['Longitude'] ?? null) ? (float)$raw['Longitude'] : null,
                'features_json'        => $features,
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
