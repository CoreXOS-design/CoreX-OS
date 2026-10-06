<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.28 — the per-job crew link, through the
 * real HTTP routes: shown once, hash only, re-issue / revoke / expiry /
 * closed / archived / switched-off / forged all render the IDENTICAL
 * "unavailable" page, and the agent sign-off + complete kills the link.
 */
final class CrewJobLinkTokenTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Token');
    }

    private function issue(RentalJobCard $card): array
    {
        return app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);
    }

    private function unavailableBody(): string
    {
        return $this->get('/secure/job-cards/' . str_repeat('x', 64))->assertOk()->assertSee('no longer available')->getContent();
    }

    public function test_a_valid_link_opens_with_no_login(): void
    {
        $card = $this->makeJobCard();
        $issued = $this->issue($card);

        $this->get('/secure/job-cards/' . $issued['raw_token'])
            ->assertOk()->assertSee('Fix the geyser')->assertSee('Drain the geyser');
    }

    public function test_office_issue_shows_the_url_once_and_stores_only_the_hash(): void
    {
        $card = $this->makeJobCard();

        $response = $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $card));
        $response->assertRedirect(route('corex.rental-job-cards.show', $card));
        $url = session('crew_link_url');
        $this->assertNotEmpty($url);
        $this->assertMatchesRegularExpression('#/secure/job-cards/[A-Za-z0-9]{64}$#', $url);
        $raw = basename($url);

        // The next page render shows it (flash), the one after does not.
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->assertSee($raw);
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->assertDontSee($raw);

        $row = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->firstOrFail();
        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertStringNotContainsString($raw, json_encode($row->getAttributes()));
        $this->assertSame(1, $card->updates()->where('update_type', 'link_issued')->count());
    }

    public function test_reissuing_kills_the_old_link_on_the_very_next_request(): void
    {
        $card = $this->makeJobCard();
        $first = $this->issue($card);
        $this->get('/secure/job-cards/' . $first['raw_token'])->assertSee('Fix the geyser');

        $second = $this->issue($card);

        $this->get('/secure/job-cards/' . $first['raw_token'])->assertSee('no longer available')->assertDontSee('Fix the geyser');
        $this->get('/secure/job-cards/' . $second['raw_token'])->assertSee('Fix the geyser');
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereNull('revoked_at')->count());
    }

    public function test_office_revoke_kills_the_link_and_logs_it(): void
    {
        $card = $this->makeJobCard();
        $issued = $this->issue($card);

        $this->actingAs($this->admin)->delete(route('corex.rental-job-cards.crew-link.revoke', $card))->assertRedirect();

        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertSee('no longer available');
        $this->assertSame(1, $card->updates()->where('update_type', 'link_revoked')->count());
    }

    public function test_every_dead_link_renders_the_identical_unavailable_page(): void
    {
        $baseline = $this->unavailableBody();
        $dead = [];

        // forged / unknown
        $dead['forged'] = '/secure/job-cards/not-a-real-token';

        // expired
        $c1 = $this->makeJobCard(['title' => 'Expired']);
        $i1 = $this->issue($c1);
        RentalSecureAccessToken::withoutGlobalScopes()->whereKey($i1['token']->id)->update(['expires_at' => now()->subMinute()]);
        $dead['expired'] = '/secure/job-cards/' . $i1['raw_token'];

        // revoked
        $c2 = $this->makeJobCard(['title' => 'Revoked']);
        $i2 = $this->issue($c2);
        app(RentalSecureAccessTokenService::class)->revokeAllFor($c2);
        $dead['revoked'] = '/secure/job-cards/' . $i2['raw_token'];

        // cancelled
        $c3 = $this->makeJobCard(['title' => 'Cancelled']);
        $i3 = $this->issue($c3);
        $c3->cancel($this->admin, 'no longer needed');
        $dead['cancelled'] = '/secure/job-cards/' . $i3['raw_token'];

        // completed
        $c4 = $this->makeJobCard(['title' => 'Completed']);
        $i4 = $this->issue($c4);
        $c4->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();
        $dead['completed'] = '/secure/job-cards/' . $i4['raw_token'];

        // archived
        $c5 = $this->makeJobCard(['title' => 'Archived']);
        $i5 = $this->issue($c5);
        $c5->archive($this->admin);
        $dead['archived'] = '/secure/job-cards/' . $i5['raw_token'];

        // a contractor-purpose / crew-page token on the crew-job URL
        $c6 = $this->makeJobCard(['title' => 'Wrong purpose']);
        $i6 = app(RentalSecureAccessTokenService::class)->issue($this->crew, RentalSecureAccessToken::PURPOSE_CREW_STANDING, $this->admin, null);
        $dead['wrong purpose'] = '/secure/job-cards/' . $i6['raw_token'];

        foreach ($dead as $why => $url) {
            $this->assertSame($baseline, $this->get($url)->assertOk()->getContent(), "{$why} must render the identical unavailable page");
        }

        // and the master switch
        $c7 = $this->makeJobCard(['title' => 'Switched off']);
        $i7 = $this->issue($c7);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);
        $this->assertSame($baseline, $this->get('/secure/job-cards/' . $i7['raw_token'])->getContent());
    }

    public function test_a_crew_job_token_never_opens_the_contractor_page(): void
    {
        $card = $this->makeJobCard();
        $issued = $this->issue($card);

        $this->get('/secure/work-orders/' . $issued['raw_token'])->assertOk()->assertSee('no longer available');
    }

    public function test_agent_sign_off_and_complete_kills_the_link(): void
    {
        $card = $this->makeJobCard();
        $issued = $this->issue($card);
        $card->workerSignOff($this->admin, 'Foreman');
        $card->fresh()->agentSignOff($this->admin);
        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertSee('Fix the geyser');

        app(RentalJobCardService::class)->complete($card->fresh(), $this->admin);

        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertSee('no longer available')->assertDontSee('Fix the geyser');
    }

    public function test_the_first_open_is_logged_once_and_last_opened_is_refreshed(): void
    {
        $card = $this->makeJobCard();
        $issued = $this->issue($card);

        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk();
        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk();

        $this->assertSame(1, $card->updates()->where('update_type', 'link_opened')->count());
        $this->assertNotNull(RentalSecureAccessToken::withoutGlobalScopes()->find($issued['token']->id)->last_used_at);
    }

    public function test_a_link_only_ever_reaches_its_own_card(): void
    {
        $cardA = $this->makeJobCard(['title' => 'Card A']);
        $cardB = $this->makeJobCard(['title' => 'Card B secret']);
        $issuedA = $this->issue($cardA);

        $this->get('/secure/job-cards/' . $issuedA['raw_token'])->assertSee('Card A')->assertDontSee('Card B secret');

        // a task of card B posted on card A's link is a 404, and changes nothing
        $taskB = $cardB->tasks()->first();
        $this->postJson('/secure/job-cards/' . $issuedA['raw_token'] . '/tasks/' . $taskB->id . '/tick')->assertNotFound();
        $this->assertFalse($taskB->fresh()->is_done);
    }

    public function test_no_link_can_be_issued_for_a_closed_card_or_with_links_switched_off(): void
    {
        $closed = $this->makeJobCard(['title' => 'Closed one', 'status' => RentalJobCard::STATUS_COMPLETED]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $closed))->assertSessionHasErrors('crew_link');
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $closed->id)->count());

        $open = $this->makeJobCard(['title' => 'Open one']);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $open))->assertSessionHasErrors('crew_link');
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $open->id)->count());
    }
}
