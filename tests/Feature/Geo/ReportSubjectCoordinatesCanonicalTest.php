<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MarketReports\MarketReport;
use App\Models\Property;
use App\Models\User;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\MarketReports\LayoutLearner;
use App\Services\MarketReports\LearnedLayoutReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The subject's coordinates are a first-class field of the canonical read (`subject.latitude` /
 * `subject.longitude`), not a CMA Info or LOOM detail. Whatever reads the report — a dedicated parser, the AI
 * read, or a learned layout — delivers them the same way, they land on market_reports.subject_latitude/longitude,
 * and the property fallback reads that column. Spec: geocoding-spec.md "Report coordinates",
 * mic-complete-spec.md §8.9.
 */
final class ReportSubjectCoordinatesCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_TEXT = <<<'TXT'
ACME PROPERTY INSIGHTS - Valuation summary
Subject property
Address:    1936 Mininkie Street, Blue Valley Golf Estate, Centurion
Latitude:   -25.94173
Longitude:  28.11192
Erf size    1 004 m2
TXT;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('services.google.geocoding_api_key', null);
        Config::set('geo.geocoding.google_api_key', null);
        Config::set('services.nominatim.enabled', false);
        GeocodeRateLimiter::releaseRuntimeOverride();
        Http::preventStrayRequests();
    }

    public function test_the_canonical_read_keeps_subject_coordinates_whatever_produced_it(): void
    {
        $mapper = new CanonicalReadMapper();

        // As the AI returns them (numbers) and as a learned layout returns them (the printed text).
        foreach ([[-25.94173, 28.11192], ['-25.94173', '28.11192']] as [$lat, $lng]) {
            $clean = $mapper->sanitise(['subject' => ['address' => '1936 Mininkie Street', 'latitude' => $lat, 'longitude' => $lng]])['clean'];
            $this->assertEqualsWithDelta(-25.94173, $clean['subject']['latitude'], 0.000001);
            $this->assertEqualsWithDelta(28.11192, $clean['subject']['longitude'], 0.000001);

            $meta = $mapper->toParseResult($clean, (new MarketReport())->forceFill(['source_suburb' => 'Blue Valley Golf Estate']))->subjectMeta;
            $this->assertEqualsWithDelta(-25.94173, (float) $meta['subject_latitude'], 0.000001, 'delivered to the report row as a first-class field');
            $this->assertEqualsWithDelta(28.11192, (float) $meta['subject_longitude'], 0.000001);
        }

        // Nonsense is dropped, never stored.
        $bad = $mapper->sanitise(['subject' => ['latitude' => 250, 'longitude' => 'not a number']]);
        $this->assertArrayNotHasKey('latitude', $bad['clean']['subject'] ?? []);
        $this->assertArrayNotHasKey('longitude', $bad['clean']['subject'] ?? []);
    }

    public function test_a_learned_layout_learns_and_then_reads_the_coordinates_for_free(): void
    {
        $rawLines  = LearnedLayoutReader::lines(self::REPORT_TEXT);
        $cellLines = array_map([LearnedLayoutReader::class, 'cells'], $rawLines);
        $clean     = ['subject' => ['latitude' => -25.94173, 'longitude' => 28.11192]];

        $m = new \ReflectionMethod(LayoutLearner::class, 'learnScalars');
        $m->setAccessible(true);
        $scalars = $m->invoke(app(LayoutLearner::class), $rawLines, $cellLines, $clean);

        $fields = array_column($scalars, 'label', 'field');
        $this->assertSame('Latitude:', $fields['subject.latitude'] ?? null);
        $this->assertSame('Longitude:', $fields['subject.longitude'] ?? null);

        // The next report in the same layout is read with no AI call and still carries the coordinates.
        $read = app(LearnedLayoutReader::class)->read(['scalars' => $scalars], self::REPORT_TEXT);
        $this->assertSame('-25.94173', (string) $read['subject']['latitude']);

        $clean2 = (new CanonicalReadMapper())->sanitise($read)['clean'];
        $this->assertEqualsWithDelta(-25.94173, $clean2['subject']['latitude'], 0.000001);
        $this->assertEqualsWithDelta(28.11192, $clean2['subject']['longitude'], 0.000001);
    }

    public function test_a_report_of_an_unknown_layout_delivers_its_coordinates_to_the_property(): void
    {
        $agency = Agency::create(['name' => 'Caprivi Unknown Layout', 'slug' => 'cul-' . uniqid()]);
        $branch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $agency->id, 'name' => 'B', 'code' => strtoupper(Str::random(4)), 'is_active' => true,
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);

        // 1. A report in a layout CoreX has never seen: no dedicated parser, only a canonical read.
        $mapper = new CanonicalReadMapper();
        $clean  = $mapper->sanitise(['subject' => [
            'address'   => '1936 Mininkie Street, Blue Valley Golf Estate, Centurion',
            'latitude'  => -25.94173, 'longitude' => 28.11192,
        ]])['clean'];
        $meta = $mapper->toParseResult($clean, (new MarketReport())->forceFill(['source_suburb' => 'Blue Valley Golf Estate']))->subjectMeta;

        // 2. Stored exactly as ParseMarketReportJob stores any parser's subjectMeta.
        DB::table('market_reports')->insert(array_merge([
            'agency_id' => $agency->id, 'report_type_id' => null, 'uploaded_by_user_id' => $user->id,
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_hash' => hash('sha256', Str::random(12)),
            'report_date' => now()->toDateString(), 'source_suburb' => 'Blue Valley Golf Estate',
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ], array_intersect_key($meta, array_flip(['subject_address', 'subject_latitude', 'subject_longitude']))));

        // 3. The agency's property in that estate is created with no location (no Google key, no stray HTTP).
        $property = Property::create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => 'UL-1', 'title' => '5 Bedroom House in Estate',
            'address' => 'Blue Valley Golf Estate, 1936 Mininkie Street',
            'street_number' => '1936', 'street_name' => 'Mininkie Street',
            'suburb' => 'Blue Valley Golf Estate', 'city' => 'Centurion', 'province' => 'Gauteng',
            'property_type' => 'house', 'status' => 'active', 'is_demo' => false,
        ])->refresh();

        Http::assertNothingSent();
        $this->assertEqualsWithDelta(-25.94173, (float) $property->latitude, 0.000001);
        $this->assertEqualsWithDelta(28.11192, (float) $property->longitude, 0.000001);
        $this->assertSame('market_report_subject', $property->geo_source);
    }
}
