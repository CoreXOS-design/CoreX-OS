<?php

namespace App\Services\PrivateProperty;

use App\Models\PpSuburb;
use App\Models\Property;

class PrivatePropertyListingMapper
{
    /**
     * Map a CoreX Property to a PP Listing struct matching the WSDL exactly.
     *
     * WSDL Listing struct fields:
     *   PropertyId, BranchId, Category (ArrayOfCategory), MandateType,
     *   StreetName, StreetNumber, ComplexName, UnitNumber, Suburb, SuburbId,
     *   Town, Province, Headline, Description, Price (double), Deposit (double),
     *   ListingDate, ExpiryDate, AvailableFrom, AgentId, PhotoUrls (ArrayOfString),
     *   OwnerID, XCoordinate (double), YCoordinate (double), ListingType,
     *   PropertyStatus, ShowdayEvents, Attributes (ArrayOfAttribute),
     *   HideStreetName, HideStreetNo, HideComplexName, HideUnitNumber,
     *   RentalPriceType, SalesPricePresentation, OffersFromPrice,
     *   SoleMandateExclusiveDays (int)
     */
    public function map(Property $property): array
    {
        $cfg         = PrivatePropertyConfig::forProperty($property);
        $branchGuid  = $cfg['branch_guid'];
        $category    = $this->mapCategory($property->category);
        $mandateType = $this->mapMandateType($property->mandate_type);
        $listingType = $this->mapListingType($property->listing_type ?? $property->mandate_type);
        $status      = $listingType === 'Rental' ? 'ToLet' : 'ForSale';

        // Build the full Listing struct — ALL fields must be present for PHP SoapClient
        $expiryDate = $property->expiry_date
            ? $property->expiry_date->format('Y-m-d\TH:i:s')
            : now()->addYear()->format('Y-m-d\TH:i:s');

        $listing = [
            'PropertyId'              => (string) $property->id,
            'BranchId'                => $branchGuid,
            'Category'                => ['Category' => $category],
            'MandateType'             => $mandateType,
            'StreetName'              => $property->street_name ?: $this->parseStreetName($property->address),
            'StreetNumber'            => $property->street_number ?: $this->parseStreetNumber($property->address),
            'FloorNumber'             => $property->floor_number ?? '',
            'ComplexName'             => $property->complex_name ?? '',
            'UnitNumber'              => $property->unit_number ?? '',
            // PP106: use EITHER SuburbId OR (Suburb + Town + Province) — never both
            'Suburb'                  => $property->suburb ?? '',
            'Town'                    => $property->town ?? $property->city ?? '',
            'Province'                => $this->mapProvince($property->province),
            'Headline'                => $property->headline ?? $property->title ?? '',
            'Description'             => $property->description ?? '',
            'Price'                   => (float) $property->price,
            'Deposit'                 => $listingType === 'Rental' ? (float) ($property->deposit_amount ?? 0) : 0.0,
            'ListingDate'             => $property->created_at ? $property->created_at->format('Y-m-d\TH:i:s') : now()->format('Y-m-d\TH:i:s'),
            'ExpiryDate'              => $expiryDate,
            'AvailableFrom'           => now()->format('Y-m-d\TH:i:s'),
            'AgentId'                 => $this->buildAgentIdString($property),
            'PhotoUrls'               => new \stdClass(), // empty ArrayOfString — overridden below if photos exist
            'OwnerID'                 => '',
            'XCoordinate'             => (float) ($property->latitude ?? 0),
            'YCoordinate'             => (float) ($property->longitude ?? 0),
            'ListingType'             => $listingType,
            'PropertyStatus'          => $status,
            'ShowdayEvents'           => $this->buildShowdayEvents($property),
            'Attributes'              => $this->buildAttributes($property),
            'HideStreetName'          => (bool) ($property->pp_hide_street_name ?? false),
            'HideStreetNo'            => (bool) ($property->pp_hide_street_number ?? false),
            'HideComplexName'         => (bool) ($property->pp_hide_complex_name ?? false),
            'HideUnitNumber'          => (bool) ($property->pp_hide_unit_number ?? false),
            'RentalPriceType'         => $this->mapRentalPriceType($property),
            'SalesPricePresentation'  => '',
            'OffersFromPrice'         => '',
            'SoleMandateExclusiveDays' => 0,
        ];

        // Resolve the property's suburb against the cached PP suburb list
        // (populated by `php artisan pp:sync-locations`). If we find a single
        // unambiguous match, persist it and switch to Mode A.
        if (!$property->pp_suburb_id && !empty($property->suburb)) {
            $resolvedId = $this->resolvePpSuburbId($property);
            if ($resolvedId !== null) {
                $property->forceFill(['pp_suburb_id' => $resolvedId])->save();
            }
        }

        // PP106: SuburbId is mutually exclusive with Suburb, Town AND Province.
        // PP rejects the call if a SuburbId is sent alongside any of the three
        // name fields — even an empty Suburb/Town or a populated Province.
        if ($property->pp_suburb_id) {
            $listing['SuburbId'] = (int) $property->pp_suburb_id;
            unset($listing['Suburb'], $listing['Town'], $listing['Province']);
        }

        // SoleMandateExclusiveDays — auto-calculated from listed_date and expiry_date for sole mandates
        if ($mandateType === 'FullMandate' && $listingType === 'Sale' && $property->listed_date && $property->expiry_date) {
            $days = (int) $property->listed_date->diffInDays($property->expiry_date);
            if ($days >= 1 && $days <= 92) {
                $listing['SoleMandateExclusiveDays'] = $days;
            }
        }

        // Photo URLs — always send images on every submission
        $photos = $this->buildPhotoUrls($property);
        if (!empty($photos)) {
            $listing['PhotoUrls'] = ['string' => $photos];
        }

        return $listing;
    }

    /**
     * Look up a PP SuburbId for a property based on its suburb name
     * (+ province where helpful). Returns null if the PP cache is empty
     * or no unambiguous match is found.
     */
    private function resolvePpSuburbId(Property $property): ?int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('pp_suburbs')) {
            return null;
        }
        if (PpSuburb::query()->limit(1)->count() === 0) {
            return null; // PP list never synced yet — stay in Mode B.
        }

        $normalised = PpSuburb::normalise((string) $property->suburb);
        if ($normalised === '') return null;

        $matches = PpSuburb::where('normalised_name', $normalised)->get();

        if ($matches->count() === 1) {
            return (int) $matches->first()->pp_suburb_id;
        }

        // Disambiguate by province if multiple suburbs share a name.
        if ($matches->count() > 1 && !empty($property->province)) {
            $enumExpected = $this->mapProvince($property->province);
            $byProvince = $matches->load('city.province')->filter(function ($s) use ($enumExpected) {
                $enum = $s->city?->province?->pp_province_enum;
                return $enum && $enum === $enumExpected;
            });
            if ($byProvince->count() === 1) {
                return (int) $byProvince->first()->pp_suburb_id;
            }
        }

        return null;
    }

    /**
     * Build comma-separated AgentId string for PP.
     * PP supports multiple agents via "AGENT1,AGENT2" format.
     */
    private function buildAgentIdString(Property $property): string
    {
        $ids = [];

        foreach ([$property->agent_id, $property->pp_second_agent_id] as $userId) {
            if (!$userId) continue;
            $user = \App\Models\User::find($userId);
            if (!$user) continue;
            $ids[] = (string) ($user->pp_external_ref ?: $user->id);
        }

        return implode(',', $ids);
    }

    /**
     * Validate a mapped payload. Returns array of error messages (empty = valid).
     */
    public function validate(array $payload): array
    {
        $errors = [];

        $required = ['PropertyId', 'BranchId', 'Category', 'MandateType', 'ListingType', 'PropertyStatus', 'Price', 'Description', 'AgentId'];

        foreach ($required as $field) {
            $val = $payload[$field] ?? null;
            if ($val === null || $val === '' || ($field === 'Category' && empty($val))) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Location: SuburbId OR (Suburb + Town + Province) required, never both.
        // PP106 rule (verbatim from PP): "You cannot provide a suburbId together
        // with town name, suburb name and province." Mutually exclusive modes.
        $hasSuburbId = !empty($payload['SuburbId']);
        if (!$hasSuburbId && empty($payload['Suburb'])) {
            $errors[] = 'Suburb or SuburbId is required for PP syndication';
        }
        if (!$hasSuburbId && empty($payload['Town'])) {
            $errors[] = 'Town is required when SuburbId is not provided';
        }
        if ($hasSuburbId && (
            !empty($payload['Suburb']) || !empty($payload['Town']) || !empty($payload['Province'])
        )) {
            $errors[] = 'PP106: SuburbId cannot be sent together with Suburb, Town or Province — pick one mode';
        }

        // If the PP suburb cache is populated and we did NOT resolve to a
        // SuburbId, block submission with closest-match suggestions. This
        // catches misspelled / unknown suburbs before they hit PP.
        if (!$hasSuburbId
            && !empty($payload['Suburb'])
            && \Illuminate\Support\Facades\Schema::hasTable('pp_suburbs')
            && PpSuburb::query()->limit(1)->count() > 0
        ) {
            $normalised = PpSuburb::normalise((string) $payload['Suburb']);
            if (!PpSuburb::where('normalised_name', $normalised)->exists()) {
                $closest = PpSuburb::where('normalised_name', 'like', substr($normalised, 0, 4) . '%')
                    ->limit(5)->pluck('name')->all();
                $hint = !empty($closest) ? ' Closest matches: ' . implode(', ', $closest) . '.' : '';
                $errors[] = "Suburb '{$payload['Suburb']}' is not on Private Property's list — submission blocked." . $hint;
            }
        }

        // Suburb and Town must not be identical (PP cannot shape the listing)
        if (!empty($payload['Suburb']) && !empty($payload['Town'])
            && strtolower(trim($payload['Suburb'])) === strtolower(trim($payload['Town']))) {
            $errors[] = 'Suburb and Town must not be identical — PP requires a correct geographic hierarchy (e.g. Suburb=Uvongo, Town=Margate)';
        }

        // StreetName validation
        $streetName = $payload['StreetName'] ?? '';
        if (empty($streetName)) {
            $errors[] = 'Street name is required for PP syndication';
        } else {
            if (strlen($streetName) > 100) {
                $errors[] = 'StreetName exceeds 100 character limit: ' . strlen($streetName) . ' chars';
            }

            // Detect listing title used as street name
            $suspiciousWords = ['bedroom', 'bathroom', 'house for sale', 'to let', 'property', 'for sale in', 'for rent'];
            foreach ($suspiciousWords as $word) {
                if (str_contains(strtolower($streetName), $word)) {
                    $errors[] = 'StreetName appears to contain a listing title rather than a real street name: ' . $streetName;
                    break;
                }
            }
        }

        if (empty($payload['StreetNumber'])) {
            $errors[] = 'Street number is required for PP syndication';
        }

        if (($payload['Price'] ?? 0) <= 0) {
            $errors[] = 'Price must be greater than zero';
        }

        if (empty($payload['Description'])) {
            $errors[] = 'Description is required and cannot be empty';
        }

        if (empty($payload['Headline'])) {
            $errors[] = 'Headline is required and cannot be empty';
        }

        // Province must be a valid PP enum
        $validProvinces = ['KwaZuluNatal', 'Gauteng', 'WesternCape', 'EasternCape', 'FreeState', 'Limpopo', 'Mpumalanga', 'NorthWest', 'NorthernCape'];
        if (!empty($payload['Province']) && !in_array($payload['Province'], $validProvinces)) {
            $errors[] = 'Province is not a valid PP enum value: ' . $payload['Province'];
        }

        // All photo URLs must be HTTPS — report every offender, not just the first
        $photoUrls = $payload['PhotoUrls'] ?? null;
        if (is_array($photoUrls) && isset($photoUrls['string'])) {
            foreach ((array) $photoUrls['string'] as $url) {
                if (!str_starts_with($url, 'https://')) {
                    $errors[] = 'Photo URL must use HTTPS: ' . $url;
                }
            }
        }

        return $errors;
    }

    /**
     * Check a Property for PP feed readiness.
     */
    public function checkReadiness(Property $property): array
    {
        $missing = [];

        $checks = [
            ['field' => 'title',        'label' => 'Title / Headline',  'tab' => 'info'],
            ['field' => 'description',  'label' => 'Description',       'tab' => 'info'],
            ['field' => 'price',        'label' => 'Price',             'tab' => 'info',  'min' => 1],
            ['field' => 'category',     'label' => 'Category',          'tab' => 'info'],
            ['field' => 'mandate_type', 'label' => 'Mandate Type',      'tab' => 'info'],
            ['field' => 'property_type','label' => 'Property Type',     'tab' => 'info'],
            ['field' => 'suburb',       'label' => 'Suburb',            'tab' => 'info'],
            ['field' => 'agent_id',     'label' => 'Listing Agent',     'tab' => 'info'],
        ];

        foreach ($checks as $check) {
            $value = $property->{$check['field']};
            $empty = $value === null || $value === '' || $value === 0;

            if (isset($check['min']) && is_numeric($value) && (int) $value < $check['min']) {
                $empty = true;
            }

            if ($empty) {
                $missing[] = $check;
            }
        }

        // Street address — PP requires both street number and street name (PP119)
        // Check the dedicated columns first, fall back to parsing from address
        $hasStreetNumber = !empty($property->street_number) || !empty($this->parseStreetNumber($property->address));
        $hasStreetName   = !empty($property->street_name) || !empty($this->parseStreetName($property->address));

        if (!$hasStreetNumber) {
            $missing[] = ['field' => 'street_number', 'label' => 'Street number (e.g. "14 Ocean Drive")', 'tab' => 'info'];
        }
        if (!$hasStreetName) {
            $missing[] = ['field' => 'street_name', 'label' => 'Street name (e.g. "14 Ocean Drive")', 'tab' => 'info'];
        }

        // Town is required for PP geographic hierarchy (suburb → town → province)
        if (empty($property->town) && empty($property->city) && empty($property->pp_suburb_id)) {
            $missing[] = ['field' => 'town', 'label' => 'Town (e.g. "Margate") — required for PP location hierarchy', 'tab' => 'info'];
        }

        // Suburb and Town must not be identical — PP cannot shape the listing without a correct hierarchy
        $suburb = trim($property->suburb ?? '');
        $town   = trim($property->town ?? $property->city ?? '');
        if ($suburb !== '' && $town !== '' && strtolower($suburb) === strtolower($town)) {
            $missing[] = ['field' => 'suburb', 'label' => "Suburb and Town are identical (\"{$suburb}\") — PP requires different values (e.g. Suburb=Uvongo, Town=Margate)", 'tab' => 'info'];
        }

        // StreetName must not contain listing title keywords
        $streetName = $property->street_name ?: $this->parseStreetName($property->address);
        if (!empty($streetName)) {
            $suspiciousWords = ['bedroom', 'bathroom', 'house for sale', 'to let', 'property', 'for sale in', 'for rent'];
            foreach ($suspiciousWords as $word) {
                if (str_contains(strtolower($streetName), $word)) {
                    $missing[] = ['field' => 'street_name', 'label' => "Street name looks like a listing title (\"{$streetName}\") — enter the actual street name", 'tab' => 'info'];
                    break;
                }
            }
            if (strlen($streetName) > 100) {
                $missing[] = ['field' => 'street_name', 'label' => 'Street name exceeds 100 characters (PP limit)', 'tab' => 'info'];
            }
        }

        // PP requires minimum 3 images for sale listings, 1 for rentals
        $allImages = $property->allImages();
        $isRental  = in_array(strtolower($property->mandate_type ?? ''), ['rental']);
        $minPhotos = $isRental ? 1 : 3;

        if (count($allImages) < $minPhotos) {
            $missing[] = ['field' => 'images', 'label' => "At least {$minPhotos} photos (have " . count($allImages) . ')', 'tab' => 'gallery'];
        }

        return $missing;
    }

    /**
     * Build the Attributes array matching WSDL ArrayOfAttribute.
     * Each attribute: { AttributeType: string, Value: string }
     */
    private function buildAttributes(Property $property): array
    {
        $attrs = [];

        $map = [
            'Bedrooms'     => (string) (int) ($property->beds ?? 0),
            'Bathrooms'    => (string) (int) ($property->baths ?? 0),
            'Garages'      => (string) (int) ($property->garages ?? 0),
            'FloorArea'    => (string) (int) ($property->size_m2 ?? 0),
            'LandArea'     => (string) (int) ($property->erf_size_m2 ?? 0),
        ];

        // PP requires category-specific type attribute:
        // Residential → HomeType, Commercial → BusinessType, Farms → FarmType, Land → LandType
        $category = strtolower($property->category ?? 'residential');
        if ($property->property_type) {
            if ($category === 'commercial') {
                $map['BusinessType'] = $this->mapBusinessType($property->property_type);
            } elseif (in_array($category, ['farm', 'farms', 'agricultural'])) {
                $map['FarmType'] = $this->mapFarmType($property->property_type);
            } elseif ($category === 'land') {
                $map['LandType'] = $this->mapLandType($property->property_type);
            } else {
                $map['HomeType'] = $this->mapPropertyType($property->property_type);
            }
        }
        if ($property->rates_taxes) {
            $map['Rates'] = (string) (int) $property->rates_taxes;
        }
        if ($property->levy) {
            $map['Levies'] = (string) (int) $property->levy;
        }

        foreach ($map as $type => $value) {
            if ($value !== '' && $value !== '0' || in_array($type, ['Bedrooms', 'Bathrooms', 'Garages'])) {
                $attrs[] = ['AttributeType' => $type, 'Value' => $value];
            }
        }

        return ['Attribute' => $attrs]; // ArrayOfAttribute wrapper
    }

    private function mapCategory(?string $category): string
    {
        $map = [
            'residential'  => 'Residential',
            'land'         => 'Land',
            'farms'        => 'Farms',
            'farm'         => 'Farms',
            'commercial'   => 'Commercial',
            'agricultural' => 'Farms',
        ];

        return $map[strtolower($category ?? '')] ?? 'Residential';
    }

    private function mapMandateType(?string $mandateType): string
    {
        $map = [
            'sole'          => 'FullMandate',
            'sole mandate'  => 'FullMandate',
            'open'          => 'OpenMandate',
            'open mandate'  => 'OpenMandate',
            'dual'          => 'OpenMandate',
            'dual mandate'  => 'OpenMandate',
            'rental'        => 'Rental',
        ];

        return $map[strtolower($mandateType ?? '')] ?? 'OpenMandate';
    }

    private function mapListingType(?string $listingType): string
    {
        return strtolower($listingType ?? '') === 'rental' ? 'Rental' : 'Sale';
    }

    private function mapPropertyType(?string $type): string
    {
        $map = [
            'house'       => 'House',
            'apartment'   => 'Apartment',
            'flat'        => 'Apartment',
            'townhouse'   => 'Townhouse',
            'simplex'     => 'Simplex',
            'duplex'      => 'Duplex',
            'cluster'     => 'Cluster',
            'garden_flat' => 'GardenFlat',
            'cottage'     => 'Cottage',
            'vacant_land' => 'VacantLand',
            'land'        => 'VacantLand',
            'farm'        => 'SmallHolding',
            'commercial'  => 'Commercial',
            'industrial'  => 'Industrial',
            'office'      => 'Office',
        ];

        return $map[strtolower($type ?? '')] ?? 'House';
    }

    private function mapBusinessType(?string $type): string
    {
        $map = [
            'commercial'     => 'Commercial',
            'office'         => 'Office',
            'retail'         => 'Retail',
            'industrial'     => 'Industrial',
            'warehouse'      => 'Warehouse',
            'factory'        => 'Factory',
            'shop'           => 'Shop',
            'restaurant'     => 'Restaurant',
            'hotel'          => 'Hotel',
            'mixed use'      => 'MixedUse',
            'other'          => 'Other',
        ];

        return $map[strtolower($type ?? '')] ?? 'Commercial';
    }

    private function mapFarmType(?string $type): string
    {
        $map = [
            'farm'            => 'Farm',
            'smallholding'    => 'SmallHolding',
            'small holding'   => 'SmallHolding',
            'agricultural'    => 'Farm',
            'game farm'       => 'GameFarm',
            'wine farm'       => 'WineFarm',
            'equestrian'      => 'Equestrian',
            'other'           => 'Other',
        ];

        return $map[strtolower($type ?? '')] ?? 'Farm';
    }

    private function mapLandType(?string $type): string
    {
        $map = [
            'vacant_land'     => 'VacantLand',
            'vacant land'     => 'VacantLand',
            'land'            => 'VacantLand',
            'residential'     => 'ResidentialLand',
            'commercial'      => 'CommercialLand',
            'industrial'      => 'IndustrialLand',
            'agricultural'    => 'AgriculturalLand',
            'other'           => 'Other',
        ];

        return $map[strtolower($type ?? '')] ?? 'VacantLand';
    }

    /**
     * Map province name to PP Province enum.
     */
    private function mapProvince(?string $province): string
    {
        $map = [
            'kwazulu-natal'  => 'KwaZuluNatal',
            'kwazulu natal'  => 'KwaZuluNatal',
            'kzn'            => 'KwaZuluNatal',
            'gauteng'        => 'Gauteng',
            'western cape'   => 'WesternCape',
            'eastern cape'   => 'EasternCape',
            'free state'     => 'FreeState',
            'limpopo'        => 'Limpopo',
            'mpumalanga'     => 'Mpumalanga',
            'north west'     => 'NorthWest',
            'northern cape'  => 'NorthernCape',
        ];

        return $map[strtolower(trim($province ?? ''))] ?? 'KwaZuluNatal';
    }

    /**
     * Map rental price type for PP (e.g. "per sqm" for commercial rentals).
     */
    private function mapRentalPriceType(Property $property): string
    {
        $listingType = $this->mapListingType($property->listing_type ?? $property->mandate_type);
        if ($listingType !== 'Rental') {
            return '';
        }

        // PP Agency Feed Service Rev 4.6 Section 2.3.1
        // Valid enum: PerMonth, PerWeek, PerDay, PerM2 (Commercial/Land only)
        return match (strtolower($property->rental_price_type ?? '')) {
            'per month', 'per_month', 'monthly'                           => 'PerMonth',
            'per week', 'per_week', 'weekly'                              => 'PerWeek',
            'per day', 'per_day', 'daily'                                 => 'PerDay',
            'per sqm', 'per_sqm', 'persqm', 'per m2', 'per_m2',
            'persquaremeter', 'per square meter', 'per_square_meter'      => 'PerM2',
            default                                                       => 'PerMonth',
        };
    }

    /**
     * Build a showday event struct for PP.
     * WSDL: ShowdayEvent { string PropertyId, dateTime StartDate, dateTime EndDate, string Description, boolean Active }
     */
    public function buildShowdayEvent(Property $property, array $showdayData): array
    {
        return [
            'PropertyId'  => (string) $property->id,
            'StartDate'   => $showdayData['start_date'],  // ISO 8601 format: 2026-03-25T10:00:00
            'EndDate'     => $showdayData['end_date'],     // ISO 8601 format: 2026-03-25T12:00:00
            'Description' => $showdayData['description'] ?? 'Open Showday',
            'Active'      => true,
        ];
    }

    /**
     * Build an Agent struct for PP from a User model.
     */
    public function buildAgentData(\App\Models\User $user, bool $active = true): array
    {
        $parts     = explode(' ', trim($user->name), 2);
        $firstName = $parts[0] ?? '';
        $lastName  = $parts[1] ?? $parts[0] ?? '';
        $cellPhone = $user->cell ?? $user->phone ?? '';

        // AgentId prefers pp_external_ref (admin-set on PP) over user->id.
        return [
            'AgentId'               => (string) ($user->pp_external_ref ?: $user->id),
            'FirstName'             => $firstName,
            'LastName'              => $lastName,
            'Email'                 => $user->email ?? '',
            'TelCell'               => $cellPhone,
            'TelWork'               => $user->phone ?? $cellPhone,
            'TelHome'               => '', // PP only recognises TelCell + TelWork
            'Active'                => $active,
            'BranchId'              => PrivatePropertyConfig::for($user->agency ?? null)['branch_guid'],
            'PrivatePropertyAgentId' => '',
            'PrivysealAlias'        => '',
        ];
    }

    /**
     * Build ShowdayEvents array from saved property showdays.
     */
    private function buildShowdayEvents(Property $property): mixed
    {
        $showdays = $property->activeShowdays ?? collect();

        if ($showdays->isEmpty()) {
            return new \stdClass(); // empty ArrayOfShowdayEvent
        }

        $events = $showdays->map(fn($s) => [
            'PropertyId'  => (string) $property->id,
            'StartDate'   => $s->start_date->format('Y-m-d\TH:i:s'),
            'EndDate'     => $s->end_date->format('Y-m-d\TH:i:s'),
            'Description' => $s->description ?? 'Open Showday',
            'Active'      => true,
        ])->values()->all();

        return ['ShowdayEvent' => count($events) === 1 ? $events[0] : $events];
    }

    private function buildPhotoUrls(Property $property): array
    {
        // PP practical limit — too many images causes their transaction to timeout
        $allImages = array_slice($property->allImages(), 0, 20);
        // Use PP_IMAGE_BASE_URL if set (for local dev against sandbox), otherwise APP_URL
        $override  = PrivatePropertyConfig::forProperty($property)['image_base_url'];
        $baseUrl   = rtrim(!empty($override) ? $override : config('app.url'), '/');
        $urls      = [];

        $appUrl = rtrim(config('app.url'), '/');

        foreach ($allImages as $imagePath) {
            if (empty($imagePath)) continue;

            if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
                // If override is set, rewrite the domain portion of existing full URLs
                if (!empty($override) && $appUrl) {
                    $imagePath = str_replace($appUrl, $baseUrl, $imagePath);
                }
                $urls[] = $imagePath;
            } else {
                // Guarantee exactly one `/` between base and path. Without
                // this, a stored path like `properties/123.jpg` (no leading
                // slash) concatenates to `https://hostproperties/...` and
                // PP fails to download → ErrorDownloadingImages.
                $urls[] = $baseUrl . '/' . ltrim($imagePath, '/');
            }
        }

        return $urls;
    }

    /**
     * Parse street number from a combined address string.
     * Handles: "14 Ocean Drive", "Lot 14 Marine Rd", "Unit 3 Beach Rd", empty.
     */
    private function parseStreetNumber(?string $address): string
    {
        $address = trim($address ?? '');
        if ($address === '') return '';

        // Match leading number: "14 Ocean Drive" → "14"
        if (preg_match('/^(\d+)\s/', $address, $m)) {
            return $m[1];
        }

        // Match "Lot 14 ...", "Unit 3 ...", "No 7 ..."
        if (preg_match('/^(?:lot|unit|no\.?)\s*(\d+)/i', $address, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Parse street name from a combined address string.
     * Returns everything after the leading number/prefix.
     */
    private function parseStreetName(?string $address): string
    {
        $address = trim($address ?? '');
        if ($address === '') return '';

        // Strip leading number: "14 Ocean Drive" → "Ocean Drive"
        if (preg_match('/^\d+\s+(.+)$/', $address, $m)) {
            return trim($m[1]);
        }

        // Strip "Lot 14 ...", "Unit 3 ...", "No 7 ..."
        if (preg_match('/^(?:lot|unit|no\.?)\s*\d+\s+(.+)$/i', $address, $m)) {
            return trim($m[1]);
        }

        // No number found — return the whole address as street name
        return $address;
    }
}
