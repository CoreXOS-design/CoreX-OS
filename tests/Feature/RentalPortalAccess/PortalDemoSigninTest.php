<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientAccessLog;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\PortalDemoSignin;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Support\PortalDemoSigninGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA DEMO SIGN-IN (never on Staging/live) - .ai/specs/rental-portal-access.md "QA demo sign-in". The route exists only on APP_ENV=qa with the
 * flag on; a token opens exactly its own contact; the staff login in the same browser is untouched; every use is logged; tokens are hashed.
 */
final class PortalDemoSigninTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Contact $owner;
    private Contact $tenant;
    private ClientUser $ownerLogin;
    private ClientUser $tenantLogin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Demo Agency', 'slug' => 'dm-' . uniqid()]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->ownerLogin = ClientUser::create(['email' => 'owner-' . uniqid() . '@example.invalid', 'current_agency_id' => $this->agency->id, 'created_by_agency_id' => $this->agency->id]);
        $this->tenantLogin = ClientUser::create(['email' => 'tenant-' . uniqid() . '@example.invalid', 'current_agency_id' => $this->agency->id, 'created_by_agency_id' => $this->agency->id]);
        $this->owner = $this->contact('Olive', 'Owner', $this->ownerLogin);
        $this->tenant = $this->contact('Tess', 'Tenant', $this->tenantLogin);
    }

    private function contact(string $first, string $last, ClientUser $login): Contact
    {
        return Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'first_name' => $first, 'last_name' => $last, 'email' => 'x-' . uniqid() . '@example.invalid', 'client_user_id' => $login->id]);
    }

    /** Boot the routes the way routes/web.php does, for the given environment and flag. */
    private function register(string $env, bool $flag): void
    {
        $this->app['env'] = $env;
        config(['clientauth.demo_signin_enabled' => $flag]);
        PortalDemoSigninGate::registerRoutes();
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->app['router']->getRoutes()->refreshActionLookups();
    }

    public function test_it_works_on_the_qa_environment_with_the_flag_on_and_signs_in_as_that_contact(): void
    {
        $this->register('qa', true);
        $token = PortalDemoSignin::issue($this->owner, 'owner');

        $res = $this->get('/demo-signin/' . $token);
        $res->assertRedirect();
        $this->assertStringContainsString('/portal?', $res->headers->get('Location'));
        $this->assertStringContainsString('as=owner', $res->headers->get('Location'));
        $this->assertSame($this->ownerLogin->id, Auth::guard('client-web')->id());

        $row = PortalDemoSignin::query()->first();
        $this->assertSame(1, (int) $row->use_count);
        $this->assertNotNull($row->last_used_at);
        // every use is logged
        $this->assertSame(1, ClientAccessLog::where('event', 'demo_signin')->where('contact_id', $this->owner->id)->count());
        // the token is stored hashed, never as given
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame(0, DB::table('portal_demo_signins')->where('token_hash', $token)->count());
    }

    public function test_the_route_is_not_registered_when_the_flag_is_off(): void
    {
        $this->register('qa', false);
        $token = PortalDemoSignin::issue($this->owner, 'owner');
        $this->assertFalse(PortalDemoSigninGate::open());
        $this->get('/demo-signin/' . $token)->assertNotFound();
        $this->assertFalse(Auth::guard('client-web')->check());
    }

    public function test_it_is_a_404_on_staging_and_production_even_when_the_flag_is_on(): void
    {
        foreach (['staging', 'production', 'local', 'testing'] as $env) {
            $this->register($env, true);
            $token = PortalDemoSignin::issue($this->owner, 'owner');
            $this->assertFalse(PortalDemoSigninGate::open(), $env);
            $this->get('/demo-signin/' . $token)->assertNotFound();
            $this->assertFalse(Auth::guard('client-web')->check(), $env);
        }
    }

    public function test_a_route_registered_by_mistake_still_answers_404_outside_the_gate(): void
    {
        $this->register('qa', true);
        $token = PortalDemoSignin::issue($this->owner, 'owner');
        // the environment changes after the routes were registered (a stale route table)
        $this->app['env'] = 'production';
        $this->get('/demo-signin/' . $token)->assertNotFound();
        $this->assertFalse(Auth::guard('client-web')->check());
    }

    public function test_a_token_opens_only_its_own_contact(): void
    {
        $this->register('qa', true);
        $ownerToken = PortalDemoSignin::issue($this->owner, 'owner');
        $tenantToken = PortalDemoSignin::issue($this->tenant, 'tenant');

        $this->get('/demo-signin/' . $tenantToken)->assertRedirect();
        $this->assertSame($this->tenantLogin->id, Auth::guard('client-web')->id());
        $this->assertNotSame($this->ownerLogin->id, Auth::guard('client-web')->id());

        // a guessed / altered / unknown token opens nothing
        $this->flushSession();
        Auth::guard('client-web')->forgetUser();
        $this->get('/demo-signin/' . substr($ownerToken, 0, -1) . 'x')->assertNotFound();
        $this->get('/demo-signin/' . str_repeat('a', 48))->assertNotFound();
        $this->assertFalse(Auth::guard('client-web')->check());

        // the owner's token still opens the owner's login, not the tenant's
        $this->get('/demo-signin/' . $ownerToken)->assertRedirect();
        $this->assertSame($this->ownerLogin->id, Auth::guard('client-web')->id());
    }

    public function test_a_revoked_token_and_a_reissued_token_replace_the_old_one(): void
    {
        $this->register('qa', true);
        $first = PortalDemoSignin::issue($this->owner, 'owner');
        $second = PortalDemoSignin::issue($this->owner, 'owner');   // issuing again revokes the earlier link
        $this->get('/demo-signin/' . $first)->assertNotFound();
        $this->get('/demo-signin/' . $second)->assertRedirect();

        $this->assertSame(1, PortalDemoSignin::revokeFor($this->owner->id));
        $this->flushSession();
        $this->get('/demo-signin/' . $second)->assertNotFound();
    }

    public function test_opening_a_demo_link_does_not_sign_a_staff_user_out_of_the_same_browser(): void
    {
        $this->register('qa', true);
        $staff = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);
        $token = PortalDemoSignin::issue($this->owner, 'owner');

        $this->actingAs($staff)->get('/demo-signin/' . $token)->assertRedirect();

        $this->assertTrue(Auth::guard('web')->check(), 'the staff login must survive');
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->assertSame($this->ownerLogin->id, Auth::guard('client-web')->id());
    }

    public function test_the_artisan_command_refuses_outside_the_gate_and_issues_a_link_inside_it(): void
    {
        $this->app['env'] = 'production';
        config(['clientauth.demo_signin_enabled' => true]);
        $this->artisan('portal:demo-signin', ['contact' => $this->owner->id, 'role' => 'owner'])->assertFailed();
        $this->assertSame(0, PortalDemoSignin::query()->count());

        $this->app['env'] = 'qa';
        $this->artisan('portal:demo-signin', ['contact' => $this->owner->id, 'role' => 'owner'])->assertSuccessful();
        $this->assertSame(1, PortalDemoSignin::query()->count());
        $this->artisan('portal:demo-signin', ['contact' => $this->owner->id, 'role' => 'owner', '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, PortalDemoSignin::query()->whereNull('revoked_at')->count());
    }
}
