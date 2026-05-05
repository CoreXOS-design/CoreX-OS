<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\AgencyLeaveVisibilityMatrix;
use Illuminate\Database\Seeder;

class AgencyLeaveVisibilityMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $agencies = Agency::withoutGlobalScopes()->get();

        foreach ($agencies as $agency) {
            foreach (AgencyLeaveVisibilityMatrix::defaultRows() as $row) {
                AgencyLeaveVisibilityMatrix::withoutGlobalScopes()->firstOrCreate(
                    [
                        'agency_id' => $agency->id,
                        'viewing_role' => $row['viewing_role'],
                        'leave_owner_role' => $row['leave_owner_role'],
                        'same_branch_only' => $row['same_branch_only'],
                    ],
                    [
                        'can_see' => $row['can_see'],
                    ]
                );
            }
        }
    }
}
