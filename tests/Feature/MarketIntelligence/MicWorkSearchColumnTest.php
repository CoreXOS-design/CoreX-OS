<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 6.13: the generated search_text columns find EVERYTHING the old five-column search found, and stay current on every kind of write. */
final class MicWorkSearchColumnTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $capturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->capturer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function advert(string $portal, string $ref, array $extra = [], ?TrackedProperty $tp = null): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => $portal, 'portal_ref' => $ref, 'portal_url' => 'https://p.test/' . $ref, 'address' => null, 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => 1_000_000, 'captured_by_user_id' => $this->capturer->id, 'tracked_property_id' => $tp?->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    /** the pre-6.13 search, spelled out: five LIKEs on the advert, four on the file, every word must match somewhere */
    private function legacyIds(string $term): array
    {
        $ids = [];
        foreach (DB::table('prospecting_listings')->where('agency_id', $this->agency->id)->get() as $l) {
            $tp = $l->tracked_property_id ? DB::table('tracked_properties')->where('id', $l->tracked_property_id)->first() : null;
            $ok = true;
            foreach (preg_split('/\s+/', trim($term)) as $w) {
                $w = mb_strtolower($w);
                $hay = array_map(fn ($v) => mb_strtolower((string) $v), [$l->address, $l->suburb, $l->agent_name, $l->agency_name, $l->portal_ref]);
                $hit = (bool) array_filter($hay, fn ($v) => $v !== '' && str_contains($v, $w));
                if (! $hit && $tp) {
                    $file = array_map(fn ($v) => mb_strtolower((string) $v), [$tp->street_name, trim($tp->street_number . ' ' . $tp->street_name), $tp->complex_name, $tp->suburb]);
                    $hit = (bool) array_filter($file, fn ($v) => $v !== '' && str_contains($v, $w));
                }
                $ok = $ok && $hit;
            }
            if ($ok) {
                $ids[] = (int) $l->id;
            }
        }
        sort($ids);

        return $ids;
    }

    public function test_the_new_search_finds_exactly_what_the_five_column_search_found(): void
    {
        $this->assertTrue(ProspectingListing::hasSearchText());
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => '13', 'street_name' => 'Newport Road', 'suburb' => 'Port Edward'], ['type' => 'p24', 'ref' => 'P24-130000001']);
        $this->advert('p24', 'P24-130000001', ['address' => '13 Newport Road', 'agency_name' => 'Chas Everitt', 'agent_name' => 'Zandile Mokoena'], $tp);
        $this->advert('pp', 'PP-T4203603', ['address' => null], $tp);
        $this->advert('p24', 'P24-130000002', ['address' => '5 Elsewhere Street', 'suburb' => 'Uvongo Beach', 'agency_name' => 'RE/MAX']);
        $this->advert('p24', 'P24-130000003', ['address' => "O'Neil's Rest", 'agency_name' => '100% Realty_Group']);

        foreach (['Newport', 'ewport', 'NEWPORT', 'T4203603', '4203603', 'chas', 'everitt', 'Zandile', 'RE/MAX', 'uvongo beach', '13 newport', 'newport edward', 'port edward', "O'Neil", '100%', 'Realty_Group', 'elsewhere margate', 'nothing-here-zzz', 'P24-130000'] as $term) {
            $got = ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->searchResolved($term, $this->agency->id)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $this->assertSame($this->legacyIds($term), $got, "search \"{$term}\" must return what the old search returned");
        }
    }

    public function test_the_column_follows_every_kind_of_write_with_no_observer(): void
    {
        $a = $this->advert('p24', 'P24-130000010', ['address' => '1 Old Street']);
        $this->assertStringContainsString('1 old street', mb_strtolower((string) DB::table('prospecting_listings')->where('id', $a->id)->value('search_text')));
        DB::table('prospecting_listings')->where('id', $a->id)->update(['address' => '2 Raw Update Lane']); // a raw write, not Eloquent
        $this->assertStringContainsString('2 raw update lane', mb_strtolower((string) DB::table('prospecting_listings')->where('id', $a->id)->value('search_text')));
        $this->assertSame([(int) $a->id], ProspectingListing::withoutGlobalScopes()->searchResolved('raw update', $this->agency->id)->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame([], ProspectingListing::withoutGlobalScopes()->searchResolved('old street', $this->agency->id)->pluck('id')->all(), 'the old text is gone once the row changed');

        $long = $this->advert('p24', 'P24-130000011', ['address' => str_repeat('A', 255), 'agent_name' => str_repeat('B', 100), 'agency_name' => str_repeat('C', 100), 'suburb' => str_repeat('D', 100)]);
        $this->assertNotNull($long->id, 'the longest possible parts still fit the column');
    }

    public function test_the_work_page_search_still_works_end_to_end(): void
    {
        $admin = $this->capturer;
        $this->advert('p24', 'P24-130000020', ['address' => '7 Searchable Close']);
        $this->actingAs($admin)->get(route('market-intelligence.work', ['search' => 'searchable']))->assertOk()->assertSee('P24-130000020', false);
    }
}
