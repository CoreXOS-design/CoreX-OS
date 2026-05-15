<?php

namespace App\Http\Concerns;

use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use Illuminate\Validation\ValidationException;

/**
 * Shared chain-verifier for the Property24 Province → City → Suburb cascading
 * selects. Used by both PropertyController (full create/edit form) and
 * PropertyWizardController (quick-setup wizard) so both code paths enforce
 * the same rule: a property MUST land on a P24-recognised suburb, and the
 * suburb's parent city/province in the request must match the suburb's
 * actual P24 parents.
 */
trait AppliesP24Location
{
    /**
     * Verify the chain (suburb → city → province) and overwrite denormalised
     * text columns (`suburb`, `city`, `province`) with canonical P24 names.
     * Returns the modified $data array.
     *
     * @throws ValidationException when chain is invalid or suburb is missing.
     */
    protected function applyP24Location(array $data, bool $required = true): array
    {
        $suburbId   = $data['p24_suburb_id']   ?? null;
        $cityId     = $data['p24_city_id']     ?? null;
        $provinceId = $data['p24_province_id'] ?? null;

        if (!$suburbId) {
            if ($required) {
                throw ValidationException::withMessages([
                    'p24_suburb_id' => 'Please pick a Property24-recognised suburb.',
                ]);
            }
            $data['p24_suburb_mismatch'] = true;
            return $data;
        }

        $suburb = P24Suburb::find($suburbId);
        if (!$suburb || !$suburb->p24_city_id) {
            throw ValidationException::withMessages([
                'p24_suburb_id' => 'Selected suburb is no longer recognised by Property24.',
            ]);
        }

        $city = P24City::find($suburb->p24_city_id);
        if (!$city) {
            throw ValidationException::withMessages([
                'p24_suburb_id' => 'Selected suburb has no parent city on Property24.',
            ]);
        }

        if ($cityId && (int) $cityId !== (int) $city->id) {
            throw ValidationException::withMessages([
                'p24_city_id' => 'Suburb does not belong to the selected city.',
            ]);
        }

        $province = P24Province::find($city->p24_province_id);
        if ($provinceId && $province && (int) $provinceId !== (int) $province->id) {
            throw ValidationException::withMessages([
                'p24_province_id' => 'City does not belong to the selected province.',
            ]);
        }

        $data['p24_suburb_id']       = $suburb->id;
        $data['p24_city_id']         = $city->id;
        $data['p24_province_id']     = $province?->id;
        $data['suburb']              = $suburb->name;
        $data['city']                = $city->name;
        if ($province) {
            $data['province'] = $province->name;
        }
        $data['p24_suburb_mismatch'] = false;

        return $data;
    }
}
