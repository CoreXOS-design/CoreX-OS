<?php

namespace App\Services\Syndication\Property24;

use App\Models\P24Suburb;
use App\Models\Property;
use Illuminate\Support\Facades\Storage;

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
            'contactAgentIds'   => [$property->agent_id ? (int) $property->agent_id : $agencyId],
            'listingType'       => $this->mapListingType($property->listing_type ?? $property->mandate_type),
            'status'            => 'NewListing',
            'price'             => (float) ($property->price ?? 0),
            'isPOA'             => (bool) $property->price_on_application,
            'listingVisibility' => 'Public',
            'expiryDate'        => $property->expiry_date?->format('Y-m-d\TH:i:s')
                                   ?? now()->addYear()->format('Y-m-d\TH:i:s'),
            'description'       => $property->description ?? '',
            'descriptionHeader' => $property->headline ?? $property->title ?? '',
            'propertyInfo'      => [
                'suburbId'       => $suburbId,
                'propertyTypeId' => $propertyTypeId,
                'streetNumber'   => $property->street_number ?? '',
                'streetName'     => $property->street_name ?? $this->parseStreetName($property->address),
                'standNumber'    => $property->stand_number ?? '',
                'sourceReference' => 'CoreX-' . $property->id,
                'showLocation'   => ($property->latitude && $property->longitude),
                'erf'            => ($property->erf_size_m2 ? ['measurement' => 'SquareMetres', 'size' => (float) $property->erf_size_m2] : null),
                'floorArea'      => ($property->size_m2 ? ['measurement' => 'SquareMetres', 'size' => (float) $property->size_m2] : null),
                'floorNumber'    => $property->floor_number ? (int) $property->floor_number : null,
                'municipalRatesAndTaxes' => ($property->rates_taxes ? ['amount' => (float) $property->rates_taxes, 'period' => 'Monthly'] : null),
                'monthlyLevy'    => ($property->levy ? ['amount' => (float) $property->levy, 'period' => 'Monthly'] : null),
                'specialLevy'    => $property->special_levy ? (float) $property->special_levy : null,
            ],
            'propertyFeatures'  => [
                'bedrooms'        => $property->beds ? (float) $property->beds : null,
                'bathrooms'       => $property->baths ? ['full' => (int) $property->baths] : null,
                'garages'         => (float) ($property->garages ?? 0),
                'garden'          => false,
                'pool'            => false,
                'flatlet'         => false,
                'petsAllowed'     => null,
                'furnishedStatus' => null,
            ],
        ];

        if ($property->latitude && $property->longitude) {
            $listing['propertyInfo']['geographicLocation'] = [
                'latitude'  => (float) $property->latitude,
                'longitude' => (float) $property->longitude,
            ];
        }

        if ($property->p24_ref) {
            $listing['listingNumber'] = (int) $property->p24_ref;
            $listing['status'] = 'Active';
        }

        if ($this->mapListingType($property->listing_type ?? $property->mandate_type) === 'Rental') {
            $listing['rentalInfo'] = [
                'depositRequirementsComments' => $property->deposit_amount
                    ? 'Deposit: R ' . number_format((float) $property->deposit_amount, 0, '.', ' ')
                    : null,
                'leasePeriod' => $property->lease_period ?? '12 Months',
            ];
        }

        $listing['photos'] = $includePhotos ? $this->buildPhotos($property) : null;

        return $listing;
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
        $images = array_slice($property->allImages(), 0, 30);

        foreach ($images as $imagePath) {
            if (empty($imagePath)) continue;
            $diskPath = str_starts_with($imagePath, 'properties/') ? 'public/' . $imagePath : $imagePath;
            if (!Storage::exists($diskPath)) continue;
            $bytes = Storage::get($diskPath);
            if (empty($bytes)) continue;

            $photos[] = [
                'bytes'           => base64_encode($bytes),
                'mimeContentType' => Storage::mimeType($diskPath) ?: 'image/jpeg',
                'caption'         => null,
                'isFloorPlan'     => false,
            ];
        }

        return $photos;
    }

    private function resolveSuburbId(Property $property): ?int
    {
        if ($property->pp_suburb_id) {
            $suburb = P24Suburb::find($property->pp_suburb_id);
            if ($suburb && $suburb->p24_id) return (int) $suburb->p24_id;
        }
        if ($property->suburb) {
            $suburb = P24Suburb::lookup($property->suburb);
            if ($suburb && $suburb->p24_id) return (int) $suburb->p24_id;
        }
        return null;
    }

    private function resolvePropertyTypeId(?string $type): ?int
    {
        if (empty($type)) return null;
        return match (strtolower(trim($type))) {
            'house'                         => 4,
            'apartment', 'flat'             => 9,
            'townhouse'                     => 6,
            'duplex'                        => 30,
            'simplex'                       => 31,
            'cluster'                       => 29,
            'freestanding', 'free standing' => 4,
            'penthouse'                     => 32,
            'garden cottage', 'cottage'     => 33,
            'farm'                          => 7,
            'smallholding', 'small holding' => 5,
            'vacant land', 'land'           => 3,
            'commercial'                    => 22,
            'industrial'                    => 8,
            'office', 'retail'              => 22,
            default                         => null,
        };
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
