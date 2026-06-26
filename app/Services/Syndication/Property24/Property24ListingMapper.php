<?php

namespace App\Services\Syndication\Property24;

use App\Exceptions\Property24ConfigurationException;
use App\Models\Agency;
use App\Models\P24Suburb;
use App\Models\Property;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Property24ListingMapper
{
    /**
     * Map a CoreX Property to a P24 Listing payload matching the v53 API schema.
     *
     * @throws Property24ConfigurationException when the property's branch/agency
     *         has no P24 agency ID configured.
     */
    public function map(Property $property, bool $includePhotos = true): array
    {
        $agencyId       = $this->resolveP24AgencyId($property);
        $suburbId       = $this->resolveSuburbId($property);
        $propertyTypeId = $this->resolvePropertyTypeId($property->property_type);

        $listing = [
            'agencyId'          => $agencyId,
            'contactAgentIds'   => $this->resolveContactAgentIds($property, $agencyId),
            'listingType'       => $this->mapListingType($property->listing_type ?? $property->mandate_type),
            'status'            => $this->mapPropertyStatus($property),
            // P24 carries the monthly rent in `price` for rentals; effectivePrice()
            // returns rental_amount for rental stock, price for sales — one source.
            'price'             => $property->effectivePrice(),
            'isPOA'             => (bool) $property->price_on_application,
            'listingVisibility' => 'Public',
            'expiryDate'        => $property->expiry_date?->format('Y-m-d\TH:i:s')
                                   ?? now()->addYear()->format('Y-m-d\TH:i:s'),
            'description'       => $property->description ?? '',
            'descriptionHeader' => $property->headline ?? $property->title ?? '',
            'propertyInfo'      => $this->buildPropertyInfo($property, $suburbId, $propertyTypeId),
            'propertyFeatures'  => $this->buildPropertyFeatures($property),
            'youTubeVideoId'    => $this->extractYouTubeId($property->youtube_video_id),
            'matterportSpaceId' => $property->matterport_id ?: null,
        ];

        // Detailed feature tags (P24 `tags` array) — amenities that have no
        // dedicated propertyFeatures field (sea view, communal braai, security
        // detail, ports, etc.). Optional and purely additive.
        $tags = $this->buildTags($property);
        if (!empty($tags)) {
            $listing['tags'] = $tags;
        }

        // AT-102/AT-103 — per-room named rooms + their features, attached to the room
        // (not the property). A feature set both globally and on a room appears in both
        // tags[] (above) and here — correct, not deduped.
        $featureTags = $this->buildFeatureTags($property);
        if (!empty($featureTags)) {
            $listing['featureTags'] = $featureTags;
        }

        if ($property->latitude && $property->longitude) {
            $listing['propertyInfo']['geographicLocation'] = [
                'latitude'  => (float) $property->latitude,
                'longitude' => (float) $property->longitude,
            ];
        }

        if ($property->p24_ref) {
            $listing['listingNumber'] = (int) $property->p24_ref;
            // Only override to Active if property is still on market
            if ($listing['status'] === 'NewListing') {
                $listing['status'] = 'Active';
            }
        }

        // Complex info — always sent (P24 needs the full address regardless of
        // the public-display flag).
        if ($property->complex_name || $property->unit_number) {
            $listing['complexInfo'] = [
                'complexName' => $property->complex_name ?? null,
                'unitNumber'  => $property->unit_number ?? null,
            ];
        }

        // Rental info
        if ($this->mapListingType($property->listing_type ?? $property->mandate_type) === 'Rental') {
            $rentalInfo = ['leasePeriod' => $property->lease_period ?? '12 Months'];
            if ($property->deposit_amount) {
                $rentalInfo['depositRequirementsComments'] = 'Deposit: R ' . number_format((float) $property->deposit_amount, 0, '.', ' ');
            }
            $listing['rentalInfo'] = $rentalInfo;
        }

        // Commercial info
        if (in_array(strtolower($property->property_type ?? ''), ['commercial', 'industrial', 'office', 'retail'])) {
            $commercial = [];
            if ($property->gross_price) $commercial['grossPrice'] = (float) $property->gross_price;
            if ($property->net_price) $commercial['netPrice'] = (float) $property->net_price;
            if ($property->lease_start_date) $commercial['availabilityDate'] = $property->lease_start_date->format('Y-m-d');
            if (!empty($commercial)) $listing['commercialInfo'] = $commercial;
        }

        // Occupation date
        if ($property->lease_start_date) {
            $listing['occupationDate'] = $property->lease_start_date->format('Y-m-d\TH:i:s');
        }

        // Showdays
        $showdays = $this->buildShowdays($property);
        if (!empty($showdays)) {
            $listing['showDays'] = $showdays;
        }

        if ($includePhotos) {
            $photos = $this->buildPhotos($property);
            if (!empty($photos)) {
                $listing['photos'] = $photos;
            }
        }

        return $listing;
    }

    private function buildPropertyInfo(Property $property, ?int $suburbId, ?int $propertyTypeId): array
    {
        // P24 "hide address" affects ONLY public display, never the data we send.
        // P24's verification team REQUIRES the full address always (to vet
        // legitimacy, link/group properties, confirm the address is real), so the
        // street number/name, stand number, complex/unit and GPS are ALWAYS sent.
        // p24_hide_address=1 merely sets showLocation=false so P24 does not show
        // the address publicly. Independent of the PP pp_hide_* flags.
        $info = [
            'suburbId'        => $suburbId,
            'propertyTypeId'  => $propertyTypeId,
            'streetNumber'    => $property->street_number ?? '',
            'streetName'      => $property->street_name ?? $this->parseStreetName($property->address),
            'sourceReference' => 'CoreX-' . $property->id,
            'showLocation'    => $property->p24_hide_address
                ? false
                : (bool) ($property->latitude && $property->longitude),
        ];

        if ($property->stand_number) $info['standNumber'] = $property->stand_number;
        if ($property->erf_size_m2) $info['erf'] = ['areaUnit' => 'SquareMetres', 'size' => (float) $property->erf_size_m2];
        if ($property->size_m2) $info['floorArea'] = ['areaUnit' => 'SquareMetres', 'size' => (float) $property->size_m2];
        if ($property->floor_number) $info['floorNumber'] = (int) $property->floor_number;
        if ($property->rates_taxes) $info['municipalRatesAndTaxes'] = ['amount' => (float) $property->rates_taxes, 'unit' => 'TotalPrice'];
        if ($property->levy) $info['monthlyLevy'] = ['amount' => (float) $property->levy, 'unit' => 'TotalPrice'];
        if ($property->special_levy) $info['specialLevy'] = (float) $property->special_levy;

        return $info;
    }

    private function buildPropertyFeatures(Property $property): array
    {
        // AT-103/AT-102 — property-level booleans (hasFeature) must source from
        // GLOBAL-ONLY features (the property feature screen = spaces_json['features']),
        // NOT the flat features_json which also carries per-room unit features. A
        // feature that exists only on a room must not flip a property-level boolean.
        $feats = $this->globalFeatures($property);
        $spacesData = $property->spaces_json ?? [];
        $spacesList = $spacesData['spaces'] ?? (isset($spacesData[0]) ? $spacesData : []);
        $hasFeature = fn(string ...$names) => !empty(array_intersect(array_map('strtolower', $feats), array_map('strtolower', $names)));
        $countSpaces = fn(string $type) => collect($spacesList)->where('type', $type)->sum(fn($s) => (float) ($s['count'] ?? 1));

        $features = [
            'garages'         => (float) ($property->garages ?? 0),
            'garden'          => $hasFeature('Garden', 'Landscaped', 'Garden Services') || $countSpaces('Garden') > 0,
            'pool'            => $hasFeature('Pool', 'Communal Pool', 'Indoor Pool', 'Splash Pool') || $countSpaces('Pool') > 0,
            'flatlet'         => $hasFeature('Flatlet') || $countSpaces('Flatlet') > 0,
            'petsAllowed'     => $hasFeature('Pet Friendly', 'Pets Allowed') ? 'Yes' : ($hasFeature('Pets Not Allowed') ? 'No' : 'DontKnow'),
            'furnishedStatus' => $hasFeature('Furnished') ? 'Yes' : ($hasFeature('Unfurnished') ? 'No' : 'No'),
        ];

        if ($property->beds) $features['bedrooms'] = (float) $property->beds;
        if ($property->baths) $features['bathrooms'] = ['bathrooms' => (float) $property->baths];

        // Parking details from spaces/features
        $parkingSpaces = $countSpaces('Parking');
        if ($parkingSpaces > 0 || $hasFeature('Carport', 'Secure Parking', 'Street Parking', 'Underground Parking', 'Visitors Parking')) {
            $features['parking'] = [
                'parkingSpaces'        => $parkingSpaces > 0 ? (int) $parkingSpaces : null,
                'carport'              => $hasFeature('Carport'),
                'secureParking'        => $hasFeature('Secure Parking'),
                'onStreetParking'      => $hasFeature('Street Parking'),
                'undergroundParking'   => $hasFeature('Underground Parking'),
                'visitorsParking'      => $hasFeature('Visitors Parking'),
                'shadeNetCoveredParking' => $hasFeature('Shade Net Covered Parking'),
                'doubleParking'        => $hasFeature('Double Parking'),
                'singleParking'        => $hasFeature('Single Parking'),
                'tandemParking'        => $hasFeature('Tandem Parking'),
                'tripleParking'        => $hasFeature('Triple Parking'),
            ];
        }

        // Studies / offices
        $studies = $countSpaces('Study') + $countSpaces('Office');
        if ($studies > 0) $features['studies'] = (int) $studies;

        // Reception rooms (lounge + dining)
        $reception = $countSpaces('Lounge') + $countSpaces('Dining Room') + $countSpaces('TV Room');
        if ($reception > 0) $features['receptionRooms'] = (int) $reception;

        // Domestic rooms
        $domestic = $countSpaces('Domestic Room');
        if ($domestic > 0) $features['domesticRooms'] = (int) $domestic;

        // Domestic bathrooms
        $domesticBaths = $countSpaces('Domestic Bathroom');
        if ($domesticBaths > 0) $features['domesticBathrooms'] = (float) $domesticBaths;

        // Outside toilets
        $outsideToilets = $countSpaces('Outside Toilet');
        if ($outsideToilets > 0) $features['outsideToilets'] = (int) $outsideToilets;

        // Second house / outbuildings
        $features['secondHouse'] = $countSpaces('Flatlet') > 0 || $countSpaces('Wendy House') > 0;

        // Standalone building
        if ($hasFeature('Standalone')) $features['hasStandaloneBuilding'] = true;

        // Wheelchair accessible
        if ($hasFeature('Wheelchair Friendly')) $features['isWheelchairAccessible'] = true;

        // Generator
        if ($hasFeature('Generator')) $features['hasGenerator'] = true;

        // Backup water
        if ($hasFeature('Backup Water', 'Water Tank', 'Borehole')) $features['hasBackupWater'] = true;

        // Internet access — ALWAYS sent so P24 reflects CoreX as the source of
        // truth. Only CoreX features with a true P24 InternetAccessInfo
        // equivalent are mapped; "Fast Internet" and "Wi-Fi" are generic flags
        // with NO P24 technology field, so they never set fibre/adsl/satellite
        // (inferring fibre from Fast Internet made listings show "Fibre
        // internet" on P24). The block is emitted unconditionally — including
        // all-false — so that a re-push deterministically CLEARS a stale
        // fibre/adsl/satellite a previous push set; omitting it lets P24 retain
        // the old value (P24 does not clear fields absent from the payload).
        $features['internetAccess'] = [
            'adsl'      => $hasFeature('ADSL'),
            'fibre'     => $hasFeature('Fibre'),
            'satellite' => $hasFeature('Satellite Internet', 'Satellite Dish'),
        ];

        // Sustainability
        if ($hasFeature('Solar Panel', 'Solar Geyser', 'Gas Geyser', 'Water Tank', 'Borehole', 'Backup Battery', 'Inverter')) {
            $features['sustainabilityInfo'] = [
                'solarPanels'             => $hasFeature('Solar Panel', 'Solar Heating'),
                'solarGeyser'             => $hasFeature('Solar Geyser'),
                'gasGeyser'               => $hasFeature('Gas Geyser'),
                'waterTank'               => $hasFeature('Water Tank'),
                'borehole'                => $hasFeature('Borehole'),
                'backupBatteryOrInverter' => $hasFeature('Backup Battery', 'Inverter'),
            ];
        }

        // Kitchens
        $kitchens = $countSpaces('Kitchen');
        if ($kitchens > 0) {
            $features['kitchens'] = [
                'kitchens'   => (int) $kitchens,
                'dishwasher' => $hasFeature('Dishwasher'),
            ];
        }

        // Outside areas (balcony, courtyard, patio, veranda, etc.)
        $patios = $countSpaces('Patio') + $countSpaces('Veranda') + $countSpaces('Lapa') + $countSpaces('Courtyard');
        if ($patios > 0 || $hasFeature('Balcony', 'Courtyard')) {
            $features['outsideArea'] = [
                'outsideAreas' => (int) max($patios, 1),
                'balcony'      => $hasFeature('Balcony'),
                'courtyard'    => $hasFeature('Courtyard') || $countSpaces('Courtyard') > 0,
                'roofArea'     => false,
            ];
        }

        // Number of floors
        if ($hasFeature('Single Storey')) $features['numberOfFloors'] = 1;

        // Public transport
        if ($hasFeature('Near Bus Service', 'Near Train Service')) {
            $features['publicTransport'] = [
                'nearbyBusService'         => $hasFeature('Near Bus Service'),
                'nearbyMinibusTaxiService' => false,
                'nearbyTrainService'       => $hasFeature('Near Train Service'),
            ];
        }

        return $features;
    }

    /**
     * CoreX feature label → P24 `Tag` enum value.
     *
     * Only amenities that have NO dedicated propertyFeatures field live here —
     * everything else is already covered by buildPropertyFeatures(). Every value
     * on the right is a verified member of the P24 v53 `Tag` enum
     * (storage/p24_swagger.json); an unrecognised value would be rejected by the
     * portal, so do NOT add a mapping without confirming the enum string first.
     */
    private const FEATURE_TAG_MAP = [
        // Views / setting
        'Sea View'               => 'Sea',
        'Communal Braai Area'    => 'Communalbraaiarea',
        // Storey / floor position
        'Single Storey'          => 'SingleStorey',
        'Ground Floor Unit'      => 'GroundFloor',
        'Second Floor and Above' => 'Secondfloorandabove',
        'Top Floor'              => 'TopFloor',
        // Security
        'Alarm System'           => 'AlarmSystem',
        'Electric Gate'          => 'ElectricGate',
        'Electric Fence'         => 'Electricfencing',
        'Security Gate'          => 'SecurityGate',
        'Burglar Bars'           => 'BurglarBars',
        'CCTV'                   => 'ClosedCircuitTV',
        'Intercom'               => 'Intercom',
        '24 Hour Access'         => 'TwentyFourHourAccess',
        '24 Hour Guard'          => 'Guard',
        'Guard House'            => 'GuardHouse',
        'Boomed Area'            => 'BoomedArea',
        'Indoor Beams'           => 'IndoorBeams',
        'Outdoor Beams'          => 'OutdoorBeams',
        'Partially Fenced'       => 'PartiallyFenced',
        'Totally Fenced'         => 'TotallyFenced',
        'Totally Walled'         => 'TotallyWalled',
        'Perimeter Wall'         => 'PerimeterWall',
        'Gated Community'        => 'GatedCommunity',
        'Security Complex'       => 'SecurityComplex',
        'Security Estate'        => 'SecurityEstate',
        // Connectivity
        'Internet Port'          => 'InternetPort',
        'Telephone Port'         => 'TelephonePort',
        'TV Port'                => 'TVPort',
        'Satellite Dish'         => 'SatelliteDish',
        // Sustainability
        'Solar Heating'          => 'SolarHeating',
        'Septic Tank'            => 'SepticTank',

        // AT-102/AT-103 coverage audit — clean 1:1 CoreX feature ↔ P24 Tag enum pairings
        // that previously never syndicated (verified exact members of
        // storage/p24_swagger.json components.schemas.Tag.enum). Room-detail / kitchen /
        // bathroom / pool / floor / outdoor features. Excludes any feature already carried
        // by a dedicated propertyFeatures field (Carport/Secure/Underground/Visitors
        // Parking → parking{}, Landscaped/Garden Services → garden) per the rule above.
        // Ambiguous pairings (Fibre, ADSL, Cable TV, Wi-Fi, Air Conditioned, Armed
        // Response, Solar Panel, Inverter, …) are held for Johan — not guessed here.
        'Auto Cleaning Equipment' => 'AutoCleaningEquipment',
        'Basin' => 'Basin',
        'Bath' => 'Bath',
        'Bidet' => 'Bidet',
        'Breakfast Nook' => 'BreakfastNook',
        'Built-In Braai' => 'Built_inBraai',
        'Built-in Cupboards' => 'Built_inCupboards',
        'Chlorinator' => 'Chlorinator',
        'Communal' => 'Communal',
        'Covered' => 'Covered',
        'Dishwasher Connection' => 'DishwasherConnection',
        'Double Basin' => 'DoubleBasin',
        'En-suite' => 'EnSuite',
        'Extractor Fan' => 'ExtractorFan',
        'Eye Level Oven' => 'EyeLevelOven',
        'Fan' => 'Fan',
        'Fenced' => 'Fenced',
        'Fridge' => 'Fridge',
        'Garbage Disposal' => 'GarbageDisposal',
        'Garden Terrace' => 'GardenTerrace',
        'Gas Hob' => 'GasHob',
        'Gas Oven' => 'GasOven',
        'Granite Tops' => 'GraniteTops',
        'Grill' => 'Grill',
        'Guest Toilet' => 'GuestToilet',
        'Half Bathroom' => 'HalfBathroom',
        'Heated' => 'Heated',
        'Hob' => 'Hob',
        'Icemaker' => 'Icemaker',
        'Jacuzzi Bath' => 'JacuzziBath',
        'Laminated Floors' => 'LaminatedFloors',
        'Lighting' => 'Lighting',
        'Main en-suite' => 'MainenSuite',
        'Oven and Hob' => 'OvenAndHob',
        'Pantry' => 'Pantry',
        'Parquet Floors' => 'ParquetFloors',
        'Pizza Oven' => 'PizzaOven',
        'Safe' => 'Safe',
        'Safety Net' => 'SafetyNet',
        'Separate Toilet' => 'SeparateToilet',
        'Shower' => 'Shower',
        'Tiled Floors' => 'TiledFloors',
        'Toilet' => 'Toilet',
        'Tumble Dryer' => 'TumbleDryer',
        'Under Counter Oven' => 'UnderCounterOven',
        'Underfloor Heating' => 'UnderfloorHeating',
        'Urinal' => 'Urinal',
        'Vinyl Floors' => 'VinylFloors',
        'Walk in Closet' => 'Walk_in_closet',
        'Washing Machine Connection' => 'WashingMachineConnection',
        'Water Cooler' => 'WaterCooler',
        'Water Feature' => 'WaterFeature',
        'Wooden Floors' => 'WoodenFloors',
        'Zen Garden' => 'ZenGarden',
        'Zinc' => 'Zinc',
    ];

    /**
     * AT-102/AT-103 — CoreX space type → P24 FeatureType enum (storage/p24_swagger.json).
     * Drives featureTags[] so each room shows as a separate NAMED room on P24. Only
     * confident, direct equivalents are mapped; CoreX space types with no clean P24
     * FeatureType (Patio, Courtyard, Veranda, Lapa, Domestic Room/Bathroom, Outside
     * Toilet, Flatlet, Wendy House, …) are skipped and logged — never guessed.
     */
    private const SPACE_TYPE_TO_P24_FEATURETYPE = [
        'Bedroom'       => 'Bedroom',
        'Bathroom'      => 'Bathroom',
        'Garage'        => 'Garage',
        'Parking'       => 'Parking',
        'Kitchen'       => 'Kitchen',
        'Garden'        => 'Garden',
        'Pool'          => 'Pool',
        'Lounge'        => 'Lounge',
        'Dining Room'   => 'DiningRoom',
        'TV Room'       => 'FamilyTVRoom',
        'Entrance Hall' => 'EntranceHall',
        'Study'         => 'Office',
        'Office'        => 'Office',
        'Bar'           => 'Bar',
        'Braai Room'    => 'BraaiRoom',
        'Loft'          => 'Loft',
        'Closet'        => 'Closet',
        'Outbuilding'   => 'Outbuilding',
    ];

    /**
     * Build the P24 `tags` array from the property's selected feature labels.
     * Case-insensitive match against FEATURE_TAG_MAP; deduped; values are valid
     * P24 Tag enum members only.
     */
    private function buildTags(Property $property): array
    {
        // AT-103 — flat tags[] = GLOBAL (property-level) features only. Sourcing from
        // the property feature screen (spaces_json['features']) instead of the flat
        // features_json stops per-room-only features (e.g. a room-only TV Port) leaking
        // into property-level tags. A feature set BOTH globally and on a room stays here
        // (it's global) AND also appears in featureTags[] (decision A — no dedupe).
        $feats = $this->globalFeatures($property);
        if (empty($feats)) {
            return [];
        }

        // Lower-cased lookup so a stored 'sea view' still resolves to 'Sea View'.
        $lookup = [];
        foreach (self::FEATURE_TAG_MAP as $label => $tag) {
            $lookup[strtolower($label)] = $tag;
        }

        $tags = [];
        foreach ($feats as $feat) {
            $key = strtolower(trim((string) $feat));
            if (isset($lookup[$key])) {
                $tags[] = $lookup[$key];
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * AT-103 — the GLOBAL-ONLY feature set for tags[] + property-level booleans.
     *
     * global = features_json MINUS room-ONLY features, where a room-ONLY feature is one
     * that appears on a room (spaces['spaces'][].units[].features or a space's
     * featuresAll) AND is NOT in the explicit property feature-screen selection
     * (spaces['features']). A feature set BOTH globally and on a room STAYS global
     * (decision A — it also appears in featureTags[]).
     *
     * NB we do NOT use spaces['features'] as the sole source: it is empty/partial for
     * a large share of properties (imports + legacy edits store global features only in
     * the flat features_json with no per-room provenance). Subtracting only the
     * room-attributable features keeps those genuinely-global features intact (no
     * tags[] regression) while still stripping per-room-only leaks.
     *
     * @return string[]
     */
    private function globalFeatures(Property $property): array
    {
        $flat = array_values(array_unique(array_filter(
            array_map('strval', (array) ($property->features_json ?? [])),
            fn ($v) => trim($v) !== ''
        )));

        $sj = $property->spaces_json;
        $spaces = is_array($sj) ? ($sj['spaces'] ?? (isset($sj[0]) ? $sj : [])) : [];
        if (empty($spaces)) {
            return $flat; // legacy / no structured rooms — features_json is the global set
        }

        // Explicit property-screen global selection (may be empty for imports/legacy).
        $explicitGlobal = [];
        foreach (($sj['features'] ?? []) as $catArr) {
            $vals = is_array($catArr) ? $catArr : [$catArr];
            foreach ($vals as $f) {
                if (filled($f)) $explicitGlobal[strtolower(trim((string) $f))] = true;
            }
        }

        // Features attributable to a room (per-unit + space-level).
        $roomFeatures = [];
        foreach ($spaces as $sp) {
            foreach (($sp['featuresAll'] ?? []) as $f) {
                if (filled($f)) $roomFeatures[strtolower(trim((string) $f))] = true;
            }
            foreach (($sp['units'] ?? []) as $u) {
                foreach (($u['features'] ?? []) as $f) {
                    if (filled($f)) $roomFeatures[strtolower(trim((string) $f))] = true;
                }
            }
        }

        return array_values(array_filter($flat, function ($f) use ($roomFeatures, $explicitGlobal) {
            $k = strtolower(trim((string) $f));
            $roomOnly = isset($roomFeatures[$k]) && !isset($explicitGlobal[$k]);
            return !$roomOnly; // keep global + both-set; drop room-only
        }));
    }

    /**
     * AT-102/AT-103 — build P24 featureTags[] so each room shows as a separate NAMED
     * room with its per-room features attached (the display agents expect). One entry
     * per unit (Bedroom 1/2/3…) carrying that unit's features mapped via FEATURE_TAG_MAP;
     * for room-type spaces without per-unit detail, one entry carrying the space-level
     * featuresAll. Unmapped feature labels and unmapped CoreX space types are skipped
     * and logged — never guessed. receptionRooms count is left untouched (no regression).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFeatureTags(Property $property): array
    {
        $sj = $property->spaces_json;
        $spaces = is_array($sj) ? ($sj['spaces'] ?? (isset($sj[0]) ? $sj : [])) : [];
        if (empty($spaces)) {
            return [];
        }

        $tagLookup = [];
        foreach (self::FEATURE_TAG_MAP as $label => $tag) {
            $tagLookup[strtolower($label)] = $tag;
        }

        $skippedTags = [];
        $mapRoomFeatures = function (array $features) use ($tagLookup, &$skippedTags): array {
            $tags = [];
            foreach ($features as $f) {
                $key = strtolower(trim((string) $f));
                if ($key === '') continue;
                if (isset($tagLookup[$key])) {
                    $tags[] = $tagLookup[$key];
                } else {
                    $skippedTags[] = (string) $f;
                }
            }
            return array_values(array_unique($tags));
        };

        $featureTags = [];
        $skippedSpaceTypes = [];

        foreach ($spaces as $sp) {
            $type = $sp['type'] ?? null;
            if (!$type) {
                continue;
            }
            $featureType = self::SPACE_TYPE_TO_P24_FEATURETYPE[$type] ?? null;
            if ($featureType === null) {
                $skippedSpaceTypes[] = (string) $type;
                continue;
            }

            $units = $sp['units'] ?? [];
            if (!empty($units)) {
                // One named room per individual unit, with its own features.
                foreach ($units as $u) {
                    $entry = ['featureType' => $featureType];
                    $tags = $mapRoomFeatures($u['features'] ?? []);
                    if (!empty($tags)) {
                        $entry['tags'] = $tags;
                    }
                    $label = trim((string) ($u['label'] ?? ''));
                    if ($label !== '') {
                        $entry['description'] = $label;
                    }
                    $featureTags[] = $entry;
                }
            } else {
                // Named room without per-unit detail (e.g. Lounge, Dining Room).
                $entry = ['featureType' => $featureType];
                $tags = $mapRoomFeatures($sp['featuresAll'] ?? []);
                if (!empty($tags)) {
                    $entry['tags'] = $tags;
                }
                $desc = trim((string) ($sp['descriptionAll'] ?? ''));
                if ($desc !== '') {
                    $entry['description'] = $desc;
                }
                $featureTags[] = $entry;
            }
        }

        if (!empty($skippedTags) || !empty($skippedSpaceTypes)) {
            // Logging is best-effort — it must never break the mapping (and the logger
            // isn't booted in pure unit contexts).
            try {
                \Illuminate\Support\Facades\Log::channel('property24')->info(
                    'P24 featureTags — skipped unmapped items',
                    [
                        'property_id'         => $property->id,
                        'skipped_tags'        => array_values(array_unique($skippedTags)),
                        'skipped_space_types' => array_values(array_unique($skippedSpaceTypes)),
                    ]
                );
            } catch (\Throwable $e) {
                // no-op — never block syndication on a log write
            }
        }

        return $featureTags;
    }

    public function validate(array $payload): array
    {
        $errors = [];
        if (empty($payload['agencyId'])) $errors[] = 'No Property24 agency ID resolved — set it on the agency or branch.';
        if (empty($payload['description'])) $errors[] = 'Description is required';
        if (empty($payload['propertyInfo']['suburbId'])) $errors[] = 'Suburb ID is required — map the suburb in P24 Suburb Settings';
        if (empty($payload['propertyInfo']['propertyTypeId'])) $errors[] = 'Property type could not be mapped to a P24 type ID';
        return $errors;
    }

    public function checkReadiness(Property $property): array
    {
        $missing = [];
        if (empty($property->description)) $missing[] = ['field' => 'description', 'label' => 'Description'];
        if (empty($property->suburb)) {
            $missing[] = ['field' => 'suburb', 'label' => 'Suburb'];
        } else {
            if (!$this->resolveSuburbId($property)) {
                $missing[] = ['field' => 'suburb_id', 'label' => 'Suburb not mapped to P24 ID (set in P24 Suburb Settings)'];
            }
        }
        if (empty($property->property_type)) $missing[] = ['field' => 'property_type', 'label' => 'Property Type'];
        if ($property->effectivePrice() <= 0 && !$property->price_on_application) $missing[] = ['field' => 'price', 'label' => 'Price (or enable Price On Application)'];
        if (empty($property->allImages())) $missing[] = ['field' => 'images', 'label' => 'At least one photo'];
        if (empty($property->listing_type) && empty($property->mandate_type)) $missing[] = ['field' => 'listing_type', 'label' => 'Listing Type (Sale/Rental)'];
        if (empty($property->resolveP24AgencyId())) {
            $missing[] = [
                'field' => 'p24_agency_id',
                'label' => 'Property24 agency ID not configured on branch or agency',
            ];
        }
        return $missing;
    }

    /**
     * Resolve the P24 agency ID for a property. Branch override > agency default.
     * Throws when neither is set so submission fails loudly rather than
     * silently routing to the wrong profile (or the wrong tenant).
     */
    private function resolveP24AgencyId(Property $property): int
    {
        $resolved = $property->resolveP24AgencyId();
        if ($resolved === null || $resolved === '') {
            $branchName = $property->branch?->name ?? '(no branch)';
            $agencyName = $property->agency?->name ?? $property->branch?->agency?->name ?? '(no agency)';
            throw new Property24ConfigurationException(
                "Property #{$property->id} cannot be syndicated: no Property24 agency ID on "
                . "branch '{$branchName}' or agency '{$agencyName}'."
            );
        }
        return (int) $resolved;
    }

    private function buildPhotos(Property $property): array
    {
        $photos = [];
        $catData = $property->gallery_categories_json;

        // Build a map of image URL → category name for captions
        $captionMap = [];
        if ($catData && isset($catData['categories'])) {
            foreach ($catData['categories'] as $cat) {
                foreach ($cat['images'] ?? [] as $img) {
                    $captionMap[$img] = $cat['name'];
                }
            }
        }

        // AT-101: per-agency photo cap (default 30 reproduces prior behaviour).
        $maxPhotos = $property->agency?->p24MaxPhotos() ?? Agency::P24_DEFAULT_MAX_PHOTOS;
        $images = array_slice($property->allImages(), 0, $maxPhotos);

        foreach ($images as $imagePath) {
            if (empty($imagePath)) continue;

            $diskPath = $this->urlToDiskPath($imagePath);

            if ($diskPath && Storage::disk('public')->exists($diskPath)) {
                $bytes = Storage::disk('public')->get($diskPath);
                if (empty($bytes)) continue;

                $photos[] = [
                    'bytes'           => base64_encode($bytes),
                    'mimeContentType' => Storage::disk('public')->mimeType($diskPath) ?: 'image/jpeg',
                    'caption'         => $captionMap[$imagePath] ?? null,
                    'isFloorPlan'     => false,
                ];
            }
        }

        return $photos;
    }

    /**
     * Convert a Storage::url() path back to a disk-relative path.
     * e.g. "/storage/properties/16/file.jpg" => "properties/16/file.jpg"
     * or "https://domain.com/storage/properties/16/file.jpg" => "properties/16/file.jpg"
     */
    private function urlToDiskPath(string $url): ?string
    {
        // Strip domain if full URL
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $parsed = parse_url($url);
            $url = $parsed['path'] ?? $url;
        }

        // Strip the /storage/ prefix that Storage::url() adds
        if (str_contains($url, '/storage/')) {
            return substr($url, strpos($url, '/storage/') + 9); // 9 = strlen('/storage/')
        }

        // If it's already a relative path like "properties/16/file.jpg"
        if (str_starts_with($url, 'properties/')) {
            return $url;
        }

        return null;
    }

    private function resolveSuburbId(Property $property): ?int
    {
        if ($property->pp_suburb_id) {
            $suburb = P24Suburb::find($property->pp_suburb_id);
            if ($suburb && $suburb->p24_id) return (int) $suburb->p24_id;
        }
        if (!$property->suburb) return null;

        // 1. Exact/slug match
        $suburb = P24Suburb::lookup($property->suburb);
        if ($suburb && $suburb->p24_id) return (int) $suburb->p24_id;

        // 2. Fuzzy match against existing p24_suburbs (handles trailing "Beach", punctuation,
        //    extra whitespace, etc. — avoids a P24 roundtrip for suburbs we already have).
        $fuzzy = $this->fuzzyLocalMatch((string) $property->suburb);
        if ($fuzzy && $fuzzy->p24_id) return (int) $fuzzy->p24_id;

        // 3. Auto-resolve via P24 API — creates/updates a P24Suburb row on success.
        return $this->autoResolveSuburbFromP24($property);
    }

    private function normaliseProvince(?string $province): string
    {
        $p = strtolower(trim((string) $province));
        if ($p === '') return '';
        return match (true) {
            str_contains($p, 'kwazulu') || $p === 'kzn'   => 'KwaZulu-Natal',
            str_contains($p, 'western')                   => 'Western Cape',
            str_contains($p, 'eastern')                   => 'Eastern Cape',
            str_contains($p, 'northern')                  => 'Northern Cape',
            str_contains($p, 'north west')                => 'North West',
            str_contains($p, 'gauteng') || $p === 'gp'    => 'Gauteng',
            str_contains($p, 'mpumalanga')                => 'Mpumalanga',
            str_contains($p, 'limpopo')                   => 'Limpopo',
            str_contains($p, 'free state')                => 'Free State',
            default                                        => ucwords($p),
        };
    }

    /**
     * Loose match against p24_suburbs using LIKE on the normalised name.
     * Returns the best single match or null.
     */
    private function fuzzyLocalMatch(string $suburbName): ?P24Suburb
    {
        $normalised = strtolower(preg_replace('/[^a-z0-9 ]+/i', '', trim($suburbName)));
        if ($normalised === '') return null;

        $candidates = P24Suburb::whereRaw('LOWER(name) LIKE ?', ['%' . $normalised . '%'])
            ->orWhereRaw('? LIKE CONCAT(\'%\', LOWER(name), \'%\')', [$normalised])
            ->limit(5)->get();

        if ($candidates->isEmpty()) return null;

        // Prefer the exact lowercase equality, else shortest name (most specific root token)
        foreach ($candidates as $c) {
            if (strtolower($c->name) === $normalised) return $c;
        }
        return $candidates->sortBy(fn ($c) => strlen($c->name))->first();
    }

    /**
     * Look up the suburb on P24 (GET /suburbs/find) and cache the result in p24_suburbs.
     * Falls back gracefully — returns null if P24 can't find it so the caller still
     * surfaces the existing "Suburb not mapped" error.
     */
    private function autoResolveSuburbFromP24(Property $property): ?int
    {
        $suburbName = trim((string) $property->suburb);
        if ($suburbName === '') return null;

        $city     = trim((string) ($property->town ?? $property->city ?? ''));
        $province = $this->normaliseProvince($property->province ?? '');

        $agency = $property->agency ?? \App\Models\Agency::find($property->agency_id);
        $client = new Property24ApiClient($agency);

        // Build province candidate list — if we know the province, try it first,
        // then fall through ALL SA provinces so suburbs like Sandton (Gauteng)
        // or Stellenbosch (Western Cape) resolve even when the property row
        // doesn't have province set.
        $allProvinces = [
            'KwaZulu-Natal', 'Gauteng', 'Western Cape', 'Eastern Cape',
            'Free State', 'Mpumalanga', 'Limpopo', 'North West', 'Northern Cape',
        ];
        $provinceCandidates = [];
        if ($province !== '') $provinceCandidates[] = $province;
        foreach ($allProvinces as $p) {
            if (!in_array($p, $provinceCandidates, true)) $provinceCandidates[] = $p;
        }

        // Suburb-name variants
        $nameVariants = [$suburbName];
        $stripped = trim(preg_replace('/\b(beach|bay|park|heights|on sea)\b/i', '', $suburbName));
        if ($stripped !== '' && strcasecmp($stripped, $suburbName) !== 0) {
            $nameVariants[] = $stripped;
        }

        // Build attempt matrix: (name, city, province).
        // Order: try city-qualified first for the known province, then
        // drop city for all provinces to maximise chance of a hit.
        $attempts = [];
        if ($province !== '' && $city !== '') {
            foreach ($nameVariants as $n) {
                $attempts[] = ['name' => $n, 'city' => $city, 'province' => $province];
            }
        }
        foreach ($provinceCandidates as $prov) {
            foreach ($nameVariants as $n) {
                // With suburb as its own cityName — common for small suburbs
                $attempts[] = ['name' => $n, 'city' => $n, 'province' => $prov];
                // And without city
                $attempts[] = ['name' => $n, 'city' => '', 'province' => $prov];
            }
        }

        $p24Id = null; $remote = null; $lastMsg = null;
        foreach ($attempts as $a) {
            try {
                $result = $client->findSuburb($a['name'], $a['city'], $a['province']);
            } catch (\Throwable $e) {
                $lastMsg = $e->getMessage();
                continue;
            }
            $lastMsg = $result['message'] ?? null;
            if (!($result['success'] ?? false)) continue;

            $data = $result['data'] ?? [];
            $found = $data['found'] ?? ($data['Found'] ?? false);
            $remote = $data['suburb'] ?? ($data['Suburb'] ?? null);
            $id = $remote['id'] ?? ($remote['Id'] ?? null);
            if ($found && $id) { $p24Id = (int) $id; break; }
        }

        if (!$p24Id) {
            Log::channel('property24')->warning('auto suburb lookup exhausted', [
                'suburb' => $suburbName, 'city' => $city, 'province' => $province, 'last' => $lastMsg,
            ]);
            return null;
        }

        $slug = Str::slug($suburbName);
        P24Suburb::updateOrCreate(
            ['slug' => $slug],
            [
                'name'      => $remote['name'] ?? $suburbName,
                'p24_id'    => (int) $p24Id,
                'region'    => $remote['cityName'] ?? $city ?: null,
                'confirmed' => true,
            ]
        );

        Log::channel('property24')->info('auto-resolved suburb from P24', ['suburb' => $suburbName, 'p24_id' => (int) $p24Id]);

        return (int) $p24Id;
    }

    /**
     * Map CoreX property type to P24 propertyTypeId.
     * IDs sourced from GET /listing/v53/property-types on ExDev:
     *   4 = House, 5 = Apartment/Flat, 6 = Townhouse,
     *   8 = Vacant Land/Plot, 10 = Farm, 11 = Commercial, 12 = Industrial
     */
    private function resolvePropertyTypeId(?string $type): ?int
    {
        if (empty($type)) return null;

        // Normalise: lowercase, replace any non-alphanum with single space, collapse, trim.
        // Lets us match "Apartment / Flat", "Apartment/Flat", "apartment-flat", etc.
        $norm = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/i', ' ', strtolower($type))));

        $padded = " {$norm} ";
        $contains = function (string ...$needles) use ($padded): bool {
            foreach ($needles as $n) {
                if (str_contains($padded, " {$n} ")) return true;
            }
            return false;
        };

        if ($contains('industrial')) return 12;
        if ($contains('commercial', 'office', 'retail', 'hospitality')) return 11;
        if ($contains('farm', 'smallholding', 'small holding', 'agricultural')) return 10;
        if ($contains('vacant land', 'land', 'plot', 'stand', 'erf')) return 8;
        if ($contains('townhouse', 'duplex', 'simplex', 'cluster')) return 6;
        if ($contains('apartment', 'flat', 'penthouse')) return 5;
        if ($contains('house', 'freestanding', 'free standing', 'cottage', 'garden cottage')) return 4;

        return 4; // default: House
    }

    private function resolveContactAgentIds(Property $property, int $agencyId): array
    {
        // Build an agency-scoped client so credentials come from the property's
        // agency row (not the now-empty .env). Without this the client falls
        // back to env creds, authenticates as nobody, and returns zero agents
        // — which P24 then rejects as "must have one or more agents".
        $agency = $property->agency ?? \App\Models\Agency::find($property->agency_id);
        $client = new Property24ApiClient($agency);
        // Scope the lookup to the property's resolved agency — otherwise a
        // property under agency B would pull agents from agency A's feed.
        $result = $client->getAgents((string) $agencyId);

        if (!$result['success']) return [];

        $agents = $result['data'] ?? [];
        $ids = [];

        // Agents who opted out of P24 must never be attached to a syndicated
        // listing, even if a stale P24 record still carries their sourceReference.
        $excludedUserIds = \App\Models\User::withTrashed()
            ->whereIn('id', array_filter([$property->agent_id, $property->pp_second_agent_id]))
            ->where('exclude_from_p24', true)
            ->pluck('id')
            ->all();

        // Primary agent
        if ($property->agent_id && !in_array((int) $property->agent_id, $excludedUserIds, true)) {
            $sourceRef = 'CoreX-Agent-' . $property->agent_id;
            foreach ($agents as $agent) {
                if (($agent['sourceReference'] ?? '') === $sourceRef) {
                    $ids[] = (int) $agent['id'];
                    break;
                }
            }
        }

        // Second agent
        if ($property->pp_second_agent_id && !in_array((int) $property->pp_second_agent_id, $excludedUserIds, true)) {
            $sourceRef = 'CoreX-Agent-' . $property->pp_second_agent_id;
            foreach ($agents as $agent) {
                if (($agent['sourceReference'] ?? '') === $sourceRef) {
                    $ids[] = (int) $agent['id'];
                    break;
                }
            }
        }

        return $ids;
    }

    private function buildShowdays(Property $property): array
    {
        $showdays = [];
        $active = $property->activeShowdays ?? $property->showdays()->where('active', true)->where('end_date', '>=', now())->get();

        foreach ($active as $s) {
            $showdays[] = [
                'startDate' => $s->start_date->format('Y-m-d\TH:i:s'),
                'endDate'   => $s->end_date->format('Y-m-d\TH:i:s'),
            ];
        }

        return $showdays;
    }

    /**
     * Map CoreX property status to P24 ListingStatus enum.
     * P24 statuses: NewListing, Active, Rented, Withdrawn, BackOnMarket,
     *               Expired, Extended, RaisedPrice, ReducedPrice,
     *               Cancelled, Pending, Sold, CancelledSale
     */
    private function mapPropertyStatus(Property $property): string
    {
        return self::getP24Status($property->status, $property->p24_ref, $property->status_label);
    }

    /**
     * Static helper: convert a CoreX two-tier status (base status + optional
     * sub-label banner) to a P24 ListingStatus. Used by both the mapper and the
     * observer.
     *
     * Two-tier model (mirrors P24/Propcon): a listing has a BASE status and an
     * optional SUB-LABEL on an on-market base — e.g. For Sale + "Reduced Price",
     * For Sale + "Pending", For Sale + "Back on Market". The sub-label IS the
     * authoritative P24 lifecycle signal when present, so it is resolved FIRST;
     * a For-Sale base with no label falls through to the base-status logic.
     *
     * Back-compat: $statusLabel is optional and defaults to null. With a null
     * label this returns exactly what the prior flat-status version returned for
     * every base status — proven by the AT-P24 before/after round-trip table —
     * with one intentional fix: a let-out rental now maps to 'Rented' instead of
     * silently falling through to 'NewListing'.
     */
    public static function getP24Status(?string $corexStatus, ?string $p24Ref = null, ?string $statusLabel = null): string
    {
        $normalise = static function (?string $v): string {
            $v = strtolower(str_replace(['•', '_'], ['', ' '], trim($v ?? '')));
            return preg_replace('/\s+/', ' ', $v); // collapse multiple spaces
        };

        // 1) Sub-label (banner) takes precedence — it is the P24 lifecycle state.
        $label = $normalise($statusLabel);
        if ($label !== '') {
            $fromLabel = match (true) {
                str_contains($label, 'reduced')        => 'ReducedPrice',
                str_contains($label, 'raised')         => 'RaisedPrice',
                str_contains($label, 'back on market') => 'BackOnMarket',
                str_contains($label, 'under offer')
                    || str_contains($label, 'pending') => 'Pending',
                default                                => null,
            };
            if ($fromLabel !== null) {
                return $fromLabel;
            }
        }

        // 2) Base status.
        $status = $normalise($corexStatus);

        return match (true) {
            str_contains($status, 'sold')              => 'Sold',
            str_contains($status, 'rented')
                || str_contains($status, 'let out')     => 'Rented',
            str_contains($status, 'withdrawn')
                || str_contains($status, 'unavailable') => 'Withdrawn',
            str_contains($status, 'under offer')
                || str_contains($status, 'pending')     => 'Pending',
            str_contains($status, 'back on market')     => 'BackOnMarket',
            str_contains($status, 'reduced')            => 'ReducedPrice',
            str_contains($status, 'raised')             => 'RaisedPrice',
            str_contains($status, 'expired')            => 'Expired',
            str_contains($status, 'cancelled')
                || str_contains($status, 'archived')    => 'Cancelled',
            str_contains($status, 'draft')              => 'Withdrawn',
            str_contains($status, 'auction')            => 'Active',
            $p24Ref !== null                            => 'Active',
            default                                     => 'NewListing',
        };
    }

    /**
     * Check if a P24 status is a terminal/off-market status.
     */
    public static function isTerminalStatus(string $p24Status): bool
    {
        return in_array($p24Status, ['Sold', 'Rented', 'Withdrawn', 'Expired', 'Cancelled']);
    }

    private function mapListingType(?string $type): string
    {
        if (empty($type)) return 'Sale';
        return match (strtolower($type)) {
            'sale', 'for sale', 'sell' => 'Sale',
            'rental', 'rent', 'to let' => 'Rental',
            default => 'Sale',
        };
    }

    private function parseStreetName(?string $address): string
    {
        if (empty($address)) return '';
        $parts = explode(',', $address);
        return preg_replace('/^\d+\s+/', '', trim($parts[0] ?? ''));
    }

    private function extractYouTubeId(?string $input): ?string
    {
        if (!$input) return null;
        $input = trim($input);
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) return $input;
        if (preg_match('/(?:youtube\.com\/(?:watch\?\S*?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/|v=)([a-zA-Z0-9_-]{11})/', $input, $m)) {
            return $m[1];
        }
        return null;
    }
}
