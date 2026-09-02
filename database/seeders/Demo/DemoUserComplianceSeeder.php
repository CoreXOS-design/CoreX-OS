<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Configuration sweep (2026-09-02, webinar prep, Johan) — every one of the
 * 14 demo users had NULL ffc_number/ffc_expiry_date/ppra_status/
 * ppra_last_verified_at/phone/cell/whatsapp_number/designation. Confirmed
 * live: `/admin/users` showed a red "14 agent(s) need PPRA re-verification"
 * banner covering the entire roster — the single most compliance-visible
 * gap on that screen for a real-estate audience.
 *
 * Agent photos are DELIBERATELY left alone — the roster degrades gracefully
 * to initials avatars (not a broken `<img>`, just generic), and fabricating
 * synthetic "photos of people" is not something this seeder does. Flagging
 * it as an accepted gap rather than a blocker, per the brief.
 *
 * IDEMPOTENT BY CONSTRUCTION — every field only set when currently null,
 * per user, keyed by user id. Dates are relative to now().
 */
class DemoUserComplianceSeeder
{
    private const DESIGNATIONS = [
        'admin'          => 'Principal',
        'branch_manager' => 'Branch Manager',
        'agent'          => 'Sales Agent',
        'viewer'         => 'Candidate Agent',
    ];

    /** @return array{updated:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        $users = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'role', 'ffc_number', 'ffc_expiry_date', 'ppra_status', 'ppra_last_verified_at', 'phone', 'cell', 'whatsapp_number', 'designation', 'id_number']);

        if ($users->isEmpty()) {
            return ['updated' => 0, 'note' => 'Skipped — agency has no users.'];
        }

        $updated = 0;
        foreach ($users as $u) {
            $update = [];

            if (empty($u->ffc_number)) {
                $update['ffc_number'] = 'FFC' . str_pad((string) (2026000 + $u->id), 7, '0', STR_PAD_LEFT);
            }
            if (empty($u->ffc_expiry_date)) {
                // Spread expiries across the next 4-14 months so the roster
                // doesn't show one identical date for every agent.
                $update['ffc_expiry_date'] = now()->addMonths(4 + ($u->id % 10))->toDateString();
            }
            if (empty($u->ppra_status)) {
                $update['ppra_status'] = 'active';
            }
            if (empty($u->ppra_last_verified_at)) {
                $update['ppra_last_verified_at'] = now()->subMonths(1 + ($u->id % 6))->toDateString();
            }
            if (empty($u->phone)) {
                $update['phone'] = $this->fakePhone($u->id, '039');
            }
            if (empty($u->cell)) {
                $update['cell'] = $this->fakePhone($u->id, '082');
            }
            if (empty($u->whatsapp_number)) {
                $update['whatsapp_number'] = $update['cell'] ?? $u->cell;
            }
            if (empty($u->designation)) {
                $update['designation'] = self::DESIGNATIONS[$u->role] ?? 'Team Member';
            }
            if (empty($u->id_number)) {
                $update['id_number'] = $this->fakeSaId($u->id);
            }

            if (!empty($update)) {
                $update['updated_at'] = now();
                DB::table('users')->where('id', $u->id)->update($update);
                $updated++;
            }
        }

        $note = "User compliance: {$updated}/{$users->count()} users updated (FFC, PPRA, contact numbers, designation).";

        return ['updated' => $updated, 'note' => $note];
    }

    private function fakePhone(int $seed, string $prefix): string
    {
        return $prefix . ' ' . str_pad((string) (100 + ($seed * 37) % 900), 3, '0', STR_PAD_LEFT)
            . ' ' . str_pad((string) (1000 + ($seed * 91) % 9000), 4, '0', STR_PAD_LEFT);
    }

    private function fakeSaId(int $seed): string
    {
        $year = str_pad((string) ($seed % 100), 2, '0', STR_PAD_LEFT);
        $month = str_pad((string) (1 + ($seed % 12)), 2, '0', STR_PAD_LEFT);
        $day = str_pad((string) (1 + ($seed % 28)), 2, '0', STR_PAD_LEFT);
        return $year . $month . $day . '0000080';
    }
}
