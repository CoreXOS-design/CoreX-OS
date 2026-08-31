<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use App\Services\Importer\P24ListingsCsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `properties.listing_type` has exactly one canon: 'sale' | 'rental' | null.
 *
 * The reported bug: on the Demo Agency Test account, saving ANY property failed
 * with "The selected listing type is invalid" — on a listing whose type the
 * agent had not touched, out of a menu offering only the two valid choices.
 *
 * Root cause: the column was never normalised on write. The P24 CSV import
 * stored a capitalised 'Sale' / 'Rental'; the UI stored lowercase. Reads had
 * been made tolerant (Property::isRental()) but the save validator is a
 * case-SENSITIVE `in:sale,rental`, so the edit form handed the stored 'Sale'
 * straight back to the validator, which rejected it. 4,753 of that agency's
 * 4,755 listings were uneditable.
 *
 * The class of bug: tolerating a divergent vocabulary on READ while forbidding
 * it on WRITE. These tests pin the write side closed — importer, model, and the
 * form field — so the read-side tolerance never has anything new to tolerate.
 *
 * @see PropertyLivePreviewListingTypeTest — the read-side half of this canon.
 */
final class PropertyListingTypeCanonTest extends TestCase
{
    use RefreshDatabase;

    /** The mutator is the write-side guard: nothing reaches the column uncanonical. */
    public function test_the_model_normalises_every_spelling_on_write(): void
    {
        // A list of pairs, NOT a keyed map: '' and null collapse to the same
        // PHP array key, which would silently drop one of the two cases.
        $cases = [
            ['sale',     'sale'],
            ['Sale',     'sale'],
            ['SALE',     'sale'],
            ['  sale  ', 'sale'],
            ['rental',   'rental'],
            ['Rental',   'rental'],   // exactly as the P24 CSV import used to store it
            ['RENTAL',   'rental'],
            [' rental ', 'rental'],
            ['to_let',   'rental'],
            ['to-let',   'rental'],
            ['lease',    'rental'],
            ['',         null],
            ['   ',      null],
            [null,       null],
        ];

        foreach ($cases as [$input, $expected]) {
            $p = new Property(['listing_type' => $input]);

            $this->assertSame(
                $expected,
                $p->getAttributes()['listing_type'] ?? null,
                "listing_type " . var_export($input, true) . " must be stored as " . var_export($expected, true),
            );
        }
    }

    /** A value the canon cannot place is preserved, never guessed at. */
    public function test_an_unrecognised_value_is_lowercased_but_never_invented(): void
    {
        $p = new Property(['listing_type' => 'Timeshare']);

        $this->assertSame('timeshare', $p->getAttributes()['listing_type']);
    }

    /** The mutator holds through a real persist + reload, not just in memory. */
    public function test_the_canon_survives_a_round_trip_to_the_database(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $p = $this->property($agencyId, $agent, 'ZZZ-Canon-Roundtrip', ['listing_type' => 'Rental']);

        $this->assertSame('rental', $p->fresh()->listing_type);
        $this->assertTrue($p->fresh()->isRental());

        // Case-sensitive check — the column's collation is case-INSENSITIVE, so
        // a plain where('listing_type','rental') would pass even on 'Rental'.
        $this->assertSame(1, DB::table('properties')
            ->where('id', $p->id)
            ->whereRaw("CAST(listing_type AS BINARY) = CAST('rental' AS BINARY)")
            ->count());
    }

    /** The origin of the bad data: the P24 CSV import must emit the canon. */
    public function test_the_p24_csv_import_maps_onto_the_canon(): void
    {
        $csv = $this->csvFixture([
            ['ListingNumber' => '1001', 'ListingType' => 'Sale',   'Price' => '2450000', 'Status' => 'Active'],
            ['ListingNumber' => '1002', 'ListingType' => 'Rental', 'RentalRate' => '8500', 'Status' => 'Active'],
        ]);

        $rows = (new P24ListingsCsvParser())->parse($csv);

        $this->assertSame('sale',   $rows[0]['mapped']['listing_type']);
        $this->assertSame('rental', $rows[1]['mapped']['listing_type']);

        foreach ($rows as $row) {
            $this->assertContains(
                $row['mapped']['listing_type'],
                Property::LISTING_TYPES,
                'The importer may only ever emit a canonical listing type.',
            );
        }
    }

    /**
     * THE regression. A listing carrying the legacy capitalised type — written
     * raw, exactly as the old importer left 4,753 rows on live — must save
     * through the edit form instead of being rejected for a type nobody chose.
     */
    public function test_a_legacy_capitalised_listing_saves_without_a_listing_type_error(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $p = $this->property($agencyId, $agent, 'ZZZ-Legacy-Capitalised', ['listing_type' => 'sale']);

        // Re-create the pre-fix state the mutator now prevents: a raw write,
        // bypassing Eloquent, leaving 'Sale' in the column.
        DB::table('properties')->where('id', $p->id)->update(['listing_type' => 'Sale']);

        $res = $this->actingAs($agent)->get(route('corex.properties.show', $p->id))->assertOk();

        // The form posts the canon, not the raw column — so the validator, which
        // is case-sensitive, is never handed a value it must reject.
        $res->assertSee('name="listing_type" value="sale"', false);
        $res->assertDontSee('name="listing_type" value="Sale"', false);
    }

    /** The backfill repairs rows already in the table. */
    public function test_the_backfill_migration_retires_legacy_values(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $sale   = $this->property($agencyId, $agent, 'ZZZ-Legacy-Sale',   ['listing_type' => 'sale']);
        $rental = $this->property($agencyId, $agent, 'ZZZ-Legacy-Rental', ['listing_type' => 'rental']);
        $none   = $this->property($agencyId, $agent, 'ZZZ-Legacy-None',   ['listing_type' => null]);

        DB::table('properties')->where('id', $sale->id)->update(['listing_type' => 'Sale']);
        DB::table('properties')->where('id', $rental->id)->update(['listing_type' => 'Rental']);

        $before = DB::table('properties')->where('id', $sale->id)->value('updated_at');

        $this->migration()->up();

        $this->assertSame('sale',   $this->rawType($sale->id));
        $this->assertSame('rental', $this->rawType($rental->id));
        $this->assertNull($this->rawType($none->id), 'A listing with no type is never given one.');

        // 4,753 listings must not all present as freshly edited afterwards.
        $this->assertSame(
            $before,
            DB::table('properties')->where('id', $sale->id)->value('updated_at'),
            'The backfill must not bump updated_at.',
        );
    }

    /** Re-running the backfill on already-clean data changes nothing. */
    public function test_the_backfill_is_idempotent(): void
    {
        [$agencyId, $agent] = $this->agencyWithAgent();

        $p = $this->property($agencyId, $agent, 'ZZZ-Already-Clean', ['listing_type' => 'rental']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('rental', $this->rawType($p->id));
    }

    // ---------------------------------------------------------------- helpers

    private function migration(): object
    {
        return require database_path('migrations/2026_08_30_000007_normalise_property_listing_type_canon.php');
    }

    /** Reads the column case-sensitively — the collation is case-insensitive. */
    private function rawType(int $id): ?string
    {
        $v = DB::table('properties')->where('id', $id)->value('listing_type');

        return $v === null ? null : (string) $v;
    }

    private function csvFixture(array $rows): string
    {
        $columns = ['ListingNumber', 'ListingType', 'Status', 'Price', 'RentalRate',
                    'PropertyTypeId', 'DescriptionHeader', 'Description'];

        $path = tempnam(sys_get_temp_dir(), 'p24') . '.csv';
        $fh   = fopen($path, 'w');
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($c) => $row[$c] ?? '', $columns));
        }
        fclose($fh);

        return $path;
    }

    private function agencyWithAgent(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'agent',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'property_type' => 'apartment',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
