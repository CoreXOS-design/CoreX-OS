<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\PropertyTypeMap;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** .ai/specs/mic-work-tab.md section 8, step 5.2 (AT-519): one property-type mapping, never guessed. */
final class PropertyTypeMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PropertyTypeResolver::flush();
    }

    public function test_every_raw_spelling_held_today_maps_to_one_canonical_key(): void
    {
        $r = app(PropertyTypeResolver::class);
        foreach ([
            'House' => 'house', 'house' => 'house', 'House (2 storey)' => 'house',
            'Apartment' => 'apartment', 'Apartment / Flat' => 'apartment', 'Flat' => 'apartment',
            'Townhouse' => 'townhouse', 'Duplex' => 'townhouse', 'Simplex' => 'townhouse',
            'Vacant land' => 'land', 'Vacant Land / Plot' => 'land', 'VacantLand' => 'land', 'Land' => 'land',
            'Farm' => 'farm', 'Commercial Property' => 'commercial', 'Industrial' => 'industrial',
            'Residence' => 'other', 'Full Title' => 'other',
        ] as $raw => $expected) {
            $this->assertSame($expected, $r->keyFor($raw), $raw);
        }
    }

    public function test_spacing_and_case_do_not_matter_and_unknown_text_is_null_not_a_guess(): void
    {
        $r = app(PropertyTypeResolver::class);
        $this->assertSame('apartment', $r->keyFor("  APARTMENT  /  Flat "));
        $this->assertNull($r->keyFor('Castle'));
        $this->assertNull($r->keyFor(''));
        $this->assertNull($r->keyFor(null));
    }

    public function test_title_type_labels_are_flagged_as_such_and_duplex_as_assumed(): void
    {
        $this->assertSame('title_type_only', PropertyTypeMap::where('raw_key', 'residence')->whereNull('agency_id')->value('confidence'));
        $this->assertSame('assumed', PropertyTypeMap::where('raw_key', 'duplex')->whereNull('agency_id')->value('confidence'));
    }

    public function test_an_agency_row_overrides_the_global_row_for_that_agency_only(): void
    {
        $a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        PropertyTypeMap::create(['agency_id' => $a->id, 'raw_key' => 'castle', 'type_key' => 'house', 'confidence' => 'exact']);
        PropertyTypeMap::create(['agency_id' => $a->id, 'raw_key' => 'duplex', 'type_key' => 'house', 'confidence' => 'exact']);
        PropertyTypeResolver::flush();

        $r = app(PropertyTypeResolver::class);
        $this->assertSame('house', $r->keyFor('Castle', $a->id));
        $this->assertNull($r->keyFor('Castle', $b->id));
        $this->assertSame('house', $r->keyFor('Duplex', $a->id));
        $this->assertSame('townhouse', $r->keyFor('Duplex', $b->id));
        $this->assertSame('townhouse', $r->keyFor('Duplex'));
    }
}
