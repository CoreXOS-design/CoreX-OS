<?php

namespace App\Observers;

use App\Events\Contact\ContactCreated;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ContactObserver
{
    /** Static registry for pre-save originals (keyed by contact ID). */
    private static array $auditOriginals = [];

    /**
     * AT-321-C — NOISE columns excluded from the audit trail everywhere: pure
     * timestamps, counters, provenance/derived stamps. (Approved exclusion list,
     * spec §3.1.) Everything NOT in this list is "meaningful" and is logged by the
     * generic diff.
     */
    private const AUDIT_NOISE_COLUMNS = [
        'updated_at', 'created_at', 'deleted_at', 'loaded_at', 'modified_at',
        'last_contacted_at', 'last_activity_at', 'last_consent_check_at',
        'whatsapp_count', 'email_count',
        'id_number_captured_at', 'buyer_pipeline_entered_at',
        'outreach_permission_asked_at', 'messaging_opt_out_at', 'messaging_opted_in_at',
    ];

    /**
     * AT-321-C — columns that get their OWN rich, human-readable event below. They
     * are still captured but excluded from the generic consolidated 'contact_updated'
     * row so they are never double-logged.
     */
    private const AUDIT_DEDICATED_COLUMNS = ['agent_id'];

    /** A column is captured/audited unless it is pure noise. */
    private static function isAuditableColumn(string $column): bool
    {
        return !in_array($column, self::AUDIT_NOISE_COLUMNS, true);
    }

    public function created(Contact $contact): void
    {
        // Domain event — spec .ai/specs/corex-domain-events-spec.md
        event(new ContactCreated($contact, Auth::id()));

        // AT-321-C — audit: contact created (attributed).
        try {
            app(\App\Services\Audit\ContactAuditService::class)->logContactCreated($contact);
        } catch (\Throwable $e) {
            Log::warning("Audit log failed on contact create #{$contact->id}: {$e->getMessage()}");
        }
    }

    /**
     * AT-321-C — capture originals for EVERY auditable dirty column before the write
     * (generic diff; the diff is emitted in saved()). Also suppress the unbypassable
     * DB trigger for THIS Eloquent write — the app layer records it richly — to
     * avoid a duplicate backstop row; the suppression is released at the top of
     * saved(). Fires before BOTH insert and update; noise columns are skipped.
     */
    public function saving(Contact $contact): void
    {
        AuditContext::markHandled();

        if ($contact->exists && !$contact->wasRecentlyCreated) {
            $captured = [];
            foreach (array_keys($contact->getDirty()) as $col) {
                if (self::isAuditableColumn($col)) {
                    $captured[$col] = $contact->getOriginal($col);
                }
            }
            if (!empty($captured)) {
                self::$auditOriginals[$contact->id] = $captured;
            }
        }
    }

    /**
     * AT-125 reverse mirror-sync — ensure any contact carrying a legacy single
     * contacts.phone/email (importers, mobile API, e-sign signer, etc.) has a
     * matching PRIMARY child identifier row, so the child tables are the complete
     * source of truth for the resolvers. Idempotent (no-op when a row exists).
     * The explicit multi-identifier form/API path manages child rows directly via
     * ContactIdentifierService::syncIdentifiers and leaves the mirror empty here,
     * so this never double-writes. Quiet internal writes → no observer recursion.
     */
    public function saved(Contact $contact): void
    {
        // AT-321-C — release the trigger suppression set in saving() now that the
        // Eloquent UPDATE/INSERT (and its trigger evaluation) has passed. Any
        // subsequent quiet/raw write on this connection is then caught by the trigger.
        AuditContext::clearHandled();

        app(\App\Services\Contacts\ContactIdentifierService::class)->ensureMirrorHasChildRows($contact);

        // AT-321-C — audit the change. Existing records only (creation is covered by
        // created()). Dedicated agent_assigned event + ONE consolidated
        // contact_updated row for every other changed, non-noise column. Never lets
        // an audit failure break the save (BUILD_STANDARD §3).
        $pre = self::$auditOriginals[$contact->id] ?? [];
        unset(self::$auditOriginals[$contact->id]);

        if (empty($pre)) {
            return;
        }

        try {
            $auditSvc = app(\App\Services\Audit\ContactAuditService::class);
            $changes  = $contact->getChanges();

            // Dedicated: agent reassignment (the #3492-analog).
            if (array_key_exists('agent_id', $changes) && array_key_exists('agent_id', $pre)
                && $pre['agent_id'] !== $changes['agent_id']) {
                $auditSvc->logAgentAssignment($contact, $pre['agent_id'], $changes['agent_id']);
            }

            // Generic: every OTHER changed (non-noise, non-dedicated) column as ONE
            // consolidated contact_updated row, old->new.
            $genericOld = [];
            $genericNew = [];
            foreach ($changes as $col => $newVal) {
                if (!self::isAuditableColumn($col)
                    || in_array($col, self::AUDIT_DEDICATED_COLUMNS, true)
                    || !array_key_exists($col, $pre)) {
                    continue;
                }
                $genericOld[$col] = $pre[$col];
                $genericNew[$col] = $newVal;
            }
            if (!empty($genericNew)) {
                $auditSvc->logFieldChanges($contact, $genericOld, $genericNew);
            }
        } catch (\Throwable $e) {
            Log::warning("Audit log failed on contact save #{$contact->id}: {$e->getMessage()}");
            try {
                event(new \App\Events\Audit\AuditWriteFailed('contact', (int) $contact->id, 'observer', $e->getMessage()));
            } catch (\Throwable) {
                // never let audit reporting break the save
            }
        }
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
