<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\MicPropertyReconciliationService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC ↔ property-pillar reconciliation (Johan 2026-08-14).
 *
 * An address unlocked in MIC must resolve to the SAME canonical property via the TrackedProperty
 * identity spine (the one deeds promote uses) — reconcile, never duplicate. A genuinely new address
 * yields no existing property (caller creates one clean record). And the MIC funnel's existence
 * check (findExistingMatch → promoted TrackedProperty) sees an address-unlock/deeds property.
 *
 * RefreshDatabase — runs on Johan's dev-check; the resolve-to-same behaviour is additionally
 * verified against the live QA1 runtime.
 */
final class MicPropertyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Recon ' . Str::random(5), 'slug' => 'recon-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedProperty(string $address, string $suburb): Property
    {
        return Property::create([
            'agency_id' => $this->agencyId,
            'external_id' => (string) Str::uuid(),
            'title' => $address, 'address' => $address, 'suburb' => $suburb,
            'street_number' => '12', 'street_name' => 'Marine Drive',
            'beds' => 0, 'baths' => 0, 'garages' => 0, 'price' => 0,
            'property_type' => 'house', 'status' => 'draft', 'listing_type' => 'sale',
        ]);
    }

    private function matcher(): TrackedPropertyMatchOrCreateService
    {
        return app(TrackedPropertyMatchOrCreateService::class);
    }

    private function recon(): MicPropertyReconciliationService
    {
        return app(MicPropertyReconciliationService::class);
    }

    /** The canonical property (deeds-promoted) is resolved even under a DIFFERENT address string. */
    public function test_existing_property_resolves_to_same_canonical_no_duplicate(): void
    {
        // A deeds-style canonical property + its promoted TrackedProperty (built via the real spine).
        $prop = $this->seedProperty('12 Marine Drive, Margate', 'Margate');
        $facts = ['address' => '12 Marine Drive, Margate', 'street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Margate'];
        $tp = $this->matcher()->matchOrCreate($this->agencyId, $facts, ['type' => 'deeds_capture', 'ref' => 'deed-1']);
        $tp->update(['promoted_to_property_id' => $prop->id, 'promoted_at' => now(), 'status' => TrackedProperty::STATUS_PROMOTED]);

        // MIC unlocks the SAME asset via a differently-formatted address string.
        $micFacts = ['address' => '12 Marine Dr, Margate', 'street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Margate'];
        $resolved = $this->recon()->resolveExistingProperty($this->agencyId, $micFacts);

        $this->assertNotNull($resolved, 'MIC address resolves to an existing canonical property');
        $this->assertSame($prop->id, $resolved->id, 'resolves to the SAME property — no duplicate');
        // And the matcher did not fork the TrackedProperty identity.
        $this->assertSame(1, TrackedProperty::where('agency_id', $this->agencyId)->count());
    }

    /** A genuinely new address yields no existing property (caller then creates one clean record). */
    public function test_new_address_yields_no_existing_property(): void
    {
        $this->seedProperty('12 Marine Drive, Margate', 'Margate');
        $micFacts = ['address' => '88 Outlook Road, Uvongo', 'street_number' => '88', 'street_name' => 'Outlook Road', 'suburb' => 'Uvongo'];
        $this->assertNull($this->recon()->resolveExistingProperty($this->agencyId, $micFacts));
    }

    /** An unpromoted TrackedProperty (address known but no property yet) resolves to null, not a stub. */
    public function test_unpromoted_tracked_property_resolves_to_null(): void
    {
        $facts = ['address' => '5 Reservoir Road, Ramsgate', 'street_number' => '5', 'street_name' => 'Reservoir Road', 'suburb' => 'Ramsgate'];
        $this->matcher()->matchOrCreate($this->agencyId, $facts, ['type' => 'manual', 'ref' => 'x']);   // TP exists, NOT promoted
        $this->assertNull($this->recon()->resolveExistingProperty($this->agencyId, $facts));
    }

    /**
     * DUPLICATE-TP reality (QA1): several TrackedProperty rows for one asset, only one promoted.
     * findExistingMatch may return an UNPROMOTED twin — reconciliation must still resolve to the
     * canonical property via the promoted sibling (erf+suburb identity).
     */
    public function test_resolves_via_promoted_sibling_when_matched_twin_is_unpromoted(): void
    {
        $prop = $this->seedProperty('12 Marine Drive, Margate', 'Margate');
        $facts = ['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Margate', 'erf_number' => '659'];
        // Promoted canonical TP (built via the real spine).
        $promoted = $this->matcher()->matchOrCreate($this->agencyId, $facts, ['type' => 'deeds_capture', 'ref' => 'deed-1']);
        $promoted->update(['promoted_to_property_id' => $prop->id, 'promoted_at' => now(), 'status' => TrackedProperty::STATUS_PROMOTED]);

        // An UNPROMOTED duplicate twin of the same asset (erf+suburb), inserted with a LOWER id so a
        // naive first-match returns it.
        DB::table('tracked_properties')->insert([
            'agency_id' => $this->agencyId, 'erf_number' => '659',
            'street_number' => '12', 'street_name' => 'Marine Drive',
            'suburb' => 'Margate', 'suburb_normalised' => $promoted->suburb_normalised,
            'status' => TrackedProperty::STATUS_ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resolved = $this->recon()->resolveExistingProperty($this->agencyId, $facts);
        $this->assertNotNull($resolved, 'resolves through the duplicate twin to the promoted sibling');
        $this->assertSame($prop->id, $resolved->id);
    }

    /** The MIC funnel existence-check backing (findExistingMatch → promoted TP) sees the property. */
    public function test_existence_check_sees_address_unlock_property(): void
    {
        $prop = $this->seedProperty('12 Marine Drive, Margate', 'Margate');
        $facts = ['address' => '12 Marine Drive, Margate', 'street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Margate'];
        $tp = $this->matcher()->matchOrCreate($this->agencyId, $facts, ['type' => 'manual_prospect_entry', 'ref' => 'p1']);
        $tp->update(['promoted_to_property_id' => $prop->id, 'promoted_at' => now(), 'status' => TrackedProperty::STATUS_PROMOTED]);

        $found = $this->matcher()->findExistingMatch($this->agencyId, $facts);
        $this->assertNotNull($found, 'findExistingMatch (the existence-check spine) sees the address-unlock property');
        $this->assertSame($prop->id, (int) $found->promoted_to_property_id);
    }
}
