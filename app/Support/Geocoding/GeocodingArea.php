<?php

declare(strict_types=1);

namespace App\Support\Geocoding;

use Illuminate\Support\Facades\DB;

/**
 * The area a geocoder result may fall in, per agency. Spec: .ai/specs/geocoding-spec.md "Accepted area".
 *
 * The country comes from agencies.geocode_country (null = config geo.geocoding.default_country, which is
 * ZA); the shapes come from config('geo.geocoding.countries'). Nothing here names a province or an agency.
 *
 * Rule: a result Google itself places in the accepted country is always kept — a province is only ever a
 * hint to bias the search (never a reason to reject). When Google returned no country at all, the
 * rectangles decide. A result outside the country is rejected.
 */
final class GeocodingArea
{
    private function __construct(
        public readonly string $country,
        private readonly array $config,
    ) {}

    public static function forAgency(?int $agencyId): self
    {
        $default = strtoupper((string) config('geo.geocoding.default_country', 'ZA'));
        $code    = $default;

        if ($agencyId) {
            try {
                $set = DB::table('agencies')->where('id', $agencyId)->value('geocode_country');
                if (is_string($set) && $set !== '') {
                    $code = strtoupper($set);
                }
            } catch (\Throwable) {
                // Never let a settings lookup stop a geocode: fall back to the default.
            }
        }

        return self::forCountry($code);
    }

    public static function forCountry(string $code): self
    {
        $countries = (array) config('geo.geocoding.countries', []);
        $default   = strtoupper((string) config('geo.geocoding.default_country', 'ZA'));
        $code      = strtoupper($code);
        if (!isset($countries[$code])) {
            $code = $default;
        }

        return new self($code, (array) ($countries[$code] ?? []));
    }

    /** Value for Google's components= parameter. */
    public function googleComponent(): string
    {
        return 'country:' . ($this->config['google_component'] ?? $this->country);
    }

    /** Value for Google's bounds= hint: the property's province when known, else the whole country. */
    public function boundsHint(?string $province): string
    {
        $key = $province !== null ? preg_replace('/[^a-z]/', '', mb_strtolower($province)) : '';
        $box = ($key !== '' ? ($this->config['provinces'][$key] ?? null) : null) ?? $this->envelope();

        return sprintf('%s,%s|%s,%s', $box[0], $box[1], $box[2], $box[3]);
    }

    /**
     * @param string|null $googleCountry short country code from the result's address_components, if any
     */
    public function accepts(float $lat, float $lng, ?string $googleCountry): bool
    {
        if ($googleCountry !== null && $googleCountry !== '') {
            return strtoupper($googleCountry) === strtoupper((string) ($this->config['google_component'] ?? $this->country));
        }

        foreach ((array) ($this->config['areas'] ?? []) as [$south, $west, $north, $east]) {
            if ($lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    private function envelope(): array
    {
        $areas = (array) ($this->config['areas'] ?? []);
        if ($areas === []) {
            return [-90.0, -180.0, 90.0, 180.0];
        }

        return [
            min(array_column($areas, 0)), min(array_column($areas, 1)),
            max(array_column($areas, 2)), max(array_column($areas, 3)),
        ];
    }
}
