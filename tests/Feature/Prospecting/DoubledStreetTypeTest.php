<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Address\AddressParser;
use App\Support\Address\StreetTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** AT-520 (conductor walk 31 Oct): "13 Newport Road Road" is "13 Newport Road" - at the parser, on every save, and in the stored data (journalled). */
final class DoubledStreetTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_identical_doubled_street_type_is_collapsed(): void
    {
        $this->assertSame('Newport Road', StreetTypes::collapseDoubled('Newport Road Road'));
        $this->assertSame('Zululami Boulevard', StreetTypes::collapseDoubled('Zululami Boulevard Boulevard'));
        $this->assertSame('Edgecombe Park, 3 Siphosethu Road', StreetTypes::collapseDoubled('Edgecombe Park, 3 Siphosethu Road Road'));
        $this->assertSame('Capri Crescent Drive', StreetTypes::collapseDoubled('Capri Crescent Drive'), 'two DIFFERENT type words are left alone');
        $this->assertSame('Worlds View Drive', StreetTypes::collapseDoubled('Worlds View Drive'));
        $this->assertSame('Road Road', StreetTypes::collapseDoubled('Road Road'), 'a street that is only the word is not touched');
        $this->assertNull(StreetTypes::collapseDoubled(null));
    }

    public function test_the_parser_reads_a_doubled_type_as_the_same_street_core(): void
    {
        $p = new AddressParser();
        [$n1, $core1, $t1] = $p->numberAndCore('13', 'Newport Road');
        [$n2, $core2, $t2] = $p->numberAndCore('13', 'Newport Road Road');

        $this->assertSame($core1, $core2);
        $this->assertSame($t1, $t2);
        $this->assertSame($n1, $n2);
    }

    public function test_a_file_saved_with_a_doubled_type_is_stored_clean_and_the_back_fill_cleans_old_rows_with_a_journal(): void
    {
        $a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $new = TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $a->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '13', 'street_name' => 'Newport Road Road', 'suburb' => 'Port Edward']);
        $this->assertSame('Newport Road', TrackedProperty::queryWithoutAgencyScope()->find($new->id)->street_name);

        $old = TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $a->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '8', 'street_name' => 'Marine Drive', 'suburb' => 'Margate']);
        DB::table('tracked_properties')->where('id', $old->id)->update(['street_name' => 'Marine Drive Drive']); // as stored before the fix
        $dir = sys_get_temp_dir() . '/dst-' . uniqid();

        Artisan::call('property-identity:fix-doubled-street-type', ['--dry-run' => true, '--journal-dir' => $dir]);
        $this->assertSame('Marine Drive Drive', DB::table('tracked_properties')->where('id', $old->id)->value('street_name'));

        Artisan::call('property-identity:fix-doubled-street-type', ['--apply' => true, '--journal-dir' => $dir]);
        $this->assertSame('Marine Drive', DB::table('tracked_properties')->where('id', $old->id)->value('street_name'));
        $journal = glob($dir . '/fix-doubled-street-type-journal-*.jsonl');
        $this->assertCount(1, $journal);
        $this->assertStringContainsString('Marine Drive Drive', (string) file_get_contents($journal[0]));
    }
}
