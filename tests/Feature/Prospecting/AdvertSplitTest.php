<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\Matching\MatchExclusions;
use App\Services\Prospecting\Matching\MergeGuard;
use App\Services\Prospecting\Matching\PortalGroupLinker;
use App\Services\Prospecting\Matching\ReviewPairs;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.12: "Not the same property" on the "Listed by N agencies" popover - the advert leaves its file for a new one, the claim stays, the split is
 * remembered (no rule puts it back), journalled, and can be undone.
 */
final class AdvertSplitTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private TrackedProperty $file;
    private ProspectingListing $a;
    private ProspectingListing $b;
    private string $dir;
    private int $n = 117300000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        MatchExclusions::flush();
        $this->dir = sys_get_temp_dir() . '/advert-split-' . uniqid();
        config(['prospecting.split_journal_dir' => $this->dir]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true, 'name' => 'Sam Agent']);
        $this->file = $this->file('4', 'Emtunzi Drive');
        $this->a = $this->advert($this->file, 'Apartment', 2, 950_000, ['agency_name' => 'Agency One']);
        $this->b = $this->advert($this->file, 'Apartment', 2, 970_000, ['agency_name' => 'Agency Two']);
        // the claim sits on the file
        DB::table('tracked_property_agency_work')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $this->file->id, 'claimed_by_user_id' => $this->admin->id,
            'prospecting_status' => 'claimed', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        MatchExclusions::flush();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function file(string $number, string $street, ?int $agencyId = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $agencyId ?? $this->agency->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_number' => $number, 'street_name' => $street, 'suburb' => 'Uvongo']);
    }

    private function advert(?TrackedProperty $tp, string $type, int $beds, int $price, array $extra = [], ?int $agencyId = null): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(['agency_id' => $agencyId ?? $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp?->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'address' => '4 Emtunzi Drive', 'suburb' => 'Uvongo', 'price' => $price, 'bedrooms' => $beds, 'bathrooms' => 2, 'property_type' => $type,
            'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'])->forceFill($extra)->save();

        return $l->fresh();
    }

    private function fileOf(ProspectingListing $l): int
    {
        return (int) DB::table('prospecting_listings')->where('id', $l->id)->value('tracked_property_id');
    }

    private function groupTogether(ProspectingListing ...$ls): void
    {
        foreach ($ls as $l) {
            DB::table('p24_group_members')->insert(['p24_group_id' => '1850999', 'listing_number' => (int) substr((string) $l->portal_ref, 4), 'group_size' => count($ls), 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function split(ProspectingListing $l, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson(route('market-intelligence.adverts.split', $l->id));
    }

    public function test_split_moves_the_advert_to_a_new_file_and_leaves_the_claim_where_it_was(): void
    {
        $r = $this->split($this->b)->assertOk()->assertJson(['ok' => true, 'from_file_id' => $this->file->id, 'adverts_left_on_file' => 1]);
        $new = (int) $r->json('new_file_id');

        $this->assertNotSame((int) $this->file->id, $new);
        $this->assertSame($new, $this->fileOf($this->b), 'the advert is on the new file');
        $this->assertSame((int) $this->file->id, $this->fileOf($this->a), 'the other advert stays');
        $this->assertSame((int) $this->admin->id, (int) DB::table('tracked_property_agency_work')->where('tracked_property_id', $this->file->id)->value('claimed_by_user_id'), 'the claim stays with the original file');
        $this->assertSame(0, DB::table('tracked_property_agency_work')->where('tracked_property_id', $new)->whereNotNull('claimed_by_user_id')->count(), 'the new file is unclaimed');
        $this->assertNotEmpty($r->json('new_file_address'));
        $this->assertSame(1, DB::table('mic_match_exclusions')->where('id', $r->json('split_id'))->whereNull('undone_at')->count());
    }

    public function test_the_popover_count_drops_and_the_rows_say_who_split_what(): void
    {
        $this->actingAs($this->admin);
        $before = app(MicWorkList::class)->forFiles([(int) $this->file->id], (int) $this->agency->id)[0];
        $this->assertCount(2, $before['adverts']);
        $this->assertTrue($before['adverts'][0]['can_split']);

        $newId = (int) $this->split($this->b)->json('new_file_id');
        $rows = app(MicWorkList::class)->forFiles([(int) $this->file->id, $newId], (int) $this->agency->id);
        $old = collect($rows)->first(fn ($r) => collect($r['adverts'])->pluck('id')->contains($this->a->id));
        $new = collect($rows)->first(fn ($r) => collect($r['adverts'])->pluck('id')->contains($this->b->id));

        $this->assertCount(1, $old['adverts']);
        $this->assertFalse($old['adverts'][0]['can_split'], 'alone on its file: nothing to split');
        $this->assertCount(1, $new['adverts']);
        $this->assertStringContainsString('Advert split off to', $old['file_notes'][0]['text']);
        $this->assertStringContainsString('by Sam Agent', $old['file_notes'][0]['text']);
        $this->assertStringContainsString('Split from', $new['file_notes'][0]['text']);
    }

    public function test_every_split_is_journalled(): void
    {
        $id = (int) $this->split($this->b)->json('split_id');
        $files = glob($this->dir . '/advert-split-agency' . $this->agency->id . '-journal-*.jsonl');
        $this->assertCount(1, $files);
        $line = json_decode(trim(file($files[0])[0]), true);
        $this->assertSame('split', $line['event']);
        $this->assertSame($id, $line['split_id']);
        $this->assertSame((int) $this->admin->id, $line['user_id']);
        $this->assertSame((int) $this->file->id, $line['from_file_id']);
        $this->assertNotEmpty($line['moves']);
        $this->assertSame($this->b->portal_ref, $line['portal_ref']);
    }

    public function test_an_advert_alone_on_its_file_cannot_be_split(): void
    {
        $this->split($this->b)->assertOk();
        $this->split($this->a)->assertStatus(409)->assertJson(['ok' => false])->assertJsonStructure(['message']);
        $this->split($this->b)->assertStatus(409);
    }

    public function test_another_agencys_advert_is_403_and_an_unknown_one_404(): void
    {
        $other = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        $this->split($this->b, $stranger)->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertSame((int) $this->file->id, $this->fileOf($this->b), 'nothing moved');
        $this->actingAs($this->admin)->postJson(route('market-intelligence.adverts.split', 99999999))->assertStatus(404)->assertJson(['ok' => false]);
        $id = (int) $this->split($this->b)->json('split_id');
        $this->actingAs($stranger)->postJson(route('market-intelligence.adverts.split-undo', $id))->assertStatus(403);
    }

    public function test_undo_puts_the_advert_back_and_lifts_the_exclusion(): void
    {
        $r = $this->split($this->b)->assertOk();
        $new = (int) $r->json('new_file_id');

        $this->actingAs($this->admin)->postJson(route('market-intelligence.adverts.split-undo', $r->json('split_id')))->assertOk()->assertJson(['ok' => true, 'file_id' => $this->file->id]);

        $this->assertSame((int) $this->file->id, $this->fileOf($this->b));
        $this->assertNotNull(DB::table('mic_match_exclusions')->where('id', $r->json('split_id'))->value('undone_at'));
        $this->assertSame($this->file->id, (int) DB::table('tracked_properties')->where('id', $new)->value('duplicate_of_tracked_property_id'), 'the empty split-off file is closed, not deleted');
        $this->actingAs($this->admin)->postJson(route('market-intelligence.adverts.split-undo', $r->json('split_id')))->assertStatus(409);
        $this->assertNull((new MatchExclusions())->advertConflict((int) $this->agency->id, (int) $this->file->id, [$this->b->canonical_ref]), 'undone: no longer remembered');
    }

    public function test_the_exclusion_stops_the_group_rule_from_joining_it_back_and_undo_lets_it(): void
    {
        $this->groupTogether($this->a, $this->b);
        $id = (int) $this->split($this->b)->json('split_id');
        $new = $this->fileOf($this->b);

        $r = app(PortalGroupLinker::class)->run((int) $this->agency->id, true);

        $this->assertSame($new, $this->fileOf($this->b), 'the group rule does not put it back');
        $this->assertSame((int) $this->file->id, $this->fileOf($this->a));
        $this->assertSame(0, $r['adverts_moved']);
        $this->assertSame(0, DB::table('property_match_decisions')->where('strategy', 'portal_group')->count(), 'and nothing is queued for review either');

        // lift the exclusion (an undo would also put the advert back, so mark it undone by hand): the same run now joins them
        DB::table('mic_match_exclusions')->where('id', $id)->update(['undone_at' => now()]);
        MatchExclusions::flush();
        $again = app(PortalGroupLinker::class)->run((int) $this->agency->id, true);
        $this->assertSame(1, $again['adverts_moved'], 'without the exclusion the same rule would have joined them');
    }

    public function test_the_guard_the_review_list_and_the_automatic_merge_all_honour_it(): void
    {
        $new = (int) $this->split($this->b)->json('new_file_id');
        $guard = app(MergeGuard::class);

        $this->assertStringContainsString('not the same property', (string) $guard->conflictWithFile((int) $this->agency->id, (int) $this->file->id, [(object) ['ref' => $this->b->canonical_ref]]), 'the advert never joins the file it was split from');
        $this->assertNotNull($guard->conflictWithFile((int) $this->agency->id, $new, [(object) ['ref' => $this->a->canonical_ref]]), 'and an advert from the old file never joins the new one (other direction)');
        $this->assertNotNull($guard->fileVsFile((int) $this->agency->id, (int) $this->file->id, $new), 'the two files are never auto-merged');
        $this->assertTrue(app(ReviewPairs::class)->vetoed((int) $this->agency->id, (int) $this->file->id, $new), 'and are never offered as a possible match');

        $merger = app(TrackedPropertyMerger::class);
        $old = TrackedProperty::queryWithoutAgencyScope()->find($this->file->id);
        $newFile = TrackedProperty::queryWithoutAgencyScope()->find($new);
        $res = $merger->merge($old, $newFile, true);
        $this->assertFalse($res['merged']);
        $this->assertStringContainsString('split these two apart', $res['reason']);
        $this->assertSame($new, $this->fileOf($this->b));
    }

    public function test_a_crawl_that_sees_the_advert_again_leaves_it_on_the_new_file(): void
    {
        $new = (int) $this->split($this->b)->json('new_file_id');
        // the crawl re-sees the advert: the same listing row is refreshed, never re-matched to the old file
        $this->b->forceFill(['last_seen_at' => now(), 'price' => 975_000])->save();

        $this->assertSame($new, $this->fileOf($this->b));
        $this->assertStringContainsString('not the same property', (string) app(MergeGuard::class)->conflictWithFile((int) $this->agency->id, (int) $this->file->id, [(object) ['ref' => $this->b->canonical_ref, 'ptype' => 'Apartment']]));
    }

    public function test_the_artisan_command_undoes_a_split_from_its_journal(): void
    {
        $id = (int) $this->split($this->b)->json('split_id');
        $journal = (string) DB::table('mic_match_exclusions')->where('id', $id)->value('journal');
        $this->assertFileExists($journal);

        $this->artisan('mic:advert-split', ['--undo' => $journal])->assertSuccessful();
        $this->assertNotSame($this->file->id, $this->fileOf($this->b), 'a dry run changes nothing');

        $this->artisan('mic:advert-split', ['--undo' => $journal, '--split' => $id, '--apply' => true])->assertSuccessful();
        $this->assertSame((int) $this->file->id, $this->fileOf($this->b));
        $this->assertNotNull(DB::table('mic_match_exclusions')->where('id', $id)->value('undone_at'));
    }
}
