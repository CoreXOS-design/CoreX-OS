<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Mail\Rentals\LeaseNoticeRejectedMail;
use App\Mail\Rentals\RentalNoticeMail;
use App\Mail\Rentals\RentalOwnerNoticeToAgentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseNotice;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Services\Rentals\LeaseNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * lease-notices.md §5.1/§8 - the portal's Notices tab. The tenant and the owner give notice; it goes to the AGENTS and nobody else; each
 * person sees only their own side's notices; the lease itself is untouched until an agent accepts.
 */
final class LeaseNoticePortalTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'lease_id' => $this->lease->id,
            'notice_date' => Carbon::today()->toDateString(),
            'intended_end_date' => Carbon::today()->addMonths(2)->toDateString(),
            'reason' => 'Moving to Cape Town for work',
        ];
    }

    public function test_a_tenant_gives_notice_from_the_portal_and_only_the_agents_are_told(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $res = $this->post('/api/v1/client/rentals/notices', $this->payload(['attachment' => UploadedFile::fake()->create('letter.pdf', 80, 'application/pdf')]), ['Accept' => 'application/json']);

        $res->assertStatus(201)->assertJsonPath('notices.0.state', 'with_agent');
        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('tenant', $notice->given_by);
        $this->assertSame('portal', $notice->via);
        $this->assertSame($this->tenant->id, $notice->given_by_contact_id);
        $this->assertSame(LeaseNotice::STATUS_RECEIVED, $notice->status);
        $this->assertTrue($notice->hasAttachment());
        Storage::disk('local')->assertExists($notice->attachment_path);

        // the lease itself is untouched: the move-out date is set only on acceptance
        $lease = $this->lease->fresh();
        $this->assertNull($lease->notice_date);
        $this->assertNull($lease->move_out_date);

        // both lease agents are emailed, no actor excluded (a portal click has none); nobody else (not the landlord, not the tenant)
        Mail::assertQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent->email) && str_contains($m->title, 'Notice received - needs review'));
        Mail::assertQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->agent2->email));
        Mail::assertNotQueued(RentalNoticeMail::class);
        Mail::assertNotQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->landlord->email) || $m->hasTo($this->tenant->email));

        // on the lease's own log
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_RECEIVED)->exists());
    }

    public function test_the_agents_get_the_in_app_alert_too(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $this->postJson('/api/v1/client/rentals/notices', $this->payload())->assertStatus(201);

        $row = \Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $this->agent->id)->get()
            ->first(fn ($n) => str_contains((string) $n->data, 'Notice received - needs review'));
        $this->assertNotNull($row, 'an in-app alert for the lease agent');
        $this->assertStringContainsString('lease-notices', (string) $row->data, 'it opens the review');
    }

    public function test_the_owner_gives_notice_from_their_portal_and_the_tenant_is_told_nothing(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);

        $res = $this->postJson('/api/v1/client/rentals/landlord/notices', $this->payload(['intended_end_date' => Carbon::parse($this->lease->end_date)->toDateString()]));

        $res->assertStatus(201);
        $notice = LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('landlord', $notice->given_by);
        $this->assertSame($this->landlord->id, $notice->given_by_contact_id);
        Mail::assertNotQueued(RentalOwnerNoticeToAgentMail::class, fn ($m) => $m->hasTo($this->tenant->email));
        $this->assertNull($this->lease->fresh()->notice_date);
    }

    public function test_a_person_sees_only_their_own_sides_notices(): void
    {
        $this->notice(['given_by' => 'tenant']);
        $this->notice(['given_by' => 'landlord', 'given_by_contact_id' => $this->landlord->id]);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $tenantList = $this->getJson('/api/v1/client/rentals/notices')->assertOk()->json('notices');
        $this->assertCount(1, $tenantList);
        $this->assertSame('with_agent', $tenantList[0]['state']);
    }

    public function test_the_owner_sees_the_landlord_side_only(): void
    {
        $this->notice(['given_by' => 'tenant']);
        $landlordNotice = $this->notice(['given_by' => 'landlord', 'given_by_contact_id' => $this->landlord->id]);

        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $list = $this->getJson('/api/v1/client/rentals/landlord/notices')->assertOk()->json('notices');

        $this->assertSame([$landlordNotice->id], array_column($list, 'id'));
    }

    public function test_a_tenant_cannot_give_notice_on_a_lease_that_is_not_theirs(): void
    {
        $otherProperty = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Other flat', 'status' => 'active', 'listing_type' => 'rental']);
        $other = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $otherProperty->id, 'status' => 'active', 'rental_amount' => 7000,
            'start_date' => Carbon::today()->subMonth()->toDateString(), 'end_date' => Carbon::today()->addMonths(5)->toDateString(), 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson('/api/v1/client/rentals/notices', $this->payload(['lease_id' => $other->id]))->assertStatus(422);
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());
    }

    public function test_an_owner_cannot_give_notice_on_another_owners_property_and_a_tenant_cannot_use_the_owner_route(): void
    {
        $stranger = $this->contact('Sam', 'Stranger');
        Sanctum::actingAs($this->clientUserFor($stranger), ['client']);
        $this->postJson('/api/v1/client/rentals/landlord/notices', $this->payload())->assertStatus(422);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $this->postJson('/api/v1/client/rentals/landlord/notices', $this->payload())->assertStatus(422);
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());
    }

    public function test_another_agencys_tenant_sees_and_gives_nothing_here(): void
    {
        $agency2 = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $agency2->id, 'name' => 'Main', 'code' => 'C-' . $agency2->id, 'is_active' => true]);
        $tenant2 = $this->contact('Thabo', 'Other', $agency2, $branch2);
        $this->notice();

        Sanctum::actingAs($this->clientUserFor($tenant2), ['client']);
        $this->getJson('/api/v1/client/rentals/notices')->assertOk()->assertJsonPath('notices', []);
        $this->postJson('/api/v1/client/rentals/notices', $this->payload())->assertStatus(422);
    }

    public function test_validation_dates_and_one_waiting_notice_per_side(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson('/api/v1/client/rentals/notices', $this->payload(['intended_end_date' => Carbon::today()->subDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('intended_end_date');
        $this->postJson('/api/v1/client/rentals/notices', $this->payload(['notice_date' => Carbon::today()->addDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('notice_date');
        $this->postJson('/api/v1/client/rentals/notices', $this->payload(['attachment' => UploadedFile::fake()->create('virus.exe', 10)]))->assertStatus(422)->assertJsonValidationErrors('attachment');
        $this->assertSame(0, LeaseNotice::withoutGlobalScopes()->count());

        $this->postJson('/api/v1/client/rentals/notices', $this->payload())->assertStatus(201);
        $this->postJson('/api/v1/client/rentals/notices', $this->payload(), ['X-Submission-Key' => 'another-press'])->assertStatus(422);
        $this->assertSame(1, LeaseNotice::withoutGlobalScopes()->count(), 'a second notice from the same side waits for the first');
    }

    public function test_a_lease_with_an_accepted_notice_takes_no_second_one(): void
    {
        $this->lease->forceFill(['notice_date' => Carbon::today()->toDateString(), 'notice_given_by' => 'tenant', 'move_out_date' => Carbon::today()->addMonths(2)->toDateString()])->save();
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->postJson('/api/v1/client/rentals/notices', $this->payload())->assertStatus(422);
    }

    public function test_the_portal_says_where_a_notice_stands_in_plain_words(): void
    {
        $accepted = $this->notice(['status' => 'accepted', 'accepted_end_date' => Carbon::today()->addMonths(3)->toDateString(), 'decision_kind' => 'corrected', 'decision_note' => 'Your lease needs three months']);
        $rejected = $this->notice(['status' => 'rejected', 'decision_note' => 'The date is in the past', 'given_by_contact_id' => $this->tenant->id]);

        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
        $list = collect($this->getJson('/api/v1/client/rentals/notices')->json('notices'))->keyBy('id');

        $this->assertStringContainsString('Accepted - your lease ends on', $list[$accepted->id]['status_line']);
        $this->assertStringContainsString('Your lease needs three months', $list[$accepted->id]['status_line'], 'a corrected date carries the agent\'s reason');
        $this->assertStringContainsString('Not accepted: The date is in the past', $list[$rejected->id]['status_line']);
    }

    public function test_the_agents_internal_note_on_a_notice_accepted_as_given_is_not_shown(): void
    {
        $this->notice(['status' => 'accepted', 'accepted_end_date' => Carbon::today()->addMonths(2)->toDateString(), 'decision_kind' => 'as_given', 'decision_note' => 'INTERNAL: checked the CPA']);
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);

        $this->assertStringNotContainsString('INTERNAL', json_encode($this->getJson('/api/v1/client/rentals/notices')->json()));
    }

    public function test_rejecting_a_portal_notice_emails_the_giver_with_the_reason_in_the_agencys_own_mail(): void
    {
        $notice = $this->notice();
        app(LeaseNoticeService::class)->reject($notice, $this->agent, 'The end date you gave is before the notice period ends.');

        Mail::assertQueued(LeaseNoticeRejectedMail::class, function (LeaseNoticeRejectedMail $m) {
            $html = $m->render();
            $env = $m->envelope();

            return $m->hasTo($this->tenant->email)
                && str_contains($html, 'before the notice period ends')
                && str_contains($html, 'Karoo Lettings')
                && str_contains($html, '3 Church Street, Graaff-Reinet')
                && stripos($html, 'corex') === false
                && $env->from->name === 'Karoo Lettings'
                && $env->replyTo[0]->address === 'desk@karoo.test';
        });
        Mail::assertNotQueued(LeaseNoticeRejectedMail::class, fn ($m) => $m->hasTo($this->landlord->email));
    }

    public function test_the_portal_tab_and_page_exist(): void
    {
        $this->get('/portal')->assertOk()->assertSee('data-tab-notices', false);
        $src = (string) file_get_contents(resource_path('views/rentals/portal/_notices.blade.php'));
        $this->assertStringContainsString('data-portal-notices', $src);
        $this->assertStringNotContainsStringIgnoringCase('corex', $src);
    }
}
