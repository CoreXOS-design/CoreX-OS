<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\ClientAuthOtpMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\RentalPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Johan, QA1, 2026-10-07: he opened a tenant's (and then the owner's) personal portal link, entered the emailed
 * code, chose a password — and landed on "Unauthorized". The browser he tested in was also signed in as STAFF; Sanctum
 * looks at the staff `web` session before the portal's own, so the staff user answered for the portal request and the
 * portal refused it. These tests walk the WHOLE path over the real endpoints, the way the portal page does (same-origin,
 * cookie session, activation token only on the set-password call): link email → lookup → code → set password → portal
 * home with the person's lease / property — for a tenant and an owner, a brand-new login and an existing one (set up
 * from the lease screen, no password yet), in a clean browser AND in a browser where a staff user is already signed in.
 */
final class PortalLinkToHomeTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Origin' => 'http://localhost'];
    private const PASSWORD = 'Sup3rSecret!pw';

    private Agency $agency;
    private Branch $branch;
    private User $staff;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->staff->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'address' => '401 Margate Boulevard',
        ]);
        $this->tenant = $this->contact('Ayanda', 'ayanda@example.test');
        $this->owner = $this->contact('Siyabonga', 'siya@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->owner->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->staff->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    /**
     * One request as the portal page makes it: same-origin (so Sanctum treats it as a browser), and every request
     * starts with NO authenticated user in memory — in production each request is its own PHP process, whereas the
     * test process would otherwise carry the previous request's user (e.g. the activation token's) into the next.
     */
    private function browser(array $headers = []): static
    {
        Auth::forgetGuards();
        Auth::shouldUse('web'); // the default guard every request starts with — `auth:sanctum` flips it (and the config) per request

        return $this->withHeaders(self::BROWSER + $headers);
    }

    /** A staff user already signed in, the way a real browser holds it: in the cookie session, not set on the guard in memory. */
    private function staffBrowser(): void
    {
        $this->withSession([Auth::guard('web')->getName() => $this->staff->id]);
    }

    private function contact(string $first, string $email): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => 'Test', 'email' => $email, 'phone' => '0821234567',
        ]);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id]);

        return $c->fresh();
    }

    /** What the portal page does after the personal link is opened: lookup → emailed code → verify → set password. Returns the set-password response. */
    private function walkLinkToPassword(string $email)
    {
        $this->browser()->postJson('/api/v1/client-auth/lookup', ['email' => $email])->assertOk()->assertJsonPath('exists', true);
        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => $email, 'purpose' => 'activation'])->assertOk();

        $code = null;
        Mail::assertSent(ClientAuthOtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->assertNotNull($code, 'the emailed code');

        $verify = $this->browser()->postJson('/api/v1/client-auth/otp/verify', ['email' => $email, 'code' => $code])->assertOk();

        $set = $this->browser(['Authorization' => 'Bearer ' . $verify->json('activation_token')])
            ->postJson('/api/v1/client-auth/password/set', ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        // The activation token travels on that ONE call only (the page sends it as a header on that fetch) — never on later requests.
        $this->flushHeaders();

        return $set;
    }

    private function assertPortalHome(string $role, string $email): void
    {
        $this->assertTrue(Auth::guard('client-web')->check(), 'a portal session must exist');
        $me = $this->browser()->getJson('/api/v1/client/me');
        $this->assertSame(200, $me->getStatusCode(), 'portal /me: ' . $me->getContent());
        $me->assertJsonPath('client.email', $email);

        if ($role === 'tenant') {
            $leases = $this->browser()->getJson('/api/v1/client/rentals/leases')->assertOk()->json('leases');
            $this->assertSame([$this->lease->id], array_column($leases, 'id'), 'the tenant\'s portal home shows the lease');
        } else {
            $props = $this->browser()->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->json('properties');
            $this->assertSame([$this->property->id], array_column($props, 'id'), 'the owner\'s portal home shows the property');
        }
    }

    public static function people(): array
    {
        return [
            'tenant, new login' => ['tenant', false],
            'tenant, existing login' => ['tenant', true],
            'owner, new login' => ['owner', false],
            'owner, existing login' => ['owner', true],
        ];
    }

    private function contactFor(string $role): Contact
    {
        return $role === 'tenant' ? $this->tenant : $this->owner;
    }

    /** @dataProvider people */
    #[\PHPUnit\Framework\Attributes\DataProvider('people')]
    public function test_clean_browser_link_to_code_to_password_to_portal_home(string $role, bool $existingLogin): void
    {
        $contact = $this->contactFor($role);
        if ($existingLogin) {
            app(RentalPortalAccessService::class)->attach($contact);
            $this->assertNotNull($contact->fresh()->client_user_id);
        }

        $this->walkLinkToPassword($contact->email)->assertOk()->assertJsonMissingPath('token');

        $this->assertPortalHome($role, $contact->email);
        $login = ClientUser::where('email', $contact->email)->first();
        $this->assertTrue($login->hasPassword());
        $this->assertSame($this->agency->id, (int) $login->current_agency_id, 'the agency is chosen for a single-agency person');
        $this->assertSame(1, ClientUser::where('email', $contact->email)->count());
    }

    /** @dataProvider people */
    #[\PHPUnit\Framework\Attributes\DataProvider('people')]
    public function test_the_same_path_in_a_browser_where_a_staff_user_is_already_signed_in(string $role, bool $existingLogin): void
    {
        $contact = $this->contactFor($role);
        if ($existingLogin) {
            app(RentalPortalAccessService::class)->attach($contact);
        }

        $this->staffBrowser();

        $this->walkLinkToPassword($contact->email)->assertOk();

        $this->assertPortalHome($role, $contact->email);
        // …and the staff login in that browser is still there: the portal never signs staff out or in as someone else.
        $this->assertSame($this->staff->id, session(Auth::guard('web')->getName()));
    }

    public function test_a_returning_person_signs_in_with_the_password_in_a_staff_browser_too(): void
    {
        $this->walkLinkToPassword($this->tenant->email)->assertOk();
        $this->browser()->postJson('/api/v1/client-auth/logout')->assertOk();

        $this->staffBrowser();
        $this->browser()->postJson('/api/v1/client-auth/login', ['email' => $this->tenant->email, 'password' => self::PASSWORD])->assertOk();

        $this->assertPortalHome('tenant', $this->tenant->email);
    }

    public function test_signing_out_of_the_portal_does_not_sign_staff_out_of_the_same_browser(): void
    {
        $this->staffBrowser();
        $this->walkLinkToPassword($this->tenant->email)->assertOk();

        $this->browser()->postJson('/api/v1/client-auth/logout')->assertOk();

        $this->assertFalse(Auth::guard('client-web')->check());
        $this->assertSame($this->staff->id, session(Auth::guard('web')->getName()), 'the staff login is still in the browser session');
        $this->browser()->getJson('/api/v1/client/me')->assertStatus(401);
    }

    public function test_a_staff_session_alone_never_opens_the_client_portal_api(): void
    {
        $this->staffBrowser();

        $this->browser()->getJson('/api/v1/client/me')->assertStatus(401);
        $this->browser()->getJson('/api/v1/client/rentals/leases')->assertStatus(401);
    }

    public function test_a_staff_bearer_token_still_cannot_use_the_client_portal(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->staff, ['*']);

        $this->getJson('/api/v1/client/me')->assertStatus(403);
    }
}
