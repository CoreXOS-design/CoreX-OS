<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Services\Rentals\RentalMailDispatcher;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalJobCardVatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.21 (2026-10-05 evening, Johan's
 * rulings): every form on the saved card keeps the panel position; a
 * completed/cancelled card is locked (UI hidden AND server refuses, direct
 * POSTs included); a card whose quote was sent stays editable and the quote
 * can be re-sent as the next revision (previous kept + superseded, VAT of a
 * post-send line frozen so totals are right, acceptance does not carry
 * over); the printouts show the VAT AMOUNT, not the VAT type.
 */
final class RentalJobCardFollowUpsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private Contact $landlord;
    private RentalJobCardService $service;
    private RentalJobCardVatService $vat;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Follow Up Agency', 'slug' => 'follow-up-' . uniqid()]);
        $this->agency->update(['vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Follow Up Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Jane', 'last_name' => 'Landlord', 'email' => 'can.assurance@gmail.com',
        ]);
        ContactPropertyLinker::link($this->landlord->id, $this->property->id, 'landlord');

        RentalWorkOrderSetting::create([
            'agency_id' => $this->agency->id, 'capture_prices_on_job_cards' => true,
            'show_costs_on_printed_job_card' => true, 'no_approval_spend_threshold' => 100000,
        ]);
        PerformanceSetting::set('vat_rate', '15', $this->agency->id);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalCatalogueItemType::seedDefaultsFor($this->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($this->agency->id);
        $this->service = app(RentalJobCardService::class);
        $this->vat = app(RentalJobCardVatService::class);
    }

    private function standardVat(): RentalVatType
    {
        return RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_AGENCY_RATE)->firstOrFail();
    }

    private function card(): RentalJobCard
    {
        return $this->service->createForProperty($this->property, ['title' => 'Follow up job'], $this->admin);
    }

    private function line(RentalJobCard $card, string $desc, float $price, $task = null): RentalJobCardLine
    {
        return $this->service->addLine($card, ['description' => $desc, 'quantity' => 1, 'unit_price' => $price, 'rental_vat_type_id' => $this->standardVat()->id], $this->admin, $task);
    }

    private function close(RentalJobCard $card, string $status): void
    {
        $card->forceFill(['status' => $status])->save();
    }

    private function show(RentalJobCard $card): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
    }

    // ── 1. Every form keeps the panel position ───────────────────────────

    public function test_every_form_on_a_saved_card_asks_the_screen_to_keep_its_scroll_position(): void
    {
        $card = $this->card();
        $this->service->addTask($card, 'Fix tap', $this->admin);
        $card->assignCrew(\App\Models\RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Crew', 'created_by_user_id' => $this->admin->id]), $this->admin);
        $this->close($card, RentalJobCard::STATUS_SCHEDULED);

        $html = $this->show($card);

        // Every <form> on a saved card carries data-keep-scroll.
        preg_match_all('/<form\b[^>]*>/i', $html, $forms);
        $this->assertNotEmpty($forms[0]);
        $missing = array_values(array_filter($forms[0], fn (string $tag) => ! str_contains($tag, 'data-keep-scroll')
            // The sidebar/layout carries its own non-card forms (logout, search…) — only card actions matter.
            && str_contains($tag, '/rental-job-cards/')));
        $this->assertSame([], $missing, 'A card form without data-keep-scroll jumps the panel back to the top.');

        // The tick checkbox itself must submit (it used to cancel its own click, so only the padding around it worked).
        $this->assertStringContainsString('onclick="event.preventDefault(); this.form.requestSubmit();"', $html);
        $this->assertStringNotContainsString('onclick="return false;"', $html);

        foreach (['/tasks"', '/assign-crew', '/schedule', '/start', '/worker-sign-off', '/agent-sign-off', '/tenant-confirm'] as $needle) {
            $this->assertMatchesRegularExpression('/<form\b[^>]*data-keep-scroll[^>]*' . preg_quote($needle, '/') . '/', $html, "{$needle} form must keep scroll");
        }
    }

    public function test_task_actions_flash_the_task_so_the_screen_can_bring_it_into_view(): void
    {
        $card = $this->card();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Paint lounge'])
            ->assertSessionHas('jc_focus_task');
        $task = $card->tasks()->firstOrFail();
        $this->actingAs($this->admin)->put(route('corex.rental-job-cards.tasks.update', [$card, $task]), ['description' => 'Paint den'])
            ->assertSessionHas('jc_focus_task', $task->id);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.tasks.toggle', [$card, $task]))
            ->assertSessionHas('jc_focus_task', $task->id);
    }

    // ── 2. Closed card: locked in the UI and on the server ───────────────

    public static function closedStatuses(): array
    {
        return ['completed' => [RentalJobCard::STATUS_COMPLETED], 'cancelled' => [RentalJobCard::STATUS_CANCELLED]];
    }

    /** @dataProvider closedStatuses */
    public function test_closed_card_refuses_every_line_and_task_change_and_changes_nothing(string $status): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $archivedTask = $this->service->addTask($card, 'Old task', $this->admin);
        $this->service->archiveTask($card, $archivedTask, $this->admin);
        $line = $this->line($card, 'Washer', 100, $task);
        $archivedLine = $this->line($card, 'Old line', 50);
        $this->service->archiveLine($card, $archivedLine, $this->admin);
        $this->close($card, $status);
        $totalBefore = (string) $card->fresh()->total_amount;
        $counts = fn () => [
            RentalJobCardLine::withTrashed()->count(),
            RentalJobCardLine::onlyTrashed()->count(),
            \App\Models\RentalJobCardTask::withTrashed()->count(),
            \App\Models\RentalJobCardTask::onlyTrashed()->count(),
        ];
        $before = $counts();

        $a = $this->actingAs($this->admin);
        $a->post(route('corex.rental-job-cards.lines.store', $card), ['description' => 'Sneaky', 'quantity' => 1, 'unit_price' => 5])->assertSessionHasErrors('rental_job_card');
        $a->put(route('corex.rental-job-cards.lines.update', [$card, $line]), ['description' => 'Changed', 'quantity' => 9, 'unit_price' => 9])->assertSessionHasErrors('rental_job_card');
        $a->delete(route('corex.rental-job-cards.lines.destroy', [$card, $line]))->assertSessionHasErrors('rental_job_card');
        $a->post(route('corex.rental-job-cards.lines.restore', [$card, $archivedLine->id]))->assertSessionHasErrors('rental_job_card');
        $a->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Sneaky task'])->assertSessionHasErrors('rental_job_card');
        $a->put(route('corex.rental-job-cards.tasks.update', [$card, $task]), ['description' => 'Renamed'])->assertSessionHasErrors('rental_job_card');
        $a->post(route('corex.rental-job-cards.tasks.toggle', [$card, $task]))->assertSessionHasErrors('rental_job_card');
        $a->delete(route('corex.rental-job-cards.tasks.destroy', [$card, $task]))->assertSessionHasErrors('rental_job_card');
        $a->post(route('corex.rental-job-cards.tasks.restore', [$card, $archivedTask->id]))->assertSessionHasErrors('rental_job_card');

        $this->assertSame($before, $counts(), 'No line or task was added, archived or restored.');
        $this->assertSame('Washer', $line->fresh()->description);
        $this->assertFalse((bool) $task->fresh()->is_done);
        $this->assertSame('Fix tap', $task->fresh()->description);
        $this->assertSame($totalBefore, (string) $card->fresh()->total_amount);

        // The message is the clear one.
        $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $card))
            ->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'x'])
            ->assertSessionHasErrors(['rental_job_card' => 'This job card is closed — its lines and tasks can no longer be changed.']);
    }

    /** @dataProvider closedStatuses */
    public function test_the_service_itself_refuses_on_a_closed_card_not_only_the_controller(string $status): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $line = $this->line($card, 'Washer', 100, $task);
        $this->close($card, $status);

        foreach ([
            fn () => $this->service->addTask($card, 'x', $this->admin),
            fn () => $this->service->renameTask($card, $task, 'x', $this->admin),
            fn () => $this->service->toggleTask($card, $task, $this->admin),
            fn () => $this->service->reorderTasks($card, [$task->id], $this->admin),
            fn () => $this->service->archiveTask($card, $task, $this->admin),
            fn () => $this->service->addLine($card, ['description' => 'x'], $this->admin),
            fn () => $this->service->updateLine($card, $line, ['description' => 'x'], $this->admin),
            fn () => $this->service->archiveLine($card, $line, $this->admin),
        ] as $i => $call) {
            try {
                $call();
                $this->fail("service call #{$i} should have been refused on a {$status} card");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('closed', $e->getMessage());
            }
        }
        $this->assertNull($line->fresh()->deleted_at);
    }

    public function test_closed_card_screen_hides_every_add_edit_archive_control(): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $line = $this->line($card, 'Washer', 100, $task);
        $archived = $this->line($card, 'Old line', 50);
        $this->service->archiveLine($card, $archived, $this->admin);

        $open = $this->show($card);
        $this->assertStringContainsString('aria-label="Edit line"', $open);
        $this->assertStringContainsString('placeholder="Add a task"', $open);
        $this->assertStringContainsString('aria-label="Archive"', $open);
        $this->assertStringContainsString('/lines/' . $archived->id . '/restore', $open);
        $this->assertStringContainsString('/tasks/' . $task->id . '/toggle', $open);
        $this->assertStringContainsString('>Rename<', $open);

        $this->close($card, RentalJobCard::STATUS_COMPLETED);
        $closed = $this->show($card);
        $this->assertStringNotContainsString('aria-label="Edit line"', $closed);
        $this->assertStringNotContainsString('aria-label="Archive"', $closed);
        $this->assertStringNotContainsString('placeholder="Add a task"', $closed);
        $this->assertStringNotContainsString('>Rename<', $closed);
        $this->assertStringNotContainsString('/lines/' . $archived->id . '/restore', $closed);
        $this->assertStringNotContainsString('/tasks/' . $task->id . '/toggle', $closed);
        $this->assertStringNotContainsString('/tasks/' . $task->id . '"', $closed, 'No rename/archive task forms.');
        $this->assertStringNotContainsString(route('corex.rental-job-cards.lines.store', $card), $closed, 'No add-line row on a closed card.');
        $this->assertStringContainsString('Washer', $closed, 'The closed card still READS fine.');
    }

    public function test_mobile_tick_on_a_closed_card_is_a_clean_422_not_a_500(): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);

        $this->actingAs($this->admin)->postJson(route('v1.mobile.rental-job-cards.tasks.tick', [$card, $task]))->assertOk();
        $this->assertTrue((bool) $task->fresh()->is_done);

        $this->close($card, RentalJobCard::STATUS_COMPLETED);
        $this->actingAs($this->admin)->postJson(route('v1.mobile.rental-job-cards.tasks.tick', [$card, $task]))
            ->assertStatus(422)->assertJsonPath('message', 'This job card is closed — its lines and tasks can no longer be changed.');
        $this->assertTrue((bool) $task->fresh()->is_done, 'Unchanged.');
    }

    // ── 3. A sent quote stays editable; re-send = next revision ──────────

    public function test_a_line_added_after_the_quote_was_sent_gets_its_vat_snapshot_and_is_in_the_totals(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $card->refresh();
        $this->assertNotNull($card->vat_snapshotted_at);
        $this->assertEquals(115.0, $this->vat->breakdown($card->load('lines'))['totalIncl']);

        $late = $this->line($card, 'Added after sending', 200);

        $this->assertTrue($late->fresh()->isVatSnapshotted(), 'The new line is frozen like any other.');
        $this->assertEquals(230.0, (float) $late->fresh()->vat_incl_snapshot);
        $breakdown = $this->vat->breakdown($card->fresh()->load('lines'));
        $this->assertEquals(345.0, $breakdown['totalIncl'], 'The frozen totals include the post-send line (was: silently skipped).');
        $this->assertEquals(45.0, $breakdown['totalVat']);
    }

    public function test_totals_never_silently_skip_an_unfrozen_line_on_a_frozen_card(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $card->refresh();

        // A line that reached the card by some path that did not freeze it.
        $raw = $card->lines()->create([
            'agency_id' => $this->agency->id, 'description' => 'Unfrozen', 'type' => 'labour', 'quantity' => 1,
            'unit_price' => 200, 'line_total' => 200, 'rental_vat_type_id' => $this->standardVat()->id, 'sort_order' => 99,
        ]);
        $this->assertFalse($raw->isVatSnapshotted());

        $breakdown = $this->vat->breakdown($card->fresh()->load('lines'));
        $this->assertEquals(345.0, $breakdown['totalIncl'], 'Computed live in the frozen mode instead of being dropped.');
        $this->assertArrayHasKey($raw->id, $breakdown['lineFigures']);
    }

    /** BUILD 2 (§17.16) — owner mails go through RentalMailDispatcher (the agency mailbox path), never a plain Mailable. */
    private function fakeDispatcher(): object
    {
        $fake = new class extends RentalMailDispatcher {
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $fake);

        return $fake;
    }

    /**
     * §14.21 is the rule for a quote the owner has NOT approved yet (BUILD 2, §17.7.1: once an amount is approved — by the owner or the
     * no-approval limit — extra work goes through the variation path instead). The limit is set low so these quotes are genuinely pending.
     */
    private function lowerTheLimit(): void
    {
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['no_approval_spend_threshold' => 50]);
    }

    public function test_first_send_is_rev_1_and_resend_creates_rev_2_superseding_rev_1_without_deleting_it(): void
    {
        Mail::fake();
        $this->lowerTheLimit();
        $card = $this->card();
        $this->line($card, 'One', 100);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect()->assertSessionHas('success', 'Quote sent to the owner.');
        $rev1 = $card->quoteRevisions()->firstOrFail();
        $this->assertSame(1, $rev1->revision);
        $this->assertEquals(115.0, (float) $rev1->amount);
        $this->assertNull($rev1->superseded_at);
        $this->assertTrue((bool) $rev1->is_selected);
        $this->assertFalse($card->fresh()->quoteChangedSinceSent());

        // Edit after sending — the card is still editable; it now reads "changed since sent".
        $this->line($card, 'Added after sending', 200);
        $this->assertTrue($card->fresh()->quoteChangedSinceSent());
        $this->assertStringContainsString('has changed since Rev 1 was sent', $this->show($card));

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect()
            ->assertSessionHas('success', 'Revised quote (Rev 2) sent to the owner — it replaces the earlier one.');

        $revisions = $card->quoteRevisions()->get();
        $this->assertCount(2, $revisions);
        [$rev2, $rev1] = [$revisions[0], $revisions[1]];
        $this->assertSame(2, $rev2->revision);
        $this->assertEquals(345.0, (float) $rev2->amount, 'Rev 2 totals include the line added after the first send.');
        $this->assertNull($rev2->superseded_at);
        $this->assertTrue((bool) $rev2->is_selected);

        $this->assertSame(1, $rev1->revision);
        $this->assertNotNull($rev1->superseded_at, 'Rev 1 is kept and marked superseded.');
        $this->assertFalse((bool) $rev1->is_selected);
        $this->assertNull($rev1->deleted_at, 'Nothing is hard- or soft-deleted.');
        $this->assertSame(1, \App\Models\RentalWorkOrderQuote::where('rental_job_card_id', $card->id)->whereNull('superseded_at')->count(), 'Exactly one current quote.');
        $this->assertSame($rev2->id, $card->fresh()->currentQuote()->id);

        // Cleared by the re-send; the screen lists both, Rev 1 as Superseded.
        $this->assertFalse($card->fresh()->quoteChangedSinceSent());
        $html = $this->show($card);
        $this->assertStringContainsString('Quote Rev 2', $html);
        $this->assertStringContainsString('Superseded', $html);
        $this->assertStringContainsString('Re-send revised quote (Rev 3)', $html);
        $this->assertStringNotContainsString('Changed since sent', $html);
        $this->assertStringContainsString('/quotes/' . $rev1->id . '/download', $html, 'The superseded revision stays viewable.');
    }

    public function test_after_the_owner_approved_extra_work_raises_a_variation_instead_of_a_resend(): void
    {
        Mail::fake();
        $fake = $this->fakeDispatcher();
        // Over-the-limit quote so the owner genuinely has to approve it.
        RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['no_approval_spend_threshold' => 50]);
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $workOrder = RentalWorkOrder::findOrFail($card->fresh()->rental_work_order_id);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $workOrder->fresh()->owner_approval_status);

        $workOrder->recordApproval($this->admin, ['decision' => RentalWorkOrder::APPROVAL_APPROVED, 'evidence_type' => 'note', 'evidence_text' => 'Owner said yes to Rev 1']);
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $workOrder->fresh()->owner_approval_status);
        $this->assertSame('115.00', $workOrder->fresh()->approved_amount, 'the baseline is the owner-facing (VAT-inclusive) figure the owner approved');
        $card->refresh();
        $card->forceFill(['status' => RentalJobCard::STATUS_APPROVED])->save();

        $this->travel(2)->seconds(); // the extra line is clearly later than the approval
        $this->line($card, 'Extra', 40);

        // The approval STANDS (it is not superseded); the extra goes to the owner as a variation, automatically.
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $workOrder->fresh()->owner_approval_status);
        $variation = $workOrder->fresh()->openVariation();
        $this->assertNotNull($variation);
        $this->assertSame('46.00', $variation->extra_amount);
        $this->assertSame('161.00', $variation->new_total);
        $this->assertDatabaseMissing('rental_work_order_updates', ['rental_work_order_id' => $workOrder->id, 'update_type' => 'approval_superseded']);
        $this->assertSame(1, $workOrder->approvals()->count());

        // And the re-send button is refused: there is nothing to re-send.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasErrors('rental_job_card');
        $this->assertSame(1, $card->quoteRevisions()->count());
        $this->assertNotEmpty($fake->sent);
    }

    public function test_a_superseded_revision_cannot_be_selected_again(): void
    {
        Mail::fake();
        $this->lowerTheLimit();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $this->line($card, 'Two', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $rev1 = $card->quoteRevisions()->where('revision', 1)->firstOrFail();
        $workOrder = RentalWorkOrder::findOrFail($card->fresh()->rental_work_order_id);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.select', [$workOrder, $rev1]))->assertSessionHasErrors('quote');

        $this->assertFalse((bool) $rev1->fresh()->is_selected);
        $this->assertSame(2, $card->fresh()->currentQuote()->revision);
    }

    public function test_resend_emails_only_the_landlord_a_revised_quote_notice(): void
    {
        Mail::fake();
        $fake = $this->fakeDispatcher();
        $this->lowerTheLimit();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $this->line($card, 'Two', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();

        // Two owner mails, both to the landlord only, both through the dispatcher (a plain Mailable would land in Mail::fake()).
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertCount(2, $fake->sent);
        foreach ($fake->sent as [$to, $mail]) {
            $this->assertSame('can.assurance@gmail.com', $to);
            $this->assertInstanceOf(RentalOwnerQuoteMail::class, $mail);
        }
        // over the (lowered) limit, so each is the "your approval is needed" form of the quote mail; the second says it is revised in its body
        $this->assertStringContainsString('Your approval is needed', $fake->sent[0][1]->envelope()->subject);
        $this->assertStringContainsString('Your approval is needed', $fake->sent[1][1]->envelope()->subject);

        // Over the limit: the owner is told their approval is needed; the revised mail says it replaces the earlier one.
        $html = $fake->sent[1][1]->render();
        $this->assertStringContainsString('Rev 2', $html);
        $this->assertStringContainsString('replaces the quote you received earlier', $html);
        $this->assertStringContainsString('approval', strtolower($html));
        // Selling figures only: never a cost or a margin word on an owner mail.
        $this->assertStringNotContainsStringIgnoringCase('margin', html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#si', '', $html)), ENT_QUOTES));
    }

    public function test_a_closed_card_cannot_send_or_resend_a_quote(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->close($card, RentalJobCard::STATUS_COMPLETED);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasErrors('rental_job_card');

        $this->assertSame(0, $card->quoteRevisions()->count());
        Mail::assertNothingQueued();
    }

    public function test_quote_download_is_scoped_to_its_own_card_and_agency(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $quote = $card->quoteRevisions()->firstOrFail();

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.quotes.download', [$card, $quote->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A quote that belongs to ANOTHER card of the same agency is not reachable through this card's URL.
        $other = $this->card();
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.quotes.download', [$other, $quote->id]))->assertNotFound();
    }

    public function test_quote_download_is_not_reachable_from_another_agency(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $quote = $card->quoteRevisions()->firstOrFail();
        $url = route('corex.rental-job-cards.quotes.download', [$card, $quote->id]);

        // A fresh application per agency — the agency scope is resolved once per request lifecycle.
        $this->refreshApplication();
        $this->withoutVite();
        Storage::fake('local');
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'B', 'agency_id' => $otherAgency->id]);
        $intruder = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $this->actingAs($intruder)->get($url)->assertNotFound();
    }

    public function test_signature_ignores_cosmetic_formatting_but_sees_every_real_edit(): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $line = $this->line($card, 'Washer', 100, $task);
        $sig = $card->quoteContentSignature();

        $line->forceFill(['unit_price' => '100.000', 'quantity' => '1'])->save();
        $this->assertSame($sig, $card->fresh()->quoteContentSignature(), 'Same figures, different formatting — not a change.');

        foreach ([
            'line edit' => fn () => $this->service->updateLine($card, $line, ['description' => 'Washer 2'], $this->admin),
            'task rename' => fn () => $this->service->renameTask($card, $task, 'Fix basin tap', $this->admin),
            'line archive' => fn () => $this->service->archiveLine($card, $line->fresh(), $this->admin),
            'title' => fn () => $card->update(['title' => 'New title']),
        ] as $label => $change) {
            $before = $card->fresh()->quoteContentSignature();
            $change();
            $this->assertNotSame($before, $card->fresh()->quoteContentSignature(), "{$label} must flip 'changed since sent'");
        }
    }

    public function test_migration_backfill_labels_legacy_multi_send_quotes_1_to_n_and_supersedes_all_but_the_last(): void
    {
        Mail::fake();
        $card = $this->card();
        $this->line($card, 'One', 100);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertRedirect();
        $workOrder = RentalWorkOrder::findOrFail($card->fresh()->rental_work_order_id);
        $workOrder->recordQuote(['rental_job_card_id' => $card->id, 'amount' => 200, 'quote_date' => now()->toDateString(), 'detail_text' => 'legacy 2'], $this->admin);
        $workOrder->recordQuote(['rental_job_card_id' => $card->id, 'amount' => 300, 'quote_date' => now()->toDateString(), 'detail_text' => 'legacy 3'], $this->admin);
        // Legacy rows exactly as the old code left them: defaults, nothing superseded.
        \App\Models\RentalWorkOrderQuote::where('rental_job_card_id', $card->id)->update(['revision' => 1, 'superseded_at' => null]);
        // An outside-supplier quote on the same work order must stay untouched.
        $supplierQuote = \App\Models\RentalWorkOrderQuote::forceCreate([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id, 'amount' => 999,
            'quote_date' => now()->toDateString(), 'detail_text' => 'supplier', 'revision' => 1,
        ]);

        (require base_path('database/migrations/2026_10_08_120000_add_revisions_to_rental_work_order_quotes_table.php'))->backfill();

        $rows = \App\Models\RentalWorkOrderQuote::where('rental_job_card_id', $card->id)->orderBy('id')->get();
        $this->assertSame([1, 2, 3], $rows->pluck('revision')->all());
        $this->assertNotNull($rows[0]->superseded_at);
        $this->assertNotNull($rows[1]->superseded_at);
        $this->assertNull($rows[2]->superseded_at, 'The newest is the current one.');
        $this->assertNull($supplierQuote->fresh()->superseded_at);
        $this->assertSame(1, $supplierQuote->fresh()->revision);
        $this->assertSame(3, \App\Models\RentalWorkOrderQuote::where('rental_job_card_id', $card->id)->count(), 'Nothing deleted.');
    }

    // ── 4. VAT amount on the printouts ───────────────────────────────────

    private function renderPdfView(RentalJobCard $card, string $view, bool $pricesOn = true): string
    {
        $card->refresh()->load(['property', 'lease.tenants.contact', 'tasks.lines.vatType', 'lines.vatType', 'crew.members', 'assignedUser', 'rentalFaultReport', 'workOrder']);

        return view("corex.rental-job-cards.{$view}", [
            'jobCard' => $card, 'pricesOn' => $pricesOn, 'costsOn' => false, 'revision' => 2,
            'vat' => $this->vat->breakdown($card), 'vatNumber' => '4123456789', 'logo' => null, 'agencyName' => 'Follow Up Agency',
        ])->render();
    }

    public function test_printouts_drop_the_vat_type_column_and_show_the_vat_amount_per_line_subtotal_and_totals(): void
    {
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $this->line($card, 'Washer', 100, $task);
        $this->line($card, 'Gasket', 200, $task);
        $this->line($card, 'Call-out', 100);

        // §17.4.7 (6 Oct 2026): the selling / VAT breakdown belongs to the OWNER quote. The worker's printed copy is
        // COST-only now (no selling, no VAT breakdown) — its own behaviour is covered by CrewPayloadNeverCarriesSellingTest.
        foreach (['quote-pdf'] as $view) {
            $html = $this->renderPdfView($card, $view);

            $this->assertStringNotContainsString('VAT type', $html, "{$view}: the VAT TYPE column is gone");
            $this->assertStringNotContainsString('Standard VAT', $html, "{$view}: no VAT type names in the lines");
            $this->assertMatchesRegularExpression('/<th[^>]*>Excl VAT<\/th>/', $html);
            $this->assertMatchesRegularExpression('/<th[^>]*>VAT<\/th>/', $html);
            // Per line: Washer 100 → 15.00, Gasket 200 → 30.00 (these used to render "—").
            $this->assertStringContainsString('R15.00', $html);
            $this->assertStringContainsString('R30.00', $html);
            // Task subtotal: excl 300.00 + VAT 45.00; General: 100.00 + 15.00.
            $this->assertMatchesRegularExpression('#Subtotal</td>\s*<td>R300\.00</td>\s*<td>R45\.00</td>#', $html);
            $this->assertMatchesRegularExpression('#Subtotal</td>\s*<td>R100\.00</td>\s*<td>R15\.00</td>#', $html);
            // Totals: excl 400, VAT @ 15% 60, incl 460.
            $this->assertStringContainsString('Subtotal (excl VAT)', $html);
            $this->assertStringContainsString('R400.00', $html);
            $this->assertStringContainsString('VAT @ 15%', $html);
            $this->assertStringContainsString('R60.00', $html);
            $this->assertStringContainsString('Total (incl VAT)', $html);
            $this->assertStringContainsString('R460.00', $html);
        }
    }

    public function test_quote_pdf_names_the_revision_and_the_one_it_replaces(): void
    {
        $card = $this->card();
        $this->line($card, 'Washer', 100);

        $html = $this->renderPdfView($card, 'quote-pdf');

        $this->assertStringContainsString('Rev 2 (replaces Rev 1)', $html);
    }

    public function test_printouts_carry_a_vat_total_line_when_more_than_one_rate_is_in_play(): void
    {
        $card = $this->card();
        $this->line($card, 'Standard', 100);
        $zero = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_FIXED)->firstOrFail();
        $this->service->addLine($card, ['description' => 'Exempt', 'quantity' => 1, 'unit_price' => 50, 'rental_vat_type_id' => $zero->id], $this->admin);
        $custom = RentalVatType::where('agency_id', $this->agency->id)->where('rate_mode', RentalVatType::RATE_MODE_CUSTOM_PER_LINE)->firstOrFail();
        $this->service->addLine($card, ['description' => 'Custom', 'quantity' => 1, 'unit_price' => 100, 'rental_vat_type_id' => $custom->id, 'custom_vat_rate' => 10], $this->admin);

        $html = $this->renderPdfView($card, 'quote-pdf');

        $this->assertStringContainsString('Total VAT', $html);
        $this->assertStringContainsString('R25.00', $html); // 15 + 0 + 10
    }

    public function test_non_vat_agency_printouts_show_no_vat_column_or_vat_line_at_all(): void
    {
        $this->agency->update(['vat_registered' => false]);
        $card = $this->card();
        $task = $this->service->addTask($card, 'Fix tap', $this->admin);
        $this->service->addLine($card, ['description' => 'Washer', 'quantity' => 2, 'unit_price' => 100], $this->admin, $task);

        // §17.4.7 (6 Oct 2026): the selling / VAT breakdown belongs to the OWNER quote. The worker's printed copy is
        // COST-only now (no selling, no VAT breakdown) — its own behaviour is covered by CrewPayloadNeverCarriesSellingTest.
        foreach (['quote-pdf'] as $view) {
            $html = $this->renderPdfView($card->fresh(), $view);

            $this->assertMatchesRegularExpression('/<th[^>]*>Line total<\/th>/', $html);
            $this->assertStringNotContainsString('VAT', $html, "{$view}: nothing VAT-related for a non-VAT agency");
            $this->assertStringContainsString('R200.00', $html);
            $this->assertStringContainsString('Subtotal', $html);
        }
    }

    public function test_incl_capture_agency_labels_the_unit_price_so_the_row_adds_up(): void
    {
        $this->agency->update(['vat_capture_mode' => Agency::VAT_CAPTURE_INCL]);
        $card = $this->card();
        $this->line($card, 'Washer', 115);

        $html = $this->renderPdfView($card->fresh(), 'quote-pdf');

        $this->assertStringContainsString('Unit price (incl VAT)', $html);
        $this->assertStringContainsString('R100.00', $html); // excl share
        $this->assertStringContainsString('R15.00', $html);  // VAT share
    }

    public function test_real_pdf_generation_still_works_with_the_new_columns(): void
    {
        $card = $this->card();
        $this->line($card, 'Washer', 100);

        $quote = app(RentalDocumentPdfService::class)->jobCardQuotePdf($card->fresh(), 2)->output();
        $print = app(RentalDocumentPdfService::class)->jobCardPrintPdf($card->fresh())->output();

        $this->assertStringStartsWith('%PDF', $quote);
        $this->assertStringStartsWith('%PDF', $print);
    }
}
