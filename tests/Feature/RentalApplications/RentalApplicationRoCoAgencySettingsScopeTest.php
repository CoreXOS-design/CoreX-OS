<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * QA1 design-standard audit, 2026-09-11 — Johan's own direction: "the same
 * bug class as the breach we found earlier this week: a raw exists:users,id
 * check that bypasses Eloquent global scopes so AgencyScope never fires."
 *
 * RentalApplicationSettingsController::updateRO()/updateCO() used to
 * validate submitted user ids with `exists:users,id` — true for ANY user on
 * the whole platform, not just this agency's own — then wrote the raw ids
 * straight onto Agency::rental_application_ro_user_ids/co_user_ids with no
 * agency-membership check at all. RentalApplicationAuthorisationController's
 * guardCanView()/guardCanDecide() check RO/CO tier membership against the
 * APPLICATION's own agency_id, so a planted foreign user id became a real,
 * working Reviewer/Override for that agency's applications, reachable by
 * direct URL — a genuine cross-agency privilege escalation.
 *
 * Fixed via resolveAgencyScopedUserIds(), which resolves every submitted id
 * through User::where('agency_id', $agencyId) — the model, not a raw
 * exists: rule — the same `agencyUsers` shape the settings screen's own
 * checkbox list already uses. Any id that doesn't resolve (nonexistent or
 * real-but-other-agency, treated identically) aborts the WHOLE save with a
 * 403 and logs the attempt, rather than silently saving a partial list.
 */
final class RentalApplicationRoCoAgencySettingsScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private User $adminA;
    private User $otherUserA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $this->agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);

        $this->adminA = User::factory()->create(['agency_id' => $this->agencyA->id, 'role' => 'admin']);
        $this->otherUserA = User::factory()->create(['agency_id' => $this->agencyA->id, 'role' => 'agent']);
        $this->userB = User::factory()->create(['agency_id' => $this->agencyB->id, 'role' => 'agent']);
    }

    public function test_ro_save_refuses_a_cross_agency_user_id_with_403_and_does_not_persist_it(): void
    {
        Log::spy();

        $response = $this->actingAs($this->adminA)->post(
            route('corex.settings.rental-applications.ro'),
            ['rental_application_ro_user_ids' => [$this->userB->id]],
        );

        $response->assertStatus(403);
        $this->assertNull($this->agencyA->fresh()->rental_application_ro_user_ids, 'a refused save must change nothing, not save a partial list');

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'refused cross-agency')
                && $context['acting_agency_id'] === $this->agencyA->id
                && in_array($this->userB->id, $context['rejected_user_ids'], true));
    }

    public function test_co_save_refuses_a_cross_agency_user_id_with_403_and_does_not_persist_it(): void
    {
        $response = $this->actingAs($this->adminA)->post(
            route('corex.settings.rental-applications.co'),
            ['rental_application_co_user_ids' => [$this->userB->id]],
        );

        $response->assertStatus(403);
        $this->assertNull($this->agencyA->fresh()->rental_application_co_user_ids);
    }

    public function test_ro_save_still_accepts_a_genuine_same_agency_user_id(): void
    {
        $response = $this->actingAs($this->adminA)->post(
            route('corex.settings.rental-applications.ro'),
            ['rental_application_ro_user_ids' => [$this->otherUserA->id]],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame([$this->otherUserA->id], $this->agencyA->fresh()->rental_application_ro_user_ids);
    }

    public function test_ro_save_with_a_mixed_valid_and_cross_agency_list_refuses_the_whole_save(): void
    {
        $response = $this->actingAs($this->adminA)->post(
            route('corex.settings.rental-applications.ro'),
            ['rental_application_ro_user_ids' => [$this->otherUserA->id, $this->userB->id]],
        );

        $response->assertStatus(403);
        $this->assertNull($this->agencyA->fresh()->rental_application_ro_user_ids, 'one bad id in the list must refuse the whole save, not silently drop just that id');
    }

    public function test_ro_save_with_an_empty_list_clears_the_setting(): void
    {
        $this->agencyA->update(['rental_application_ro_user_ids' => [$this->otherUserA->id]]);

        $response = $this->actingAs($this->adminA)->post(
            route('corex.settings.rental-applications.ro'),
            ['rental_application_ro_user_ids' => []],
        );

        $response->assertRedirect();
        $this->assertNull($this->agencyA->fresh()->rental_application_ro_user_ids);
    }
}
