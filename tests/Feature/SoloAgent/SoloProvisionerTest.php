<?php

namespace Tests\Feature\SoloAgent;

use App\Exceptions\SoloSeatException;
use App\Models\Agency;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\SoloAgent\SoloAgentProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 2b - the provisioner and the one-seat rule:
 * .ai/specs/solo-agent-accounts.md sections 4.2 and 5.
 */
class SoloProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $over = []): array
    {
        return array_merge([
            'agency_name' => 'Lone Star Realty',
            'name'        => 'Sam Solo',
            'email'       => 'sam@lonestar.test',
            'cell'        => '0821234567',
        ], $over);
    }

    private function provision(array $over = []): array
    {
        return app(SoloAgentProvisioner::class)->provision($this->input($over));
    }

    public function test_it_creates_a_solo_agency_with_one_branch_and_one_inactive_admin_login(): void
    {
        ['agency' => $agency, 'user' => $user] = $this->provision();

        $agency = $agency->fresh();
        $this->assertTrue($agency->isSolo());
        $this->assertSame('Lone Star Realty', $agency->name);
        $this->assertSame('both', $agency->agency_type, 'a solo agent gets sales and rentals unless told otherwise');
        $this->assertNotNull($agency->default_branch_id);

        $this->assertSame(1, \App\Models\Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        $this->assertSame(1, User::withoutGlobalScopes()->where('agency_id', $agency->id)->count());

        $user = $user->fresh();
        $this->assertSame('admin', $user->role);
        $this->assertSame($agency->default_branch_id, $user->branch_id);
        $this->assertFalse((bool) $user->is_active, 'no sign-in until the signed agreement activates the account');
        $this->assertNull($user->invited_at, 'the password-setup email is sent at activation, not at creation');
        $this->assertNull($user->email_verified_at);
    }

    public function test_the_feature_locks_apply_from_the_first_request(): void
    {
        // account_kind is set in the creating insert, so the feature cache is never holding
        // a normal agency's answers for the new account.
        ['agency' => $agency] = $this->provision();

        $map = app(AgencyFeatureService::class)->all($agency);
        foreach (config('solo-agent.locked_features') as $key) {
            $this->assertFalse($map[$key], "'{$key}' must be off for the new solo agency straight after creation");
        }
    }

    public function test_it_fires_the_agency_created_event_for_the_new_agency(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\AgencyCreated::class]);

        ['agency' => $agency, 'user' => $user] = $this->provision();

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\AgencyCreated::class,
            fn ($e) => $e->agency->id === $agency->id && $e->adminUser->id === $user->id
        );
    }

    public function test_agency_name_is_required(): void
    {
        $this->expectException(ValidationException::class);
        $this->provision(['agency_name' => '']);
    }

    public function test_an_email_already_in_use_is_refused_and_nothing_is_left_behind(): void
    {
        $this->provision();
        $agenciesBefore = Agency::count();

        try {
            $this->provision(['agency_name' => 'Another Name']);
            $this->fail('A duplicate email must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame($agenciesBefore, Agency::count());
    }

    public function test_two_solo_agents_with_the_same_agency_name_get_different_slugs(): void
    {
        $a = $this->provision();
        $b = $this->provision(['email' => 'other@lonestar.test']);

        $this->assertNotSame($a['agency']->slug, $b['agency']->slug);
    }

    public function test_a_solo_agency_refuses_a_second_login(): void
    {
        ['agency' => $agency] = $this->provision();

        $this->expectException(SoloSeatException::class);
        User::factory()->create(['agency_id' => $agency->id, 'role' => 'agent']);
    }

    public function test_a_user_cannot_be_moved_into_a_solo_agency(): void
    {
        ['agency' => $solo] = $this->provision();
        $other = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        $user  = User::factory()->create(['agency_id' => $other->id, 'role' => 'agent']);

        $this->expectException(SoloSeatException::class);
        $user->update(['agency_id' => $solo->id]);
    }

    public function test_the_solo_user_can_still_be_edited(): void
    {
        ['user' => $user] = $this->provision();

        $user->fresh()->update(['name' => 'Samantha Solo', 'is_active' => true]);

        $this->assertSame('Samantha Solo', $user->fresh()->name);
        $this->assertTrue((bool) $user->fresh()->is_active);
    }

    public function test_a_normal_agency_is_unaffected_by_the_seat_rule(): void
    {
        $agency = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);

        User::factory()->count(3)->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $this->assertFalse($agency->fresh()->isSolo());
        $this->assertSame(3, User::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }
}
