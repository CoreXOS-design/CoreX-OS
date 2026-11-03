<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Services\Prospecting\MicCaptureBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * MIC Adverts view, capture (spec 13.6 / 11.2.4): `mic:capture-backfill card-text` reads the heading and wording off stored P24 results
 * pages (and the saved real pages shipped with the extension), fills only what is empty, journals every row, and leaves the excluded
 * test agency alone. Dry run counts only.
 */
final class MicCardTextBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $test;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->test = Agency::create(['name' => 'Test agency', 'slug' => 'test-agency']);
        $branch = \App\Models\Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->userId = \App\Models\User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id])->id;
        Storage::fake('local');
    }

    private function listing(Agency $agency, string $ref, array $over = []): void
    {
        $now = now();
        DB::table('prospecting_listings')->insert($over + [
            'agency_id' => $agency->id, 'captured_by_user_id' => $this->userId, 'portal_source' => 'p24', 'portal_ref' => $ref, 'suburb' => 'Palm Beach', 'is_active' => 1,
            'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_the_johan_proof_page_cards_are_parsed_with_their_sole_mandate_wording(): void
    {
        $html = file_get_contents(base_path('public/chrome-extension/portal-capture/tests/fixtures/p24-search/hibiscus-coast-p7-sole-mandate-cards.html'));
        $cards = MicCaptureBackfill::parseCardText($html);
        $this->assertSame('3 Bedroom House', $cards['117752073']['heading']);
        $this->assertStringStartsWith('Sole mandate - top quality', $cards['117752073']['snippet']);
        $this->assertStringStartsWith('Sole Mandate...', $cards['117061095']['snippet']);
        $this->assertGreaterThanOrEqual(14, count($cards));
    }

    public function test_dry_run_counts_only_apply_fills_the_empty_ones_and_journals_and_a_second_apply_changes_nothing(): void
    {
        $this->listing($this->a, 'P24-117752073');
        $this->listing($this->a, 'P24-117061095', ['description_snippet' => 'An agent wrote this earlier']);
        $this->listing($this->a, 'P24-117728645', ['is_active' => 0]);
        $this->listing($this->test, 'P24-117752073');
        $this->listing($this->a, 'P24-999999999');
        $b = new MicCaptureBackfill();

        $dry = $b->cardText(false, [$this->test->id]);
        $this->assertSame(0, DB::table('prospecting_listings')->whereNotNull('description_snippet')->count() - 1, 'dry run wrote nothing');
        $this->assertGreaterThanOrEqual(1, $dry['fixtures_read']);
        $this->assertSame(3, $dry['adverts_matched']);
        $this->assertSame(2, $dry['adverts_getting_snippet']);
        $this->assertSame(1, $dry['adverts_already_have_snippet']);
        $this->assertSame(1, $dry['live_adverts_getting_snippet']);
        $this->assertGreaterThanOrEqual(2, $dry['with_sole_mandate_wording']);

        $journal = sys_get_temp_dir() . '/mic-cardtext-' . uniqid() . '.jsonl';
        $b->journalTo($journal);
        $run = $b->cardText(true, [$this->test->id]);
        $b->journalTo(null);
        $this->assertSame(2, $run['adverts_getting_snippet']);
        $row = DB::table('prospecting_listings')->where('agency_id', $this->a->id)->where('portal_ref', 'P24-117752073')->first();
        $this->assertStringStartsWith('Sole mandate - top quality', $row->description_snippet);
        $this->assertSame('3 Bedroom House', $row->card_heading);
        $this->assertSame('An agent wrote this earlier', DB::table('prospecting_listings')->where('portal_ref', 'P24-117061095')->value('description_snippet'), 'never overwrites');
        $this->assertNull(DB::table('prospecting_listings')->where('agency_id', $this->test->id)->value('description_snippet'), 'the test agency is left alone');
        $this->assertGreaterThanOrEqual(4, count(file($journal)));

        $again = $b->cardText(true, [$this->test->id]);
        $this->assertSame(0, $again['adverts_getting_snippet']);
        @unlink($journal);
    }
}
