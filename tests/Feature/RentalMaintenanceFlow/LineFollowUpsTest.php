<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\User;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Support\RentalJobCardLineGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md section 17.43, follow-ups of 19 Oct 2026 (audit /root/rental-e2e-2026-10-19.md section 2):
 *  - the office "Tasks & lines" table fits a laptop (compact tracks, the low-value columns under the description);
 *  - C-E4: the office add carries a retry key - a double tap makes ONE line;
 *  - C-E5/G: a retried tick SETS the state instead of flipping it, and a crew add replayed after the cache window is still one line;
 *  - C-E3: the crew adding a part the office already has is asked "Already on the job card. Add anyway?".
 */
final class LineFollowUpsTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private User $user;
    private RentalJobCard $card;
    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Line Followups');
        $this->user = $this->agentHolding(['rental_job_cards.create', 'rental_job_cards.price', 'rental_job_cards.view_costs', 'rental_catalogue.view']);
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()]);
        $this->raw = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
    }

    private function crewAjax(array $data): TestResponse
    {
        return $this->call('POST', route('rentals.crew-job.lines.store', $this->raw), $data, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function officeAjax(array $data): TestResponse
    {
        return $this->actingAs($this->user)->call('POST', route('corex.rental-job-cards.lines.store', $this->card), $data, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function part(array $over = []): array
    {
        return $over + ['type' => 'part', 'description' => 'Basin trap', 'quantity' => 1, 'unit_cost' => '85.00'];
    }

    // ── C-E4: the office add has a retry key ──────────────────────────────────────────────

    public function test_a_double_tap_on_the_office_add_makes_one_line(): void
    {
        $key = 'office-key-1234-abcd';
        $first = $this->officeAjax(['description' => 'Washer', 'quantity' => 2, 'unit_cost' => '3.50', 'client_key' => $key])->assertOk()->assertJson(['ok' => true]);
        $again = $this->officeAjax(['description' => 'Washer', 'quantity' => 2, 'unit_cost' => '3.50', 'client_key' => $key])->assertOk()->assertJson(['ok' => true, 'message' => 'Line added.']);

        $this->assertSame($first->json('line_id'), $again->json('line_id'));
        $this->assertSame(1, RentalJobCardLine::where('description', 'Washer')->count());
        $this->assertSame($key, RentalJobCardLine::findOrFail($first->json('line_id'))->client_key);

        // another form (another key) is another line, and a request with no key at all still adds (older clients)
        $this->officeAjax(['description' => 'Washer', 'quantity' => 1, 'unit_cost' => '3.50', 'client_key' => 'office-key-5678-wxyz'])->assertOk();
        $this->officeAjax(['description' => 'Washer', 'quantity' => 1, 'unit_cost' => '3.50'])->assertOk();
        $this->assertSame(3, RentalJobCardLine::where('description', 'Washer')->count());
    }

    public function test_a_retried_plain_form_post_with_the_same_key_also_makes_one_line(): void
    {
        $data = ['description' => 'Gasket', 'quantity' => 1, 'unit_cost' => '9', 'client_key' => 'office-key-plain-0001'];
        $this->actingAs($this->user)->post(route('corex.rental-job-cards.lines.store', $this->card), $data)->assertRedirect();
        $this->actingAs($this->user)->post(route('corex.rental-job-cards.lines.store', $this->card), $data)->assertRedirect();

        $this->assertSame(1, RentalJobCardLine::where('description', 'Gasket')->count());
    }

    public function test_a_badly_shaped_key_is_refused_and_creates_nothing(): void
    {
        $this->officeAjax(['description' => 'Washer', 'quantity' => 1, 'client_key' => 'x'])->assertStatus(422);
        $this->assertSame(0, RentalJobCardLine::count());
    }

    public function test_every_office_add_form_carries_its_own_fresh_key(): void
    {
        $this->task();
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'name="client_key"'), 'the task-less General add row has one; each add row gets its own');
        preg_match_all('/name="client_key" value="([0-9a-f-]{36})"/', $html, $m);
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'every add form has its own key');
    }

    // ── C-E5/G: a crew add replayed later is still one line ───────────────────────────────

    public function test_a_crew_add_replayed_after_the_old_cache_window_is_still_one_line(): void
    {
        $key = 'crew-key-1234-abcd';
        $first = $this->crewAjax($this->part(['client_key' => $key]))->assertOk();
        Cache::flush(); // what the 60-minute cache key lost: expiry, or a cache:clear on a deploy
        $this->travel(3)->hours();
        $again = $this->crewAjax($this->part(['client_key' => $key]))->assertOk()->assertJson(['replayed' => true]);

        $this->assertSame($first->json('line.id'), $again->json('line.id'));
        $this->assertSame(1, RentalJobCardLine::count());
    }

    public function test_a_replayed_add_does_not_bring_back_a_line_the_crew_removed(): void
    {
        $key = 'crew-key-removed-001';
        $id = $this->crewAjax($this->part(['client_key' => $key]))->assertOk()->json('line.id');
        $this->call('POST', route('rentals.crew-job.lines.archive', [$this->raw, $id]), [], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])->assertOk();

        $this->crewAjax($this->part(['client_key' => $key]))->assertOk()->assertJson(['replayed' => true]);

        $this->assertSame(1, RentalJobCardLine::withTrashed()->count(), 'no second line');
        $this->assertSame(0, RentalJobCardLine::count(), 'and the removed one stays removed');
    }

    public function test_a_key_that_belongs_to_an_office_line_is_never_handed_to_the_crew_link(): void
    {
        $office = app(RentalJobCardService::class)->addLine($this->card, ['description' => 'Office part', 'type' => 'part', 'quantity' => 1, 'client_key' => 'shared-key-0000-0001'], $this->admin);

        $res = $this->crewAjax($this->part(['client_key' => 'shared-key-0000-0001', 'description' => 'Crew part']))->assertOk();

        $this->assertNotSame($office->id, $res->json('line.id'), 'the crew gets its own new line, not the office line');
        $this->assertSame('Crew part', $res->json('line.description'));
    }

    // ── C-E5: ticks set, they do not flip ─────────────────────────────────────────────────

    private function task(): RentalJobCardTask
    {
        return app(RentalJobCardService::class)->addTask($this->card, 'Fit the basin trap', $this->admin);
    }

    public function test_a_retried_crew_tick_does_not_flip_the_task_back(): void
    {
        $task = $this->task();
        $url = route('rentals.crew-job.tick', ['token' => $this->raw, 'task' => $task->id]);
        $hdr = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        $this->call('POST', $url, ['done' => 1], [], [], $hdr)->assertOk()->assertJson(['is_done' => true]);
        $this->call('POST', $url, ['done' => 1], [], [], $hdr)->assertOk()->assertJson(['is_done' => true]);
        $this->assertTrue($task->fresh()->is_done, 'the second, retried tick left it ticked');
        $this->assertSame(1, $this->card->updates()->where('update_type', 'task_ticked')->count(), 'and wrote one history line, not two');

        $this->call('POST', $url, ['done' => 0], [], [], $hdr)->assertOk()->assertJson(['is_done' => false]);
        $this->call('POST', $url, ['done' => 0], [], [], $hdr)->assertOk()->assertJson(['is_done' => false]);
        $this->assertFalse($task->fresh()->is_done);
    }

    public function test_a_tick_with_no_state_still_flips_as_older_clients_expect(): void
    {
        $task = $this->task();
        $url = route('rentals.crew-job.tick', ['token' => $this->raw, 'task' => $task->id]);
        $hdr = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        $this->call('POST', $url, [], [], [], $hdr)->assertOk()->assertJson(['is_done' => true]);
        $this->call('POST', $url, [], [], [], $hdr)->assertOk()->assertJson(['is_done' => false]);
    }

    public function test_the_offices_toggle_and_the_mobile_tick_set_the_state_too(): void
    {
        $task = $this->task();

        $this->actingAs($this->user)->post(route('corex.rental-job-cards.tasks.toggle', [$this->card, $task]), ['done' => 1])->assertRedirect();
        $this->actingAs($this->user)->post(route('corex.rental-job-cards.tasks.toggle', [$this->card, $task]), ['done' => 1])->assertRedirect();
        $this->assertTrue($task->fresh()->is_done, 'a second "tick it" does not untick it');

        $this->actingAs($this->user)->post(route('corex.rental-job-cards.tasks.toggle', [$this->card, $task]), ['done' => 0])->assertRedirect();
        $this->assertFalse($task->fresh()->is_done);

        app(RentalJobCardService::class)->toggleTask($this->card, $task, $this->user, true);
        app(RentalJobCardService::class)->toggleTask($this->card, $task, $this->user, true);
        $this->assertTrue($task->fresh()->is_done);
        app(RentalJobCardService::class)->toggleTask($this->card, $task->fresh(), $this->user);
        $this->assertFalse($task->fresh()->is_done, 'no state given: it flips, as before');
    }

    public function test_the_tick_forms_ask_for_the_state_they_want(): void
    {
        $task = $this->task();

        $crew = $this->get(route('rentals.crew-job.show', $this->raw))->assertOk()->getContent();
        $this->assertStringContainsString('name="done" value="1"', $crew, 'an unticked task asks for ticked');
        $task->forceFill(['is_done' => true])->save();
        $this->assertStringContainsString('name="done" value="0"', $this->get(route('rentals.crew-job.show', $this->raw))->getContent());

        $office = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringContainsString('name="done" value="0"', $office);
    }

    // ── C-E3: the crew adds a part the office already has ─────────────────────────────────

    public function test_adding_a_part_the_office_already_has_is_asked_first_and_adds_nothing(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);
        $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'quantity' => 2]);

        $res = $this->crewAjax(['rental_catalogue_item_id' => $item->id, 'quantity' => 1])
            ->assertStatus(409)->assertJson(['ok' => false, 'duplicate' => true, 'message' => 'Already on the job card. Add anyway?']);
        $this->assertSame('ELEM item', $res->json('existing.description'));
        $this->assertSame('2', $res->json('existing.quantity'));
        $this->assertSame(0, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count());
    }

    public function test_add_anyway_goes_ahead_and_a_retry_of_that_is_still_one_line(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);
        $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id]);

        $body = ['rental_catalogue_item_id' => $item->id, 'quantity' => 1, 'add_anyway' => 1, 'client_key' => 'anyway-key-0000-01'];
        $this->crewAjax($body)->assertOk()->assertJson(['ok' => true]);
        $this->crewAjax($body)->assertOk()->assertJson(['replayed' => true]);

        $this->assertSame(1, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count());
    }

    public function test_free_typed_words_that_match_an_office_part_are_asked_about_and_other_words_are_not(): void
    {
        $this->officeLine($this->card, ['description' => 'Basin trap', 'type' => 'part']);

        $this->crewAjax($this->part(['description' => '  basin TRAP ']))->assertStatus(409)->assertJson(['duplicate' => true]);
        $this->crewAjax($this->part(['description' => 'Basin waste']))->assertOk();
    }

    public function test_labour_the_crews_own_drafts_and_unaccepted_lines_are_never_flagged(): void
    {
        $labour = $this->catalogueItem('HOUR', 'labour', 100.00, 80.00);
        $this->officeLine($this->card, ['rental_catalogue_item_id' => $labour->id, 'type' => 'labour', 'quantity' => 1]);
        $this->crewAjax(['rental_catalogue_item_id' => $labour->id, 'quantity' => 2])->assertOk();

        // the crew's own earlier draft of the same words is not "the office already has it"
        $this->crewAjax($this->part(['description' => 'Tap washer']))->assertOk();
        $this->crewAjax($this->part(['description' => 'Tap washer']))->assertOk();
        $this->assertSame(3, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count());
    }

    public function test_an_archived_office_part_is_not_a_duplicate(): void
    {
        $line = $this->officeLine($this->card, ['description' => 'Basin trap', 'type' => 'part']);
        $line->delete();

        $this->crewAjax($this->part())->assertOk();
    }

    public function test_a_plain_form_post_cannot_be_asked_so_it_saves_and_says_so(): void
    {
        $this->officeLine($this->card, ['description' => 'Basin trap', 'type' => 'part']);

        $this->post(route('rentals.crew-job.lines.store', $this->raw), $this->part())->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'the office already has "Basin trap"'));
        $this->assertSame(1, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count());
    }

    public function test_the_crew_page_asks_the_same_question(): void
    {
        $this->officeLine($this->card, ['description' => 'Basin trap', 'type' => 'part']);
        $crewPageRaw = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];

        $this->call('POST', route('rentals.crew-page.lines.store', [$crewPageRaw, $this->card->id]), $this->part(), [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertStatus(409)->assertJson(['duplicate' => true]);
    }

    // ── the table fits a laptop ───────────────────────────────────────────────────────────

    public function test_the_lines_table_has_five_money_and_text_columns_and_puts_the_rest_under_the_description(): void
    {
        $item = $this->catalogueItem('ELEM', 'part', 600.00, 350.00);
        $this->officeLine($this->card, ['rental_catalogue_item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 350]);

        $this->assertSame(['Description', 'Qty', 'Cost', 'Selling', 'Total', ''], RentalJobCardLineGrid::labels(true, true, true));
        $this->assertSame(['Description', 'Qty', 'Unit price', 'Total', ''], RentalJobCardLineGrid::labels(true, false, false));
        $this->assertSame(['Description', ''], RentalJobCardLineGrid::labels(false, false, false));
        $this->assertCount(6, RentalJobCardLineGrid::columns(true, true, true));

        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringNotContainsString('min-width: 980px', $html);
        $this->assertStringContainsString('data-line-total', $html, 'the line total is on the row');
        $this->assertStringContainsString('R1,200.00', $html, 'qty 2 x the 600 item price');
        preg_match('/data-line-sub>(.*?)<\/span>\s*<\/span>/s', $html, $m);
        $sub = preg_replace('/\s+/', ' ', strip_tags($m[1] ?? ''));
        foreach (['ELEM', 'Part', 'Each', 'Margin'] as $bit) {
            $this->assertStringContainsString($bit, $sub, "{$bit} sits in the line under the description");
        }
        // the old per-column cells are gone from the saved row: no Item / Type / Unit / Margin header
        $head = substr($html, (int) strpos($html, 'class="jc-lines-head"'), 1800);
        foreach (['>Item<', '>Type<', '>Unit<', '>VAT<', '>Margin<'] as $gone) {
            $this->assertStringNotContainsString($gone, $head);
        }
        $this->assertStringContainsString('>Total<', $head);
    }

    public function test_the_add_row_has_a_pick_line_and_a_main_line_on_the_same_tracks_as_the_header(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();

        $this->assertStringContainsString('data-line-pick', $html);
        $this->assertStringContainsString('data-line-main', $html);
        $this->assertStringContainsString(RentalJobCardLineGrid::pickGridStyle(true, false), $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, RentalJobCardLineGrid::gridStyle(true, false, true)), 'header + add row main line share the tracks');
        $this->assertSame(['minmax(130px,1fr)', '104px', '84px', '104px'], RentalJobCardLineGrid::pickColumns(true, true));
    }
}
