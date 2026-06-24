<?php

namespace App\Observers;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\AgencyLeaveVisibilityMatrix;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;

class AgencyObserver
{
    /**
     * When a new agency is created, seed default contact governance
     * and leave visibility settings.
     */
    public function created(Agency $agency): void
    {
        // These child records belong to the BRAND-NEW agency ($agency->id).
        // BelongsToAgency's `creating` hook would otherwise OVERRIDE agency_id
        // to the acting admin's effective agency (their switcher session) —
        // withoutGlobalScopes() strips the read scope but not that write hook —
        // producing a cross-agency unique clash (e.g. 1062 on
        // agency_contact_settings_agency_id_unique). Suppress model events for
        // the seed so the explicit agency_id is honoured. This is trusted
        // system ingress, not a user-supplied tenant.
        Model::withoutEvents(function () use ($agency) {
            // Seed contact settings with defaults (new agencies get 'branch' mode)
            AgencyContactSettings::withoutGlobalScopes()->firstOrCreate(
                ['agency_id' => $agency->id],
                [
                    'sharing_mode' => 'branch',
                    'duplicate_mode' => 'soft_warn',
                    'duplicate_match_fields' => ['phone', 'email', 'id_number'],
                    'buyer_warm_days' => 14,
                    'buyer_cold_days' => 30,
                    'buyer_lost_days' => 60,
                    'contact_retention_years' => 5,
                    'consent_retention_years' => 5,
                    'access_log_retention_years' => 5,
                ]
            );

            // Seed leave visibility matrix with defaults
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
        });

        // Set default_branch_id if branches exist for this agency
        if (!$agency->default_branch_id) {
            $firstBranch = Branch::withoutGlobalScopes()
                ->where('agency_id', $agency->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');
            if ($firstBranch) {
                $agency->updateQuietly(['default_branch_id' => $firstBranch]);
            }
        }
    }
}
