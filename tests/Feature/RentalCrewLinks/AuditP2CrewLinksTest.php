<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\Contact;
use App\Models\RentalCrew;
use App\Models\RentalJobCardCrewReport;
use App\Models\RentalOwnerHold;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\Scopes\AgencyScope;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026 (rentals-faults-work-orders.md section 29), package P2 - the crew's two public links (the job link and the crew page): an owner hold refuses EVERY action
 * (C-C5), a cancelled job says so (C-A4), expiry renews on use (C-D3), the heavy routes are counted once per token (C-D2), and findings are typed once (C-E1).
 */
final class AuditP2CrewLinksTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Audit P2 Crew');
    }

    private function jobLink($card): string
    {
        return app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin)['raw_token'];
    }

    private function hold($card): void
    {
        $owner = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Olly', 'last_name' => 'Owner', 'email' => 'olly-' . uniqid() . '@example.invalid']);
        RentalOwnerHold::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $card->rental_work_order_id, 'stopped_by_contact_id' => $owner->id,
            'reason' => 'Owner wants to think', 'stopped_via' => 'link', 'stopped_at' => now(), 'stage_when_stopped' => 'scheduled',
        ]);
    }

    public function test_an_owner_hold_refuses_ticks_photos_lines_and_send_to_office_on_the_job_link_and_the_crew_page(): void
    {
        $card = $this->makeJobCard();
        $raw = $this->jobLink($card);
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $task = $card->tasks()->first();
        $this->hold($card);
        $msg = 'This job is on hold - please do not attend until you hear from us.';

        $calls = [
            ['/secure/job-cards/' . $raw . '/tasks/' . $task->id . '/tick', []],
            ['/secure/job-cards/' . $raw . '/photos', ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('p.jpg')]]],
            ['/secure/job-cards/' . $raw . '/lines', ['type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit' => 'each', 'unit_cost' => '5.00']],
            ['/secure/job-cards/' . $raw . '/lines/send', ['findings' => 'Perished washer']],
            ['/secure/job-cards/' . $raw . '/complete', ['full_name' => 'Crew Chief', 'confirm' => 1]],
            ['/secure/crews/' . $page . '/job-cards/' . $card->id . '/tasks/' . $task->id . '/tick', []],
            ['/secure/crews/' . $page . '/job-cards/' . $card->id . '/photos', ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('p.jpg')]]],
            ['/secure/crews/' . $page . '/job-cards/' . $card->id . '/lines', ['type' => 'part', 'description' => 'Washer', 'quantity' => 1, 'unit' => 'each', 'unit_cost' => '5.00']],
            ['/secure/crews/' . $page . '/job-cards/' . $card->id . '/lines/send', ['findings' => 'Perished washer']],
        ];
        foreach ($calls as [$url, $data]) {
            $this->post($url, $data)->assertSessionHasErrors('work_order');
            $this->assertSame($msg, session('errors')->first('work_order'), $url);
        }
        $this->assertFalse((bool) $task->fresh()->is_done, 'nothing ticked');
        $this->assertSame(0, $card->photos()->count());
        $this->assertNull($card->fresh()->worker_signed_off_at);
        // JSON rows (the no-reload line entry) get a 423 with the same words
        $this->postJson('/secure/job-cards/' . $raw . '/lines', ['type' => 'part'])->assertStatus(423)->assertJson(['on_hold' => true]);
        // reading the page is never blocked
        $this->get('/secure/job-cards/' . $raw)->assertOk();
    }

    public function test_a_job_that_is_not_on_hold_still_works(): void
    {
        $card = $this->makeJobCard();
        $raw = $this->jobLink($card);
        $task = $card->tasks()->first();
        $this->post('/secure/job-cards/' . $raw . '/tasks/' . $task->id . '/tick')->assertSessionHasNoErrors();
        $this->assertTrue((bool) $task->fresh()->is_done);
    }

    public function test_a_cancelled_job_card_says_so_on_the_job_link_and_the_crew_page(): void
    {
        $card = $this->makeJobCard();
        $raw = $this->jobLink($card);
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $this->get('/secure/job-cards/' . $raw)->assertOk();

        $card->fresh()->cancel($this->admin, 'Cancelled by the office');

        $this->get('/secure/job-cards/' . $raw)->assertSee('This job was cancelled')->assertDontSee('may have expired');
        $this->get('/secure/crews/' . $page . '/job-cards/' . $card->id)->assertStatus(404)->assertSee('This job was cancelled');
        // a card id that never existed is still the generic page
        $this->get('/secure/crews/' . $page . '/job-cards/999999')->assertStatus(404)->assertSee('no longer available')->assertDontSee('This job was cancelled');
    }

    public function test_crew_links_renew_on_use_and_a_standing_link_with_no_expiry_stays_so(): void
    {
        $card = $this->makeJobCard();
        $raw = $this->jobLink($card);
        $set = fn ($id, $at) => RentalSecureAccessToken::withoutGlobalScopes()->whereKey($id)->update(['expires_at' => $at]);
        $job = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->firstOrFail();
        $set($job->id, now()->addDays(2));
        $this->get('/secure/job-cards/' . $raw)->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $job->fresh()->expires_at->timestamp, 120, 'the per-job crew link: 14 days from use');

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_standing_link_expiry_days' => 0]);
        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $standing = RentalSecureAccessToken::withoutGlobalScopes()->where('rental_crew_id', $this->crew->id)->whereNull('revoked_at')->firstOrFail();
        $this->assertNull($standing->expires_at);
        $this->get('/secure/crews/' . $page)->assertOk();
        $this->assertNull($standing->fresh()->expires_at, 'until revoked stays until revoked');

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_standing_link_expiry_days' => 30]);
        $set($standing->id, now()->addDays(3));
        $this->get('/secure/crews/' . $page)->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $standing->fresh()->expires_at->timestamp, 120);
    }

    public function test_a_heavy_route_is_counted_once_per_token_not_twice(): void
    {
        config(['rental-public-links.per_token_per_minute' => 100, 'rental-public-links.per_address_per_minute' => 1000, 'rental-public-links.heavy_per_token_per_ten_minutes' => 4]);
        $card = $this->makeJobCard();
        $raw = $this->jobLink($card);
        $other = $this->jobLink($this->makeJobCard(['title' => 'Second']));
        $statuses = [];
        foreach (range(1, 5) as $i) {
            $statuses[] = $this->post('/secure/job-cards/' . $raw . '/photos', ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image("p{$i}.jpg")]])->getStatusCode();
        }
        $this->assertNotContains(429, array_slice($statuses, 0, 4), 'four allowed - not halved by a second stacked limiter');
        $this->assertSame(429, $statuses[4]);
        // a different token on the same address is untouched
        $this->post('/secure/job-cards/' . $other . '/photos', ['photo_type' => 'completed', 'photos' => [UploadedFile::fake()->image('o.jpg')]])->assertStatus(302);
    }

    public function test_what_the_crew_found_is_carried_into_the_completion_form_and_not_lost_by_a_blank_box(): void
    {
        $card = $this->makeJobCard();
        $wo = $card->workOrder()->first();
        $wo->forceFill(['reported_fault_report_id' => $this->faultFor($wo)])->save();
        RentalJobCardCrewReport::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'findings' => 'The element had burnt out and the thermostat is loose.', 'line_count' => 1, 'sent_label' => 'Crew', 'sent_via' => 'link']);
        $raw = $this->jobLink($card);

        $this->get('/secure/job-cards/' . $raw)->assertOk()->assertSee('The element had burnt out and the thermostat is loose.')->assertSee('data-findings-carried', false);
        $this->assertSame('The element had burnt out and the thermostat is loose.', app(\App\Services\Rentals\RentalWhoPaysService::class)->carriedFindings($wo->fresh()));

        // a box left blank on the way to "Mark work completed" records what they already said, and the cause is still asked for
        $this->post('/secure/job-cards/' . $raw . '/complete', ['full_name' => 'Crew Chief', 'confirm' => 1, 'found_text' => '', 'cause_note' => 'Normal wear'])->assertSessionHasNoErrors();
        $this->assertSame('The element had burnt out and the thermostat is loose.', $wo->fresh()->found_text);
    }

    private function faultFor($wo): int
    {
        return \App\Models\RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone',
            'title' => 'Geyser', 'description' => 'No hot water', 'status' => 'work_order_raised', 'owner_approval_status' => 'approved', 'reported_at' => now(),
        ])->id;
    }
}
