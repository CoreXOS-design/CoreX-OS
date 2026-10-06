<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.27.5 / §14.29 — the crew page's standing link, over
 * real HTTP with no session.
 *
 * Input paths proven: opens + lists exactly that crew's cards with the summed
 * "what to load"; lives until revoked (no expiry by default — still live a year
 * later); optional agency expiry; regenerate and revoke each kill the old link
 * on its very next request; archived crew / inactive crew / crew links switched
 * off / unknown / wrong-purpose / forged job id / another crew's card / another
 * agency's card / closed card / draft card all render the IDENTICAL unavailable
 * page (same status, same body); only a hash is stored; prices, tenant contact
 * and landlord data follow the settings and rules; last_used_at on every open.
 */
final class CrewPageLinkTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Page');
        $this->raw = $this->issue($this->crew);
    }

    private function issue(RentalCrew $crew): string
    {
        return app(RentalSecureAccessTokenService::class)->issueForCrew($crew, $this->admin)['raw_token'];
    }

    private function page(?string $raw = null): \Illuminate\Testing\TestResponse
    {
        return $this->get('/secure/crews/' . ($raw ?? $this->raw));
    }

    private function assertUnavailable(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(404)->assertSee('data-crew-link-unavailable', false)->assertSee('no longer available');
    }

    private function otherAgencyCard(): array
    {
        $agency = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ctr-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'email' => 'ct-' . uniqid() . '@example.invalid']);
        $property = Property::forceCreate(['agency_id' => $agency->id, 'agent_id' => $admin->id, 'branch_id' => $branch->id, 'title' => '9 Kloof Street', 'status' => 'active', 'listing_type' => 'rental']);
        $crew = RentalCrew::create(['agency_id' => $agency->id, 'name' => 'Cape Team', 'created_by_user_id' => $admin->id]);
        $card = RentalJobCard::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'title' => 'Cape Town secret job',
            'status' => 'scheduled', 'rental_crew_id' => $crew->id, 'scheduled_at' => now()->addDay(), 'created_by_user_id' => $admin->id,
        ]);

        return [$agency, $crew, $card, $admin];
    }

    // ── the page ────────────────────────────────────────────────────────

    public function test_the_link_opens_without_a_login_and_lists_that_crews_cards_with_what_to_load(): void
    {
        $this->makeJobCard(['title' => 'Fix the geyser', 'scheduled_at' => now()->addHours(2)]);
        $second = $this->makeJobCard(['title' => 'Second geyser', 'scheduled_at' => now()->addDays(2)]);

        $resp = $this->page()->assertOk();

        $resp->assertSee('data-crew-page', false)->assertSee('Team 1')->assertSee('Fix the geyser')->assertSee('Second geyser');
        $resp->assertSee('What to load')->assertSee('Geyser element');
        $resp->assertSee('data-job-id="' . $second->id . '"', false);
        $resp->assertDontSee('Plumber hour', false); // labour is not stock
    }

    public function test_the_what_to_load_total_is_the_sum_of_the_part_lines_of_the_listed_cards(): void
    {
        $this->makeJobCard(['title' => 'Job 1', 'scheduled_at' => now()->addHours(1)]);   // 2 x Geyser element
        $this->makeJobCard(['title' => 'Job 2', 'scheduled_at' => now()->addDays(3)]);    // 2 x Geyser element
        $this->makeJobCard(['title' => 'Far job', 'scheduled_at' => now()->addDays(40)]); // outside the window

        $html = $this->page()->assertOk()->getContent();

        preg_match('/data-qty>\s*([0-9.]+)\s*each/', $html, $m);
        $this->assertSame('4', $m[1] ?? null, 'exactly the two listed cards\' parts, summed');
        $this->assertStringContainsString('2 jobs', $html);
    }

    public function test_an_empty_crew_page_says_so_instead_of_breaking(): void
    {
        $this->page()->assertOk()->assertSee('data-crew-page-empty', false)->assertSee('Nothing is booked');
    }

    public function test_no_other_crews_or_agencys_cards_appear(): void
    {
        $mine = $this->makeJobCard(['title' => 'My crew job']);
        $other = $this->makeJobCard(['title' => 'Team 2 job'], RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'created_by_user_id' => $this->admin->id]));
        [, , $foreign] = $this->otherAgencyCard();

        $resp = $this->page()->assertOk();

        $resp->assertSee('data-job-id="' . $mine->id . '"', false);
        $resp->assertDontSee('data-job-id="' . $other->id . '"', false)->assertDontSee('Team 2 job');
        $resp->assertDontSee('Cape Town secret job')->assertDontSee('data-job-id="' . $foreign->id . '"', false);
    }

    public function test_draft_quoted_and_closed_cards_are_not_on_the_page(): void
    {
        $this->makeJobCard(['title' => 'Draft one', 'status' => 'draft']);
        $this->makeJobCard(['title' => 'Quoted one', 'status' => 'quoted']);
        $this->makeJobCard(['title' => 'Cancelled one', 'status' => 'cancelled', 'cancelled_at' => now()]);
        $this->makeJobCard(['title' => 'Booked one', 'status' => 'approved']);

        $resp = $this->page()->assertOk();

        $resp->assertSee('Booked one')->assertDontSee('Draft one')->assertDontSee('Quoted one')->assertDontSee('Cancelled one');
    }

    public function test_recently_completed_is_listed_read_only_without_a_link_into_the_card(): void
    {
        $done = $this->makeJobCard(['title' => 'Finished geyser', 'status' => 'completed', 'completed_at' => now()->subDay()]);

        $resp = $this->page()->assertOk();

        $resp->assertSee('data-recent', false)->assertSee('Finished geyser');
        $resp->assertDontSee('data-job-id="' . $done->id . '"', false);
        $this->assertUnavailable($this->get("/secure/crews/{$this->raw}/job-cards/{$done->id}"));
    }

    public function test_every_open_refreshes_last_used_at_but_the_open_log_is_throttled(): void
    {
        $token = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->firstOrFail();
        $this->assertNull($token->last_used_at);

        $this->page()->assertOk();
        $first = $token->fresh()->last_used_at;
        $this->assertNotNull($first);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->page()->assertOk();
        $this->assertTrue($token->fresh()->last_used_at->gt($first), 'last_used_at updates on every open');
        $this->assertSame(1, \App\Models\RentalCrewLinkEvent::withoutGlobalScopes()->where('token_id', $token->id)->where('event', 'opened')->count(), 'one log row inside 10 minutes');

        Carbon::setTestNow(now()->addMinutes(11));
        $this->page()->assertOk();
        $this->assertSame(2, \App\Models\RentalCrewLinkEvent::withoutGlobalScopes()->where('token_id', $token->id)->where('event', 'opened')->count(), 'a new row after the window');
        Carbon::setTestNow();
    }

    // ── lifetime ────────────────────────────────────────────────────────

    public function test_the_standing_link_has_no_expiry_by_default_and_is_still_live_a_year_later(): void
    {
        $token = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->firstOrFail();
        $this->assertNull($token->expires_at);

        Carbon::setTestNow(now()->addDays(400));
        $this->page()->assertOk();
        Carbon::setTestNow();
    }

    public function test_an_agency_set_expiry_ends_the_link_on_time(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_standing_link_expiry_days' => 30]);
        $raw = $this->issue($this->crew); // regenerate under the new setting
        $this->assertUnavailable($this->page()); // the earlier link died when this one was issued

        Carbon::setTestNow(now()->addDays(29));
        $this->page($raw)->assertOk();
        Carbon::setTestNow(now()->addDays(2));
        $this->assertUnavailable($this->page($raw));
        Carbon::setTestNow();
    }

    public function test_regenerate_kills_the_old_link_on_the_very_next_request_and_the_new_one_works(): void
    {
        $this->page()->assertOk();

        $new = $this->issue($this->crew);

        $this->assertUnavailable($this->page());
        $this->page($new)->assertOk();
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->whereNull('revoked_at')->count(), 'one live link per crew');
    }

    public function test_revoke_kills_the_link_on_the_very_next_request(): void
    {
        $this->page()->assertOk();

        app(RentalSecureAccessTokenService::class)->revokeAllFor($this->crew);

        $this->assertUnavailable($this->page());
    }

    public function test_an_archived_or_inactive_crew_link_is_unavailable(): void
    {
        $this->crew->forceFill(['is_active' => false])->save();
        $this->assertUnavailable($this->page());

        $this->crew->forceFill(['is_active' => true])->save();
        $this->page()->assertOk();

        $this->crew->delete();
        $this->assertUnavailable($this->page());
    }

    public function test_switching_crew_links_off_for_the_agency_kills_every_link_at_once(): void
    {
        $card = $this->makeJobCard();
        $this->page()->assertOk();

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);

        $this->assertUnavailable($this->page());
        $this->assertUnavailable($this->get("/secure/crews/{$this->raw}/job-cards/{$card->id}"));
    }

    public function test_only_a_hash_is_stored_never_the_raw_link(): void
    {
        $row = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->firstOrFail();

        $this->assertSame(hash('sha256', $this->raw), $row->token_hash);
        $this->assertNotSame($this->raw, $row->token_hash);
        $this->assertSame(RentalSecureAccessToken::PURPOSE_CREW_STANDING, $row->purpose);
        $this->assertNull($row->rental_job_card_id);
        $this->assertNull($row->rental_work_order_id);
        $this->assertSame(64, strlen($this->raw));
    }

    // ── the identical "unavailable" page ───────────────────────────────

    public function test_every_dead_link_renders_the_identical_page(): void
    {
        $card = $this->makeJobCard();
        $draft = $this->makeJobCard(['status' => 'draft']);
        $theirCrew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'created_by_user_id' => $this->admin->id]);
        $theirCard = $this->makeJobCard([], $theirCrew);
        [, , $foreign] = $this->otherAgencyCard();
        $tokens = app(RentalSecureAccessTokenService::class);
        $jobLink = $tokens->issueForJobCard($card, $this->admin)['raw_token']; // a PER-JOB crew token

        $bodies = [
            'unknown' => $this->page(str_repeat('a', 64))->getContent(),
            'garbage' => $this->page('x')->getContent(),
            'per-job token on the crew page' => $this->page($jobLink)->getContent(),
            "another crew's card" => $this->get("/secure/crews/{$this->raw}/job-cards/{$theirCard->id}")->getContent(),
            "another agency's card" => $this->get("/secure/crews/{$this->raw}/job-cards/{$foreign->id}")->getContent(),
            'draft card' => $this->get("/secure/crews/{$this->raw}/job-cards/{$draft->id}")->getContent(),
            'no such card' => $this->get("/secure/crews/{$this->raw}/job-cards/99999999")->getContent(),
        ];
        $tokens->revokeAllFor($this->crew);
        $bodies['revoked'] = $this->page()->getContent();

        foreach ($bodies as $why => $body) {
            $this->assertSame($bodies['unknown'], $body, "'{$why}' must render exactly the same page as an unknown link");
        }
        foreach (['unknown', "another agency's card", 'draft card'] as $why) {
            $this->assertStringNotContainsString('Cape Town secret job', $bodies[$why]);
            $this->assertStringNotContainsString('Team 1', $bodies[$why]);
        }
    }

    public function test_the_crew_page_token_does_not_open_a_per_job_link_nor_the_contractor_page(): void
    {
        $card = $this->makeJobCard();

        $this->get("/secure/job-cards/{$this->raw}")->assertSee('no longer available');
        $this->get("/secure/work-orders/{$this->raw}")->assertSee('no longer available');
    }

    public function test_a_non_numeric_card_id_is_a_404_never_a_server_error(): void
    {
        $this->get("/secure/crews/{$this->raw}/job-cards/abc")->assertStatus(404);
    }

    // ── what a crew sees of a card ─────────────────────────────────────

    public function test_a_card_opened_from_the_crew_page_shows_the_shared_per_job_view(): void
    {
        $card = $this->makeJobCard();

        $resp = $this->get("/secure/crews/{$this->raw}/job-cards/{$card->id}")->assertOk();

        $resp->assertSee('data-crew-page-job', false)->assertSee('data-back-to-crew-page', false);
        $resp->assertSee('Fix the geyser')->assertSee('Key is under the pot plant')->assertSee('Drain the geyser');
        $resp->assertSee('Mark work completed')->assertSee('Upload photos');
    }

    public function test_costs_appear_only_with_the_agency_setting_and_never_the_selling_price(): void
    {
        $card = $this->makeJobCard(['scheduled_at' => now()->addHours(3)]);
        // 2 x R200 part cost; the part SELLS at 2 x R450 = R900 (never shown to a crew).
        \App\Models\RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')->update(['unit_cost' => 200, 'cost_total' => 400]);

        $off = $this->page()->getContent() . $this->get("/secure/crews/{$this->raw}/job-cards/{$card->id}")->getContent();
        $this->assertStringNotContainsString('400.00', $off);
        $this->assertStringNotContainsString('900.00', $off);
        $this->assertStringNotContainsString('1,800', $off);

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_costs' => true]);

        $on = $this->page()->getContent();
        $this->assertStringContainsString('400.00', $on);
        $this->assertStringNotContainsString('900.00', $on);
        $job = $this->get("/secure/crews/{$this->raw}/job-cards/{$card->id}")->getContent();
        $this->assertStringContainsString('400.00', $job);
        $this->assertStringNotContainsString('900.00', $job);
        $this->assertStringNotContainsString('1,800', $job);
    }

    public function test_tenant_contact_only_with_the_setting_and_landlord_data_never(): void
    {
        $card = $this->makeJobCard();
        $this->attachTenant($card, 'Tina', 'Tenant', '0831112222');
        $landlord = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlordson', 'phone' => '0829998888', 'email' => 'landlord@example.invalid']);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');
        $job = "/secure/crews/{$this->raw}/job-cards/{$card->id}";

        $off = $this->get($job)->getContent();
        $this->assertStringNotContainsString('Tina', $off);
        $this->assertStringNotContainsString('0831112222', $off);

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_tenant_contact' => true]);
        $on = $this->get($job)->getContent();
        $this->assertStringContainsString('Tina Tenant', $on);
        $this->assertStringContainsString('0831112222', $on);

        foreach ([$off, $on, $this->page()->getContent()] as $html) {
            $this->assertStringNotContainsString('Landlordson', $html);
            $this->assertStringNotContainsString('0829998888', $html);
            $this->assertStringNotContainsString('landlord@example.invalid', $html);
        }
    }

    public function test_the_page_names_the_agency_from_its_record_and_assumes_no_one_agency(): void
    {
        $this->agency->forceFill(['name' => 'Karoo Lettings (Pty) Ltd'])->save();

        $html = $this->page()->assertOk()->getContent();

        $this->assertStringContainsString('Karoo Lettings (Pty) Ltd', $html);
        $this->assertStringNotContainsString('Home Finders', $html);
        $this->assertStringNotContainsString('HFC', $html);
    }
}
