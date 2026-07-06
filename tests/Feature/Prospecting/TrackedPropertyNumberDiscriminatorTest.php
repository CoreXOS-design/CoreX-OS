<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Contact\ContactAddressPropertyGuard;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Street/unit number as a HARD discriminator in the Universal Match-or-Create
 * engine (CLAUDE.md #10). Root cause of the live false positive: strategy 5
 * (token overlap) dropped <3-char tokens — so "1"/"2" never reached the token
 * bag — and matched "1 The Oval" to "2 The Oval" on the shared {the, oval}
 * tokens alone. The number is now a veto across the address-similarity
 * strategies. Blast radius = every consumer of the matcher (portal capture,
 * P24 dedup, CMA/MIC parsers, map, seller-outreach, contact guard).
 *
 * Rules under test:
 *   1. street number differs  ⇒ NEVER match (different properties)
 *   2. unit number differs    ⇒ NEVER match (sectional title)
 *   3. same number, messy formatting ⇒ STILL match (not exact-string)
 *   4. input space: missing numbers, name-embedded numbers
 */
final class TrackedPropertyNumberDiscriminatorTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private TrackedPropertyMatchOrCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->service = new TrackedPropertyMatchOrCreateService();
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'street_name' => 'The Oval',
            'suburb'      => 'Umhlali Golf Estate',
        ], $overrides);
    }

    /** THE reported live pair — must NOT match. */
    public function test_reported_pair_one_vs_two_the_oval_does_not_match(): void
    {
        $two = $this->service->matchOrCreate(
            $this->agency->id,
            $this->facts(['street_number' => '2']),
            ['type' => 'p24', 'ref' => 'REF-2-OVAL']
        );

        // Capture "1 The Oval" (the agent's flow) — must resolve to no match…
        $match = $this->service->findExistingMatch($this->agency->id, $this->facts(['street_number' => '1']));
        $this->assertNull($match, '"1 The Oval" must not match "2 The Oval"');

        // …and matchOrCreate must MINT a new tracked property, not append to #2.
        $one = $this->service->matchOrCreate(
            $this->agency->id,
            $this->facts(['street_number' => '1']),
            ['type' => 'manual', 'ref' => 'REF-1-OVAL']
        );
        $this->assertNotSame($two->id, $one->id);
        $this->assertSame(2, TrackedProperty::queryWithoutAgencyScope()->count());
    }

    /** The capture-warning guard (the actual reported surface) must not warn. */
    public function test_contact_guard_does_not_flag_held_for_different_number(): void
    {
        $this->service->matchOrCreate(
            $this->agency->id,
            $this->facts(['street_number' => '2']),
            ['type' => 'p24', 'ref' => 'REF-2-OVAL']
        );

        $guard = app(ContactAddressPropertyGuard::class);
        $held = $guard->findHeldFromComponents($this->agency->id, [
            'unit_number'   => '1',
            'complex_name'  => 'Umhlali Country Club',
            'street_number' => '1',
            'street_name'   => 'The Oval',
            'suburb'        => 'Umhlali Golf Estate',
            'city'          => 'Ballito',
            'province'      => 'KwaZulu Natal',
        ]);

        $this->assertNull($held, 'Capturing 1 The Oval must not flag 2 The Oval as held');
    }

    /** Rule 3 — same number, messy formatting still resolves to the same TP. */
    public function test_same_number_messy_formatting_still_matches(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            $this->facts(['street_number' => '1']),
            ['type' => 'p24', 'ref' => 'REF-A']
        );

        // Same number + overlapping street/estate tokens, different source ref
        // and messier text — token strategy should still bind it to the same TP.
        $again = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '1', 'street_name' => 'The Oval Road', 'suburb' => 'Umhlali Golf Estate'],
            ['type' => 'manual', 'ref' => 'REF-B']
        );

        $this->assertSame($first->id, $again->id, 'Same number + same street tokens must still match');
        $this->assertSame(1, TrackedProperty::queryWithoutAgencyScope()->count());
    }

    /** Rule 2 — unit numbers discriminate sectional-title properties. */
    public function test_different_unit_same_address_does_not_match(): void
    {
        $unit1 = $this->service->matchOrCreate(
            $this->agency->id,
            ['unit_number' => '1', 'street_number' => '1', 'street_name' => 'The Oval', 'suburb' => 'Umhlali Golf Estate'],
            ['type' => 'p24', 'ref' => 'U1']
        );

        $match = $this->service->findExistingMatch(
            $this->agency->id,
            ['unit_number' => '2', 'street_number' => '1', 'street_name' => 'The Oval', 'suburb' => 'Umhlali Golf Estate']
        );
        $this->assertNull($match, 'Unit 1 and Unit 2 at the same address are different properties');
    }

    /** Rule 4 — estate/street-only (no number) still matches on tokens. */
    public function test_missing_number_still_matches_on_tokens(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Sunbird Close', 'suburb' => 'Simbithi Eco Estate'],
            ['type' => 'p24', 'ref' => 'NN-A']
        );

        // No street number on either side — must NOT be blocked by the gate.
        $again = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Sunbird Close', 'suburb' => 'Simbithi Eco Estate'],
            ['type' => 'manual', 'ref' => 'NN-B']
        );

        $this->assertSame($first->id, $again->id, 'Number-less captures must still match on tokens');
        $this->assertSame(1, TrackedProperty::queryWithoutAgencyScope()->count());
    }

    /** Rule 4 — a number captured on one side only must not block enrichment. */
    public function test_number_on_one_side_only_still_matches(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Sunbird Close', 'suburb' => 'Simbithi Eco Estate'],
            ['type' => 'p24', 'ref' => 'ONE-A']
        );

        $again = $this->service->findExistingMatch(
            $this->agency->id,
            ['street_number' => '7', 'street_name' => 'Sunbird Close', 'suburb' => 'Simbithi Eco Estate']
        );

        $this->assertNotNull($again, 'A number on one side only must not veto the match');
        $this->assertSame($first->id, $again->id);
    }

    /** Rule 4 — numbers embedded in complex NAMES discriminate too. */
    public function test_name_embedded_numbers_discriminate(): void
    {
        $three = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Aqua Breeze 3', 'suburb' => 'Ballito Central'],
            ['type' => 'p24', 'ref' => 'AB3']
        );

        // Same complex name, different embedded unit number → different property.
        $matchFive = $this->service->findExistingMatch(
            $this->agency->id,
            ['street_name' => 'Aqua Breeze 5', 'suburb' => 'Ballito Central']
        );
        $this->assertNull($matchFive, '"Aqua Breeze 3" and "Aqua Breeze 5" are different units');

        // Same embedded number → same property (still matches).
        $matchThree = $this->service->findExistingMatch(
            $this->agency->id,
            ['street_name' => 'Aqua Breeze 3', 'suburb' => 'Ballito Central']
        );
        $this->assertNotNull($matchThree);
        $this->assertSame($three->id, $matchThree->id);
    }
}
