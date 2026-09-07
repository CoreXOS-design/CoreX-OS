<?php

namespace App\Http\Concerns;

use App\Models\P24Suburb;
use App\Models\Property;
use App\Services\P24\P24LocationResolver;
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
     * @param Property|null $property The property being edited, if any — used
     *        only as a fallback source of address/coordinate context for
     *        re-deriving a stale P24 link (see below). Absent on create.
     *
     * @throws ValidationException when chain is invalid or suburb is missing.
     */
    protected function applyP24Location(array $data, bool $required = true, ?Property $property = null): array
    {
        $suburbId   = $data['p24_suburb_id']   ?? null;
        $cityId     = $data['p24_city_id']     ?? null;
        $provinceId = $data['p24_province_id'] ?? null;

        // Properties #21014/#15774/#15777 investigation (2026-09-07) — the P24
        // location picker pre-fills its hidden id inputs from whatever the
        // record already has, so a save that never touches the picker keeps
        // silently re-submitting (and re-confirming) a WRONG link forever —
        // "if an address gets edited and saved we need to update the record
        // accordingly" (Johan). Whenever the submitted/current suburb TEXT
        // doesn't match the name the submitted id actually points to, OR the
        // linked suburb's own province disagrees with the submitted/known
        // province — re-derive it the same province-constrained,
        // coordinate-aware way DeedsCaptureController::promote() now does,
        // reusing the exact same P24Suburb::lookup(). The province check
        // matters on its own: two colliding suburbs share the SAME name by
        // definition (that's the whole bug), so a name-only staleness check
        // would never catch a same-named suburb wrongly linked to the other
        // province's row — exactly property #21014's stored shape
        // (suburb="MELVILLE" text matched its own wrong id, only the
        // province disagreed). An id that already agrees on both name AND
        // province is left alone: either already correct, or a real
        // explicit pick just made through the picker — never a guess.
        $suburbName = $data['suburb'] ?? $property?->suburb;
        if ($suburbName) {
            $provinceNameForCheck = $data['province'] ?? $property?->province;
            $linked = $suburbId ? P24Suburb::find($suburbId) : null;
            $nameMismatch = !$linked || strcasecmp((string) $linked->name, (string) $suburbName) !== 0;
            $provinceMismatch = $linked && $provinceNameForCheck && $linked->city?->province
                && strcasecmp(
                    str_replace('-', ' ', $linked->city->province->name),
                    str_replace('-', ' ', $provinceNameForCheck)
                ) !== 0;
            $idLooksStale = $nameMismatch || $provinceMismatch;

            if ($idLooksStale) {
                $lat = $data['latitude'] ?? $property?->latitude;
                $lng = $data['longitude'] ?? $property?->longitude;
                $provinceName = $data['province'] ?? $property?->province;

                $rederived = P24Suburb::lookup(
                    $suburbName,
                    $provinceName,
                    $lat !== null ? (float) $lat : null,
                    $lng !== null ? (float) $lng : null,
                );

                if ($rederived && $rederived->p24_verified_at) {
                    $suburbId = $rederived->id;
                    // The re-derivation is authoritative now — a stale
                    // submitted city/province id would otherwise fail the
                    // consistency checks below against the newly resolved
                    // suburb's REAL parents.
                    $cityId = null;
                    $provinceId = null;
                }
            }
        }

        if (!$suburbId) {
            if ($required) {
                throw ValidationException::withMessages([
                    'p24_suburb_id' => 'Please pick a Property24-recognised suburb.',
                ]);
            }
            $data['p24_suburb_mismatch'] = true;
            return $data;
        }

        $resolved = P24LocationResolver::resolve((int) $suburbId);
        if (!$resolved) {
            throw ValidationException::withMessages([
                'p24_suburb_id' => 'Selected suburb is no longer recognised by Property24.',
            ]);
        }
        $suburb = $resolved['suburb'];
        $city = $resolved['city'];
        $province = $resolved['province'];

        // Existence guard: the suburb→city→province chain being internally
        // consistent is NOT enough — the suburb's p24_id must actually be one
        // P24 returns in its live list, or P24 rejects the listing ("SuburbId is
        // invalid"). A row is only verified by the location sync / reconcile,
        // which stamps `p24_verified_at`. Phantom rows (never returned by P24)
        // stay NULL and are blocked here. See AT-104 audit.
        if (!$suburb->p24_verified_at) {
            throw ValidationException::withMessages([
                'p24_suburb_id' => 'Selected suburb is not confirmed on Property24. Pick a Property24-recognised suburb.',
            ]);
        }

        if ($cityId && (int) $cityId !== (int) $city->id) {
            throw ValidationException::withMessages([
                'p24_city_id' => 'Suburb does not belong to the selected city.',
            ]);
        }

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
        // `town` is a separate column other screens read (e.g. the property
        // Intelligence tab's market snapshot prefers it over `city`) but this
        // trait never wrote it — picking a suburb updated suburb/city/province
        // and all three P24 ids while town silently kept whatever value it was
        // given at creation, so a corrected address could still display its old
        // area. town and city both mean "the real town an agent/buyer
        // recognises" for a P24-linked property (see
        // DeedsCaptureController::promote()'s own town-resolution comment), so
        // town now travels with city from the same $city row every time a
        // suburb is picked, create or edit, web or mobile.
        $data['town']                = $city->name;
        if ($province) {
            $data['province'] = $province->name;
        }
        $data['p24_suburb_mismatch'] = false;

        return $data;
    }
}
