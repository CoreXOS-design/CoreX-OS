<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Mail\Rentals\RentalCrewStandingLinkMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalCrew;
use App\Models\RentalCrewLinkEvent;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.29 — the "Crew link" panel on the Rental Crews edit
 * page and the routes behind it.
 *
 * Input paths proven: permission (no `rental_job_cards.share` = no panel and a
 * 403 on every route); generate shows the URL ONCE (a second page load never
 * does) and stores only a hash; generate-and-email; "email this link" with the
 * just-generated URL; a forged / stale / other-crew / malformed link_url and a
 * bad address are refused; a mail failure still leaves the link created; email
 * goes through the agency mailbox dispatcher (Mail::fake() receives nothing);
 * regenerate kills the old link; revoke kills it and is safe to repeat; inactive
 * crew / crew links switched off cannot issue; another agency's crew is a 404;
 * the log (newest first, filterable, paginated, empty state); the link-status
 * column on the Crews list. Every address is @example.invalid.
 */
final class RentalCrewLinkPanelTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Panel');

        $this->fake = new class extends RentalMailDispatcher {
            public array $sent = [];
            public ?\Throwable $failWith = null;

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                if ($this->failWith) {
                    throw $this->failWith;
                }
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $this->fake);
        Mail::fake();
    }

    private function route(string $name, ?RentalCrew $crew = null): string
    {
        return route("corex.rental-crews.{$name}", $crew ?? $this->crew);
    }

    private function generate(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post($this->route('link.issue'), $data);
    }

    private function urlFrom(\Illuminate\Testing\TestResponse $response): string
    {
        $url = $response->getSession()->get('crew_link_url');
        $this->assertNotNull($url, 'the generated URL must be flashed for the page to show once');

        return $url;
    }

    private function tokenOf(string $url): string
    {
        return substr($url, strrpos($url, '/') + 1);
    }

    private function eventCount(string $event, ?RentalCrew $crew = null): int
    {
        return RentalCrewLinkEvent::withoutGlobalScopes()->where('rental_crew_id', ($crew ?? $this->crew)->id)->where('event', $event)->count();
    }

    private function userWith(array $permissions, string $role = 'agent'): User
    {
        Role::firstOrCreate(['name' => $role, 'agency_id' => $this->agency->id], ['label' => ucfirst($role)]);
        foreach ($permissions as $key) {
            RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'email' => 'u-' . uniqid() . '@example.invalid']);
    }

    // ── permission ──────────────────────────────────────────────────────

    public function test_without_the_share_permission_there_is_no_panel_and_every_route_is_forbidden(): void
    {
        $user = $this->userWith(['rental_catalogue.view', 'rental_catalogue.manage']);

        $this->actingAs($user)->get($this->route('edit'))->assertOk()->assertDontSee('id="crew-link-panel"', false);
        $this->actingAs($user)->post($this->route('link.issue'))->assertForbidden();
        $this->actingAs($user)->delete($this->route('link.revoke'))->assertForbidden();
        $this->actingAs($user)->post($this->route('link.email'), ['to' => 'a@example.invalid', 'link_url' => 'x'])->assertForbidden();
        $this->actingAs($user)->get($this->route('link.events'))->assertForbidden();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->count());
    }

    public function test_with_the_share_permission_the_panel_shows(): void
    {
        $user = $this->userWith(['rental_catalogue.view', 'rental_catalogue.manage', 'rental_job_cards.share'], 'dispatcher');

        $this->actingAs($user)->get($this->route('edit'))->assertOk()->assertSee('id="crew-link-panel"', false)->assertSee('Create link');
    }

    public function test_another_agencys_crew_is_a_404_on_every_route(): void
    {
        $agency = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $foreign = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'name' => 'Foreign crew', 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('corex.rental-crews.link.issue', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('corex.rental-crews.link.revoke', $foreign))->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.rental-crews.link.email', $foreign), ['to' => 'a@example.invalid', 'link_url' => 'x'])->assertNotFound();
        $this->actingAs($this->admin)->get(route('corex.rental-crews.link.events', $foreign))->assertNotFound();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $foreign->id)->count());
    }

    // ── generate / show once ───────────────────────────────────────────

    public function test_generate_shows_the_url_once_stores_only_a_hash_and_logs_who_did_it(): void
    {
        $resp = $this->generate()->assertRedirect($this->route('edit'))->assertSessionHasNoErrors();
        $url = $this->urlFrom($resp);
        $raw = $this->tokenOf($url);

        $first = $this->actingAs($this->admin)->withSession(['crew_link_url' => $url])->get($this->route('edit'));
        $first->assertOk()->assertSee('data-crew-link-new', false)->assertSee($url, false)->assertSee('shown only once');

        // The very next page load (no flash) never shows it again — nothing raw is kept anywhere.
        $second = $this->actingAs($this->admin)->get($this->route('edit'));
        $second->assertOk()->assertDontSee('data-crew-link-new', false)->assertDontSee($raw, false);
        $second->assertSee('data-crew-link-state="live"', false)->assertSee('Stands until it is revoked');

        $row = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->firstOrFail();
        $this->assertSame(hash('sha256', $raw), $row->token_hash);
        $this->assertSame($this->admin->id, $row->created_by_user_id);
        $event = RentalCrewLinkEvent::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->where('event', 'issued')->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->get('/secure/crews/' . $raw)->assertOk();
    }

    public function test_generate_and_email_sends_through_the_dispatcher_to_the_typed_address(): void
    {
        $resp = $this->generate(['email_to' => 'foreman@example.invalid'])->assertSessionHasNoErrors();
        $url = $this->urlFrom($resp);

        $this->assertCount(1, $this->fake->sent);
        [$to, $mail] = $this->fake->sent[0];
        $this->assertSame('foreman@example.invalid', $to);
        $this->assertInstanceOf(RentalCrewStandingLinkMail::class, $mail);
        $this->assertSame($this->admin->id, $mail->sendingAgentId(), 'sent AS the agent who pressed the button');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $html = $mail->render();
        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('Team 1', $html);
        $this->assertStringContainsString('Crew Panel Agency', $html);
        $this->assertStringNotContainsString('Home Finders', $html);
        $this->assertSame(1, $this->eventCount('emailed'));
        $this->assertSame('Link emailed to foreman@example.invalid', RentalCrewLinkEvent::withoutGlobalScopes()->where('event', 'emailed')->value('note'));
    }

    public function test_the_crews_own_address_is_prefilled_in_the_panel(): void
    {
        $this->actingAs($this->admin)->get($this->route('edit'))->assertSee('value="team1@example.invalid"', false);
    }

    public function test_generating_with_a_malformed_email_is_refused_and_creates_nothing(): void
    {
        $this->generate(['email_to' => 'not an address'])->assertSessionHasErrors('email_to');

        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->count());
        $this->assertSame([], $this->fake->sent);
    }

    public function test_a_mail_failure_still_leaves_the_link_created_and_tells_the_agent_plainly(): void
    {
        $this->fake->failWith = new \RuntimeException('SMTP connection refused');

        $resp = $this->generate(['email_to' => 'foreman@example.invalid']);

        $resp->assertSessionHasErrors('crew_link');
        $this->assertStringContainsString('could not be sent', session('errors')->first('crew_link'));
        $this->assertNotNull($resp->getSession()->get('crew_link_url'), 'the agent can still copy the link');
        $this->assertSame(0, $this->eventCount('emailed'));
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->whereNull('revoked_at')->count());
    }

    // ── email this link ────────────────────────────────────────────────

    public function test_email_this_link_sends_the_just_generated_url(): void
    {
        $url = $this->urlFrom($this->generate());

        $this->actingAs($this->admin)->post($this->route('link.email'), ['to' => 'boss@example.invalid', 'link_url' => $url])
            ->assertRedirect($this->route('edit'))->assertSessionHasNoErrors();

        $this->assertCount(1, $this->fake->sent);
        $this->assertSame('boss@example.invalid', $this->fake->sent[0][0]);
        $this->assertStringContainsString($url, $this->fake->sent[0][1]->render());
        $this->assertSame($url, session('crew_link_url'), 'the URL stays on screen so it can still be copied');
    }

    public function test_email_refuses_a_forged_stale_foreign_or_malformed_link_and_a_bad_address(): void
    {
        $url = $this->urlFrom($this->generate());
        $forged = url('/secure/crews/' . str_repeat('a', 64));
        $otherCrew = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'created_by_user_id' => $this->admin->id]);
        $otherUrl = $this->urlFrom($this->actingAs($this->admin)->post($this->route('link.issue', $otherCrew)));

        $cases = [
            'forged token' => ['boss@example.invalid', $forged],
            'another crew\'s live link' => ['boss@example.invalid', $otherUrl],
            'not a crew link' => ['boss@example.invalid', 'https://example.invalid/phish'],
            'garbage' => ['boss@example.invalid', 'x'],
        ];
        foreach ($cases as $why => [$to, $link]) {
            $this->actingAs($this->admin)->post($this->route('link.email'), ['to' => $to, 'link_url' => $link])->assertSessionHasErrors('crew_link');
        }
        $this->actingAs($this->admin)->post($this->route('link.email'), ['to' => 'nope', 'link_url' => $url])->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->post($this->route('link.email'), ['to' => '', 'link_url' => $url])->assertSessionHasErrors('to');

        // A link that has since been replaced is stale: never emailed.
        $this->generate();
        $this->actingAs($this->admin)->post($this->route('link.email'), ['to' => 'boss@example.invalid', 'link_url' => $url])->assertSessionHasErrors('crew_link');

        $this->assertSame([], $this->fake->sent, 'nothing the system did not issue can be emailed');
    }

    // ── regenerate / revoke ────────────────────────────────────────────

    public function test_regenerate_kills_the_old_link_and_logs_it(): void
    {
        $old = $this->tokenOf($this->urlFrom($this->generate()));
        $this->get('/secure/crews/' . $old)->assertOk();

        $new = $this->tokenOf($this->urlFrom($this->generate()));

        $this->get('/secure/crews/' . $old)->assertStatus(404);
        $this->get('/secure/crews/' . $new)->assertOk();
        $this->assertSame(1, $this->eventCount('issued'));
        $this->assertSame(1, $this->eventCount('regenerated'));
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->whereNull('revoked_at')->count());
    }

    public function test_revoke_kills_the_link_logs_it_and_is_safe_to_repeat(): void
    {
        $raw = $this->tokenOf($this->urlFrom($this->generate()));

        $this->actingAs($this->admin)->delete($this->route('link.revoke'))->assertRedirect($this->route('edit'))->assertSessionHas('success');

        $this->get('/secure/crews/' . $raw)->assertStatus(404);
        $this->assertSame(1, $this->eventCount('revoked'));
        $this->assertSame($this->admin->id, RentalCrewLinkEvent::withoutGlobalScopes()->where('event', 'revoked')->value('actor_user_id'));
        $this->actingAs($this->admin)->get($this->route('edit'))->assertSee('data-crew-link-state="revoked"', false);

        // Pressing it again with nothing live is harmless and says so.
        $this->actingAs($this->admin)->delete($this->route('link.revoke'))->assertRedirect()->assertSessionHas('success', 'There was no live link to revoke.');
        $this->assertSame(1, $this->eventCount('revoked'), 'no phantom second row');
    }

    public function test_an_inactive_crew_or_switched_off_crew_links_cannot_issue(): void
    {
        $this->crew->forceFill(['is_active' => false])->save();
        $this->generate()->assertSessionHasErrors('crew_link');
        $this->crew->forceFill(['is_active' => true])->save();

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_links_enabled' => false]);
        $this->generate()->assertSessionHasErrors('crew_link');

        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->count());
    }

    public function test_the_panel_shows_the_agency_expiry_when_one_is_set(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_standing_link_expiry_days' => 30]);
        $this->generate();

        $this->actingAs($this->admin)->get($this->route('edit'))->assertSee('Valid until ' . now()->addDays(30)->format('j M Y'))->assertDontSee('Stands until it is revoked');
    }

    // ── the log ─────────────────────────────────────────────────────────

    public function test_what_the_crew_does_shows_in_the_panel_log_newest_first(): void
    {
        $raw = $this->tokenOf($this->urlFrom($this->generate()));
        $card = $this->makeJobCard();
        $this->get('/secure/crews/' . $raw);
        $this->get("/secure/crews/{$raw}/job-cards/{$card->id}");
        $this->post("/secure/crews/{$raw}/job-cards/{$card->id}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);

        $html = $this->actingAs($this->admin)->get($this->route('edit'))->getContent();

        foreach (['issued', 'opened', 'job_opened', 'action'] as $kind) {
            $this->assertStringContainsString('data-crew-link-event="' . $kind . '"', $html);
        }
        $this->assertGreaterThan(strpos($html, 'data-crew-link-event="action"'), strpos($html, 'data-crew-link-event="issued"'), 'newest first: the action is above the creation');
        $this->assertStringContainsString('opened 1 time', $html);
    }

    public function test_the_full_log_page_filters_paginates_and_has_an_empty_state(): void
    {
        $this->actingAs($this->admin)->get($this->route('link.events'))->assertOk()->assertSee('Nothing has happened on this link yet.');

        for ($i = 0; $i < 60; $i++) {
            RentalCrewLinkEvent::record($i % 2 ? 'opened' : 'action', $this->agency->id, $this->crew->id, null, null, "Row {$i}");
        }

        $p1 = $this->actingAs($this->admin)->get($this->route('link.events'))->assertOk();
        $p1->assertSee('Row 59')->assertDontSee('Row 9<', false);
        $this->actingAs($this->admin)->get($this->route('link.events') . '?page=2')->assertOk()->assertSee('Row 0');
        $only = $this->actingAs($this->admin)->get($this->route('link.events') . '?event=action')->assertOk();
        $only->assertSee('Row 58')->assertDontSee('Row 59');
        $this->actingAs($this->admin)->get($this->route('link.events') . '?event=revoked')->assertSee('No entries of that kind.');
    }

    // ── the Crews list ─────────────────────────────────────────────────

    public function test_the_crews_list_shows_each_crews_link_status(): void
    {
        $never = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Never linked', 'created_by_user_id' => $this->admin->id]);
        $this->generate();
        $revoked = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Revoked crew', 'created_by_user_id' => $this->admin->id]);
        app(RentalSecureAccessTokenService::class)->issueForCrew($revoked, $this->admin);
        app(RentalSecureAccessTokenService::class)->revokeAllFor($revoked);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-crews.index'))->assertOk()->getContent();

        $cell = fn (RentalCrew $c) => (preg_match('/data-crew-link-cell="' . $c->id . '">(.*?)<\/td>/s', $html, $m) ? strip_tags($m[1]) : '');
        $this->assertStringContainsString('Live', $cell($this->crew));
        $this->assertStringContainsString('No link', $cell($never));
        $this->assertStringContainsString('Revoked', $cell($revoked));
    }

    public function test_the_crews_list_has_no_link_column_without_the_share_permission(): void
    {
        $user = $this->userWith(['rental_catalogue.view']);

        $this->actingAs($user)->get(route('corex.rental-crews.index'))->assertOk()->assertDontSee('data-crew-link-cell', false);
    }
}
