<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Mail\Rentals\RentalWorkOrderAppointmentMail;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionNotification;
use App\Services\Rentals\PortalIdentityService;
use App\Services\Rentals\RentalInspectionNotificationService;
use App\Services\Rentals\RentalPortalAccessService;
use App\Services\Rentals\RentalPortalNotificationService;
use App\Services\Rentals\RentalWorkOrderService;
use App\Support\PortalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 9 Oct 2026 - "still finding that the tenant and owner links, names etc are mixed up" (Johan tests tenant and owner in ONE browser).
 * Portal identity end to end (spec section 28):
 *
 *   - a login that carries SEVERAL contacts in the agency (the same person captured twice, or two people on one address) is served
 *     as the contact that holds the side on screen - the header name and the person a report is filed under - and as the contact the
 *     link named, never as "the lowest-id contact" on both sides;
 *   - the contact a link names is chosen among the login's OWN contacts only: a hint can change a name, never a permission;
 *   - every link, invite and signed-lease copy names the exact contact it is for;
 *   - each recipient of a multi-tenant / multi-owner lease gets their own name and their own link;
 *   - a staff session in the same browser never answers for the portal.
 */
final class PortalIdentityEntryPointsTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private ClientUser $login;
    private Contact $ownerSelf;       // the same person's OWNER contact: a different name, a higher id than the tenant contact
    private \App\Models\Property $second;
    private \App\Models\RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->login = $this->clientUserFor($this->tenant);                   // tenant contact = the lowest id of this login
        $this->second = $this->makeProperty($this->agency, $this->admin, '9 Beach Road, Uvongo');
        $this->ownerSelf = $this->makeLandlord($this->agency, $this->second, ['first_name' => 'Piet', 'last_name' => 'Owner', 'email' => 'owner.self.' . uniqid() . '@example.invalid']);
        $this->ownerSelf->forceFill(['client_user_id' => $this->login->id])->saveQuietly();
        $this->faultType = \App\Models\RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    /** @return array<string,mixed> a tenant's fault report as the portal form sends it */
    private function tenantForm(string $title): array
    {
        return ['rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => $title, 'description' => 'x'];
    }

    private function as(ClientUser $login): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($login->fresh(), ['client']);
    }

    // ── the contact for the side on screen ───────────────────────────────────

    public function test_me_names_the_contact_that_holds_the_side_on_screen(): void
    {
        $this->assertLessThan($this->ownerSelf->id, $this->tenant->id, 'precondition: the tenant contact has the lowest id');
        $this->as($this->login);

        $this->assertSame($this->tenant->id, $this->getJson('/api/v1/client/me')->assertOk()->json('contact.id'), 'no side asked for: unchanged, the lowest id');
        $this->assertSame($this->tenant->id, $this->getJson('/api/v1/client/me?as=tenant')->json('contact.id'));
        $this->assertSame($this->ownerSelf->id, $this->getJson('/api/v1/client/me?as=owner')->json('contact.id'), 'the owner view is the owner contact');
        $this->assertSame($this->ownerSelf->id, $this->getJson('/api/v1/client/me?as=landlord')->json('contact.id'));

        $me = $this->getJson('/api/v1/client/me')->json();
        $this->assertSame($this->tenant->id, $me['side_contacts']['tenant']['id']);
        $this->assertSame('Piet Owner', $me['side_contacts']['landlord']['full_name'], 'the header can greet each side by its own contact');
        $this->assertNull($me['owns_linked_contact'], 'a link that names nobody');
    }

    public function test_a_login_with_one_contact_is_served_exactly_as_before(): void
    {
        $this->as($this->clientUserFor($this->landlord));
        $me = $this->getJson('/api/v1/client/me')->assertOk()->json();
        $this->assertSame($this->landlord->id, $me['contact']['id']);
        $this->assertNull($me['side_contacts']['tenant']);
        $this->assertSame($this->landlord->id, $me['side_contacts']['landlord']['id']);
    }

    public function test_the_contact_a_link_named_is_chosen_among_the_logins_own_contacts_only(): void
    {
        // a couple on one address and one login: two tenant contacts
        $partner = $this->makeContact($this->agency, ['first_name' => 'Lerato', 'last_name' => 'Nkosi', 'email' => $this->tenant->email]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $partner->id, 'is_primary' => false]);
        $partner->forceFill(['client_user_id' => $this->login->id])->saveQuietly();
        $this->as($this->login);

        $this->assertSame($this->tenant->id, $this->getJson('/api/v1/client/me?as=tenant')->json('contact.id'));
        $named = $this->withHeader(PortalIdentityService::HINT_HEADER, (string) $partner->id)->getJson('/api/v1/client/me?as=tenant')->assertOk();
        $this->assertSame($partner->id, $named->json('contact.id'), 'the link was written for the partner');
        $this->assertTrue($named->json('owns_linked_contact'));

        // a contact that is not this login's (someone else's) changes nothing and is reported as not theirs
        $stranger = $this->withHeader(PortalIdentityService::HINT_HEADER, (string) $this->landlord->id)->getJson('/api/v1/client/me?as=tenant')->assertOk();
        $this->assertSame($this->tenant->id, $stranger->json('contact.id'));
        $this->assertFalse($stranger->json('owns_linked_contact'));
        $this->assertStringNotContainsString($this->landlord->email, $stranger->getContent(), 'nothing of the named stranger is returned');

        // a hint for the tenant contact does not turn the OWNER view into the tenant
        $owner = $this->withHeader(PortalIdentityService::HINT_HEADER, (string) $this->tenant->id)->getJson('/api/v1/client/me?as=owner')->assertOk();
        $this->assertSame($this->ownerSelf->id, $owner->json('contact.id'));
        // garbage is ignored
        $this->withHeader(PortalIdentityService::HINT_HEADER, 'abc;drop')->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('owns_linked_contact', null);
    }

    // ── who a report is filed under ──────────────────────────────────────────

    public function test_a_report_is_filed_under_the_contact_for_the_side_it_was_made_from(): void
    {
        $this->as($this->login);

        $owner = $this->postJson('/api/v1/client/rentals/landlord/properties/' . $this->second->id . '/fault-reports', ['title' => 'Fence down', 'description' => 'x'])->assertStatus(201);
        $this->assertSame($this->ownerSelf->id, (int) RentalFaultReport::withoutGlobalScopes()->find($owner->json('fault_report.id'))->reported_by_contact_id,
            'filed under the OWNER contact, not the lowest-id (tenant) contact of the login');

        $tenant = $this->postJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', $this->tenantForm('Tap leaking'))->assertStatus(201);
        $this->assertSame($this->tenant->id, (int) RentalFaultReport::withoutGlobalScopes()->find($tenant->json('fault_report.id'))->reported_by_contact_id);
    }

    public function test_a_report_is_filed_under_the_person_the_link_was_written_for(): void
    {
        $partner = $this->makeContact($this->agency, ['first_name' => 'Lerato', 'last_name' => 'Nkosi', 'email' => $this->tenant->email]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $partner->id, 'is_primary' => false]);
        $partner->forceFill(['client_user_id' => $this->login->id])->saveQuietly();
        $this->as($this->login);

        $res = $this->withHeader(PortalIdentityService::HINT_HEADER, (string) $partner->id)
            ->postJson('/api/v1/client/rentals/properties/' . $this->property->id . '/fault-reports', $this->tenantForm('Window stuck'))->assertStatus(201);
        $this->assertSame($partner->id, (int) RentalFaultReport::withoutGlobalScopes()->find($res->json('fault_report.id'))->reported_by_contact_id);
    }

    // ── a staff session in the same browser ──────────────────────────────────

    public function test_a_staff_session_in_the_same_browser_never_answers_for_the_portal(): void
    {
        $origin = ['Origin' => 'http://localhost'];
        $this->app['auth']->forgetGuards();

        // staff only: the portal is not signed in (and never returns the staff user's data)
        $this->actingAs($this->admin, 'web')->withHeaders($origin)->getJson('/api/v1/client/me')->assertStatus(401);

        // staff AND a portal login in one browser session: /me is the portal person
        $login = ClientUser::create(['email' => 'both.' . uniqid() . '@example.invalid', 'password' => Hash::make('Sup3rSecret!'), 'password_set_at' => now(), 'activated_at' => now(), 'current_agency_id' => $this->agency->id]);
        $contact = $this->makeContact($this->agency, ['email' => $login->email, 'first_name' => 'Portal', 'last_name' => 'Person']);
        $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();
        $this->withHeaders($origin)->postJson('/api/v1/client-auth/login', ['email' => $login->email, 'password' => 'Sup3rSecret!'])->assertOk();
        $this->actingAs($this->admin, 'web');
        $me = $this->withHeaders($origin)->getJson('/api/v1/client/me')->assertOk();
        $this->assertSame($login->email, $me->json('client.email'));
        $this->assertNotSame($this->admin->email, $me->json('client.email'));
    }

    // ── two tabs, one browser ────────────────────────────────────────────────

    public function test_a_page_that_thinks_it_is_somebody_else_is_refused_before_anything_runs(): void
    {
        $this->as($this->login);
        $header = 'X-Portal-Expect';
        $other = (string) ($this->login->id + 1000);

        // the page was opened as this login: everything works
        $this->withHeader($header, (string) $this->login->id)->getJson('/api/v1/client/rentals/leases')->assertOk();
        // a client that sends nothing (the mobile app, an older page) is untouched
        $this->getJson('/api/v1/client/rentals/leases')->assertOk();

        // another tab has since signed somebody else in: the cookie is now another person's
        $refused = $this->withHeader($header, $other)->getJson('/api/v1/client/rentals/leases')->assertStatus(409);
        $this->assertTrue($refused->json('session_changed'));
        $this->withHeader($header, $other)->getJson('/api/v1/client/rentals/landlord/properties')->assertStatus(409);
        $this->withHeader($header, $other)->postJson('/api/v1/client/rentals/landlord/properties/' . $this->second->id . '/fault-reports', ['title' => 'Should not be filed'])->assertStatus(409);
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->where('title', 'Should not be filed')->count(), 'nothing was written under the wrong person');
        // ...and a stale tab cannot sign the NEW person out
        $this->withHeader($header, $other)->postJson('/api/v1/client-auth/logout')->assertStatus(409);
        // /me is how the page learns who is really signed in, so it always answers
        $this->withHeader($header, $other)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('client.id', $this->login->id);
    }

    // ── the page ─────────────────────────────────────────────────────────────

    public function test_the_page_hands_over_the_contact_the_link_names_and_ignores_a_deleted_or_forged_one(): void
    {
        $q = parse_url(PortalLink::forContact($this->tenant, PortalLink::VIEW_TENANT, ['fault' => 3]), PHP_URL_QUERY);
        $this->get('/portal?' . $q)->assertOk()->assertViewHas('linkedContactId', $this->tenant->id)->assertViewHas('linkedEmail', strtolower($this->tenant->email));

        // the old readable link names no contact
        $this->get('/portal?email=' . rawurlencode($this->tenant->email))->assertOk()->assertViewHas('linkedContactId', null);
        // a forged reference names nobody
        parse_str($q, $parts);
        $this->get('/portal?r=' . rawurlencode(strrev($parts['r'])))->assertOk()->assertViewHas('linkedContactId', null)->assertViewHas('linkedEmail', null);
        // a contact archived since the mail went out is not handed to the page
        $this->tenant->delete();
        $this->get('/portal?' . $q)->assertOk()->assertViewHas('linkedContactId', null)->assertViewHas('linkedEmail', strtolower($this->tenant->email));
    }

    public function test_the_page_script_sends_the_hint_checks_the_link_after_signing_in_and_greets_by_side(): void
    {
        $html = $this->get('/portal?as=tenant')->assertOk()->getContent();

        $this->assertStringContainsString("headers['X-Portal-Contact']", $html);
        $this->assertStringContainsString('async afterSignIn()', $html);
        $this->assertStringContainsString('linkIsForSomeoneElse(', $html);
        $this->assertStringContainsString('side_contacts', $html);
        $this->assertSame(2, substr_count($html, 'await this.afterSignIn();'), 'both sign-in paths (code + password, and password) run the same check');
    }

    // ── every link names the exact contact ───────────────────────────────────

    public function test_every_link_builder_writes_the_contact_it_is_for(): void
    {
        $this->assertSame($this->tenant->id, PortalLink::parse($this->q(PortalLink::forContact($this->tenant, PortalLink::VIEW_TENANT))['r'])['contact_id']);
        $this->assertSame(55, PortalLink::parse($this->q(PortalLink::forEmail('a@example.invalid', PortalLink::VIEW_OWNER, [], 55))['r'])['contact_id']);
        $this->assertSame(0, PortalLink::parse($this->q(PortalLink::forEmail('a@example.invalid', PortalLink::VIEW_OWNER))['r'])['contact_id'], 'unchanged when the caller has no contact');
        $personal = $this->q(PortalLink::personal('a@example.invalid', PortalLink::VIEW_TENANT, [], 55));
        $this->assertSame(55, PortalLink::parse($personal['r'])['contact_id']);
        $this->assertSame('a@example.invalid', $personal['email'], 'the readable form still works');
    }

    public function test_the_lease_screen_card_the_invite_and_the_decision_mail_name_the_contact(): void
    {
        $access = app(RentalPortalAccessService::class);

        foreach ([[$this->tenant, 'tenant'], [$this->ownerSelf, 'landlord']] as [$contact, $role]) {
            $status = $access->status($contact, $role);
            $this->assertSame($contact->id, PortalLink::parse($this->q($status['url'])['r'])['contact_id'], "lease screen card ({$role})");
            $this->assertSame($role === 'landlord' ? 'owner' : 'tenant', $this->q($status['url'])['as']);
        }

        $fault = $this->faultReport(['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);
        $mail = new RentalLandlordDecisionNeededMail($fault, 'Pieter', $this->landlord->email, $this->landlord);
        $this->assertSame($this->landlord->id, PortalLink::parse($this->q($mail->portalUrl)['r'])['contact_id']);
    }

    public function test_the_inspection_mail_link_names_the_contact_the_mail_is_for(): void
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->admin->id,
            'status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()->subDay(),
        ]);
        $svc = app(RentalInspectionNotificationService::class);
        $url = $svc->linkFor(['role' => RentalInspectionNotification::PARTY_TENANT, 'name' => 'x', 'email' => $this->tenant->email, 'contact_id' => $this->tenant->id], $inspection);

        $this->assertSame($this->tenant->id, PortalLink::parse($this->q($url)['r'])['contact_id']);
    }

    public function test_the_signed_lease_copy_names_the_party_it_is_addressed_to_when_two_share_an_address(): void
    {
        $access = app(RentalPortalAccessService::class);
        $partner = $this->makeContact($this->agency, ['first_name' => 'Lerato', 'last_name' => 'Nkosi', 'email' => $this->tenant->email]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $partner->id, 'is_primary' => false]);

        $forPartner = $access->mailBlockForLease($this->lease->fresh(), $this->tenant->email, $partner->id);
        $forDefault = $access->mailBlockForLease($this->lease->fresh(), $this->tenant->email);

        $this->assertNotNull($forPartner);
        $this->assertSame($partner->id, PortalLink::parse($this->q($forPartner['url'])['r'])['contact_id']);
        $this->assertSame($this->tenant->id, PortalLink::parse($this->q($forDefault['url'])['r'])['contact_id'], 'with nobody named, the first party carrying the address, as before');
    }

    // ── each recipient: their own name, their own link ───────────────────────

    public function test_every_tenant_of_a_lease_gets_their_own_name_and_their_own_link(): void
    {
        $partner = $this->makeContact($this->agency, ['first_name' => 'Lerato', 'last_name' => 'Zulu', 'email' => 'lerato.' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $partner->id, 'is_primary' => false]);
        $fault = $this->faultReport(['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);
        $wo = $this->externalJob();

        app(RentalPortalNotificationService::class)->notifyTenantStatusChanged($fault);
        app(RentalWorkOrderService::class)->notifyTenantAppointment($wo, false);

        foreach ([[RentalTenantStatusChangeMail::class, 'fault', $fault->id], [RentalWorkOrderAppointmentMail::class, 'wo', $wo->id]] as [$class, $key, $id]) {
            $mails = $this->mailsOf($class);
            $this->assertCount(2, $mails, "{$class}: one per tenant");
            foreach ([$this->tenant, $partner] as $person) {
                $mail = $mails->first(fn ($m) => $m->hasTo($person->email));
                $this->assertNotNull($mail, "{$class}: {$person->first_name} was written to");
                $this->assertSame($person->first_name, $mail->recipientName ?? $mail->tenantName, "{$class}: greeted by their own first name");
                $q = $this->q($mail->portalUrl);
                $this->assertSame($person->id, PortalLink::parse($q['r'])['contact_id'], "{$class}: their own link");
                $this->assertSame(strtolower($person->email), PortalLink::parse($q['r'])['email']);
                $this->assertSame('tenant', $q['as']);
                $this->assertSame((string) $id, $q[$key]);
            }
        }
    }

    public function test_every_owner_of_a_property_gets_their_own_name_and_their_own_link(): void
    {
        $coOwner = $this->makeLandlord($this->agency, $this->property, ['first_name' => 'Hendrik', 'last_name' => 'Botha', 'email' => 'hendrik.' . uniqid() . '@example.invalid']);
        $fault = $this->faultReport(['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);

        app(RentalPortalNotificationService::class)->notifyLandlordDecisionNeeded($fault);

        $mails = $this->mailsOf(RentalLandlordDecisionNeededMail::class);
        $this->assertCount(2, $mails);
        foreach ([$this->landlord, $coOwner] as $person) {
            $mail = $mails->first(fn ($m) => $m->hasTo($person->email));
            $this->assertNotNull($mail);
            $this->assertSame($person->first_name, $mail->recipientName);
            $q = $this->q($mail->portalUrl);
            $this->assertSame($person->id, PortalLink::parse($q['r'])['contact_id'], 'their own link, not the other owner\'s');
            $this->assertSame('owner', $q['as']);
            $this->assertSame((string) $fault->id, $q['fault']);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    private function q(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function mailsOf(string $class)
    {
        return collect(Mail::queued($class))->merge(Mail::sent($class))->values();
    }
}
