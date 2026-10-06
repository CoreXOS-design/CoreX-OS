<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\RentalCrew;
use App\Models\RentalCrewLinkEvent;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.29 — archiving a crew revokes its
 * standing link AND every open per-job link on its cards (audited); restoring
 * the crew brings NOTHING back — the office generates a new link.
 */
final class CrewArchiveRevokesLinksTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalSecureAccessTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Archive');
        $this->tokens = app(RentalSecureAccessTokenService::class);
    }

    private function standing(?RentalCrew $crew = null): string
    {
        return $this->tokens->issueForCrew($crew ?? $this->crew, $this->admin)['raw_token'];
    }

    private function archiveViaOffice(RentalCrew $crew): void
    {
        $this->actingAs($this->admin)
            ->delete(route('corex.rental-crews.archive', $crew))
            ->assertRedirect(route('corex.rental-crews.index'));
        $this->app['auth']->forgetGuards();
    }

    private function restoreViaOffice(RentalCrew $crew): void
    {
        $this->actingAs($this->admin)
            ->post(route('corex.rental-crews.restore', $crew->id))
            ->assertRedirect();
        $this->app['auth']->forgetGuards();
    }

    private function assertUnavailable(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(404)->assertSee('data-crew-link-unavailable', false);
    }

    /** A dead per-job link renders the shared "Link unavailable" page (200, same as every dead reason). */
    private function assertJobLinkDead(string $raw): void
    {
        $this->get("/secure/job-cards/{$raw}")->assertOk()->assertSee('Link unavailable')->assertDontSee('What to load');
    }

    private function assertJobLinkLive(string $raw): void
    {
        $this->get("/secure/job-cards/{$raw}")->assertOk()->assertDontSee('Link unavailable')->assertSee('What to load');
    }

    public function test_archiving_a_crew_kills_its_standing_link_and_restoring_does_not_bring_it_back(): void
    {
        $raw = $this->standing();
        $this->get("/secure/crews/{$raw}")->assertOk();

        $this->archiveViaOffice($this->crew);
        $this->assertUnavailable($this->get("/secure/crews/{$raw}"));

        $this->restoreViaOffice($this->crew);
        $this->assertNull(RentalCrew::withoutGlobalScopes()->find($this->crew->id)->deleted_at, 'the crew is restored');
        $this->assertUnavailable($this->get("/secure/crews/{$raw}"));

        $new = $this->standing();
        $this->get("/secure/crews/{$new}")->assertOk();
        $this->assertUnavailable($this->get("/secure/crews/{$raw}"));
    }

    public function test_archiving_a_crew_kills_every_open_per_job_link_and_restoring_does_not_bring_them_back(): void
    {
        $cardA = $this->makeJobCard(['title' => 'Job A']);
        $cardB = $this->makeJobCard(['title' => 'Job B']);
        $rawA = $this->tokens->issueForJobCard($cardA, $this->admin)['raw_token'];
        $rawB = $this->tokens->issueForJobCard($cardB, $this->admin)['raw_token'];
        $this->assertJobLinkLive($rawA);
        $this->assertJobLinkLive($rawB);

        $this->archiveViaOffice($this->crew);
        $this->assertJobLinkDead($rawA);
        $this->assertJobLinkDead($rawB);

        $this->restoreViaOffice($this->crew);
        $this->assertJobLinkDead($rawA);
        $this->assertJobLinkDead($rawB);

        $newA = $this->tokens->issueForJobCard($cardA->fresh(), $this->admin)['raw_token'];
        $this->assertJobLinkLive($newA);
        $this->assertJobLinkDead($rawA);
    }

    public function test_every_revoked_link_is_audited_on_the_crew_log_and_the_card_history(): void
    {
        $card = $this->makeJobCard();
        $this->standing();
        $this->tokens->issueForJobCard($card, $this->admin);

        $this->archiveViaOffice($this->crew);

        $revoked = RentalCrewLinkEvent::withoutGlobalScopes()
            ->where('rental_crew_id', $this->crew->id)->where('event', RentalCrewLinkEvent::EVENT_REVOKED)->get();
        $this->assertCount(2, $revoked, 'one audit row per revoked link — the standing link and the per-job link');
        $this->assertTrue($revoked->every(fn ($e) => $e->actor_user_id === $this->admin->id && str_contains((string) $e->note, 'archived')));
        $this->assertSame([$card->id], $revoked->whereNotNull('rental_job_card_id')->pluck('rental_job_card_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereNull('revoked_at')->count());

        $this->assertTrue(
            $card->fresh()->updates()->where('update_type', 'link_revoked')->where('note', 'like', '%archived%')->exists(),
            'the card history says the crew link was revoked because the crew was archived',
        );
    }

    public function test_archiving_one_crew_leaves_another_crews_links_alone(): void
    {
        $other = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'created_by_user_id' => $this->admin->id]);
        $otherCard = $this->makeJobCard(['title' => 'Team 2 job'], $other);
        $otherStanding = $this->standing($other);
        $otherJob = $this->tokens->issueForJobCard($otherCard, $this->admin)['raw_token'];
        $this->standing();

        $this->archiveViaOffice($this->crew);

        $this->get("/secure/crews/{$otherStanding}")->assertOk();
        $this->assertJobLinkLive($otherJob);
    }

    public function test_archiving_a_crew_with_no_links_still_archives_it(): void
    {
        $this->archiveViaOffice($this->crew);

        $this->assertNotNull(RentalCrew::withoutGlobalScopes()->withTrashed()->find($this->crew->id)->deleted_at);
        $this->assertSame(0, RentalCrewLinkEvent::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->count());
    }
}
