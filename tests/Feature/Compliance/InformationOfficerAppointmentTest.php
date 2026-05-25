<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\InformationOfficerAppointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 9c-2 — IO appointment lifecycle (mirrors FICA pattern).
 */
final class InformationOfficerAppointmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_primary_io_appointment(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();

        $appt = InformationOfficerAppointment::create([
            'agency_id'    => $agencyId,
            'user_id'      => $userId,
            'role'         => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name'    => 'Elize Test',
            'appointed_on' => now()->toDateString(),
        ]);

        $this->assertNotNull($appt->id);
        $this->assertTrue($appt->isPrimary());
        $this->assertSame($userId, $appt->user_id);
    }

    public function test_appointing_second_primary_auto_ends_first(): void
    {
        [$agencyId, $aliceId] = $this->seedAgencyAndUser();
        $bob = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);

        $first = InformationOfficerAppointment::create([
            'agency_id'    => $agencyId,
            'user_id'      => $aliceId,
            'role'         => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name'    => 'Alice',
            'appointed_on' => now()->subMonth()->toDateString(),
        ]);

        InformationOfficerAppointment::create([
            'agency_id'    => $agencyId,
            'user_id'      => $bob->id,
            'role'         => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name'    => 'Bob',
            'appointed_on' => now()->toDateString(),
        ]);

        $this->assertNotNull($first->fresh()->ended_on,
            'first primary must be auto-ended when a new primary is appointed');
        $this->assertSame('Bob', InformationOfficerAppointment::currentPrimary($agencyId)->full_name);
    }

    public function test_can_create_multiple_deputies(): void
    {
        [$agencyId, $aliceId] = $this->seedAgencyAndUser();
        $bob = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        InformationOfficerAppointment::create([
            'agency_id' => $agencyId, 'user_id' => $aliceId,
            'role' => InformationOfficerAppointment::ROLE_DEPUTY,
            'full_name' => 'Alice', 'appointed_on' => now()->toDateString(),
        ]);
        InformationOfficerAppointment::create([
            'agency_id' => $agencyId, 'user_id' => $bob->id,
            'role' => InformationOfficerAppointment::ROLE_DEPUTY,
            'full_name' => 'Bob', 'appointed_on' => now()->toDateString(),
        ]);

        $deputies = InformationOfficerAppointment::activeDeputiesFor($agencyId);
        $this->assertCount(2, $deputies);
    }

    public function test_end_appointment_marks_ended_on(): void
    {
        [$agencyId, $userId] = $this->seedAgencyAndUser();
        $appt = InformationOfficerAppointment::create([
            'agency_id' => $agencyId, 'user_id' => $userId,
            'role' => InformationOfficerAppointment::ROLE_DEPUTY,
            'full_name' => 'X', 'appointed_on' => now()->toDateString(),
        ]);

        $appt->update(['ended_on' => now()->toDateString()]);

        $this->assertNotNull($appt->fresh()->ended_on);
        $this->assertCount(0, InformationOfficerAppointment::activeDeputiesFor($agencyId));
    }

    public function test_agency_helpers_return_active_appointments(): void
    {
        [$agencyId, $aliceId] = $this->seedAgencyAndUser();
        $bob = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        InformationOfficerAppointment::create([
            'agency_id' => $agencyId, 'user_id' => $aliceId,
            'role' => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name' => 'Alice', 'appointed_on' => now()->toDateString(),
        ]);
        InformationOfficerAppointment::create([
            'agency_id' => $agencyId, 'user_id' => $bob->id,
            'role' => InformationOfficerAppointment::ROLE_DEPUTY,
            'full_name' => 'Bob', 'appointed_on' => now()->toDateString(),
        ]);

        $agency = \App\Models\Agency::find($agencyId);
        $this->assertSame($aliceId, $agency->currentInformationOfficer()?->id);
        $this->assertCount(2, $agency->allActiveInformationOfficers(),
            'primary + deputy both returned by allActiveInformationOfficers()');
    }

    // ── Helpers ──

    /** @return array{0:int,1:int} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
        return [$agencyId, $user->id];
    }
}
