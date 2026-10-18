<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Exceptions\Rentals\PortalAccessException;
use App\Mail\Rentals\RentalPortalInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalPortalSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §16 — Johan, QA1, 2026-10-07: "Create Client Login" on the lease screen said
 * "email already in use" for a tenant who had no login at all. The tenant's OWN contact row was what the old check
 * tripped over. A person is one login: Create attaches this contact to the login for that email, safely.
 */
final class LeasePortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Flat', 'status' => 'active', 'listing_type' => 'rental', 'address' => '401 Margate Boulevard',
            'suburb' => 'Margate', 'city' => 'Margate', 'rental_amount' => 11400,
        ]);
        $this->tenant = $this->contact('Ayanda', 'ayanda@example.test');
        $this->landlord = $this->contact('Siyabonga', 'siya@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lease = $this->leaseFor($this->property, [$this->tenant]);
    }

    private function contact(string $first, ?string $email, ?Agency $agency = null): Contact
    {
        $agency ??= $this->agency;

        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'first_name' => $first, 'last_name' => 'Test', 'email' => $email, 'phone' => '0821234567',
        ]);
        // BelongsToAgency re-homes a row to the signed-in user's agency on create — pin the agency the test asked for.
        DB::table('contacts')->where('id', $contact->id)->update(['agency_id' => $agency->id]);

        return $contact->fresh();
    }

    private function leaseFor(Property $property, array $tenants): Lease
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 11400, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        foreach ($tenants as $i => $t) {
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $t->id, 'is_primary' => $i === 0]);
        }

        return $lease;
    }

    private function service(): RentalPortalAccessService
    {
        return app(RentalPortalAccessService::class);
    }

    private function postFor(string $action, Lease $lease, Contact $contact)
    {
        return $this->actingAs($this->agent)->post(route('corex.leases.portal-access.' . $action, [$lease, $contact->id]));
    }

    // ── 1. The reported dead end ─────────────────────────────────────────────────────────────

    public function test_a_contact_with_its_own_email_and_no_login_gets_one_instead_of_already_in_use(): void
    {
        $this->assertNull($this->tenant->client_user_id);

        $this->postFor('setup', $this->lease, $this->tenant)->assertRedirect();

        $login = ClientUser::where('email', 'ayanda@example.test')->first();
        $this->assertNotNull($login, 'The login must be created for the tenant\'s own email.');
        $this->assertSame($login->id, $this->tenant->fresh()->client_user_id);
        $this->assertSame($this->agency->id, (int) $login->created_by_agency_id);
        $this->assertFalse($login->hasPassword(), 'No password: the person confirms with an emailed code the first time.');
    }

    public function test_the_card_shows_the_login_as_configured_with_its_status_and_link(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="not_set_up"', $html);
        $this->assertStringContainsString('data-test="portal-access-setup-and-send"', $html);

        $this->postFor('setup', $this->lease, $this->tenant);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="pending"', $html);
        $this->assertStringContainsString('Pending OTP', $html);
        $this->assertStringContainsString(url('/portal') . '?email=ayanda%40example.test', $html);
        $this->assertStringContainsString('data-test="portal-access-copy"', $html);
        $this->assertStringContainsString('data-test="portal-access-resend"', $html);
        $this->assertStringContainsString('data-test="portal-access-whatsapp"', $html);
        // The landlord is still not set up, so exactly one "set up & email" action remains — the tenant's is gone.
        $this->assertSame(1, substr_count($html, 'data-test="portal-access-setup-and-send"'));
    }

    public function test_setup_is_idempotent(): void
    {
        $first = $this->service()->attach($this->tenant);
        $second = $this->service()->attach($this->tenant->fresh());

        $this->assertSame('created', $first['outcome']);
        $this->assertSame('already', $second['outcome']);
        $this->assertSame(1, ClientUser::where('email', 'ayanda@example.test')->count());
    }

    // ── 2. A person is one login ─────────────────────────────────────────────────────────────

    public function test_a_login_that_already_exists_for_the_contacts_email_is_attached_not_refused(): void
    {
        $existing = ClientUser::create(['email' => 'ayanda@example.test', 'created_by_agency_id' => $this->agency->id]);

        $this->postFor('setup', $this->lease, $this->tenant)->assertRedirect();

        $this->assertSame($existing->id, $this->tenant->fresh()->client_user_id);
        $this->assertSame(1, ClientUser::where('email', 'ayanda@example.test')->count());
    }

    public function test_the_same_person_in_another_agency_shares_the_one_login(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $elsewhere = $this->contact('Ayanda', 'ayanda@example.test', $other);
        $login = ClientUser::create(['email' => 'ayanda@example.test', 'created_by_agency_id' => $other->id]);
        $elsewhere->forceFill(['client_user_id' => $login->id])->saveQuietly();

        $this->service()->attach($this->tenant);

        $this->assertSame($login->id, $this->tenant->fresh()->client_user_id, 'One email, one login, across agencies.');
    }

    public function test_two_tenants_on_one_lease_each_get_access_and_a_shared_email_means_one_login(): void
    {
        $partner = $this->contact('Partner', 'ayanda@example.test');
        $lease = $this->leaseFor($this->property, [$this->tenant, $partner]);

        $this->postFor('setup', $lease, $this->tenant);
        $this->postFor('setup', $lease, $partner);

        $this->assertNotNull($this->tenant->fresh()->client_user_id);
        $this->assertSame($this->tenant->fresh()->client_user_id, $partner->fresh()->client_user_id);
        $this->assertSame(1, ClientUser::count());

        $second = $this->contact('Second', 'second@example.test');
        $lease2 = $this->leaseFor($this->property, [$this->tenant, $second]);
        $this->postFor('setup', $lease2, $second);
        $this->assertNotSame($this->tenant->fresh()->client_user_id, $second->fresh()->client_user_id);
        $this->assertSame(2, ClientUser::count());
    }

    public function test_an_email_that_belongs_to_a_stranger_is_refused_in_plain_words_and_nothing_is_linked(): void
    {
        $rival = Agency::create(['name' => 'Rival ' . uniqid(), 'slug' => 'rival-' . uniqid()]);
        Branch::create(['agency_id' => $rival->id, 'name' => 'Main']);
        $theirs = $this->contact('Theirs', 'someone.else@example.test', $rival);
        $stranger = ClientUser::create(['email' => 'someone.else@example.test', 'created_by_agency_id' => $rival->id]);
        $theirs->forceFill(['client_user_id' => $stranger->id])->saveQuietly();

        try {
            $this->service()->attach($this->tenant, 'someone.else@example.test');
            $this->fail('A login that belongs to someone outside this agency must not be attachable.');
        } catch (PortalAccessException $e) {
            $this->assertSame('belongs_to_someone_else', $e->reason);
            $this->assertStringNotContainsStringIgnoringCase('already in use', $e->getMessage());
            $this->assertStringContainsString('someone who is not on your contact list', $e->getMessage());
        }
        $this->assertNull($this->tenant->fresh()->client_user_id);
        $this->assertSame(0, (int) ClientUser::whereKey($stranger->id)->value('password_must_change'));
    }

    public function test_a_secondary_email_saved_on_the_contact_counts_as_the_contacts_own(): void
    {
        \App\Models\ContactEmail::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->tenant->id, 'email' => 'ayanda.work@example.test', 'is_primary' => false,
        ]);

        $result = $this->service()->attach($this->tenant, 'ayanda.work@example.test');

        $this->assertSame('created', $result['outcome']);
        $this->assertSame($result['client_user']->id, $this->tenant->fresh()->client_user_id);
    }

    public function test_a_typed_email_that_is_not_on_the_contact_is_refused(): void
    {
        try {
            $this->service()->attach($this->tenant, 'not.saved@example.test');
            $this->fail('An email that is not saved on the contact must be refused.');
        } catch (PortalAccessException $e) {
            $this->assertSame('email_not_on_contact', $e->reason);
        }
        $this->assertNull(ClientUser::where('email', 'not.saved@example.test')->first());
    }

    public function test_the_contact_page_button_gives_the_same_plain_message_and_the_attach(): void
    {
        $this->actingAs($this->agent)
            ->post(route('corex.contacts.client-login.create', $this->tenant), ['email' => 'ayanda@example.test'])
            ->assertSessionHas('client_login_success');
        $this->assertNotNull($this->tenant->fresh()->client_user_id);

        $rival = Agency::create(['name' => 'Rival ' . uniqid(), 'slug' => 'rival-' . uniqid()]);
        Branch::create(['agency_id' => $rival->id, 'name' => 'Main']);
        $theirs = $this->contact('Theirs', 'far.away@example.test', $rival);
        $theirs->forceFill(['client_user_id' => ClientUser::create(['email' => 'far.away@example.test', 'created_by_agency_id' => $rival->id])->id])->saveQuietly();

        $this->actingAs($this->agent)->from('/x')
            ->post(route('corex.contacts.client-login.create', $this->landlord), ['email' => 'far.away@example.test'])
            ->assertSessionHasErrors('email');
        $message = session('errors')->first('email');
        $this->assertStringNotContainsStringIgnoringCase('already used', $message);
        $this->assertStringNotContainsStringIgnoringCase('already in use', $message);
        $this->assertNull($this->landlord->fresh()->client_user_id);
    }

    // ── 3. No email / email changed / placeholder login ──────────────────────────────────────

    public function test_no_email_on_the_contact_asks_for_one(): void
    {
        $noEmail = $this->contact('Nomail', null);
        $lease = $this->leaseFor($this->property, [$noEmail]);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="no_email"', $html);
        $this->assertStringContainsString('Add an email on the contact', $html);

        $this->postFor('setup', $lease, $noEmail)->assertSessionHas('portal_access_flash', fn ($f) => $f['type'] === 'error' && str_contains($f['message'], 'no email address'));
        $this->assertSame(0, ClientUser::count());
    }

    public function test_email_changed_on_the_contact_offers_a_switch_that_moves_the_login(): void
    {
        $this->service()->attach($this->tenant);
        $old = ClientUser::where('email', 'ayanda@example.test')->first();
        $this->tenant->forceFill(['email' => 'ayanda.new@example.test'])->saveQuietly();

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="email_changed"', $html);
        $this->assertStringContainsString('data-test="portal-access-switch"', $html);

        $this->postFor('switch', $this->lease, $this->tenant)->assertRedirect();

        $new = ClientUser::where('email', 'ayanda.new@example.test')->first();
        $this->assertNotNull($new);
        $this->assertSame($new->id, $this->tenant->fresh()->client_user_id);
        $this->assertNull(ClientUser::find($old->id), 'The old login, with nobody left on it, is removed (soft-deleted).');
        $this->assertNotNull(ClientUser::withTrashed()->find($old->id));
    }

    public function test_the_old_login_stays_when_another_contact_is_still_on_it(): void
    {
        $partner = $this->contact('Partner', 'ayanda@example.test');
        $this->service()->attach($this->tenant);
        $this->service()->attach($partner);
        $old = $this->tenant->fresh()->client_user_id;
        $this->tenant->forceFill(['email' => 'ayanda.new@example.test'])->saveQuietly();

        $this->service()->switchToContactEmail($this->tenant->fresh());

        $this->assertNotNull(ClientUser::find($old));
        $this->assertSame($old, $partner->fresh()->client_user_id);
    }

    public function test_a_placeholder_login_is_flagged_and_can_be_moved_to_the_real_email(): void
    {
        $fake = ClientUser::create(['email' => 'siyabonga@corexclient.co.za', 'created_by_agency_id' => $this->agency->id]);
        $this->landlord->forceFill(['client_user_id' => $fake->id])->saveQuietly();

        $st = $this->service()->status($this->landlord->fresh(), 'landlord');
        $this->assertSame('email_changed', $st['state']);
        $this->assertSame('Login is a placeholder address', $st['label']);

        $this->service()->switchToContactEmail($this->landlord->fresh());
        $this->assertSame('siya@example.test', ClientUser::find($this->landlord->fresh()->client_user_id)->email);
    }

    // ── 4. One login, both roles ─────────────────────────────────────────────────────────────

    public function test_one_login_carries_a_tenant_role_and_a_landlord_role_across_two_contact_records(): void
    {
        // Same person: tenant on this lease (contact A), landlord of another property under a second record (contact B).
        $asLandlord = $this->contact('Ayanda', 'ayanda@example.test');
        $second = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Cottage', 'status' => 'active', 'listing_type' => 'rental', 'address' => '9 Beach Road',
            'suburb' => 'Uvongo', 'city' => 'Uvongo',
        ]);
        DB::table('contact_property')->insert([
            'contact_id' => $asLandlord->id, 'property_id' => $second->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service()->attach($this->tenant);
        $this->service()->attach($asLandlord);
        $login = ClientUser::where('email', 'ayanda@example.test')->first();
        $this->assertSame(1, ClientUser::count());
        $login->forceFill(['current_agency_id' => $this->agency->id])->save();

        Sanctum::actingAs($login, ['client']);
        $leases = collect($this->getJson('/api/v1/client/rentals/leases')->assertOk()->json('leases'))->pluck('id')->all();
        $props = collect($this->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->json('properties'))->pluck('id')->all();

        $this->assertContains($this->lease->id, $leases, 'The tenant side of the login still sees the lease.');
        $this->assertContains($second->id, $props, 'The landlord side, held on the other contact record, shows up under the same login.');
    }

    public function test_the_tenant_sees_this_lease_next_to_a_lease_on_another_contact_record(): void
    {
        $dupe = $this->contact('Ayanda', 'ayanda@example.test');
        $other = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Earlier flat', 'status' => 'active', 'listing_type' => 'rental', 'address' => '2 Old Road',
            'suburb' => 'Margate', 'city' => 'Margate',
        ]);
        $earlier = $this->leaseFor($other, [$dupe]);
        $this->service()->attach($this->tenant);
        $this->service()->attach($dupe);
        $login = ClientUser::where('email', 'ayanda@example.test')->first();
        $login->forceFill(['current_agency_id' => $this->agency->id])->save();

        Sanctum::actingAs($login, ['client']);
        $ids = collect($this->getJson('/api/v1/client/rentals/leases')->assertOk()->json('leases'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->lease->id, $earlier->id], $ids);
    }

    // ── 5. The link: copy / share / resend ───────────────────────────────────────────────────

    public function test_resend_invite_emails_the_link_and_sets_access_up_first(): void
    {
        Mail::fake();

        $this->postFor('invite', $this->lease, $this->tenant)
            ->assertSessionHas('portal_access_flash', fn ($f) => $f['type'] === 'ok');

        $this->assertNotNull($this->tenant->fresh()->client_user_id, 'One action: sets up and sends.');
        Mail::assertSent(RentalPortalInviteMail::class, function (RentalPortalInviteMail $m) {
            $html = $m->render();

            return $m->hasTo('ayanda@example.test')
                && str_contains($html, url('/portal') . '?email=ayanda%40example.test')
                && str_contains($html, 'report a fault')
                && ! str_contains($html, 'statement');
        });
    }

    public function test_the_landlord_invite_lists_only_what_the_portal_offers_a_landlord(): void
    {
        Mail::fake();

        $this->postFor('invite', $this->lease, $this->landlord)->assertRedirect();

        Mail::assertSent(RentalPortalInviteMail::class, function (RentalPortalInviteMail $m) {
            $html = $m->render();

            return $m->hasTo('siya@example.test')
                && stripos($html, 'approve or decline repair decisions') !== false
                && stripos($html, 'report a fault and follow it through to a fix') === false;
        });
    }

    public function test_the_whatsapp_share_uses_the_contacts_phone_in_the_agencys_dial_code_form(): void
    {
        $this->service()->attach($this->tenant);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->getContent();

        $this->assertStringContainsString('https://wa.me/27821234567?text=', $html);
    }

    public function test_the_portal_page_prefills_the_email_from_a_personal_link(): void
    {
        $html = $this->get('/portal?email=ayanda%40example.test')->assertOk()->getContent();

        $this->assertStringContainsString("get('email')", $html);
        $this->assertStringContainsString('Only pre-fills the field', $html);
    }

    // ── 6. Switched off / permissions / scoping ──────────────────────────────────────────────

    public function test_with_the_tenant_portal_switched_off_no_link_is_offered_and_nothing_is_sent(): void
    {
        Mail::fake();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_portal_enabled' => false]);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-state="disabled"', $html);
        // The tenant card offers nothing; only the landlord (portal still on) keeps its one action.
        $this->assertSame(1, substr_count($html, 'data-test="portal-access-setup-and-send"'));
        $this->assertStringNotContainsString('data-test="portal-access-link"', $html);

        $this->postFor('invite', $this->lease, $this->tenant)
            ->assertSessionHas('portal_access_flash', fn ($f) => $f['type'] === 'error');
        Mail::assertNothingSent();
        $this->assertNull($this->tenant->fresh()->client_user_id);
    }

    public function test_a_contact_that_is_not_on_the_lease_is_a_404(): void
    {
        $stranger = $this->contact('Stranger', 'stranger@example.test');

        $this->postFor('setup', $this->lease, $stranger)->assertNotFound();
        $this->assertNull($stranger->fresh()->client_user_id);
    }

    public function test_an_agent_from_another_agency_cannot_act_on_this_lease(): void
    {
        $otherAgency = Agency::create(['name' => 'Rival ' . uniqid(), 'slug' => 'rival-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $rival = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->actingAs($rival)->post(route('corex.leases.portal-access.setup', [$this->lease, $this->tenant->id]))
            ->assertStatus(404);
        $this->assertNull($this->tenant->fresh()->client_user_id);
    }

    public function test_without_the_create_login_permission_the_action_is_forbidden_and_the_card_offers_none(): void
    {
        Role::create(['name' => 'clerk', 'label' => 'Clerk', 'agency_id' => $this->agency->id]);
        foreach (['leases.view', 'contacts.view'] as $key) {
            RolePermission::updateOrCreate(['role' => 'clerk', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $clerk = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'clerk']);

        $this->actingAs($clerk)->post(route('corex.leases.portal-access.setup', [$this->lease, $this->tenant->id]))->assertForbidden();
        $this->assertNull($this->tenant->fresh()->client_user_id);

        $html = $this->actingAs($clerk)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-test="portal-access-setup-and-send"', $html);
        $this->assertStringContainsString('You do not have permission to create portal access', $html);
    }

    public function test_an_own_scope_user_cannot_act_on_a_lease_someone_else_created(): void
    {
        Role::create(['name' => 'clerk', 'label' => 'Clerk', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(['role' => 'clerk', 'permission_key' => 'leases.view', 'agency_id' => $this->agency->id], ['scope' => 'own']);
        RolePermission::updateOrCreate(['role' => 'clerk', 'permission_key' => 'client_app.create_login', 'agency_id' => $this->agency->id], ['scope' => 'all']);
        PermissionService::clearCache();
        $clerk = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'clerk']);

        $this->actingAs($clerk)->post(route('corex.leases.portal-access.setup', [$this->lease, $this->tenant->id]))->assertForbidden();
        $this->assertNull($this->tenant->fresh()->client_user_id);
    }
}
