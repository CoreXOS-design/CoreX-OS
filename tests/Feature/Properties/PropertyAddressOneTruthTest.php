<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use App\Services\Properties\PropertyAddressReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-266 — ONE TRUTH for the property address.
 *
 * `properties.address` and the structured address columns were two independent
 * copies of the same fact, with nothing reconciling them. They drifted on 74 live
 * rows; on 17 the drift was bad enough to pitch a seller an address their own
 * agent never typed.
 *
 * `address` is now DERIVED from the structured columns on every save — the same
 * pattern the observer already uses to derive title_type from property_type.
 */
final class PropertyAddressOneTruthTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $agentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agentId = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ])->id;
    }

    // ── The sync: edit the parts, the address follows ────────────────────

    public function test_editing_the_street_updates_the_address_in_the_same_save(): void
    {
        $p = $this->property(['street_number' => '14', 'street_name' => 'Marine Drive']);
        $this->assertSame('14 Marine Drive', $p->address);

        $p->street_number = '16';
        $p->save();

        $this->assertSame('16 Marine Drive', $p->fresh()->address,
            'the display string can no longer sit frozen while the parts move');
    }

    /** The exact failure that started AT-266: complex/unit added, address left behind. */
    public function test_adding_a_complex_and_unit_updates_the_address(): void
    {
        $p = $this->property(['street_number' => '40', 'street_name' => 'Bulwer Street']);
        $this->assertSame('40 Bulwer Street', $p->address);

        $p->complex_name = 'Umzimkhulu Court';
        $p->unit_number  = '6';
        $p->save();

        $this->assertSame('Unit 6, Umzimkhulu Court, 40 Bulwer Street', $p->fresh()->address);
    }

    /** A sectional unit with no street of its own still composes. */
    public function test_a_sectional_unit_with_no_street_composes_its_scheme_identity(): void
    {
        $p = $this->property(['complex_name' => 'Arista', 'unit_number' => '6']);

        $this->assertSame('Unit 6, Arista', $p->address);
    }

    /** A save that touches nothing address-shaped must not rewrite the address. */
    public function test_an_unrelated_save_leaves_the_address_alone(): void
    {
        $p = $this->property(['street_number' => '14', 'street_name' => 'Marine Drive']);

        // A legacy row whose address disagrees with its parts — set behind the observer.
        DB::table('properties')->where('id', $p->id)->update(['address' => 'LEGACY VALUE']);

        $p = Property::withoutGlobalScopes()->find($p->id);
        $p->price = 2_000_000;
        $p->save();

        $this->assertSame('LEGACY VALUE', $p->fresh()->address,
            'a price change must not silently rewrite an address nobody asked us to touch');
    }

    /** With no structured part at all, we do not blank a row we know nothing better about. */
    public function test_an_empty_composition_never_blanks_an_existing_address(): void
    {
        $p = $this->property(['address' => '19A Clarendon road']);

        $p->street_name = null;
        $p->save();

        $this->assertSame('19A Clarendon road', $p->fresh()->address);
    }

    // ── The reconciler: the real live corruption shapes ──────────────────

    /** Live property 3719 — the complex was typed into the street-name box. */
    public function test_the_reconciler_moves_a_bled_complex_back_out_of_the_street(): void
    {
        $p = $this->property([
            'address' => '73 Marine Drive',              // frozen at import
            'street_number' => '73',
            'street_name' => '26 Stafford Close Marine Drive',   // the pollution
            'complex_name' => '26 Stafford Close',
            'unit_number' => '26',
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::HIGH, $r['status']);
        $this->assertSame('Marine Drive', $r['after']['street_name']);
        // The agent's enrichment is PRESERVED — not thrown away.
        $this->assertSame('Unit 26, 26 Stafford Close, 73 Marine Drive', $r['after']['address']);
    }

    /** Live property 2725 — a single-line input deleted the newline. */
    public function test_the_reconciler_rebuilds_a_newline_glued_address(): void
    {
        $p = $this->property([
            'address' => "Umzimkhulu Court\r\n40 Bulwer Street",
            'street_name' => 'Umzimkhulu Court40 Bulwer Street',   // the glue
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::HIGH, $r['status']);
        $this->assertSame('40', $r['after']['street_number']);
        $this->assertSame('Bulwer Street', $r['after']['street_name']);
        $this->assertSame('Umzimkhulu Court', $r['after']['complex_name']);
        $this->assertSame('Umzimkhulu Court, 40 Bulwer Street', $r['after']['address']);
    }

    /**
     * qa1 #1293 — the scheme is in the street box and the complex column is EMPTY.
     * The first cut of the reconciler destroyed the comma and left the scheme
     * inside the street: "Unit 3, 3 Aqua Pearl 55 Queen Street". Caught by the
     * report-only run, before a single row was written.
     */
    public function test_the_reconciler_lifts_a_scheme_out_of_the_street_when_no_complex_is_recorded(): void
    {
        $p = $this->property([
            'address' => '3 Aqua Pearl, 55 Queen Street',
            'street_number' => '3',
            'street_name' => 'Aqua Pearl, 55 Queen Street',
            'unit_number' => '3',
            'complex_name' => null,
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::HIGH, $r['status']);
        $this->assertSame('Aqua Pearl', $r['after']['complex_name']);
        $this->assertSame('55', $r['after']['street_number']);
        $this->assertSame('Queen Street', $r['after']['street_name']);
        $this->assertSame('Unit 3, Aqua Pearl, 55 Queen Street', $r['after']['address']);
    }

    /** qa1 #1297 — street_number holds the UNIT, and the "street" is really the scheme. */
    public function test_the_reconciler_recognises_a_unit_masquerading_as_a_street_number(): void
    {
        $p = $this->property([
            'address' => '9 Casa Montana',
            'street_number' => '9',
            'street_name' => 'Casa Montana',
            'unit_number' => '9',
            'complex_name' => null,
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::HIGH, $r['status']);
        $this->assertSame('Casa Montana', $r['after']['complex_name']);
        $this->assertSame('', $r['after']['street_name']);
        $this->assertSame('Unit 9, Casa Montana', $r['after']['address']);
    }

    /** qa1 #1301 — street_number is not a house number at all ("The"). */
    public function test_the_reconciler_repairs_a_non_numeric_street_number(): void
    {
        $p = $this->property([
            'address' => 'The Farm Estates',
            'street_number' => 'The',
            'street_name' => 'Farm Estates',
            'unit_number' => '19',
            'complex_name' => null,
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::HIGH, $r['status']);
        $this->assertSame('The Farm Estates', $r['after']['complex_name']);
        $this->assertSame('Unit 19, The Farm Estates', $r['after']['address']);
    }

    /**
     * The safety invariant. A repair may never lose information — if a rule would
     * drop a word from the address, it is not a repair, it is a guess, and it goes
     * to a human instead.
     */
    public function test_a_repair_that_would_lose_a_word_is_refused(): void
    {
        $p = $this->property([
            'address' => '55 Queen Street, Block C, Erf 1290',
            'street_number' => '55',
            'street_name' => 'Queen Street',
            'complex_name' => 'Block C',
            'unit_number' => null,
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        // "Erf 1290" exists in the address and in no structured column — any
        // recomposition would silently delete it.
        $this->assertSame(PropertyAddressReconciler::REVIEW, $r['status']);
        $this->assertStringContainsString('refusing to guess', $r['reason']);
        $this->assertSame($r['before'], $r['after']);
    }

    /** A coherent row is left alone — the reconciler is not a rewriter. */
    public function test_a_coherent_property_is_reported_as_ok(): void
    {
        $p = $this->property(['street_number' => '14', 'street_name' => 'Marine Drive']);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::OK, $r['status']);
    }

    /** What it cannot repair without guessing, it refuses to touch. */
    public function test_an_unsplittable_row_is_flagged_for_review_not_guessed(): void
    {
        $p = $this->property([
            'address' => "Arista Unit 6\r\nLilliecrona 76",   // no line opens with a house number
            'street_name' => 'Arista Unit 6Lilliecrona 76',
            'unit_number' => '6',
        ], sync: false);

        $r = app(PropertyAddressReconciler::class)->analyse($p);

        $this->assertSame(PropertyAddressReconciler::REVIEW, $r['status']);
        $this->assertSame($r['before'], $r['after'], 'a REVIEW row must be left exactly as it was');
    }

    // ── Helper ───────────────────────────────────────────────────────────

    /** @param array<string,?string> $attrs */
    private function property(array $attrs, bool $sync = true): Property
    {
        $base = [
            'external_id' => 'AT266-' . Str::random(8), 'title' => 'Test',
            'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'house',
            'beds' => 3, 'status' => 'active', 'is_demo' => false,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->agentId,
            'created_at' => now(), 'updated_at' => now(),
        ];

        if ($sync) {
            $p = new Property();
            foreach (array_merge($base, $attrs) as $k => $v) {
                $p->{$k} = $v;
            }
            $p->save();     // through the observer — address is derived

            return $p->fresh();
        }

        // Behind the observer's back: reproduce a corrupted LIVE row exactly as it
        // sits in the database today.
        $id = (int) DB::table('properties')->insertGetId(array_merge($base, $attrs));

        return Property::withoutGlobalScopes()->findOrFail($id);
    }
}
