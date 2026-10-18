<?php

namespace Tests\Feature\CoreX;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An owner with no agency selected must not see a console error on every page: the portal-leads toast poll is not started
 * and, if called anyway, answers an empty 200 instead of "422 Agency context required".
 */
class PortalLeadPollNoAgencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function ownerWithoutAgency(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    public function test_poll_answers_an_empty_200_when_there_is_no_agency_context(): void
    {
        $owner = $this->ownerWithoutAgency();
        $this->assertNull($owner->effectiveAgencyId());

        $this->actingAs($owner)->getJson(route('corex.portal-leads.poll'))
            ->assertOk()->assertJson(['leads' => []])->assertJsonStructure(['leads', 'server_time']);
    }

    public function test_the_toast_poll_is_not_started_without_an_agency_context(): void
    {
        $this->actingAs($this->ownerWithoutAgency());

        $html = view('components.portal-lead-toast')->render();

        $this->assertStringNotContainsString('portalLeadToast()', $html, 'no poll loop for an owner who has not picked an agency');
    }

    public function test_the_poll_route_still_requires_the_portal_leads_permission(): void
    {
        $this->getJson(route('corex.portal-leads.poll'))->assertUnauthorized();
    }
}
