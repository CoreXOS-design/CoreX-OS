<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Compliance\InformationOfficerAppointment;
use Illuminate\Support\Facades\DB;

/**
 * Configuration sweep addendum (2026-09-02, webinar prep) — Settings →
 * Profile & Account renders a visible red banner: "No Information Officer
 * appointed. POPIA s55 requires every responsible party to designate an
 * IO." Agency 1 has a Primary FICA Compliance Officer (Thandi Mokoena,
 * DemoDataSeeder) but no Information Officer appointment at all.
 *
 * Reuses the same fictional officer identity already established for FICA
 * — realistic for a small agency where one person holds both compliance
 * roles — rather than inventing a second fictional person.
 *
 * IDEMPOTENT BY CONSTRUCTION: guarded by InformationOfficerAppointment's
 * own currentPrimary() check before creating.
 */
class DemoInformationOfficerSeeder
{
    private const NAME = 'Thandi Mokoena';
    private const ID_NUMBER = '0001010000080'; // same fictional, registry-impossible ID as the FICA CO

    /** @return array{created:bool, note:string} */
    public function run(int $agencyId = 1): array
    {
        if (InformationOfficerAppointment::currentPrimary($agencyId)) {
            return ['created' => false, 'note' => 'Skipped — a primary Information Officer is already appointed.'];
        }

        $adminId = DB::table('users')->where('agency_id', $agencyId)->where('role', 'admin')->orderBy('id')->value('id');

        InformationOfficerAppointment::create([
            'agency_id'    => $agencyId,
            'user_id'      => $adminId,
            'role'         => InformationOfficerAppointment::ROLE_PRIMARY,
            'full_name'    => self::NAME,
            'id_number'    => self::ID_NUMBER,
            'email'        => 'compliance@corexdemorealty.co.za',
            'title'        => 'Information Officer',
            'appointed_on' => now()->subMonths(6)->toDateString(),
            'appointed_by' => $adminId,
            'notes'        => 'Demo appointment — fictional officer, mirrors the FICA Compliance Officer identity.',
        ]);

        return ['created' => true, 'note' => 'Information Officer appointed: ' . self::NAME . '.'];
    }
}
