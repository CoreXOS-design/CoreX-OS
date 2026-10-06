<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalJobCard;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalMailDispatcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.28 — the office side: own / branch /
 * agency scoping, the `rental_job_cards.share` permission matrix, the email
 * going through the agency mailbox path (asserted with a fake dispatcher, to
 * @example.invalid only — never a plain Mailable) and the audit rows.
 */
final class RentalJobCardCrewLinkOfficeTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Office');
        $this->card = $this->makeJobCard();

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

    // ── email via the agency mailbox path ───────────────────────────────

    public function test_email_goes_through_the_dispatcher_not_a_plain_mailable(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew-lead@example.invalid'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->fake->sent);
        [$to, $mail] = $this->fake->sent[0];
        $this->assertSame('crew-lead@example.invalid', $to);
        $this->assertInstanceOf(RentalJobCardCrewLinkMail::class, $mail);
        $this->assertSame($this->admin->id, $mail->sendingAgentId(), 'sent AS the agent who pressed the button');
        $this->assertStringContainsString('/secure/job-cards/', $mail->url);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();

        $this->assertSame(1, $this->card->updates()->where('update_type', 'link_emailed')->count());
        $this->assertStringContainsString('crew-lead@example.invalid', $this->card->updates()->where('update_type', 'link_emailed')->first()->note);
    }

    public function test_the_emailed_link_opens_the_job(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew@example.invalid']);
        $url = $this->fake->sent[0][1]->url;

        $this->get($url)->assertOk()->assertSee('Fix the geyser');
    }

    public function test_emailing_the_link_just_generated_reuses_it_instead_of_issuing_another(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $this->card));
        $raw = session('crew_link_token');
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->count());

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew@example.invalid', 'link_token' => $raw]);

        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $this->card->id)->count());
        $this->assertStringEndsWith($raw, $this->fake->sent[0][1]->url);
    }

    public function test_emailing_without_the_token_issues_a_new_link_and_kills_the_old_one(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $this->card));
        $old = session('crew_link_token');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew@example.invalid']);

        $this->get('/secure/job-cards/' . $old)->assertSee('no longer available');
        $this->get($this->fake->sent[0][1]->url)->assertSee('Fix the geyser');
    }

    public function test_a_posted_token_for_another_card_is_not_trusted(): void
    {
        $other = $this->makeJobCard(['title' => 'Other']);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $other));
        $otherRaw = session('crew_link_token');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew@example.invalid', 'link_token' => $otherRaw]);

        $this->assertStringNotContainsString($otherRaw, $this->fake->sent[0][1]->url);
        $this->get('/secure/job-cards/' . $otherRaw)->assertSee('Other', false);
    }

    public function test_email_validation_and_send_failure(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'nope'])->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), [])->assertSessionHasErrors('email');
        $this->assertCount(0, $this->fake->sent);

        $this->fake->failWith = new \RuntimeException('smtp down');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'crew@example.invalid'])
            ->assertSessionHasErrors('crew_link');
        $this->assertSame(0, $this->card->updates()->where('update_type', 'link_emailed')->count());
        $this->assertNotEmpty(session('crew_link_url'), 'the link is still live and shown so it can be sent another way');
    }

    // ── permission matrix ───────────────────────────────────────────────

    public function test_the_share_permission_is_required_for_issue_revoke_and_email(): void
    {
        $viewer = $this->agentWith(['rental_job_cards.view' => 'all', 'rental_job_cards.create' => 'all']);

        $this->actingAs($viewer)->post(route('corex.rental-job-cards.crew-link.issue', $this->card))->assertForbidden();
        $this->actingAs($viewer)->delete(route('corex.rental-job-cards.crew-link.revoke', $this->card))->assertForbidden();
        $this->actingAs($viewer)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'a@example.invalid'])->assertForbidden();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());

        $sharer = $this->agentWith(['rental_job_cards.view' => 'all', 'rental_job_cards.share' => 'all']);
        $this->actingAs($sharer)->post(route('corex.rental-job-cards.crew-link.issue', $this->card))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, RentalSecureAccessToken::withoutGlobalScopes()->count());
    }

    public function test_the_panel_only_shows_to_users_with_the_share_permission(): void
    {
        $viewer = $this->agentWith(['rental_job_cards.view' => 'all']);
        $this->actingAs($viewer)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->assertDontSee('Share with crew');

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->assertSee('Share with crew')->assertSee('Generate link');
    }

    // ── scoping ─────────────────────────────────────────────────────────

    public function test_an_own_scope_user_cannot_share_another_users_card_by_direct_url(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'own', 'rental_job_cards.share' => 'own']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-link.issue', $this->card))->assertForbidden();
        $this->actingAs($agent)->delete(route('corex.rental-job-cards.crew-link.revoke', $this->card))->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-link.email', $this->card), ['email' => 'a@example.invalid'])->assertForbidden();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());
    }

    public function test_an_own_scope_user_can_share_their_own_card(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'own', 'rental_job_cards.share' => 'own']);
        $mine = $this->makeJobCard(['created_by_user_id' => $agent->id, 'title' => 'Mine']);

        $this->actingAs($agent)->post(route('corex.rental-job-cards.crew-link.issue', $mine))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_branch_scope_user_is_limited_to_their_branch(): void
    {
        $agent = $this->agentWith(['rental_job_cards.view' => 'branch', 'rental_job_cards.share' => 'branch']);
        $otherBranch = \App\Models\Branch::forceCreate(['name' => 'Elsewhere', 'agency_id' => $this->agency->id]);
        $agent->forceFill(['branch_id' => $otherBranch->id])->save();

        $this->actingAs($agent->fresh())->post(route('corex.rental-job-cards.crew-link.issue', $this->card))->assertForbidden();
    }

    public function test_another_agencys_user_gets_a_404(): void
    {
        $foreignAgency = \App\Models\Agency::create(['name' => 'Foreign Agency', 'slug' => 'foreign-' . uniqid()]);
        $foreignBranch = \App\Models\Branch::forceCreate(['name' => 'F', 'agency_id' => $foreignAgency->id]);
        $foreign = User::factory()->create(['agency_id' => $foreignAgency->id, 'branch_id' => $foreignBranch->id, 'role' => 'admin']);

        $this->actingAs($foreign)->post(route('corex.rental-job-cards.crew-link.issue', $this->card))->assertNotFound();
        $this->assertSame(0, RentalSecureAccessToken::withoutGlobalScopes()->count());
    }

    // ── the panel ───────────────────────────────────────────────────────

    public function test_the_panel_shows_status_but_never_the_raw_url_after_the_first_render(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.crew-link.issue', $this->card));
        $raw = session('crew_link_token');

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->assertSee($raw);
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))
            ->assertOk()->assertDontSee($raw)->assertSee('Link active')->assertSee('Last opened: never')->assertSee('Re-issue link')->assertSee('Revoke');
    }

    public function test_the_email_box_is_prefilled_with_the_crews_address_but_editable(): void
    {
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))
            ->assertOk()->assertSee('value="team1@example.invalid"', false);
    }

    public function test_a_closed_card_has_no_share_panel(): void
    {
        $this->card->forceFill(['status' => RentalJobCard::STATUS_COMPLETED])->save();

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))->assertOk()->assertDontSee('Share with crew');
    }

    public function test_the_header_chip_shows_crew_completion(): void
    {
        $this->card->recordCrewCompletion('Sipho Dlamini', 'crew_link', '203.0.113.5', 'Phone', null);

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))
            ->assertOk()->assertSee('Crew completed — Sipho Dlamini — via crew link');
    }
}
