<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-460 (Johan, 15 Oct 2026, after walking job card 97): the office job card screen puts what came back and what to do next first - a Returned | Next bar under the
 * stepper, ONE Money & approval card, ONE Crew card, the owner-limit sentence once. rental-work-orders.md §17.39. Layout and prominence only: the routes and fields are
 * untouched (the other job card test files keep covering them).
 */
final class JobCardShowReworkTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('JC Rework');
    }

    private function page(RentalJobCard $card): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card->fresh()))->assertOk()->getContent();
    }

    /** The label of the Next bar's primary button (null = the bar has no button). */
    private function nextButton(string $html): ?string
    {
        return preg_match('/<(?:button|a)\b[^>]*data-next-button[^>]*>([^<]*)</', $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES) : null;
    }

    private function nextText(string $html): string
    {
        preg_match('/<span data-next-text>([^<]*)</', $html, $m);

        return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
    }

    private function returnedText(string $html): string
    {
        preg_match('/data-returned-text>([^<]*)</', $html, $m);

        return html_entity_decode(trim($m[1] ?? ''), ENT_QUOTES);
    }

    // ── the Next bar: one primary action per status ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_next_bar_offers_the_right_primary_action_at_every_status(): void
    {
        $this->ownerLimits(1000000.0);
        [$card, $wo] = $this->internalJob(345);
        $crew = RentalCrew::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Team A']);

        // draft, price inside the owner's limit -> confirm it
        $html = $this->page($card);
        $this->assertSame(1, substr_count($html, 'data-next-step'), 'exactly one Next bar');
        $this->assertStringContainsString('data-stage="draft"', $html);
        $this->assertSame('Confirm the price', $this->nextButton($html));
        $this->assertStringContainsString('Confirm the price: R345.00', $this->nextText($html));

        // price confirmed, nothing authorised yet -> authorise the cost
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertStringContainsString('data-stage="quoted"', $html);
        $this->assertSame('Authorise R345.00', $this->nextButton($html));

        // approved, no crew -> assign the crew; with a crew -> book the date
        $this->authoriseCostOf($wo->fresh());
        $html = $this->page($card);
        $this->assertStringContainsString('data-stage="approved"', $html);
        $this->assertSame('Assign the crew', $this->nextButton($html));
        $card->forceFill(['rental_crew_id' => $crew->id])->save();
        $this->assertSame('Book the date', $this->nextButton($this->page($card)));

        // booked, the crew not yet told -> send them their link (Mark in progress is the secondary)
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertStringContainsString('data-stage="scheduled"', $html);
        $this->assertSame('Send the crew their link', $this->nextButton($html));
        $this->assertStringContainsString('form="jc-crew-send-form"', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*form="jc-start-form"[^>]*data-next-secondary>Mark in progress</', $html);
        $this->assertStringContainsString('id="jc-start-form"', $html, 'the button has a form to submit');

        // in progress -> the crew link is the primary action while the crew works, recording the sign-off the secondary
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertStringContainsString('data-stage="in_progress"', $html);
        $this->assertSame('Send the crew their link', $this->nextButton($html));
        $this->assertMatchesRegularExpression('/data-jc-go data-next-secondary>Record the sign-off</', $html);

        // the crew is done -> the agent signs it off, then completes it
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sam'])->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertStringContainsString('data-stage="signed_off"', $html);
        $this->assertSame('Agent sign-off', $this->nextButton($html));
        $this->assertStringContainsString('id="jc-agent-signoff-form"', $html);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
        $this->assertSame('Mark complete', $this->nextButton($this->page($card)));

        // closed -> no button
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $card), ['paid_by' => 'owner'])->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertNull($this->nextButton($html));
        $this->assertStringContainsString('Completed', $this->nextText($html));
    }

    public function test_above_the_limit_the_next_bar_sends_the_quote_and_then_asks_for_the_owners_decision(): void
    {
        $this->ownerLimits(500.0);
        [$card] = $this->internalJob(1200);

        $html = $this->page($card);
        $this->assertSame('Send quote to owner for approval', $this->nextButton($html));
        // the Money & approval card carries the SAME action, as one button
        $this->assertSame(1, preg_match_all('/<button[^>]*form="jc-send-quote-form"[^>]*data-quote-button/', $html), 'one send-quote button in the Money card');
        $this->assertStringContainsString('id="jc-send-quote-form"', $html);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertSame("Record the owner's decision", $this->nextButton($html));
        $this->assertStringContainsString('Waiting for the owner to approve R1,200.00', $this->nextText($html));
        // …and the Money card's one button is the same decision button (it presses the hidden one inside the shared "Approve the cost" box)
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $buttons = (new \DOMXPath($dom))->query("//*[@id='jc-quote-box']//*[(self::a or self::button) and contains(@class,'corex-btn-primary')]");
        $this->assertSame(1, $buttons->length, 'one primary button in the Money & approval card');
        $this->assertSame("Record the owner's decision", trim($buttons->item(0)->textContent));
        $this->assertSame('[data-cost-owner-decision-open]', $buttons->item(0)->getAttribute('data-jc-click'));
        $this->assertStringContainsString('data-cost-owner-decision-open', $html, 'the box it presses exists');
    }

    // ── Returned: the latest thing that came back into the office ─────────────────────────────────────────────────────────────────

    public function test_returned_says_what_the_crew_sent_and_what_became_of_it(): void
    {
        $this->ownerLimits(1000000.0);
        [$card] = $this->internalJob(345);
        $this->assertStringContainsString('Last event', $this->page($card), 'nothing has come back yet: the last event');

        $line = $card->lines()->first();
        $card->logUpdate('crew_lines_sent', null, '1 line (via crew link)');
        $line->forceFill(['office_status' => RentalJobCardLine::OFFICE_AWAITING, 'origin' => RentalJobCardLine::ORIGIN_CREW_EXTRA, 'crew_added_at' => now()])->save();

        $html = $this->page($card);
        $this->assertStringContainsString('>Returned<', $html);
        $this->assertMatchesRegularExpression('/Crew sent 1 line .* - 1 waiting/', $this->returnedText($html));
        $this->assertStringContainsString('waiting for you', $html);
        $this->assertStringContainsString('data-returned-anchor="jc-lines"', $html);
        $this->assertStringContainsString('id="jc-lines"', $html, 'the Returned link has somewhere to land');
        // the crew's line sits inline in the lines table with Accept / Reject on its row, not in a separate card
        $this->assertStringContainsString('data-awaiting-line="' . $line->id . '"', $html);
        $this->assertStringContainsString('action="' . route('corex.rental-job-cards.crew-lines.accept', [$card, $line->id]) . '"', $html);
        $this->assertStringContainsString('action="' . route('corex.rental-job-cards.crew-lines.reject', [$card, $line->id]) . '"', $html);

        $line->forceFill(['office_status' => RentalJobCardLine::OFFICE_ACCEPTED])->save();
        $text = $this->returnedText($this->page($card));
        $this->assertMatchesRegularExpression('/Crew sent 1 line .* - 1 accepted$/', $text);
        $this->assertStringNotContainsString('waiting', $text);
    }

    public function test_returned_says_when_the_owner_answered(): void
    {
        $this->ownerLimits(5000.0);
        [$card, $wo] = $this->internalJob(1200);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->authoriseCostOf($wo->fresh());

        $text = $this->returnedText($this->page($card));
        // an agent authorising under a limit is the office's own act, not something that came back; nothing inbound -> the last event
        $this->assertStringNotContainsString('Owner approved', $text);
    }

    // ── the layout ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_right_column_is_one_money_card_and_one_crew_card_and_the_owner_limit_sentence_is_written_once(): void
    {
        $this->ownerLimits(1000000.0);
        [$card] = $this->internalJob(345);
        $html = $this->page($card);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new \DOMXPath($dom);
        $kids = $xp->query("//*[@id='jc-layout']/*");
        $this->assertSame(['jc-left-col', 'jc-right-col'], [$kids->item(0)->getAttribute('id'), $kids->item(1)->getAttribute('id')]);
        $cards = $xp->query("//*[@id='jc-right-col']/*");
        $ids = [];
        foreach ($cards as $c) {
            $ids[] = $c->getAttribute('id') ?: ($c->hasAttribute('data-cancel-job-card') ? 'cancel' : $c->nodeName);
        }
        $this->assertSame(['jc-money', 'jc-crew', 'cancel'], $ids, 'Money & approval, Crew, and the small Cancel link - nothing else');
        foreach (['jc-lines', 'jc-job'] as $left) {
            $this->assertSame(1, $xp->query("//*[@id='jc-left-col']//*[@id='{$left}']")->length, "#{$left} is in the left column");
        }
        $this->assertSame(1, $xp->query("//*[@id='jc-right-col']//*[@id='jc-quote-box']")->length);
        $this->assertSame(1, $xp->query("//*[@id='jc-right-col']//*[@id='jc-approval-panel']")->length);

        // "Inside the owner's repair limit ... you can authorise it" - once, in the money card
        $text = $this->visibleText($html);
        $this->assertSame(1, substr_count($text, "Inside the owner's repair limit"), 'the owner-limit verdict is written once');
        $this->assertSame(1, substr_count($xp->query("//*[@id='jc-money']")->item(0)->textContent, "Inside the owner's repair limit"));
        $this->assertSame(0, substr_count($text, 'The owner has not set a repair limit'));
        // the old stacked cards and the explainer are gone
        $this->assertStringNotContainsString('What happens next', $html);
        $this->assertStringNotContainsString('data-later-steps', $html);
        $this->assertStringNotContainsString('data-pricing-panel', $html, 'no separate Pricing card');
    }

    public function test_the_stepper_marks_what_has_been_reached(): void
    {
        $this->ownerLimits(1000000.0);
        [$card, $wo] = $this->internalJob(345);
        $steps = function () use ($card): array {
            preg_match_all('/data-step="([a-z_]+)" data-state="([a-z]+)"/', $this->page($card), $m, PREG_SET_ORDER);

            return array_column($m, 2, 1);
        };

        $this->assertSame(['quoted' => 'current', 'approved' => 'todo', 'booked' => 'todo', 'in_progress' => 'todo', 'completed' => 'todo'], $steps());
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->authoriseCostOf($wo->fresh());
        $s = $steps();
        $this->assertSame(['done', 'done', 'current'], [$s['quoted'], $s['approved'], $s['booked']]);
    }

    public function test_the_bulk_markup_is_an_inline_link_in_the_totals_not_a_card(): void
    {
        [$card] = $this->internalJob(345);
        $html = $this->page($card);

        $this->assertStringContainsString('data-markup-open', $html);
        $this->assertStringContainsString('Apply markup', $html);
        $this->assertStringContainsString('action="' . route('corex.rental-job-cards.markup', $card) . '"', $html);
        $this->assertSame(1, substr_count($html, 'name="markup_all_percent"'));
    }
}
