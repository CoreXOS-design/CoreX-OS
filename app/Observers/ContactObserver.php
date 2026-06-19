<?php

namespace App\Observers;

use App\Events\Contact\ContactCreated;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use Illuminate\Support\Facades\Auth;

class ContactObserver
{
    public function created(Contact $contact): void
    {
        // Domain event — spec .ai/specs/corex-domain-events-spec.md
        event(new ContactCreated($contact, Auth::id()));
    }

    /**
     * When a contact is being created:
     *  - ensure branch_id is populated (creator's branch → agency default → lowest branch)
     *  - auto-link to an existing ClientUser if one exists for this email
     *    (Spec: .ai/specs/client-auth.md — a client already signed up in
     *    another agency keeps a single global identity; new contact rows
     *    join that identity automatically.)
     */
    public function creating(Contact $contact): void
    {
        $this->autoLinkClientUser($contact);

        // Every contact "sits under" an agent. Default the primary agent to the
        // capturer for ALL ingress paths (quick-add, property inline create, etc.)
        // unless one was set explicitly — mirrors the back-catalogue backfill in
        // 2026_06_17_120000_add_agent_assignment_to_contacts_table.
        if (empty($contact->agent_id) && !empty($contact->created_by_user_id)) {
            $contact->agent_id = $contact->created_by_user_id;
        }

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

    private function autoLinkClientUser(Contact $contact): void
    {
        if (!empty($contact->client_user_id) || empty($contact->email)) {
            return;
        }

        $email = strtolower(trim($contact->email));
        if ($email === '') {
            return;
        }

        $existing = ClientUser::where('email', $email)->first();
        if ($existing) {
            $contact->client_user_id = $existing->id;
        }
    }
}
