<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Send the Subscription Agreement from the agency's own screen — spec §11.14: button, pre-filled recipient,
 * status on the agency screen, one-click re-issue after expiry.
 */
class AgencyScreenSendTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function agencyWithTimeline(array $extra = []): array
    {
        $agency = Agency::create(array_merge(['name' => 'Caprivi Realty', 'slug' => 'caprivi-' . uniqid(), 'email' => 'office@caprivi.test', 'phone' => '039 555 0100'], $extra));
        $timeline = app(AgencyTimelineService::class)->start($agency, Carbon::parse('2026-10-01'), null);

        return [$agency, $timeline];
    }

    public function test_the_agency_screen_offers_the_send_button_with_the_agency_already_chosen(): void
    {
        $owner = $this->owner();
        [$agency, $timeline] = $this->agencyWithTimeline();
        $res = $this->actingAs($owner)->get(route('admin.agency-timelines.show', $timeline))->assertOk();
        $res->assertSee('Send Subscription Agreement')->assertSee(route('platform-esign.agreements.create', ['agency' => $agency->id]), false);
        $res->assertSee('No Subscription Agreement has been sent to Caprivi Realty yet.');
    }

    public function test_the_send_form_prefills_the_recipient_from_the_principal_then_the_agency(): void
    {
        $owner = $this->owner();
        [$agency] = $this->agencyWithTimeline();
        $url = route('platform-esign.agreements.create', ['agency' => $agency->id]);

        // No principal on file → the agency's own email and phone, name left for the owner.
        $res = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertStringContainsString('value="office@caprivi.test"', $res->getContent());
        $this->assertStringContainsString('value="039 555 0100"', $res->getContent());
        $this->assertSame(1, preg_match('/<option value="' . $agency->id . '" selected>/', $res->getContent()));

        // With a principal → their name, email and cell win; every field stays editable.
        User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent', 'name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'cell' => '082 555 0123', 'is_active' => true, 'is_principal_practitioner' => true]);
        User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent', 'name' => 'Not The Principal', 'email' => 'other@caprivi.test', 'is_active' => true]);
        $res = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertStringContainsString('value="Pat Principal"', $res->getContent());
        $this->assertStringContainsString('value="pat@caprivi.test"', $res->getContent());
        $this->assertStringContainsString('value="0825550123"', $res->getContent());
        $this->assertStringNotContainsString('other@caprivi.test', explode('data-prefill=', $res->getContent())[0]);

        // The form with no agency chosen stays blank.
        $res = $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->assertOk();
        $this->assertStringNotContainsString('value="Pat Principal"', $res->getContent());
    }

    public function test_sending_from_the_agency_screen_links_the_agreement_and_the_screen_then_shows_its_status(): void
    {
        Mail::fake();
        $owner = $this->owner();
        [$agency, $timeline] = $this->agencyWithTimeline();

        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), ['name' => 'Pat Principal', 'email' => 'pat@throwaway.invalid', 'agency_id' => $agency->id])->assertRedirect();
        $doc = Document::where('agency_id', $agency->id)->firstOrFail();
        $this->assertSame($doc->id, (int) $timeline->fresh()->agreement_document_id);

        $res = $this->actingAs($owner)->get(route('admin.agency-timelines.show', $timeline))->assertOk();
        $res->assertSee($doc->contract_ref)->assertSee('Sent')->assertSee(route('platform-esign.documents.show', $doc->id), false);
        $res->assertDontSee('has not been sent')->assertDontSee('Re-issue');
    }

    public function test_an_expired_agreement_offers_a_one_click_reissue_on_the_agency_screen_and_on_the_document(): void
    {
        Mail::fake();
        $owner = $this->owner();
        [$agency, $timeline] = $this->agencyWithTimeline();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@throwaway.invalid', 'agency_id' => $agency->id], $owner->id);
        $oldToken = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
        $doc->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();

        // The recipient gets a clear message, and nothing is lost.
        $this->get(route('platform-esign.agreement.show', $oldToken))->assertOk()->assertSee('This link has expired')->assertSee('everything you entered has been kept');

        $this->actingAs($owner)->get(route('admin.agency-timelines.show', $timeline))->assertOk()->assertSee('Re-issue — new link, fresh expiry')->assertSee('The link expired');
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Re-issue — new link, fresh 30 days');

        $this->actingAs($owner)->post(route('platform-esign.documents.resend', $doc->id))->assertSessionHasNoErrors();
        $fresh = $doc->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertTrue($fresh->expires_at->isFuture());
        $new = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
        $this->assertNotSame($oldToken, $new);
        $this->get(route('platform-esign.agreement.show', $new))->assertOk()->assertSee('Subscription Agreement');
    }

    public function test_the_agency_screen_is_owner_only(): void
    {
        [$agency, $timeline] = $this->agencyWithTimeline();
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->get(route('platform-esign.agreements.create', ['agency' => $agency->id]))->assertForbidden();
    }
}
