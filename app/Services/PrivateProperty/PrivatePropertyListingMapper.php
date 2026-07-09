<?php

namespace App\Services\PrivateProperty;

use App\Models\Agency;
use App\Models\PpSuburb;
use App\Models\Property;
use App\Services\Images\PropertyImageGuard;
use App\Services\Syndication\Concerns\ResolvesPropertyFeatures;
use Illuminate\Support\Facades\Log;

class PrivatePropertyListingMapper
{
    use ResolvesPropertyFeatures;

    /**
     * PP `Attribute.Value` string for a boolean amenity that is PRESENT.
     *
     * MUST be "Yes" — verified against PP's own stored data via
     * GetFullDetailsOfAllListingsByBranch (2026-07-02). PP stores/displays every
     * boolean amenity as "Yes"; a value of "true" is ACCEPTED by UpdateListing
     * (returns "Successful") but SILENTLY DROPPED — so the feature never appears
     * on the portal. That was the "almost no features show on PP" bug: property
     * 6049 pushed with "true" had ZERO amenities stored; re-pushed with "Yes",
     * all of them (Electric_Fencing, Alarm, Fence, Satelite, TV, …) appeared.
     * Amenity flags are emitted PRESENT-ONLY (absent features send no attribute),
     * so only "Yes" is ever transmitted. Single source of truth — change here if
     * PP's accepted value ever differs.
     */
    private const ATTR_PRESENT = 'Yes';

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
        $category    = self::resolvePpCategory($property);
        $mandateType = $this->mapMandateType($property->mandate_type);
        $listingType = self::resolveListingType($property);
        $status      = $this->mapPropertyStatus($property, $listingType);

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
            'Price'                   => $property->effectivePrice(), // rental_amount for rentals, price for sales (PP carries rent in Price + RentalPriceType)
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
        // (populated by `php artisan pp:sync-locations`) and persist it as useful
        // metadata for other call paths. It is NOT used to switch the submission
        // into SuburbId mode — see below.
        if (!$property->pp_suburb_id && !empty($property->suburb)) {
            $resolvedId = $this->resolvePpSuburbId($property);
            if ($resolvedId !== null) {
                $property->forceFill(['pp_suburb_id' => $resolvedId])->save();
            }
        }

        // LOCATION MODE — always name mode (Suburb + Town + Province).
        //
        // SuburbId mode is DISABLED: PP106 requires SuburbId to be sent WITHOUT
        // Suburb/Town/Province, but PHP's SoapClient serialises the WSDL-required
        // `Province` element as <Province xsi:nil="true"/> even when we omit the
        // array key (Suburb/Town are minOccurs=0 and drop cleanly; Province is
        // minOccurs=1 nillable and cannot be suppressed via the array). PP treats
        // that nil element as "Province provided" and rejects the SuburbId call
        // with PP106 — so SuburbId mode never actually succeeded through this
        // client. Name mode is the proven-working path (verified live on 6049).
        // If SuburbId mode is ever needed, the fix is to send ListingImport as a
        // pre-rendered SoapVar so Province can be omitted entirely.

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

        // PP60: a Unit Number identifies a unit within a scheme, so PP requires
        // the Complex/Scheme name alongside it. Submitting UnitNumber with an
        // empty ComplexName is rejected by PP with "PP60 - The address details
        // are insufficient, please add a Scheme/Complex name and resubmit."
        if (!empty($payload['UnitNumber']) && empty($payload['ComplexName'])) {
            $errors[] = "PP60: a Complex/Scheme name is required because this listing has a Unit Number ({$payload['UnitNumber']}). Add the complex/scheme name on the property and resubmit.";
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

        // PP rejects a residential listing whose Bathrooms attribute is 0/absent
        // ("PP60 - Bathrooms is a mandatory attribute for residential listings").
        // Catch it pre-flight with an actionable message — and point out the most
        // common cause: a plot/farm left with a residential-resolving type. See
        // resolvePpCategory().
        if (($payload['Category']['Category'] ?? '') === 'Residential') {
            $bathrooms = 0;
            foreach ((array) ($payload['Attributes']['Attribute'] ?? []) as $attr) {
                if (($attr['AttributeType'] ?? '') === 'Bathrooms') {
                    $bathrooms = (int) ($attr['Value'] ?? 0);
                    break;
                }
            }
            if ($bathrooms < 1) {
                $errors[] = 'Private Property requires at least 1 bathroom for a residential listing. '
                    . 'If this is land or a farm, set the Property Type to "Vacant Land / Plot" or "Farm" '
                    . 'so it syndicates in the correct category.';
            }
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
            // Price uses effectivePrice() (rental_amount for rentals) so a priced
            // rental is never flagged "missing Price" just because the sale column is 0.
            $value = $check['field'] === 'price' ? $property->effectivePrice() : $property->{$check['field']};
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

        // PP60: a unit number means the property sits within a scheme, so PP
        // requires the complex/scheme name. Flag it pre-flight so the agent
        // fixes it before submitting rather than hitting a PP60 rejection.
        if (!empty($property->unit_number) && empty($property->complex_name)) {
            $missing[] = ['field' => 'complex_name', 'label' => 'Complex / Scheme name — required because a Unit Number is set (PP60)', 'tab' => 'info'];
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
        // Resolve via the SAME rule map() uses (property_type-aware), never the
        // raw CoreX category — a 'Vacant Land / Plot' has category 'Residential'
        // but must send LandType, not HomeType (property 2391).
        $category = strtolower(self::resolvePpCategory($property));
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

        // Bedrooms/Bathrooms/Garages are force-sent (even at 0) ONLY for
        // residential listings, because PP treats them as mandatory there. For
        // Land/Farms/Commercial they are meaningless — a forced Bathrooms=0 on a
        // plot is exactly what made PP reject property 2391 with "PP60 - Bathrooms
        // is a mandatory attribute for residential listings". Those categories
        // send a count only when it is genuinely > 0.
        $forceZero = $category === 'residential' ? ['Bedrooms', 'Bathrooms', 'Garages'] : [];
        foreach ($map as $type => $value) {
            if (($value !== '' && $value !== '0') || in_array($type, $forceZero, true)) {
                $attrs[] = ['AttributeType' => $type, 'Value' => $value];
            }
        }

        // --- Feature attributes -------------------------------------------
        // Every value on the LEFT is a verified member of the PP `AttributeType`
        // enum (storage/pp-attributetype-enum.txt, 70 values, from the live
        // WSDL). PP's own misspellings are preserved verbatim (Satelite,
        // Jaccuzzi, ElectrictyIncluded) — an unrecognised type is rejected by
        // the feed. Anything CoreX carries with NO clean PP attribute is skipped,
        // never guessed (mirrors the P24 mapper discipline).

        // COUNT-typed attributes only (PP Appendix A = integer). Datatypes below
        // were verified against a live GetFullDetailsOfAllListingsByBranch
        // read-back (2026-07-05, 327 HFC listings): Lounges/DiningAreas/Parking/
        // Carports/EnSuite store as integers. Kitchen/Family_TV_Room/Study/
        // StaffQuarters/Entrance_hall store as "Yes" (boolean) — PP coerces a
        // numeric value to "Yes" for these, so an integer was tolerated but is
        // the WRONG datatype; they are emitted as present-only flags below, NOT
        // here. (Corrects the audit's "Kitchen is a count" anchor — 6049 only
        // survived because PP coerced its Kitchen="1" to "Yes".)
        $counts = [
            'Lounges'        => $this->countSpaces($property, 'Lounge'),
            'DiningAreas'    => $this->countSpaces($property, 'Dining Room'),
            'Parking'        => $this->countSpaces($property, 'Parking'),
            // A carport is NOT a space type — it is a FEATURE of the `Parking`
            // space (config/property-spaces.php:69). countSpaces($property,
            // 'Carport') therefore always returned 0 and the attribute never
            // syndicated. Count Parking bays/units carrying the Carport feature.
            'Carports'       => $this->countCarports($property),
        ];
        foreach ($counts as $type => $n) {
            if ($n > 0) {
                $attrs[] = ['AttributeType' => $type, 'Value' => (string) (int) $n];
            }
        }

        // Presence flags, sourced from the FULL feature set (flat ∪ every
        // space/unit). globalFeatures() strips features entered in the room editor
        // (Fireplace, Built-in Braai, Built-in Cupboards, Walk-in Closet, TV,
        // En-suite, Aircon-on-a-room, …), so sourcing flags from it meant almost
        // no amenities reached PP. These are "does the property have X anywhere"
        // flags → allFeatures() is the correct source.
        $feats = $this->allFeatures($property);
        $has = fn (string ...$names) => !empty(array_intersect(
            array_map('strtolower', $feats),
            array_map('strtolower', $names)
        ));
        $hasSpace = fn (string $type) => $this->countSpaces($property, $type) > 0;

        $flags = [
            // Room-PRESENCE attributes — PP Appendix A types these as boolean
            // "Yes", NOT integer counts (verified 2026-07-05 read-back). Present
            // when the property carries the corresponding space. Trigger strings
            // mirror the old count sources.
            'Kitchen'          => $hasSpace('Kitchen'),
            'Family_TV_Room'   => $hasSpace('TV Room'),
            'Study'            => $hasSpace('Study') || $hasSpace('Office'),
            'StaffQuarters'    => $hasSpace('Domestic Room'),
            'Entrance_hall'    => $hasSpace('Entrance Hall'),
            'Pool'             => $has('Pool', 'Communal Pool', 'Indoor Pool', 'Splash Pool') || $hasSpace('Pool'),
            'Garden'           => $has('Garden', 'Landscaped', 'Garden Services') || $hasSpace('Garden'),
            'Flatlet'          => $has('Flatlet') || $hasSpace('Flatlet'),
            'Patio'            => $has('Patio') || $hasSpace('Patio'),
            'Balcony'          => $has('Balcony') || $hasSpace('Balcony'),
            'Lapa'             => $has('Lapa') || $hasSpace('Lapa'),
            'Scullery'         => $has('Scullery') || $hasSpace('Scullery'),
            'Pantry'           => $has('Pantry') || $hasSpace('Pantry'),
            // PP's Bathrooms attribute is an integer (no fractional baths) and PP
            // has no numeric half-bath field, so the wizard's `half_baths` scalar
            // surfaces through PP's native Guest_Toilet amenity flag — a half
            // bathroom IS a guest toilet / cloakroom. Also still driven by an
            // explicit "Guest Toilet" feature/space.
            'Guest_Toilet'     => $has('Guest Toilet') || $hasSpace('Guest Toilet') || (int) ($property->half_baths ?? 0) > 0,
            // Space type is 'Laundry Room' (config/property-spaces.php:31), not
            // 'Laundry' — the old $hasSpace('Laundry') never matched, so a
            // laundry entered as a space never sent the flag.
            'Laundry'          => $has('Laundry') || $hasSpace('Laundry Room'),
            'Garden_Cottage'   => $has('Garden Cottage', 'Wendy House') || $hasSpace('Wendy House'),
            'Fireplace'        => $has('Fireplace'),
            'Built_in_Braai'   => $has('Built-In Braai', 'Built-in Braai'),
            'Deck'             => $has('Deck'),
            'Storage'          => $has('Storage', 'Storeroom') || $hasSpace('Storeroom'),
            'Borehole'         => $has('Borehole'),
            'IrrigationSystem' => $has('Irrigation', 'Sprinklers', 'Irrigation System'),
            'PetsAllowed'      => $has('Pet Friendly', 'Pets Allowed'),
            'Furnished'        => $has('Furnished'),
            'Aircon'           => $has('Air Conditioned', 'Aircon', 'Air Conditioning'),
            'Alarm'            => $has('Alarm System', 'Alarm'),
            'Intercom'         => $has('Intercom'),
            'Satelite'         => $has('Satellite Dish', 'Satellite'),
            'TV'               => $has('TV Port', 'TV'),
            'SeaView'          => $has('Sea View'),
            'ScenicView'       => $has('Scenic View', 'Mountain View', 'Bush View', 'Garden View', 'City View', 'River View'),
            'WalkInCloset'     => $has('Walk in Closet', 'Walk-in Closet'),
            'BuiltInCupboards' => $has('Built-in Cupboards', 'Built-In Cupboards'),
            'HandicapAvailable' => $has('Wheelchair Friendly', 'Handicap Access'),
            'AccessGate'       => $has('Electric Gate', 'Security Gate', 'Access Gate', 'Boomed Area', 'Gated Community'),
            'Electric_Fencing' => $has('Electric Fence', 'Electric Fencing'),
            'Fence'            => $has('Totally Fenced', 'Partially Fenced', 'Fenced', 'Totally Walled', 'Perimeter Wall'),
            'SecurityPost'     => $has('Guard House', '24 Hour Guard', 'Security Post', 'Security Complex', 'Security Estate'),
            // Each of these is ALSO a real space type an agent can add — entered
            // as a space (not a flat feature) the has()-only check never fired.
            // Mirror Pool/Garden/etc. by OR-ing the matching space type.
            'TennisCourt'      => $has('Tennis Court') || $hasSpace('Tennis Court'),
            'SquashCourt'      => $has('Squash Court') || $hasSpace('Squash Court'),
            'Clubhouse'        => $has('Clubhouse') || $hasSpace('Clubhouse'),
            'Gym'              => $has('Gym') || $hasSpace('Gym'),
            'Golf'             => $has('Golf', 'Golf Estate'),
            'Jaccuzzi'         => $has('Jacuzzi', 'Jacuzzi Bath', 'Jaccuzzi') || $hasSpace('Jacuzzi'),
            'Jetty_Berth'      => $has('Jetty', 'Berth', 'Jetty/Berth') || $hasSpace('Jetty'),
            'WaterIncluded'    => $has('Water Included'),
            'ElectrictyIncluded' => $has('Electricity Included', 'Electricty Included'),
        ];
        foreach ($flags as $type => $present) {
            if ($present) {
                $attrs[] = ['AttributeType' => $type, 'Value' => self::ATTR_PRESENT];
            }
        }

        // Storeys — CoreX only models "Single Storey" explicitly (theProperty
        // group); emit Storeys=1 when set. Multi-storey has no CoreX feature, so
        // it is omitted rather than guessed.
        if ($has('Single Storey')) {
            $attrs[] = ['AttributeType' => 'Storeys', 'Value' => '1'];
        }

        // EnSuite is a COUNT in PP's Appendix A (it sits among Bedrooms/Bathrooms/
        // Lounges/Garages), NOT a boolean flag — emitting 'true' triggers PP106
        // "Please match attribute datatypes to Appendix A of API" and rejects the
        // whole listing. Count the rooms carrying an en-suite feature.
        $enSuiteNames = ['en-suite', 'en suite', 'ensuite', 'main en-suite'];
        $roomHasEnSuite = fn ($fs) => !empty(array_intersect(
            array_map('strtolower', array_map('trim', array_map('strval', (array) $fs))),
            $enSuiteNames
        ));
        $enSuiteCount = 0;
        foreach ($this->spacesList($property) as $sp) {
            if ($roomHasEnSuite($sp['featuresAll'] ?? [])) {
                $enSuiteCount += (int) ($sp['count'] ?? 1);
                continue;
            }
            foreach (($sp['units'] ?? []) as $u) {
                if ($roomHasEnSuite($u['features'] ?? [])) {
                    $enSuiteCount++;
                }
            }
        }
        if ($enSuiteCount > 0) {
            $attrs[] = ['AttributeType' => 'EnSuite', 'Value' => (string) $enSuiteCount];
        }

        return ['Attribute' => $attrs]; // ArrayOfAttribute wrapper
    }

    /**
     * Count parking bays flagged as carports. A carport is a FEATURE of the
     * `Parking` space (config/property-spaces.php:69), never its own space type,
     * so we sum Parking-space bays/units carrying the 'Carport' feature.
     * Mirrors the EnSuite counting shape: space-level featuresAll counts the
     * whole bay count; otherwise each per-unit hit counts one.
     */
    private function countCarports(Property $property): int
    {
        $isCarport = fn ($fs) => in_array('carport', array_map(
            'strtolower',
            array_map('trim', array_map('strval', (array) $fs))
        ), true);

        $count = 0;
        foreach ($this->spacesList($property) as $sp) {
            if (($sp['type'] ?? null) !== 'Parking') {
                continue;
            }
            $spaceHasCarport = $isCarport($sp['featuresAll'] ?? []);
            $units = $sp['units'] ?? [];

            if (!empty($units)) {
                // Per-unit model: count units that are (or inherit) a carport.
                foreach ($units as $u) {
                    if ($spaceHasCarport || $isCarport($u['features'] ?? [])) {
                        $count++;
                    }
                }
            } elseif ($spaceHasCarport) {
                // Space-level model: the whole Parking space is carports.
                $count += (int) ($sp['count'] ?? 1);
            }
        }

        return $count;
    }

    /**
     * Resolve the PP top-level Category for a property — the SINGLE source of
     * truth used by both map() (the Category field) and buildAttributes() (which
     * type attribute to send + whether Bedrooms/Bathrooms/Garages are forced).
     *
     * PP's four top categories are Residential, Commercial, Land and Farms.
     * CoreX's own `category` vocabulary is Residential/Commercial/Industrial/
     * Retirement/Holiday/Project — it has NO Land or Farms value, so the
     * "this is a plot" / "this is a farm" signal lives ONLY in `property_type`
     * ('Vacant Land / Plot', 'Farm'). mapCategory() alone therefore sent every
     * plot and farm to PP as Residential, and PP rejected them with
     * "PP60 - The attributes are insufficient. Bathrooms is a mandatory attribute
     * for residential listings" (property 2391, vacant land). Derive from
     * property_type FIRST, fall back to the CoreX category. If map() and
     * buildAttributes() ever resolve this differently they diverge — always call
     * this.
     */
    public static function resolvePpCategory(Property $property): string
    {
        $type = strtolower(trim((string) $property->property_type));

        if ($type !== '') {
            if (str_contains($type, 'vacant land') || str_contains($type, 'plot')
                || str_contains($type, 'stand') || preg_match('/\bland\b/', $type)) {
                return 'Land';
            }
            if (str_contains($type, 'farm') || str_contains($type, 'smallholding')
                || str_contains($type, 'small holding') || str_contains($type, 'agricultural')) {
                return 'Farms';
            }
            if (str_contains($type, 'commercial') || str_contains($type, 'industrial')
                || str_contains($type, 'office') || str_contains($type, 'retail')
                || str_contains($type, 'warehouse') || str_contains($type, 'factory')) {
                return 'Commercial';
            }
        }

        return self::mapCategory($property->category);
    }

    private static function mapCategory(?string $category): string
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

    /**
     * Single source of truth for the PP Sale-vs-Rental decision.
     *
     * PP keys every listing by (PropertyId, ListingType), so the submit payload
     * (this mapper) and EVERY follow-up call that must hit the same record —
     * deactivate, reactivate, GetReferenceNumberByListing, video push, unique-id
     * update — MUST derive the type identically. They previously diverged
     * (mandate_type-only vs listing_type-only vs mixed), so a sole rental could be
     * submitted as Rental but deactivated as Sale, missing the record and leaving
     * the listing live. Prefer listing_type, fall back to mandate_type — exactly
     * how map() builds the submitted listing. See audit syndication-bug-sweep
     * 2026-06-20 (PP-7).
     */
    public static function resolveListingType(Property $property): string
    {
        return strtolower((string) ($property->listing_type ?? $property->mandate_type)) === 'rental'
            ? 'Rental' : 'Sale';
    }

    /**
     * Map the CoreX property status to a PP PropertyStatus enum.
     *
     * Previously this was hardcoded to ForSale/ToLet, so a Sold/Withdrawn/Expired
     * listing was re-published as actively on-market on every UpdateListing (incl.
     * the agent "Refresh to portal" button). Off-market statuses now map to
     * 'Inactive' — the only off-market PropertyStatus PP's submission contract
     * documents (ForSale, ToLet, Inactive). The active de-listing path is the
     * ListingStatusUpdate SOAP call (see DesyndicatePropertyFromPortalsJob); this
     * mapping is the safety net so a stray submit can never re-advertise a dead
     * listing. Under-offer/pending stays advertised (still on-market, just flagged).
     * See .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-1).
     */
    private function mapPropertyStatus(Property $property, string $listingType): string
    {
        $status = strtolower(trim($property->status ?? ''));

        foreach (['sold', 'rented', 'withdrawn', 'expired', 'cancelled', 'archived', 'unavailable'] as $offMarket) {
            if (str_contains($status, $offMarket)) {
                return 'Inactive';
            }
        }

        return $listingType === 'Rental' ? 'ToLet' : 'ForSale';
    }

    // The four type-attribute mappers below use substring matching, NOT exact
    // keys. CoreX's property_type vocabulary is human-facing ("Apartment / Flat",
    // "Vacant Land / Plot", "Industrial Property") — exact-key maps silently fell
    // through to the default, so an apartment syndicated to PP as "House". Match
    // on the meaningful token instead; order matters (specific before generic).
    private function mapPropertyType(?string $type): string
    {
        $t = strtolower(trim($type ?? ''));

        return match (true) {
            $t === ''                                                 => 'House',
            str_contains($t, 'apartment') || str_contains($t, 'flat') => 'Apartment',
            str_contains($t, 'townhouse')                             => 'Townhouse',
            str_contains($t, 'simplex')                               => 'Simplex',
            str_contains($t, 'duplex')                                => 'Duplex',
            str_contains($t, 'cluster')                               => 'Cluster',
            str_contains($t, 'cottage')                               => 'Cottage',
            default                                                   => 'House',
        };
    }

    // BusinessType/FarmType Values use PP's SPACED Title-Case convention — the
    // same one that made LandType "Residential Land" (not "VacantLand"). Confirmed
    // spaced multi-word values in the live read-back: "Residential Land",
    // "Bed And Breakfast". camelCase multi-word values ("MixedUse", "SmallHolding")
    // are the identical latent PP106 trap and are spelled spaced here. (CoreX's
    // current property_type vocab only reaches "Commercial"/"Industrial"/"Farm";
    // the multi-word branches are future-proofing, so they are inferred-not-yet-
    // live-confirmed — verify against a live push if that vocab ever expands.)
    private function mapBusinessType(?string $type): string
    {
        $t = strtolower(trim($type ?? ''));

        return match (true) {
            str_contains($t, 'office')     => 'Office',
            str_contains($t, 'retail')     => 'Retail',
            str_contains($t, 'industrial') => 'Industrial',
            str_contains($t, 'warehouse')  => 'Warehouse',
            str_contains($t, 'factory')    => 'Factory',
            str_contains($t, 'shop')       => 'Shop',
            str_contains($t, 'restaurant') => 'Restaurant',
            str_contains($t, 'hotel')      => 'Hotel',
            str_contains($t, 'mixed')      => 'Mixed Use',
            default                        => 'Commercial',
        };
    }

    private function mapFarmType(?string $type): string
    {
        $t = strtolower(trim($type ?? ''));

        return match (true) {
            str_contains($t, 'game')                                             => 'Game Farm',
            str_contains($t, 'wine')                                             => 'Wine Farm',
            str_contains($t, 'equestrian')                                       => 'Equestrian',
            str_contains($t, 'smallholding') || str_contains($t, 'small holding') => 'Small Holding',
            default                                                              => 'Farm',
        };
    }

    private function mapLandType(?string $type): string
    {
        $t = strtolower(trim($type ?? ''));

        // PP's LandType Value vocabulary uses SPACED names, verified against a
        // live GetFullDetailsOfAllListingsByBranch read-back (2026-07-06): of the
        // branch's 329 listings, all 38 land listings store LandType="Residential
        // Land". PP rejected the camelCase "VacantLand" with "PP106 - Invalid
        // attribute values supplied: VacantLand". Commercial/Industrial/
        // Agricultural follow PP's evident "<X> Land" pattern; a generic plot or
        // stand is residential land — the proven-accepted default.
        return match (true) {
            str_contains($t, 'commercial')   => 'Commercial Land',
            str_contains($t, 'industrial')   => 'Industrial Land',
            str_contains($t, 'agricultural') => 'Agricultural Land',
            default                          => 'Residential Land',
        };
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
        // Canonicalise to PP's digits-only format — legacy/raw values that
        // bypassed the User mutators (direct DB writes) still reach PP clean,
        // so "076 901 7397" never triggers PP107. See App\Support\SaPhoneNumber.
        $cellPhone = \App\Support\SaPhoneNumber::normalize($user->cell ?? $user->phone) ?? '';
        $workPhone = \App\Support\SaPhoneNumber::normalize($user->phone) ?? $cellPhone;

        // AgentId prefers pp_external_ref (admin-set on PP) over user->id.
        return [
            'AgentId'               => (string) ($user->pp_external_ref ?: $user->id),
            'FirstName'             => $firstName,
            'LastName'              => $lastName,
            'Email'                 => $user->outward_email ?? '', // AT-79 outward override
            'TelCell'               => $cellPhone,
            'TelWork'               => $workPhone,
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
        // Per-agency photo cap (default 150, matching P24). Source =
        // syndicationImages() — the exact curated gallery the agent sees
        // (gallery_images_json), NOT allImages(), which over-counts by merging
        // images_json (a divergent public mirror) and the dawn/noon/dusk sets.
        // What goes to PP must equal the CoreX gallery. PP downloads each URL
        // inside its SOAP transaction, so the cap guards against PP timing out.
        $maxPhotos     = $property->agency?->ppMaxPhotos() ?? Agency::PP_DEFAULT_MAX_PHOTOS;
        $galleryImages = $property->syndicationImages();

        // Never silently truncate. If the curated gallery exceeds the cap, log
        // a warning naming the dropped count so an over-cap listing is visible
        // rather than quietly losing photos.
        if (count($galleryImages) > $maxPhotos) {
            try {
                Log::channel('private_property')->warning(
                    'PP photo cap exceeded — photos dropped from submission',
                    [
                        'property_id' => $property->id,
                        'gallery'     => count($galleryImages),
                        'cap'         => $maxPhotos,
                        'dropped'     => count($galleryImages) - $maxPhotos,
                    ]
                );
            } catch (\Throwable $e) {
                // never block syndication on a log write
            }
        }

        $allImages = array_slice($galleryImages, 0, $maxPhotos);

        // PP downloads every URL inside its SOAP transaction and fails the ENTIRE
        // UpdateListing if a single one 404s (PP120 — "Image server returned N
        // failures"). One dangling reference therefore blocks all further updates
        // to the portal, silently, until someone reads the log. Property24 embeds
        // bytes and skips missing files, so it never surfaces the fault.
        //
        // Never hand PP a CoreX-hosted URL we cannot serve. Externally-hosted
        // images (portal mirrors) are passed through — we cannot stat them.
        //
        // Dropping to an EMPTY set is refused upstream (submitListing), because
        // an empty photo set can clear the images on the live portal listing.
        $servable  = $property->servableSyndicationImages();
        $missing   = array_values(array_diff($allImages, $servable));
        $allImages = array_values(array_intersect($allImages, $servable));

        if ($missing) {
            try {
                Log::channel('private_property')->error(
                    'PP submission dropped image references with no file on disk',
                    [
                        'property_id' => $property->id,
                        'dropped'     => count($missing),
                        'urls'        => array_slice($missing, 0, 10),
                    ]
                );
            } catch (\Throwable $e) {
                // never block syndication on a log write
            }
        }

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
