<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardCrewReport;
use App\Models\RentalJobCardLine;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsPricingFixtures;
use Tests\TestCase;

/**
 * AT-465 (.ai/specs/rental-work-orders.md section 17.41) - the crew link's list and its ONE send. Johan: the crew captured 2 hours of
 * labour, "looked for a submit" and found none. Lines go on the crew's list (stored server-side, so a refresh keeps them); nothing
 * reaches the office until the one "Send to office", which carries what the crew found, the list, and what is on it.
 */
final class CrewStagedListAndSendTest extends TestCase
{
    use BuildsPricingFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private string $rawToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingWorld('Crew List');
        $this->card = $this->emptyCard(['status' => RentalJobCard::STATUS_DRAFT]);
        $this->rawToken = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin)['raw_token'];
    }

    private function url(): string
    {
        return url('/secure/job-cards/' . $this->rawToken);
    }

    private function stage(string $description = 'Basin trap', array $over = [])
    {
        return $this->post(route('rentals.crew-job.lines.store', $this->rawToken), $over + ['type' => 'part', 'description' => $description, 'quantity' => 1, 'unit' => 'each', 'unit_cost' => '85.00']);
    }

    private function stageLabour(float $hours = 2)
    {
        return $this->post(route('rentals.crew-job.lines.store', $this->rawToken), ['type' => 'labour', 'description' => 'Fit the trap', 'quantity' => $hours]);
    }

    private function send(array $data = ['findings' => 'The basin trap is cracked and leaking. Needs a new trap.'])
    {
        return $this->post(route('rentals.crew-job.lines.send', $this->rawToken), $data);
    }

    private function alerts(): int
    {
        $typeId = NotificationEventType::where('key', 'rental_job_card.crew_lines_submitted')->value('id');

        return NotificationDispatchLog::where('notification_event_type_id', $typeId)->count();
    }

    public function test_two_staged_lines_survive_a_refresh_and_nothing_reaches_the_office(): void
    {
        $this->stage('Basin trap')->assertRedirect()->assertSessionHas('success');
        $this->stageLabour(2)->assertRedirect();

        foreach ([1, 2] as $_refresh) {
            $body = $this->get($this->url())->assertOk()->getContent();
            $this->assertStringContainsString('Basin trap', $body);
            $this->assertStringContainsString('Fit the trap', $body);
            $this->assertSame(2, substr_count($body, 'On your list — not sent yet'), 'both lines are still on the list after a refresh');
            $this->assertStringContainsString('Add new line', $body);
            $this->assertStringNotContainsString('>Add line<', $body, 'the old "Add line" wording is gone');
            $this->assertStringContainsString('Send to office (2)', $body);
        }

        $this->assertSame(2, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count());
        $this->assertSame(0, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
        $this->assertSame(0, RentalJobCardCrewReport::count());
        $this->assertSame(0, $this->alerts(), 'no alert until the crew sends');
        $this->assertSame(0, $this->card->updates()->where('update_type', 'crew_lines_sent')->count());
    }

    public function test_the_next_bar_follows_the_job(): void
    {
        $body = $this->get($this->url())->assertOk()->getContent();
        $this->assertStringContainsString('data-crew-next', $body);
        $this->assertStringContainsString('what you found and what is needed', $body, 'nothing asked yet: report what you found');

        // the office asks the crew to price it
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.price-requests.store', $this->card), ['note' => 'Please price the drain']);
        $this->flushSession();
        $this->assertStringContainsString('The office asked you to price this job: add your parts and hours, then press Send to office', $this->get($this->url())->getContent());

        // lines on the list: press Send
        $this->stage();
        $this->assertStringContainsString('Your list has 1 line. Tell the office what you found, then press Send to office.', $this->get($this->url())->getContent());

        // sent
        $this->send()->assertSessionHasNoErrors();
        $body = $this->get($this->url())->getContent();
        $this->assertStringContainsString('Sent - waiting for the office.', $body);
        $this->assertStringContainsString('Sent — waiting for the office', $body, 'and the line says so');

        // approved: do the work, then mark it completed
        $this->card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();
        $this->approveCard();
        $body = $this->get($this->url())->getContent();
        $this->assertStringContainsString('Do the work, take photos, then Mark work completed.', $body);
        $this->assertStringContainsString('id="cj-complete"', $body);
    }

    public function test_one_send_delivers_the_whole_list_findings_and_one_alert_and_the_office_reads_it(): void
    {
        $this->stage('Basin trap');
        $this->stageLabour(2);

        $this->send(['findings' => "The basin trap is cracked and leaking.\nNeeds a new trap."])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(2, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
        $report = RentalJobCardCrewReport::firstOrFail();
        $this->assertSame(2, $report->line_count);
        $this->assertStringContainsString('cracked and leaking', (string) $report->findings);
        $this->assertSame(2, RentalJobCardLine::where('rental_job_card_crew_report_id', $report->id)->count(), 'both lines belong to the one send');
        $this->assertSame(1, $this->card->updates()->where('update_type', 'crew_lines_sent')->count(), 'ONE "Crew sent N lines" event');
        $this->assertStringContainsString('2 lines', (string) $this->card->updates()->where('update_type', 'crew_lines_sent')->value('note'));
        $this->assertSame(1, $this->alerts(), 'ONE alert to the office');

        // the office job card: the Returned bar and the findings block
        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->getContent();
        $this->assertStringContainsString('Crew reported:', $html);
        $this->assertStringContainsString('2 lines', $html);
        $this->assertStringContainsString('data-crew-reports', $html);
        $this->assertStringContainsString('Needs a new trap.', $html);
    }

    public function test_a_second_batch_works_and_alerts_again_with_findings_optional(): void
    {
        $this->stage('Basin trap');
        $this->send()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->alerts());

        $this->stage('Silicone');
        $body = $this->get($this->url())->getContent();
        $this->assertStringContainsString('(optional this time)', $body);
        $this->assertStringContainsString('Sent — waiting for the office', $body, 'the first batch still shows as sent');
        $this->assertStringContainsString('On your list — not sent yet', $body);

        // the first send already created a report; a second one at once is a different batch with new lines - no findings needed
        $this->send(['findings' => ''])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(2, RentalJobCardCrewReport::count());
        $this->assertSame(2, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
        $this->assertSame(2, $this->card->updates()->where('update_type', 'crew_lines_sent')->count());
        $this->assertSame(2, $this->alerts(), 'the second batch alerts the office again');
    }

    public function test_sending_is_refused_with_no_lines_and_without_findings_the_first_time(): void
    {
        $this->send()->assertSessionHasErrors('crew');
        $this->assertStringContainsString('Add at least one part or labour line', session('errors')->first('crew'));

        $this->stage();
        $this->send(['findings' => '   '])->assertSessionHasErrors('crew');
        $this->assertStringContainsString('what you found', session('errors')->first('crew'));
        $this->assertSame(1, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count(), 'the list is kept');
        $this->assertSame(0, RentalJobCardCrewReport::count());

        $this->send(['findings' => str_repeat('x', 2001)])->assertSessionHasErrors('findings');
        $this->assertSame(0, RentalJobCardCrewReport::count());
    }

    public function test_a_double_tap_sends_the_batch_once(): void
    {
        $this->stage();
        $this->send()->assertSessionHasNoErrors();
        $this->send()->assertSessionHasNoErrors()->assertSessionHas('success', 'That is already with the office.');

        $this->assertSame(1, RentalJobCardCrewReport::count());
        $this->assertSame(1, $this->card->updates()->where('update_type', 'crew_lines_sent')->count());
        $this->assertSame(1, $this->alerts());
    }

    public function test_an_expired_link_cannot_send(): void
    {
        $this->stage();
        RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->update(['expires_at' => now()->subMinute()]);

        $this->send()->assertOk()->assertSee('no longer available');
        $this->assertSame(0, RentalJobCardCrewReport::count());
        $this->assertSame(0, RentalJobCardLine::where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count());
    }

    public function test_the_owner_quote_carries_what_the_crew_found_and_the_tenant_does_not(): void
    {
        $this->stage();
        $this->send(['findings' => 'Trap cracked, needs replacing.'])->assertSessionHasNoErrors();

        $wo = \App\Models\RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->card->agency_id, 'property_id' => $this->card->property_id, 'title' => 'Blocked drain',
            'description' => 'Drain is blocked.', 'status' => 'open', 'reported_by_type' => \App\Models\RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_at' => now(), 'assignment_type' => \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL,
        ]);
        $this->card->forceFill(['rental_work_order_id' => $wo->id])->save();
        $svc = app(\App\Services\Rentals\RentalWorkOrderClientViewService::class);

        $owner = $svc->payload($wo->fresh(), \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertStringContainsString('Drain is blocked.', $owner['description']);
        $this->assertStringContainsString('Trap cracked, needs replacing.', $owner['description']);

        $tenant = $svc->payload($wo->fresh(), \App\Services\Rentals\RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertSame('Drain is blocked.', $tenant['description']);

        // the work order screen shows it to the office
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->assertSee('What the crew found')->assertSee('Trap cracked, needs replacing.');

        // and the quote PDF's own page carries it where it already shows the job's description
        $data = [];
        \Illuminate\Support\Facades\View::composer('corex.rental-job-cards.quote-pdf', function ($view) use (&$data) { $data = $view->getData(); });
        app(\App\Services\Rentals\RentalDocumentPdfService::class)->jobCardQuotePdf($this->card->fresh());
        $this->assertNotEmpty($data, 'the quote view was rendered');
        $html = view('corex.rental-job-cards.quote-pdf', $data)->render();
        $this->assertStringContainsString('What our maintenance team found', $html);
        $this->assertStringContainsString('Trap cracked, needs replacing.', $html);
    }

    /** The crew-visible "authorised" flag is true when the agency does not capture prices on job cards (the gate has nothing to authorise). */
    private function approveCard(): void
    {
        \App\Models\RentalWorkOrderSetting::where('agency_id', $this->agency->id)->update(['capture_prices_on_job_cards' => false]);
    }
}
