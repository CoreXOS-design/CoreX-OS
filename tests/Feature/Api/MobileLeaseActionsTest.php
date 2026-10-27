<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Lease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Mobile parity, leases and notices: the lease actions run through the web controller's own action (RunsWebAction). This proves the phone
 * gets the web's validation and refusals as JSON with the right status, the web's permission and scope, and the lease back on success.
 */
final class MobileLeaseActionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function url(string $suffix = '', ?Lease $lease = null): string
    {
        return '/api/v1/mobile/leases/' . ($lease ?? $this->lease)->id . $suffix;
    }

    private function viewer(): User
    {
        $this->grantScope($this->agency, 'viewer', 'all', ['leases.view', 'rental_notices.view', 'properties.view', 'access_properties']);

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
    }

    public function test_the_webs_validation_comes_back_as_422_with_its_own_wording(): void
    {
        $this->asStaff($this->admin)->putJson($this->url('/agents'), [])
            ->assertStatus(422)->assertJsonPath('code', 'invalid')->assertJsonValidationErrors('owner_agent_user_id');
        $this->asStaff($this->admin)->postJson($this->url('/cancel'), [])
            ->assertStatus(422)->assertJsonValidationErrors('cancel_reason');
        $this->asStaff($this->admin)->postJson($this->url('/archive'), [])
            ->assertStatus(422)->assertJsonValidationErrors('archive_reason');
    }

    public function test_a_lease_can_be_cancelled_and_the_lease_comes_back(): void
    {
        $this->asStaff($this->admin)->postJson($this->url('/cancel'), ['cancel_reason' => 'Tenant moved out early'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('lease.id', $this->lease->id);

        $this->assertSame(Lease::STATUS_CANCELLED, $this->lease->fresh()->status);

        // the web refuses a second cancel with its own words; the phone passes them through as 409
        $this->asStaff($this->admin)->postJson($this->url('/cancel'), ['cancel_reason' => 'again'])
            ->assertStatus(409)->assertJsonPath('code', 'refused');
    }

    public function test_the_agents_on_a_lease_are_changed_through_the_web_action(): void
    {
        $this->asStaff($this->admin)->putJson($this->url('/agents'), ['owner_agent_user_id' => $this->colleague->id, 'tenant_agent_user_id' => $this->agent->id])
            ->assertOk()->assertJsonPath('ok', true);

        $lease = $this->lease->fresh();
        $this->assertSame($this->colleague->id, (int) $lease->owner_agent_user_id);
        $this->assertSame($this->agent->id, (int) $lease->tenant_agent_user_id);
    }

    public function test_a_lease_that_was_never_activated_can_be_archived_and_restored(): void
    {
        $draft = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonths(13)->toDateString(),
            'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->asStaff($this->admin)->postJson($this->url('/archive', $draft), ['archive_reason' => 'Captured by mistake'])->assertOk()->assertJsonPath('ok', true);
        $this->assertSoftDeleted('leases', ['id' => $draft->id]);

        $this->asStaff($this->admin)->postJson($this->url('/restore', $draft))->assertOk()->assertJsonPath('ok', true);
        $this->assertNull(Lease::withTrashed()->find($draft->id)->deleted_at);
    }

    public function test_confirming_notice_terms_answers_with_the_webs_message(): void
    {
        $this->asStaff($this->admin)->postJson($this->url('/notice-terms/confirm'))
            ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['message', 'lease']);
    }

    public function test_the_expiry_notice_actions_are_refused_with_the_webs_status_when_they_do_not_apply(): void
    {
        $this->lease->forceFill(['status' => Lease::STATUS_CANCELLED])->saveQuietly();

        $this->asStaff($this->admin)->postJson($this->url('/expiry-notice/mark-given'), ['note' => 'by hand'])
            ->assertStatus(422)->assertJsonPath('message', 'An expiry notice does not apply to this lease.');
    }

    public function test_every_action_is_scoped_to_the_agency_and_permissioned(): void
    {
        $this->grantEveryone();
        $viewer = $this->viewer();

        foreach ([['post', '/cancel'], ['post', '/activate'], ['post', '/escalate'], ['post', '/archive'], ['post', '/notice-terms/confirm'],
            ['post', '/expiry-notice/send'], ['post', '/signing/prepare'], ['post', '/breaches'], ['post', '/notices']] as [$verb, $path]) {
            $this->asStaff($this->outsider)->json(strtoupper($verb), $this->url($path), ['cancel_reason' => 'x'])->assertStatus(404);
            $this->asStaff($viewer)->json(strtoupper($verb), $this->url($path), ['cancel_reason' => 'x'])->assertForbidden();
        }
        $this->asStaff($viewer)->putJson($this->url('/agents'), [])->assertForbidden();
        $this->assertSame(Lease::STATUS_ACTIVE, $this->lease->fresh()->status);
    }

    public function test_accepting_a_notice_needs_the_review_permission(): void
    {
        $this->asStaff($this->viewer())->postJson('/api/v1/mobile/rental-notices/1/accept', [])->assertForbidden();
    }
}
