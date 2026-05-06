<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\Contact;
use Illuminate\Support\Facades\Auth;

class ContactObserver
{
    /**
     * When a contact is being created, ensure branch_id is populated.
     * Derives from creator's branch_id; falls back to agency's first branch.
     */
    public function creating(Contact $contact): void
    {
        if (!empty($contact->branch_id)) {
            return;
        }

        $user = Auth::user();

        // Try creator's branch
        if ($user && $user->branch_id) {
            $contact->branch_id = $user->branch_id;
            return;
        }

        // Try effective branch (session override)
        if ($user && method_exists($user, 'effectiveBranchId') && $user->effectiveBranchId()) {
            $contact->branch_id = $user->effectiveBranchId();
            return;
        }

        // Fallback: agency's configured default branch
        $agencyId = $contact->agency_id
            ?? ($user ? ($user->effectiveAgencyId() ?? $user->agency_id) : null);

        if ($agencyId) {
            $agency = \App\Models\Agency::withoutGlobalScopes()->find($agencyId);
            if ($agency && $agency->default_branch_id) {
                $contact->branch_id = $agency->default_branch_id;
            } else {
                // Ultimate fallback: lowest branch in agency
                $defaultBranch = Branch::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->orderBy('id')
                    ->value('id');
                if ($defaultBranch) {
                    $contact->branch_id = $defaultBranch;
                }
            }
        }
    }
}
