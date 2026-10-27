<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Mobile parity, inspections: schedule, reschedule, cancel, archive, restore, replace, public link, and the routes that point straight
 * at the web actions (ready to sign, header block, room marks, signing links ...). The web rule for each is tested with the web; this file
 * proves the phone reaches the same rule with the same permission, and that scoping and archived records behave as on every other
 * mobile inspection route.
 */
final class MobileInspectionLifecycleTest extends TestCase
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

    private function open(array $attrs = []): RentalInspection
    {
        return $this->inspectionRow(RentalInspection::TYPE_AD_HOC, $attrs);
    }

    private function viewer(): User
    {
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_inspections.view', 'leases.view', 'properties.view', 'access_properties']);

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
    }

    // ── schedule / reschedule ─────────────────────────────────────────────────────────────

    public function test_an_inspection_can_be_scheduled_for_a_lease_and_comes_back_as_the_full_detail(): void
    {
        $res = $this->asStaff($this->admin)->postJson($this->base('/schedule'), [
            'lease_id' => $this->lease->id, 'type' => RentalInspection::TYPE_AD_HOC,
            'scheduled_for' => now()->addDays(20)->toDateString(), 'scheduled_time' => '09:30', 'schedule_note' => 'Gate code 1234',
        ])->assertCreated();

        $res->assertJsonPath('duplicate', false)->assertJsonPath('id', $res->json('id'));
        $insp = RentalInspection::findOrFail($res->json('id'));
        $this->assertSame($this->lease->id, (int) $insp->lease_id);
        $this->assertSame(now()->addDays(20)->toDateString(), $insp->scheduled_for->toDateString());
        $this->assertSame('Gate code 1234', $insp->schedule_note);
    }

    public function test_scheduling_needs_a_date_and_another_agencys_lease_is_not_found(): void
    {
        $this->asStaff($this->admin)->postJson($this->base('/schedule'), ['lease_id' => $this->lease->id, 'type' => 'ad_hoc'])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_for');

        $this->asStaff($this->outsider)->postJson($this->base('/schedule'), [
            'lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'scheduled_for' => now()->addDays(3)->toDateString(),
        ])->assertNotFound();
    }

    public function test_reschedule_moves_the_date_and_keeps_the_old_one_as_history(): void
    {
        $insp = RentalInspection::schedule($this->property, RentalInspection::TYPE_AD_HOC, $this->admin, ['scheduled_for' => now()->addDays(10)->toDateString()], $this->lease);
        $new = now()->addDays(14)->toDateString();

        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/reschedule"), ['scheduled_for' => $new, 'reason' => 'Tenant away'])
            ->assertOk()->assertJsonPath('id', $insp->id);

        $this->assertSame($new, $insp->fresh()->scheduled_for->toDateString());
        $this->assertSame(1, \App\Models\RentalInspectionReschedule::query()->where('rental_inspection_id', $insp->id)->count());
    }

    // ── cancel / archive / restore ────────────────────────────────────────────────────────

    public function test_cancel_needs_a_reason_then_cancels_and_a_second_cancel_is_refused(): void
    {
        $insp = $this->open();

        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/cancel"), [])->assertStatus(422)->assertJsonValidationErrors('cancel_reason');
        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/cancel"), ['cancel_reason' => 'Tenant moved out early'])->assertOk();
        $this->assertSame(RentalInspection::STATUS_CANCELLED, $insp->fresh()->status);

        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/cancel"), ['cancel_reason' => 'again'])
            ->assertStatus(409)->assertJsonPath('code', 'not_recordable');
    }

    public function test_archive_soft_deletes_and_restore_brings_it_back(): void
    {
        $insp = $this->open();

        $this->asStaff($this->admin)->deleteJson($this->base("/{$insp->id}"))->assertOk()->assertJsonPath('archived', true);
        $this->assertSoftDeleted('rental_inspections', ['id' => $insp->id]);
        $this->assertSame($this->admin->id, (int) RentalInspection::withTrashed()->find($insp->id)->archived_by_user_id);

        // an archived inspection answers 410 on the lifecycle routes that need it open
        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/cancel"), ['cancel_reason' => 'x'])->assertStatus(410)->assertJsonPath('code', 'archived');

        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/restore"))->assertOk()->assertJsonPath('id', $insp->id);
        $this->assertNull(RentalInspection::withTrashed()->find($insp->id)->deleted_at);
    }

    public function test_a_sent_or_signed_inspection_can_only_be_archived_by_someone_with_archive_completed(): void
    {
        $this->grantScope($this->agency, 'agent', 'own', ['rental_inspections.view', 'rental_inspections.archive', 'leases.view', 'properties.view', 'access_properties']);
        $insp = $this->open(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()]);

        $this->asStaff($this->agent)->deleteJson($this->base("/{$insp->id}"))->assertForbidden()->assertJsonPath('code', 'needs_manager');
        $this->assertNull(RentalInspection::find($insp->id)->deleted_at);
    }

    // ── permissions and scoping ───────────────────────────────────────────────────────────

    public function test_a_viewer_cannot_cancel_reschedule_archive_or_restore(): void
    {
        $insp = $this->open();
        $viewer = $this->viewer();

        $this->asStaff($viewer)->postJson($this->base("/{$insp->id}/cancel"), ['cancel_reason' => 'x'])->assertForbidden();
        $this->asStaff($viewer)->postJson($this->base("/{$insp->id}/reschedule"), ['scheduled_for' => now()->addDay()->toDateString()])->assertForbidden();
        $this->asStaff($viewer)->deleteJson($this->base("/{$insp->id}"))->assertForbidden();
        $this->asStaff($viewer)->postJson($this->base("/{$insp->id}/restore"))->assertForbidden();
        $this->asStaff($viewer)->postJson($this->base("/{$insp->id}/ready-to-sign"))->assertForbidden();
        $this->assertSame(RentalInspection::STATUS_DRAFT, $insp->fresh()->status);
    }

    public function test_another_agencys_inspection_is_not_found_on_every_new_route(): void
    {
        $insp = $this->open();

        foreach ([['post', '/cancel', ['cancel_reason' => 'x']], ['post', '/restore', []], ['post', '/replace', []], ['post', '/next', ['type' => 'out']],
            ['post', '/ready-to-sign', []], ['post', '/mark-all-good', []], ['get', '/signing-links', []], ['get', '/report', []]] as [$verb, $path, $body]) {
            $this->asStaff($this->outsider)->json(strtoupper($verb), $this->base("/{$insp->id}{$path}"), $body)->assertNotFound();
        }
        $this->asStaff($this->outsider)->deleteJson($this->base("/{$insp->id}"))->assertNotFound();
    }

    // ── public link / replace / the routes that point at the web ──────────────────────────

    public function test_the_public_link_is_minted_and_revoked_and_refused_for_a_cancelled_inspection(): void
    {
        $insp = $this->open();

        $url = $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/public-link"))->assertCreated()->json('url');
        $this->assertStringContainsString('/rental-inspection-report/', $url);
        $this->assertTrue($insp->fresh()->publicLinkIsValid());

        $this->asStaff($this->admin)->deleteJson($this->base("/{$insp->id}/public-link"))->assertOk();
        $this->assertFalse($insp->fresh()->publicLinkIsValid());

        $insp->forceFill(['status' => RentalInspection::STATUS_CANCELLED])->save();
        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/public-link"))->assertStatus(409)->assertJsonPath('code', 'cancelled');
    }

    public function test_the_web_actions_the_phone_now_reaches_answer_json_for_a_bearer_token(): void
    {
        $insp = $this->open();

        // the signing-links panel is the web controller, answering JSON to the phone
        $this->asStaff($this->admin)->getJson($this->base("/{$insp->id}/signing-links"))->assertOk();
        // header block: the same validation as the web
        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/details"), ['keys_count' => 2])->assertSuccessful();
        // ready to sign is the web action: an inspection with nothing left to record moves to awaiting signature
        $this->asStaff($this->admin)->postJson($this->base("/{$insp->id}/ready-to-sign"))->assertSuccessful();
        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $insp->fresh()->status);
    }
}
