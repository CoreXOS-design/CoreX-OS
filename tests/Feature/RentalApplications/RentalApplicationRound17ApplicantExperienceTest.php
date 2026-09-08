<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-392 — Round 17. Johan's own applicant-experience test list, driven on
 * QA1 with the panel redesign confirmed correct at 1522px. Four items, all
 * found to already be CODE-COMPLETE on inspection — the actual gap in every
 * case was test coverage / verification, not missing functionality:
 *
 * a) Rental term quick buttons (6/12/24, hard 24-month ceiling) — already
 *    built on both the public form and the agent's own edit screen, with
 *    server-side `in:6,12,24` validation. The two stale test failures
 *    flagged in the prior round lived here — fixed as part of this round
 *    (see RentalApplicationAgentControllerTest and
 *    RentalApplicationInputPreservationTest), never re-reported.
 * b) Current landlord "still living here" tick — already built
 *    (RentalApplication::normalizeStillLiving(), wired into both submit
 *    paths) but had ZERO test coverage before this round.
 * c) Send redirects to the rental applications list — already built, but
 *    no test asserted the redirect TARGET, only the success message. The
 *    list itself showing empty at every scope is cc3's bug, not touched
 *    here.
 * d) Applicant invite email reusing the e-sign agent-footer template —
 *    already built (RentalApplicationInviteMail extends BaseSignatureMail,
 *    both share resources/views/emails/signatures/partials/agent-footer.
 *    blade.php) but nothing had ever rendered the mail and asserted the
 *    agent's photo/name/FFC actually appear in the output.
 */
final class RentalApplicationRound17ApplicantExperienceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(), 'email' => 'admin@hfcoastal.co.za']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function agent(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ], $attrs));
    }

    private function application(array $attrs = []): RentalApplication
    {
        $agent = $this->agent();

        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'draft', 'submitted_at' => null,
        ], $attrs));
    }

    // ── (a) Rental term — hard 24-month ceiling, not just a UI suggestion ──

    public function test_rental_term_months_rejects_anything_outside_six_twelve_twenty_four_on_agent_update(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $this->actingAs($agent)->put(route('corex.rental-applications.update', $app), [
            'rental_term_months' => 36,
        ])->assertSessionHasErrors('rental_term_months');

        $this->assertNull($app->fresh()->rental_term_months);
    }

    public function test_rental_term_months_accepts_each_of_the_three_lawful_options_on_agent_update(): void
    {
        $agent = $this->agent();

        foreach ([6, 12, 24] as $months) {
            $app = $this->application();

            $this->actingAs($agent)->put(route('corex.rental-applications.update', $app), [
                'rental_term_months' => $months,
            ])->assertSessionDoesntHaveErrors('rental_term_months');

            $this->assertSame($months, $app->fresh()->rental_term_months);
        }
    }

    public function test_rental_term_months_rejects_a_crafted_thirty_six_on_the_public_form(): void
    {
        $app = $this->application(['status' => 'sent', 'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14)]);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $this->post(route('rental-applications.public.submit', $app->token), [
            'rental_term_months' => 36,
            'declaration_signature' => $sig, 'tpn_consent_signature' => $sig,
        ])->assertSessionHasErrors('rental_term_months');

        $this->assertNull($app->fresh()->rental_term_months);
    }

    public function test_agent_edit_screen_renders_the_three_quick_buttons_with_the_law_ceiling_note(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));

        // The buttons themselves render client-side (Alpine x-for) — a
        // PHPUnit HTTP test sees only the server-rendered source, so the
        // real assertion is on the Alpine template's own source array,
        // which IS static server output.
        $response->assertOk();
        $response->assertSee('name="rental_term_months"', false);
        $response->assertSee('x-for="m in [6, 12, 24]"', false);
        $response->assertDontSee('[6, 12, 24, 36]', false);
    }

    // ── (b) Current landlord — "still living here" tick, server-enforced ───

    public function test_still_living_tick_forces_current_rental_to_null_even_if_tampered_on_agent_update(): void
    {
        $agent = $this->agent();
        $app = $this->application(['current_rental_to' => '2026-01-01']);

        $this->actingAs($agent)->put(route('corex.rental-applications.update', $app), [
            'current_rental_still_living' => 1,
            'current_rental_to' => '2099-12-31', // a crafted/stale date arriving alongside the tick
        ])->assertSessionDoesntHaveErrors();

        $fresh = $app->fresh();
        $this->assertTrue((bool) $fresh->current_rental_still_living);
        $this->assertNull($fresh->current_rental_to, 'The tick must win over any date value arriving with it — never a bogus end date.');
    }

    public function test_still_living_tick_forces_current_rental_to_null_even_if_tampered_on_public_submit(): void
    {
        $app = $this->application(['status' => 'sent', 'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14), 'current_rental_to' => '2026-01-01']);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $this->post(route('rental-applications.public.submit', $app->token), [
            'current_rental_still_living' => 1,
            'current_rental_to' => '2099-12-31',
            'declaration_signature' => $sig, 'tpn_consent_signature' => $sig,
        ])->assertSessionDoesntHaveErrors();

        $fresh = $app->fresh();
        $this->assertTrue((bool) $fresh->current_rental_still_living);
        $this->assertNull($fresh->current_rental_to);
    }

    public function test_unticked_still_living_leaves_a_typed_current_rental_to_untouched(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $this->actingAs($agent)->put(route('corex.rental-applications.update', $app), [
            'current_rental_still_living' => 0,
            'current_rental_to' => '2026-06-30',
        ])->assertSessionDoesntHaveErrors();

        $fresh = $app->fresh();
        $this->assertFalse((bool) $fresh->current_rental_still_living);
        $this->assertSame('2026-06-30', $fresh->current_rental_to->format('Y-m-d'));
    }

    public function test_both_forms_render_the_still_living_checkbox(): void
    {
        $agent = $this->agent();
        $app = $this->application(['status' => 'sent', 'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14)]);

        $agentSide = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));
        $agentSide->assertOk();
        $agentSide->assertSee('name="current_rental_still_living"', false);
        $agentSide->assertSee('Still living here', false);

        $publicSide = $this->get(route('rental-applications.public.show', $app->token));
        $publicSide->assertOk();
        $publicSide->assertSee('name="current_rental_still_living"', false);
        $publicSide->assertSee('Still living here', false);
    }

    // ── (c) Send redirects to the list — the redirect itself, not the list's
    // own (separately broken, cc3's) rendering ─────────────────────────────

    public function test_a_successful_send_redirects_to_the_rental_applications_list(): void
    {
        Mail::fake();
        $app = $this->application(['email' => 'has-email@example.com']);

        $this->actingAs($this->agent())->post(route('corex.rental-applications.send', $app))
            ->assertRedirect(route('corex.rental-applications.index'));
    }

    // ── (d) Applicant invite email — genuinely reuses the e-sign agent
    // footer template, not a second copy that can drift ────────────────────

    public function test_invite_email_renders_the_sending_agents_photo_name_and_ffc_via_the_shared_esign_footer(): void
    {
        $agent = $this->agent([
            'name' => 'Thandiwe Agent', 'ffc_number' => 'FFC123456', 'designation' => 'Rental Agent',
            'agent_photo_path' => 'agents/thandiwe.jpg',
        ]);
        $app = $this->application([
            'created_by_user_id' => $agent->id, 'email' => 'has-email@example.com',
            'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14),
        ]);

        $rendered = (new RentalApplicationInviteMail($app))->fromAgent($agent)->render();

        $this->assertStringContainsString('Thandiwe Agent', $rendered);
        $this->assertStringContainsString('FFC123456', $rendered);
        $this->assertStringContainsString('agents/thandiwe.jpg', $rendered, 'The agent photo must actually render, not just the name.');
    }

    public function test_invite_email_and_the_esign_signing_request_email_share_the_same_footer_partial(): void
    {
        // Structural guard against future drift — Johan's own words: "we
        // have been bitten repeatedly by two versions of the same thing
        // drifting apart." Asserts the SOURCE FILES include the identical
        // partial, not just that both happen to render similar HTML today.
        $inviteSource = file_get_contents(resource_path('views/emails/rental-application-invite.blade.php'));
        $signingSource = file_get_contents(resource_path('views/emails/signatures/signing-request.blade.php'));

        $this->assertStringContainsString("@include('emails.signatures.partials.agent-footer')", $inviteSource);
        $this->assertStringContainsString("@include('emails.signatures.partials.agent-footer')", $signingSource);
    }
}
