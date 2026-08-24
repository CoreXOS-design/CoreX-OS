<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\User;
use App\Services\Prospecting\OwnerContactResolver;
use App\Services\Prospecting\OwnershipHistoryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OwnerContactResolver — persisting parsed ownership-history rows as real
 * Contact + TrackedPropertyOwner rows, and linking CURRENT owners onto a
 * promoted Property. .ai/specs/deeds-capture.md §7.8, §7.11.
 *
 * NOT exercised via HTTP (Api\DeedsCaptureController / CoreX\DeedsCaptureController
 * are currently held by another lane, spec §7.15 Stage 3) — calls the service
 * directly against real DB fixtures, same real SEESKULP Section 4 data as the
 * parser's own test.
 */
final class OwnerContactResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
    }

    private function resolver(): OwnerContactResolver
    {
        return app(OwnerContactResolver::class);
    }

    private function trackedProperty(): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id'     => $this->agencyId,
            'scheme_name'   => 'SEESKULP',
            'scheme_number' => '257/1987',
            'section_number' => '4',
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'capture_kind'  => 'deeds_capture',
        ]);
    }

    private function property(): Property
    {
        return Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $this->user->id, 'branch_id' => $this->agencyId,
            'external_id' => (string) Str::uuid(), 'title' => 'SEESKULP Section 4',
            'suburb' => 'Uvongo Beach', 'property_type' => 'apartment', 'status' => 'active', 'price' => 0,
        ]);
    }

    /** The real panel data, IDs masked exactly as cmainfo rendered them to Johan. */
    private function seeskulpRawMasked(): array
    {
        return [
            'owner_names' => 'WILKEN JOHAN 82.7397% ; WILKEN HESTER JOHANNA CATHARINA ; WILKEN HESTER JOHANNA CATHARINA ; '
                . 'WILKEN JOHAN 15.3424% ; STEVE DU TOIT TRUST-TRUSTEES 1.9178% ; WILKEN JOHAN 1.9178% ; '
                . 'WILKEN HESTER JOHANNA CATHARINA ; FISHER RONALD GEORGE 98.0822% ; FISHER LUCILLE 0.9589% ; '
                . 'SEE-SKULP TRUST-TRUSTEES',
            'owner_ids' => '581111******* ; 620117******* ; 620117******* ; 581111******* ; IT 1203/91 ; '
                . '581111******* ; 620117******* ; 290527******* ; 340427******* ;',
            'title_deeds' => 'ST39075/2003 82.7397% ; ST39075/2003 ; ST39074/2003 ; ST39074/2003 15.3424% ; '
                . 'ST39073/2003 1.9178% ; ST6815/1993 1.9178% ; ST6815/1993 ; ST4830/1993 98.0822% ; '
                . 'ST4830/1993 0.9589% ; ST257-4',
        ];
    }

    /** Same data, IDs UNMASKED (as they'll arrive once cc6's reveal fix handles all ten positions). */
    private function seeskulpRawUnmasked(): array
    {
        $raw = $this->seeskulpRawMasked();
        $raw['owner_ids'] = '5811115009087 ; 6201175009084 ; 6201175009084 ; 5811115009087 ; IT 1203/91 ; '
            . '5811115009087 ; 6201175009084 ; 2905275009083 ; 3404275009082 ;';

        return $raw;
    }

    public function test_masked_ids_still_capture_and_classify_correctly_but_do_not_dedupe(): void
    {
        $tp = $this->trackedProperty();
        $result = app(OwnershipHistoryParser::class)->parse($this->seeskulpRawMasked(), '2003-01-15', '2003-07-11');
        $this->assertSame('warning', $result->status);

        $owners = $this->resolver()->persist($tp, $result->rows, $this->agencyId, $this->user);
        $this->assertCount(10, $owners);

        // Every masked position still gets a Contact — just not deduped, since there's no ID to dedupe on.
        $johanRows = TrackedPropertyOwner::where('tracked_property_id', $tp->id)->where('name', 'WILKEN JOHAN')->get();
        $this->assertCount(3, $johanRows);
        $this->assertCount(
            3,
            Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('first_name', 'WILKEN')->get(),
            'without a real ID, each masked position inserts its own contact — expected, not a bug (§7.8)'
        );

        // Entity contact still resolves correctly even though its ID was never masked.
        $trustOwner = TrackedPropertyOwner::where('tracked_property_id', $tp->id)
            ->where('deed_reference', 'ST39073/2003')->firstOrFail();
        $trustContact = Contact::withoutGlobalScopes()->find($trustOwner->contact_id);
        $this->assertSame(Contact::TYPE_ENTITY, $trustContact->contact_kind);
        $this->assertSame('IT 1203/91', $trustContact->entity_reg_no);
        $this->assertNull($trustContact->id_number, 'a trust registration number must NEVER land in id_number');

        // Every masked-ID position stored id_number = null, never a partial value.
        $this->assertSame(0, TrackedPropertyOwner::where('tracked_property_id', $tp->id)
            ->where('id_number', 'like', '%*%')->count());
    }

    public function test_unmasked_ids_dedupe_and_link_current_owners_only(): void
    {
        $tp = $this->trackedProperty();
        $result = app(OwnershipHistoryParser::class)->parse($this->seeskulpRawUnmasked(), '2003-01-15', '2003-07-11');
        $this->assertSame('warning', $result->status); // still the one unparseable SEE-SKULP TRUST-TRUSTEES row

        $this->resolver()->persist($tp, $result->rows, $this->agencyId, $this->user);

        // Johan Wilken appears on 3 positions — must dedupe to ONE contact.
        $johanContacts = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('id_number', '5811115009087')->get();
        $this->assertCount(1, $johanContacts, 'Johan Wilken must dedupe to one contact across all 3 positions');
        $johan = $johanContacts->first();
        $this->assertCount(3, TrackedPropertyOwner::where('tracked_property_id', $tp->id)
            ->where('contact_id', $johan->id)->get(), 'but still 3 distinct owner rows — one per deed position');

        $hester = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('id_number', '6201175009084')->firstOrFail();
        $fisherR = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('id_number', '2905275009083')->firstOrFail();
        $fisherL = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('id_number', '3404275009082')->firstOrFail();
        $trust = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('entity_reg_no', 'IT 1203/91')->firstOrFail();

        $property = $this->property();
        $linked = $this->resolver()->linkCurrentOwners($tp, $property);

        // Current owners: Johan, Hester, Steve du Toit Trust — 3 distinct contacts linked.
        $this->assertSame(3, $linked);
        $this->assertDatabaseHas('contact_property', ['contact_id' => $johan->id, 'property_id' => $property->id, 'role' => 'owner']);
        $this->assertDatabaseHas('contact_property', ['contact_id' => $hester->id, 'property_id' => $property->id, 'role' => 'owner']);
        $this->assertDatabaseHas('contact_property', ['contact_id' => $trust->id, 'property_id' => $property->id, 'role' => 'owner']);

        // Past owners (the Fishers, 1993 sellers) must NEVER be linked as owners of this property —
        // the exact structural guarantee the spec is built around.
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $fisherR->id, 'property_id' => $property->id]);
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $fisherL->id, 'property_id' => $property->id]);

        // Regression guard — the unclassified row (SEE-SKULP TRUST-TRUSTEES, its deed didn't
        // parse to a year) must NEVER silently default to 'current' and get linked either.
        $seeSkulp = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('entity_name', 'SEE-SKULP TRUST-TRUSTEES')->firstOrFail();
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seeSkulp->id, 'property_id' => $property->id]);

        // Fisher contacts DO exist (captured as past-owner history) — just never linked.
        $this->assertNotNull($fisherR);
        $this->assertNotNull($fisherL);
        $pastRows = TrackedPropertyOwner::where('tracked_property_id', $tp->id)
            ->where('ownership_status', TrackedPropertyOwner::OWNERSHIP_PAST)->get();
        $this->assertCount(4, $pastRows, 'Johan+Hester (1993 deed) + Ronald + Lucille Fisher');
    }
}
