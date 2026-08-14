<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\DeedsCaptureLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC ↔ Deeds ↔ Contact loop — Part A (Johan 2026-08-14).
 *
 * The MIC compose screen must surface the deed the agent scraped: a deeds capture lands as a
 * `tracked_properties` row (capture_kind='deeds_capture') whose registered owner(s) live on
 * `tracked_property_owners` (name + SA-ID), each already a Contact deduped on id_number.
 * DeedsCaptureLinkService resolves those owners for the property being pitched — via the
 * listing's own TrackedProperty when the deed enriched it, or via a deeds sibling sharing the
 * asset identity (the duplicate-TP reality). READ-ONLY across the deeds/contact domains.
 */
final class DeedsCaptureLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Deeds ' . Str::random(5), 'slug' => 'deeds-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): DeedsCaptureLinkService
    {
        return app(DeedsCaptureLinkService::class);
    }

    private function trackedProperty(array $attrs = []): TrackedProperty
    {
        return TrackedProperty::create(array_merge([
            'agency_id'     => $this->agencyId,
            'street_number' => '516',
            'street_name'   => 'Bream Crescent',
            'suburb'        => 'Ramsgate',
            'erf_number'    => '659',
            'address'       => '516 Bream Crescent, Ramsgate',
        ], $attrs));
    }

    private function ownerContact(string $first, string $last, string $id): Contact
    {
        return Contact::create([
            'agency_id'  => $this->agencyId,
            'first_name' => $first,
            'last_name'  => $last,
            'phone'      => '',
            'id_number'  => $id,
            'id_type'    => 'sa_id',
        ]);
    }

    private function addOwner(TrackedProperty $tp, string $name, string $id, ?int $contactId, bool $primary = true): void
    {
        DB::table('tracked_property_owners')->insert([
            'tracked_property_id' => $tp->id,
            'contact_id'          => $contactId,
            'name'                => $name,
            'id_number'           => $id,
            'id_type'             => 'sa_id',
            'is_primary'          => $primary,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function listing(?int $trackedPropertyId): object
    {
        return (object) [
            'id'                  => 1505,
            'tracked_property_id' => $trackedPropertyId,
            'address'             => '516 Bream Crescent, Ramsgate',
            'suburb'              => 'Ramsgate',
        ];
    }

    /** The deed enriched the SAME TrackedProperty the listing is linked to — owner surfaces directly. */
    public function test_resolves_owner_on_the_listings_own_tracked_property(): void
    {
        $tp = $this->trackedProperty(['capture_kind' => 'deeds_capture']);
        $contact = $this->ownerContact('Johan', 'Human', '7610025020081');
        $this->addOwner($tp, 'Johan Human', '7610025020081', $contact->id);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($tp->id));

        $this->assertSame($tp->id, $result['tracked_property_id']);
        $this->assertCount(1, $result['owners']);
        $this->assertSame('Johan', $result['owners'][0]['first_name']);
        $this->assertSame('Human', $result['owners'][0]['last_name']);
        $this->assertSame('7610025020081', $result['owners'][0]['id_number']);
        $this->assertTrue($result['owners'][0]['is_primary']);
        $this->assertSame($contact->id, $result['owners'][0]['contact_id']);
    }

    /**
     * Duplicate-TP reality: the listing points at a portal TP with NO owners, but a SEPARATE
     * deeds-capture TP shares the asset identity (erf + suburb) and holds the owner — resolve it.
     */
    public function test_resolves_owner_via_deeds_sibling_sharing_identity(): void
    {
        $portalTp = $this->trackedProperty(); // no owners, plain portal capture
        $deedsTp  = $this->trackedProperty(['capture_kind' => 'deeds_capture']);
        $contact  = $this->ownerContact('Elize', 'Reichel', '8005050050082');
        $this->addOwner($deedsTp, 'Elize Reichel', '8005050050082', $contact->id);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($portalTp->id));

        $this->assertSame($deedsTp->id, $result['tracked_property_id']);
        $this->assertSame('8005050050082', $result['owners'][0]['id_number']);
    }

    /** No deed capture for this asset → empty owners (the screen simply shows no deed panel). */
    public function test_no_deed_returns_empty(): void
    {
        $portalTp = $this->trackedProperty(); // no owners, no deeds sibling
        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($portalTp->id));

        $this->assertSame([], $result['owners']);
        $this->assertNull($result['tracked_property_id']);
    }

    /** No linked contact on the owner row → first/last are split from the deed's name string. */
    public function test_name_split_fallback_when_owner_has_no_contact(): void
    {
        $tp = $this->trackedProperty(['capture_kind' => 'deeds_capture']);
        $this->addOwner($tp, 'Andre van der Merwe', '7001015000088', null);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($tp->id));

        $this->assertSame('Andre van der', $result['owners'][0]['first_name']);
        $this->assertSame('Merwe', $result['owners'][0]['last_name']);
        $this->assertNull($result['owners'][0]['contact_id']);
    }

    /** Multiple owners come back primary-first. */
    public function test_multiple_owners_primary_first(): void
    {
        $tp = $this->trackedProperty(['capture_kind' => 'deeds_capture']);
        $this->addOwner($tp, 'Second Owner', '9001015000087', null, primary: false);
        $this->addOwner($tp, 'Primary Owner', '6505055000086', null, primary: true);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($tp->id));

        $this->assertCount(2, $result['owners']);
        $this->assertTrue($result['owners'][0]['is_primary']);
        $this->assertSame('6505055000086', $result['owners'][0]['id_number']);
    }

    /**
     * Johan's real case: the deeds-office address diverges from the portal address (different
     * street name + suburb, no shared erf, no usable GPS). No CONFIRMED match — but the deed is
     * surfaced as a CANDIDATE (same street number + shared suburb token) for the agent to verify.
     */
    public function test_surfaces_candidate_when_deeds_address_diverges(): void
    {
        // Portal/listing TP — "516 Bream Crescent, Ramsgate", no erf, no owners.
        $portalTp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '516', 'street_name' => 'Bream Crescent',
            'suburb' => 'Ramsgate', 'address' => '516 Bream Crescent, Ramsgate',
        ]);
        // Deeds TP — same number, deeds-office street/suburb, erf, one owner.
        $deedsTp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '516', 'street_name' => 'Bidstone',
            'complex_name' => 'THE NEST', 'scheme_name' => 'THE NEST', 'erf_number' => '516',
            'suburb' => 'Ramsgate Beach', 'capture_kind' => 'deeds_capture',
        ]);
        $contact = $this->ownerContact('Robyn Ann', 'Bailey', '6904050051082');
        $this->addOwner($deedsTp, 'Robyn Ann Bailey', '6904050051082', $contact->id);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($portalTp->id));

        // No CONFIRMED match (no shared erf/street/GPS) …
        $this->assertSame([], $result['owners']);
        $this->assertNull($result['tracked_property_id']);
        // … but the deed IS surfaced as a candidate for verification.
        $this->assertCount(1, $result['candidates']);
        $this->assertSame($deedsTp->id, $result['candidates'][0]['tracked_property_id']);
        $this->assertSame('6904050051082', $result['candidates'][0]['owners'][0]['id_number']);
        $this->assertSame('Robyn Ann', $result['candidates'][0]['owners'][0]['first_name']);
    }

    /** A confirmed (exact-identity) match is returned as owners and does NOT also list candidates. */
    public function test_confirmed_match_suppresses_candidates(): void
    {
        $tp = $this->trackedProperty(['capture_kind' => 'deeds_capture']);
        $this->addOwner($tp, 'Johan Human', '7610025020081', null);
        // A same-number/suburb decoy that would be a candidate if we weren't already confirmed.
        $decoy = $this->trackedProperty(['street_name' => 'Other Road', 'capture_kind' => 'deeds_capture']);
        $this->addOwner($decoy, 'Decoy Person', '8001015000085', null);

        $result = $this->service()->ownersForListing($this->agencyId, $this->listing($tp->id));

        $this->assertSame($tp->id, $result['tracked_property_id']);
        $this->assertCount(1, $result['owners']);
        $this->assertSame([], $result['candidates']);
    }

    /** A remembered manual link (linked_deed_tracked_property_id) is a confirmed match, no candidates. */
    public function test_remembered_manual_link_is_confirmed(): void
    {
        // Portal TP the listing points at (no owners) + a divergent deed the agent manually linked.
        $portalTp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '516', 'street_name' => 'Bream Crescent',
            'suburb' => 'Ramsgate', 'address' => '516 Bream Crescent, Ramsgate',
        ]);
        $deedsTp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '516', 'street_name' => 'Bidstone',
            'suburb' => 'Ramsgate Beach', 'erf_number' => '516', 'capture_kind' => 'deeds_capture',
        ]);
        $this->addOwner($deedsTp, 'Robyn Ann Bailey', '6904050051082', null);

        $listing = $this->listing($portalTp->id);
        $listing->linked_deed_tracked_property_id = $deedsTp->id;   // remembered choice

        $result = $this->service()->ownersForListing($this->agencyId, $listing);

        $this->assertSame($deedsTp->id, $result['tracked_property_id']);
        $this->assertSame('6904050051082', $result['owners'][0]['id_number']);
        $this->assertSame([], $result['candidates']);
    }

    /** The manual-modal list surfaces owner-bearing deeds with clean, searchable rows. */
    public function test_available_deeds_lists_owner_bearing_deeds(): void
    {
        $deed = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '516', 'street_name' => 'Bidstone',
            'scheme_name' => 'THE NEST', 'suburb' => 'Ramsgate Beach', 'erf_number' => '516',
            'capture_kind' => 'deeds_capture', 'last_known_sold_price' => '705000.00',
        ]);
        $this->addOwner($deed, 'Robyn Ann Bailey', '6904050051082', null);
        // An owner-less TP must NOT appear in the list.
        TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '9', 'street_name' => 'Empty Road', 'suburb' => 'Ramsgate',
        ]);

        $deeds = $this->service()->availableDeeds($this->agencyId);

        $this->assertCount(1, $deeds);
        $row = $deeds[0];
        $this->assertSame($deed->id, $row['tracked_property_id']);
        $this->assertStringContainsString('THE NEST', $row['address']);
        $this->assertSame('516', $row['erf']);
        $this->assertStringContainsString('Robyn Ann Bailey', $row['owner_names']);
        // The search blob is lower-cased and includes owner + erf + address for client filtering.
        $this->assertStringContainsString('robyn', $row['search']);
        $this->assertStringContainsString('516', $row['search']);
    }

    /** Agency isolation — a deed owner in another agency is never surfaced. */
    public function test_does_not_cross_agency(): void
    {
        $otherAgency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(5), 'slug' => 'other-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tp = TrackedProperty::create([
            'agency_id' => $otherAgency, 'street_number' => '516', 'street_name' => 'Bream Crescent',
            'suburb' => 'Ramsgate', 'erf_number' => '659', 'capture_kind' => 'deeds_capture',
            'address' => '516 Bream Crescent, Ramsgate',
        ]);
        $this->addOwner($tp, 'Someone Else', '7610025020081', null);

        // A listing in THIS agency with no local TP must not reach the other agency's deed.
        $result = $this->service()->ownersForListing($this->agencyId, $this->listing(null));

        $this->assertSame([], $result['owners']);
    }
}
