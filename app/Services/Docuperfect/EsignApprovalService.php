<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Events\Esign\ComplianceApprovalDecided;
use App\Events\Esign\ComplianceApprovalRequested;
use App\Models\Compliance\OfficerAppointment;
use App\Models\Docuperfect\EsignApproval;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Compliance\OfficerRegistry;
use App\Services\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The compliance approval gate for e-sign documents.
 *
 * Spec: .ai/specs/esign-compliance-approval-gate.md §6.
 *
 *  - gateApplies()  — agency route is ro_co AND the document is not a candidate flow (candidate
 *                     documents are gated by the supervisor co-signature instead, §6.5).
 *  - hold()         — called by SignatureService at the two dispatch points; flips the ceremony
 *                     to approval_pending, writes the ledger + audit, emits the event.
 *  - approve()/decline()/override()/resubmit() — the officer / sender actions.
 *
 * SignatureService is resolved lazily (app()) to avoid a constructor cycle.
 */
class EsignApprovalService
{
    public const SCOPE_MODULE = 'esign_approvals';

    public function __construct(private OfficerRegistry $registry) {}

    // ── Gate ──

    public function gateApplies(SignatureTemplate $template): bool
    {
        $agencyId = (int) ($template->agency_id ?: 0);
        if ($agencyId <= 0) {
            return false;
        }
        if (! $this->registry->esignRouteIsRoCo($agencyId)) {
            return false;
        }

        return ! $this->signatureService()->chainHasAuthoriser($template);
    }

    /**
     * Hold the ceremony before it leaves the agency. Runs inside the caller's transaction.
     */
    public function hold(SignatureTemplate $template, string $completedParty): EsignApproval
    {
        $template->update([
            'status'        => SignatureTemplate::STATUS_APPROVAL_PENDING,
            'document_hash' => $this->signatureService()->generateDocumentHash($template->document),
        ]);

        $approval = EsignApproval::create([
            'agency_id'             => (int) $template->agency_id,
            'branch_id'             => $template->document?->branch_id ?? $template->creator?->branch_id,
            'signature_template_id' => $template->id,
            'document_id'           => $template->document_id,
            'requested_by_user_id'  => $template->created_by,
            'status'                => EsignApproval::STATUS_PENDING,
        ]);

        SignatureAuditLog::log(
            $template,
            'compliance_approval_requested',
            SignatureAuditLog::ACTOR_SYSTEM,
            'System',
            metadata: [
                'completed_party' => $completedParty,
                'approval_id'     => $approval->id,
                'route'           => OfficerRegistry::ESIGN_ROUTE_RO_CO,
            ],
        );

        $this->raiseAfterCommit(new ComplianceApprovalRequested($approval, $template, $template->created_by));

        return $approval;
    }

    /**
     * Officers are told only once the hold / decision is durable. Inside a transaction the event
     * waits for the commit (a rollback then tells nobody); outside one it fires immediately.
     */
    private function raiseAfterCommit(object $event): void
    {
        DB::afterCommit(static fn () => event($event));
    }

    /** A decision is only valid while the document itself is still in the state the officer saw. */
    private function assertDocumentStillHeld(SignatureTemplate $template, string $expected): void
    {
        if ($template->status === $expected) {
            return;
        }

        $message = match ($template->status) {
            SignatureTemplate::STATUS_CANCELLED => 'The sender cancelled this document, so there is nothing left to decide.',
            SignatureTemplate::STATUS_APPROVAL_DECLINED => 'This document has already been declined.',
            default => 'This document is no longer waiting for approval — it has moved on since this page was opened.',
        };

        throw ValidationException::withMessages(['approval' => $message]);
    }

    // ── Decisions ──

    public function approve(SignatureTemplate $template, User $officer, ?string $note = null): EsignApproval
    {
        $template->refresh();
        $approval = $this->pendingFor($template);

        if (! $approval) {
            // Idempotent: a second click on a stale form lands here.
            if ($template->status !== SignatureTemplate::STATUS_APPROVAL_PENDING) {
                $latest = $this->latestFor($template);
                if ($latest && $latest->status === EsignApproval::STATUS_APPROVED) {
                    return $latest;
                }
            }
            throw ValidationException::withMessages(['approval' => 'This document is not waiting for approval.']);
        }

        // The ledger row AND the document must both still be waiting — a stale form on a document
        // the sender has since cancelled must never approve (or resurrect) it.
        $this->assertDocumentStillHeld($template, SignatureTemplate::STATUS_APPROVAL_PENDING);

        // Outside the transaction on purpose: a refused attempt writes its audit row and that row
        // must survive the refusal (inside the transaction it would roll back with it).
        $this->assertOfficerMayDecide($template, $approval, $officer, allowSelfIfCo: true);

        return DB::transaction(function () use ($template, $approval, $officer, $note) {
            $approval->update([
                'status'             => EsignApproval::STATUS_APPROVED,
                'decided_by_user_id' => $officer->id,
                'decided_at'         => now(),
                'decision_note'      => $note ?: null,
                'is_override'        => false,
            ]);

            SignatureAuditLog::log(
                $template,
                'compliance_approved',
                SignatureAuditLog::ACTOR_USER,
                $officer->name,
                $officer->email,
                $officer->id,
                metadata: ['approval_id' => $approval->id, 'note' => $note],
            );

            $this->signatureService()->releaseAfterComplianceApproval($template);

            $this->raiseAfterCommit(new ComplianceApprovalDecided($approval, $template, $officer->id));

            return $approval->fresh();
        });
    }

    /**
     * The sender cancelled a held or declined document: close every open ledger row so no officer
     * can act on it again, and put that on the record. Safe to call for any document — a document
     * with no open row is a no-op. Runs inside the caller's (cancel) transaction.
     */
    public function withdraw(SignatureTemplate $template, User $by, string $reason = ''): int
    {
        $open = EsignApproval::withoutGlobalScopes()
            ->where('signature_template_id', $template->id)
            ->whereIn('status', EsignApproval::OPEN_STATUSES)
            ->get();

        if ($open->isEmpty()) {
            return 0;
        }

        foreach ($open as $row) {
            $row->update(['status' => EsignApproval::STATUS_WITHDRAWN]);
        }

        SignatureAuditLog::log(
            $template,
            'compliance_approval_withdrawn',
            SignatureAuditLog::ACTOR_USER,
            $by->name,
            $by->email,
            $by->id,
            metadata: ['approval_ids' => $open->pluck('id')->all(), 'reason' => $reason],
        );

        return $open->count();
    }

    public function decline(SignatureTemplate $template, User $officer, string $reason): EsignApproval
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give the sender a reason (at least a few words) so they know what to fix.']);
        }

        $template->refresh();
        $approval = $this->pendingFor($template);
        if (! $approval) {
            throw ValidationException::withMessages(['approval' => 'This document is not waiting for approval.']);
        }

        $this->assertDocumentStillHeld($template, SignatureTemplate::STATUS_APPROVAL_PENDING);

        // Outside the transaction — see approve().
        $this->assertOfficerMayDecide($template, $approval, $officer, allowSelfIfCo: true);

        return DB::transaction(function () use ($template, $approval, $officer, $reason) {
            $approval->update([
                'status'             => EsignApproval::STATUS_DECLINED,
                'decided_by_user_id' => $officer->id,
                'decided_at'         => now(),
                'decision_note'      => $reason,
            ]);

            $template->update(['status' => SignatureTemplate::STATUS_APPROVAL_DECLINED]);

            SignatureAuditLog::log(
                $template,
                'compliance_declined',
                SignatureAuditLog::ACTOR_USER,
                $officer->name,
                $officer->email,
                $officer->id,
                metadata: ['approval_id' => $approval->id, 'reason' => $reason],
            );

            $this->raiseAfterCommit(new ComplianceApprovalDecided($approval, $template, $officer->id));

            return $approval->fresh();
        });
    }

    /** CO overrides a declined document (ruling 6 — "the CO overrides all"). Reason required. */
    public function override(SignatureTemplate $template, User $co, string $reason): EsignApproval
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'An override needs a reason on the record.']);
        }

        return DB::transaction(function () use ($template, $co, $reason) {
            $template->refresh();
            if ($template->status !== SignatureTemplate::STATUS_APPROVAL_DECLINED) {
                throw ValidationException::withMessages(['approval' => 'Only a declined document can be overridden.']);
            }
            $agencyId = (int) $template->agency_id;
            if (! $this->registry->isCo($co, OfficerAppointment::MODULE_ESIGN, $agencyId)) {
                throw new HttpException(403, 'Only the e-sign Compliance Officer can override a decline.');
            }
            $declined = $this->latestFor($template);
            $this->assertInScope($co, $declined);

            // The decline is history the moment the override lands.
            if ($declined && $declined->status === EsignApproval::STATUS_DECLINED) {
                $declined->update(['status' => EsignApproval::STATUS_SUPERSEDED]);
            }

            $approval = EsignApproval::create([
                'agency_id'             => $agencyId,
                'branch_id'             => $template->document?->branch_id,
                'signature_template_id' => $template->id,
                'document_id'           => $template->document_id,
                'requested_by_user_id'  => $template->created_by,
                'status'                => EsignApproval::STATUS_APPROVED,
                'decided_by_user_id'    => $co->id,
                'decided_at'            => now(),
                'decision_note'         => $reason,
                'is_override'           => true,
            ]);

            SignatureAuditLog::log(
                $template,
                'compliance_override_approved',
                SignatureAuditLog::ACTOR_USER,
                $co->name,
                $co->email,
                $co->id,
                metadata: ['approval_id' => $approval->id, 'reason' => $reason],
            );

            $this->signatureService()->releaseAfterComplianceApproval($template);

            $this->raiseAfterCommit(new ComplianceApprovalDecided($approval, $template, $co->id));

            return $approval;
        });
    }

    /** The sender asks again after a decline (new ledger row, officers notified again). */
    public function resubmit(SignatureTemplate $template, User $sender): EsignApproval
    {
        return DB::transaction(function () use ($template, $sender) {
            $template->refresh();
            if ($template->status !== SignatureTemplate::STATUS_APPROVAL_DECLINED) {
                throw ValidationException::withMessages(['approval' => 'Only a declined document can be sent for approval again.']);
            }
            if ((int) $template->created_by !== (int) $sender->id) {
                throw new HttpException(403, 'Only the person who sent this document can ask for approval again.');
            }

            // The decline stays on the record but is no longer the document's state.
            EsignApproval::withoutGlobalScopes()
                ->where('signature_template_id', $template->id)
                ->where('status', EsignApproval::STATUS_DECLINED)
                ->update(['status' => EsignApproval::STATUS_SUPERSEDED]);

            $approval = $this->hold($template, 'agent');

            SignatureAuditLog::log(
                $template,
                'compliance_approval_resubmitted',
                SignatureAuditLog::ACTOR_USER,
                $sender->name,
                $sender->email,
                $sender->id,
                metadata: ['approval_id' => $approval->id],
            );

            return $approval;
        });
    }

    // ── Queries for queues / badges ──

    public function scopeFor(User $user): ?string
    {
        if ($user->isOwnerRole()) {
            return 'all';
        }

        return PermissionService::getDataScope($user, self::SCOPE_MODULE);
    }

    /**
     * Documents waiting on (or declined for) officers, inside the viewer's scope.
     *
     * A ledger row is only listed while the DOCUMENT is still in the matching state: a declined
     * row whose sender has since resubmitted (document back to approval_pending — a new pending
     * row exists) or cancelled is history, not work. Without this the Declined tab kept showing
     * superseded declines with an "Override & send" button that could only ever be refused.
     */
    public function queueQuery(User $user, array $statuses = [EsignApproval::STATUS_PENDING])
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        $liveTemplateStatuses = array_values(array_filter(array_map(
            static fn (string $status): ?string => match ($status) {
                EsignApproval::STATUS_PENDING  => SignatureTemplate::STATUS_APPROVAL_PENDING,
                EsignApproval::STATUS_DECLINED => SignatureTemplate::STATUS_APPROVAL_DECLINED,
                default                        => null,
            },
            $statuses,
        )));

        return EsignApproval::query()
            ->withoutGlobalScopes()
            ->whereNull('esign_approvals.deleted_at')
            ->where('agency_id', $agencyId)
            ->whereIn('status', $statuses)
            // Only the newest ledger row of a document is its current state: decline → ask again →
            // decline again leaves two declined rows, and the queue must show the document once.
            ->whereNotExists(function ($newer) {
                $newer->select(DB::raw(1))
                    ->from('esign_approvals as newer')
                    ->whereColumn('newer.signature_template_id', 'esign_approvals.signature_template_id')
                    ->whereColumn('newer.id', '>', 'esign_approvals.id')
                    ->whereNull('newer.deleted_at');
            })
            ->when($liveTemplateStatuses !== [], fn ($q) => $q->whereHas(
                'signatureTemplate',
                fn ($t) => $t->withoutGlobalScopes()->whereIn('status', $liveTemplateStatuses),
            ))
            ->visibleTo($user, $this->scopeFor($user))
            ->with(['signatureTemplate.document.template', 'signatureTemplate.requests', 'requester'])
            ->orderBy('created_at');
    }

    public function pendingCountFor(User $user): int
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0 || ! $this->registry->isOfficer($user, OfficerAppointment::MODULE_ESIGN, $agencyId)) {
            return 0;
        }

        return $this->queueQuery($user)->count();
    }

    /** True when this officer may act on this document (scope + appointment). */
    public function canAct(User $user, EsignApproval $approval): bool
    {
        $agencyId = (int) $approval->agency_id;
        if (! $this->registry->isOfficer($user, OfficerAppointment::MODULE_ESIGN, $agencyId)) {
            return false;
        }

        return EsignApproval::query()->withoutGlobalScopes()
            ->whereKey($approval->id)
            ->visibleTo($user, $this->scopeFor($user))
            ->exists();
    }

    // ── Internals ──

    private function pendingFor(SignatureTemplate $template): ?EsignApproval
    {
        return EsignApproval::withoutGlobalScopes()
            ->where('signature_template_id', $template->id)
            ->pending()
            ->latest('id')
            ->first();
    }

    private function latestFor(SignatureTemplate $template): ?EsignApproval
    {
        return EsignApproval::withoutGlobalScopes()
            ->where('signature_template_id', $template->id)
            ->latest('id')
            ->first();
    }

    private function assertOfficerMayDecide(SignatureTemplate $template, EsignApproval $approval, User $officer, bool $allowSelfIfCo): void
    {
        $agencyId = (int) $template->agency_id;

        if (! $this->registry->isOfficer($officer, OfficerAppointment::MODULE_ESIGN, $agencyId)) {
            throw new HttpException(403, 'Only an appointed e-sign Reporting Officer or Compliance Officer can decide this document.');
        }

        // Self-approval — the sender may not approve their own document unless they are the CO
        // (FICA's primary-officer rule). The attempt itself goes on the record.
        $isSelf = (int) $approval->requested_by_user_id === (int) $officer->id;
        if ($isSelf && ! ($allowSelfIfCo && $this->registry->isCo($officer, OfficerAppointment::MODULE_ESIGN, $agencyId))) {
            SignatureAuditLog::log(
                $template,
                'compliance_self_approval_blocked',
                SignatureAuditLog::ACTOR_USER,
                $officer->name,
                $officer->email,
                $officer->id,
                metadata: ['approval_id' => $approval->id],
            );
            throw new HttpException(403, 'You sent this document, so another officer has to approve it. Only the Compliance Officer may approve their own.');
        }

        $this->assertInScope($officer, $approval);
    }

    private function assertInScope(User $officer, ?EsignApproval $approval): void
    {
        if (! $approval) {
            return;
        }
        $inScope = EsignApproval::query()->withoutGlobalScopes()
            ->whereKey($approval->id)
            ->visibleTo($officer, $this->scopeFor($officer))
            ->exists();
        if (! $inScope) {
            throw new HttpException(403, 'This document is outside the branch or agency you may act on.');
        }
    }

    private function signatureService(): SignatureService
    {
        return app(SignatureService::class);
    }
}
