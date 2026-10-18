<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A property saved with ONLY a typed address ("12 Beach Road") must keep its street name.
 *
 * The structured address layer lifts the house number out of the typed text into an empty street_number
 * column; PropertyObserver then derives `address` from the parts. When the street NAME was not lifted with
 * the number the derived address collapsed to "12" and every screen, file name, PDF and email built from it
 * lost the street. This pins the whole input space: number only, street only, "12A", unit + number + street,
 * complex names, no number — and that nothing an agent typed in a column is ever overwritten.
 * Spec: .ai/specs/structured-address-matching.md §5.
 */
final class PropertyTypedAddressKeepsStreetTest extends TestCase
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

    public function test_a_number_and_street_typed_as_one_line_keeps_both(): void
    {
        $p = $this->save(['address' => '12 Beach Road']);

        $this->assertSame('12 Beach Road', $p->address);
        $this->assertSame('12', $p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
        $this->assertSame('beach', $p->street_core);
        $this->assertNotNull($p->street_name_normalised, 'the normalised street cache is built from the filled street name');
    }

    public function test_a_number_with_a_letter_survives(): void
    {
        $p = $this->save(['address' => '12A Beach Road']);

        $this->assertSame('12A Beach Road', $p->address);
        $this->assertSame('12A', $p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_unit_number_and_street_all_survive(): void
    {
        $p = $this->save(['address' => 'Unit 3, 12 Beach Road']);

        $this->assertSame('Unit 3, 12 Beach Road', $p->address);
        $this->assertSame('3', $p->unit_number);
        $this->assertSame('12', $p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_a_complex_name_before_the_street_survives(): void
    {
        $p = $this->save(['address' => 'Sunset Court, 12 Beach Road']);

        $this->assertSame('Sunset Court, 12 Beach Road', $p->address);
        $this->assertSame('Sunset Court', $p->complex_name);
        $this->assertSame('12', $p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_unit_complex_and_street_together(): void
    {
        $p = $this->save(['address' => 'Unit 3, Sunset Court, 12 Beach Road']);

        $this->assertSame('Unit 3, Sunset Court, 12 Beach Road', $p->address);
        $this->assertSame('3', $p->unit_number);
        $this->assertSame('Sunset Court', $p->complex_name);
        $this->assertSame('12', $p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_a_street_with_no_number_keeps_its_name(): void
    {
        $p = $this->save(['address' => 'Beach Road']);

        $this->assertSame('Beach Road', $p->address);
        $this->assertNull($p->street_number);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_a_unit_in_a_complex_with_no_street_keeps_the_complex(): void
    {
        $p = $this->save(['address' => 'Unit 5, Sunset Court']);

        $this->assertStringContainsString('Sunset Court', (string) $p->address);
        $this->assertStringContainsString('5', (string) $p->address);
    }

    public function test_a_bare_number_is_left_exactly_as_typed(): void
    {
        $p = $this->save(['address' => '12']);

        $this->assertSame('12', $p->address);
    }

    public function test_the_street_is_kept_as_typed_not_as_its_match_key(): void
    {
        $king = $this->save(['address' => "5 King's Road"]);
        $this->assertSame("5 King's Road", $king->address);
        $this->assertSame("King's Road", $king->street_name);

        $saint = $this->save(['address' => '3 Saint Andrews Drive']);
        $this->assertSame('3 Saint Andrews Drive', $saint->address);
        $this->assertSame('Saint Andrews Drive', $saint->street_name);
    }

    public function test_the_suburb_in_the_typed_line_is_not_taken_for_a_street_or_complex(): void
    {
        $p = $this->save(['address' => '12 Beach Road, Uvongo', 'suburb' => 'Uvongo']);

        $this->assertSame('12 Beach Road', $p->address);
        $this->assertSame('Beach Road', $p->street_name);
        $this->assertNull($p->complex_name);
    }

    public function test_a_number_given_in_its_column_and_the_street_only_in_the_text_keeps_both(): void
    {
        $p = $this->save(['address' => '12 Beach Road', 'street_number' => '12']);

        $this->assertSame('12 Beach Road', $p->address);
        $this->assertSame('Beach Road', $p->street_name);
    }

    public function test_columns_an_agent_filled_are_never_overwritten(): void
    {
        $p = $this->save([
            'address' => '99 Somewhere Else',
            'street_number' => '14', 'street_name' => 'Marine Drive',
        ]);

        // the parts win, exactly as before the structured layer existed
        $this->assertSame('14', $p->street_number);
        $this->assertSame('Marine Drive', $p->street_name);
        $this->assertSame('14 Marine Drive', $p->address);
    }

    public function test_the_wizard_shape_parts_only_still_composes(): void
    {
        $p = $this->save(['street_number' => '40', 'street_name' => 'Bulwer Street']);

        $this->assertSame('40 Bulwer Street', $p->address);
    }

    public function test_an_unrelated_save_leaves_a_typed_address_row_alone(): void
    {
        $p = $this->save(['address' => '12 Beach Road']);

        $p->price = 2_000_000;
        $p->save();

        $this->assertSame('12 Beach Road', $p->fresh()->address);
    }

    public function test_a_row_with_no_address_text_stays_empty(): void
    {
        $p = $this->save([]);

        $this->assertNull($p->street_name);
        $this->assertNull($p->street_number);
    }

    private function save(array $attrs): Property
    {
        $base = [
            'external_id' => 'TYPED-' . Str::random(8), 'title' => 'Test',
            'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'house',
            'beds' => 3, 'status' => 'active', 'is_demo' => false,
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->agentId,
            'created_at' => now(), 'updated_at' => now(),
        ];

        $p = new Property();
        foreach (array_merge($base, $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p->fresh();
    }
}
