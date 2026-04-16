<?php

namespace App\Services\Syndication\Property24;

use App\Models\P24Suburb;
use App\Models\Property;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Property24ListingMapper
{
    /**
     * Map a CoreX Property to a P24 Listing payload matching the v53 API schema.
     */
    public function map(Property $property, bool $includePhotos = true): array
    {
        $agencyId       = (int) config('services.property24_syndication.agency_id');
        $suburbId       = $this->resolveSuburbId($property);
        $propertyTypeId = $this->resolvePropertyTypeId($property->property_type);

        $listing = [
            'agencyId'          => $agencyId,
            'contactAgentIds'   => $this->resolveContactAgentIds($property, $agencyId),
            'listingType'       => $this->mapListingType($property->listing_type ?? $property->mandate_type),
            'status'            => $this->mapPropertyStatus($property),
            'price'             => (float) ($property->price ?? 0),
            'isPOA'             => (bool) $property->price_on_application,
            'listingVisibility' => 'Public',
            'expiryDate'        => $property->expiry_date?->format('Y-m-d\TH:i:s')
                                   ?? now()->addYear()->format('Y-m-d\TH:i:s'),
            'description'       => $property->description ?? '',
            'descriptionHeader' => $property->headline ?? $property->title ?? '',
            'propertyInfo'      => $this->buildPropertyInfo($property, $suburbId, $propertyTypeId),
            'propertyFeatures'  => $this->buildPropertyFeatures($property),
        ];

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

        // Complex info
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
        $info = [
            'suburbId'        => $suburbId,
            'propertyTypeId'  => $propertyTypeId,
            'streetNumber'    => $property->street_number ?? '',
            'streetName'      => $property->street_name ?? $this->parseStreetName($property->address),
            'sourceReference' => 'CoreX-' . $property->id,
            'showLocation'    => (bool) ($property->latitude && $property->longitude),
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
        $feats = $property->features_json ?? [];
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

        // Internet access
        if ($hasFeature('ADSL', 'Fibre', 'Fast Internet', 'Satellite Internet', 'Wi-Fi')) {
            $features['internetAccess'] = [
                'adsl'      => $hasFeature('ADSL'),
                'fibre'     => $hasFeature('Fibre', 'Fast Internet'),
                'satellite' => $hasFeature('Satellite Internet', 'Satellite Dish'),
            ];
        }

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

    public function validate(array $payload): array
    {
        $errors = [];
        if (empty($payload['agencyId'])) $errors[] = 'Agency ID is not configured (P24_EXDEV_AGENCY_ID)';
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
        if (empty($property->price) && !$property->price_on_application) $missing[] = ['field' => 'price', 'label' => 'Price (or enable Price On Application)'];
        if (empty($property->allImages())) $missing[] = ['field' => 'images', 'label' => 'At least one photo'];
        if (empty($property->listing_type) && empty($property->mandate_type)) $missing[] = ['field' => 'listing_type', 'label' => 'Listing Type (Sale/Rental)'];
        return $missing;
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

        $images = array_slice($property->allImages(), 0, 30);

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

        $client = app(Property24ApiClient::class);

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
        return match (strtolower(trim($type))) {
            'house', 'freestanding', 'free standing'            => 4,
            'apartment', 'flat', 'penthouse'                    => 5,
            'townhouse', 'duplex', 'simplex', 'cluster'        => 6,
            'vacant land', 'land', 'plot'                       => 8,
            'farm', 'smallholding', 'small holding'             => 10,
            'commercial', 'office', 'retail'                    => 11,
            'industrial'                                        => 12,
            'garden cottage', 'cottage'                         => 4,
            default                                             => 4,
        };
    }

    private function resolveContactAgentIds(Property $property, int $agencyId): array
    {
        $client = app(Property24ApiClient::class);
        $result = $client->getAgents();

        if (!$result['success']) return [];

        $agents = $result['data'] ?? [];
        $ids = [];

        // Primary agent
        if ($property->agent_id) {
            $sourceRef = 'CoreX-Agent-' . $property->agent_id;
            foreach ($agents as $agent) {
                if (($agent['sourceReference'] ?? '') === $sourceRef) {
                    $ids[] = (int) $agent['id'];
                    break;
                }
            }
        }

        // Second agent
        if ($property->pp_second_agent_id) {
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
        return self::getP24Status($property->status, $property->p24_ref);
    }

    /**
     * Static helper: convert CoreX status string to P24 ListingStatus.
     * Used by both the mapper and the observer.
     */
    public static function getP24Status(?string $corexStatus, ?string $p24Ref = null): string
    {
        // Normalize: lowercase, strip bullets, replace underscores with spaces
        $status = strtolower(str_replace(['•', '_'], ['', ' '], trim($corexStatus ?? '')));
        $status = preg_replace('/\s+/', ' ', $status); // collapse multiple spaces

        return match (true) {
            str_contains($status, 'sold')              => 'Sold',
            str_contains($status, 'rented')            => 'Rented',
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
}
