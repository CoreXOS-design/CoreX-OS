<?php

namespace App\Services\DealV2;

use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealStepEscalation;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Notifications\DealV2\DealStepAlertNotification;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Support\Collection;

/**
 * AT-158 DR2 WS6 — deal-pipeline notifications & escalation.
 *
 * The single owner of "who gets told what, on which channel, and once" for the
 * deal pipeline. Two entry points fire on state the WS0 engine already
 * computes:
 *   • notifyStepRagTransition() — the responsible agent is nudged when a step
 *     crosses into amber / red / overdue (called from deals:process-rag, which
 *     only invokes it on the persisted-RAG edge → naturally once per edge).
 *   • escalateOverdueStep() — the overdue ladder: BM at +N days, admin at +M
 *     days (deals:process-escalations, hourly). Each rung fires exactly once,
 *     enforced by the deal_step_escalations log (an hourly re-run is a no-op).
 * Plus the two engine hooks the WS0 stubs left open: BM-approval-pending and
 * agent-rejection.
 *
 * Nothing is hardcoded: the ladder is config('deals.escalation') with a
 * per-step escalation_config override; recipients derive from the deal; each
 * channel is AND-gated by the recipient's own notification preferences (the
 * DealStepAlertNotification::via() gate, same as the calendar reminder).
 */
class NotificationService
{
    public function __construct(
        private OutboundProvisionalLogger $comms,
    ) {}

    // ---- Entry points ------------------------------------------------------

    /**
     * A step crossed into amber / red / overdue → nudge the responsible agent
     * (and BM if the step opts in). Idempotent per (step, target RAG).
     */
    public function notifyStepRagTransition(DealStepInstance $step, ?string $fromRag, string $toRag): void
    {
        if (! in_array($toRag, ['amber', 'red', 'overdue'], true)) {
            return; // green / grey are not alert-worthy
        }

        $deal = $step->deal;
        if (! $deal) {
            return;
        }

        // RAG transitions nudge the RESPONSIBLE AGENT only — the BM/admin hear via
        // the overdue-escalation ladder (escalateOverdueStep), never here, so a
        // step turning red doesn't double-notify the BM.
        if (! $step->notify_agent) {
            return;
        }
        $recipients = $this->responsibleAgents($deal);

        $verb  = $toRag === 'overdue' ? 'is OVERDUE' : "turned {$toRag}";
        $title = "Deal step {$verb}: {$step->name}";
        $body  = "The step \"{$step->name}\" on {$this->dealRef($deal)} {$verb}"
            . ($step->due_date ? " (due {$step->due_date->format('d M Y')})." : '.');

        $this->fireLevel(
            $step, "rag:{$toRag}", 'rag_transition',
            $recipients->unique('id'),
            $title, $body,
            severity: $toRag === 'overdue' ? 'overdue' : 'warning',
            context: ['from' => $fromRag, 'to' => $toRag],
            commsArchive: false, // agent nudge — not a client/FICA comm
        );
    }

    /**
     * Run the overdue-escalation ladder for one step. Fires every rung now DUE
     * (days_overdue ≥ rung threshold) that has not fired yet. Returns the number
     * of rungs fired this call (0 on a re-run once all due rungs have fired).
     */
    public function escalateOverdueStep(DealStepInstance $step): int
    {
        $deal = $step->deal;
        if (! $deal || $deal->status !== 'active') {
            return 0;
        }
        if ($step->status !== 'overdue' || ! $step->due_date) {
            return 0;
        }

        // Whole days past the due date (>=1 means at least one day overdue).
        $daysOverdue = (int) now()->startOfDay()->diffInDays($step->due_date->copy()->startOfDay(), false);
        $daysOverdue = max(0, -$daysOverdue);
        if ($daysOverdue < 1) {
            return 0;
        }

        $fired = 0;
        foreach ($this->ladderFor($step) as $rung) {
            $role      = $rung['role'] ?? null;
            $threshold = (int) ($rung['days_overdue'] ?? 0);
            if (! $role || $daysOverdue < $threshold) {
                continue;
            }
            if (! $this->roleEnabledForStep($role, $step)) {
                continue; // step silenced this rung (notify_bm / notify_admin = false)
            }

            $levelKey = "escalation:{$role}";
            if ($this->levelHasFired($step, $levelKey)) {
                continue; // already escalated to this rung — no re-send
            }

            $recipients = $this->recipientsForRole($role, $deal);
            if ($recipients->isEmpty()) {
                continue; // no such recipient in the agency — skip (do not record, so it can fire when one exists)
            }

            $roleLabel = str_replace('_', ' ', $role);
            $title = "Escalation: overdue deal step — {$step->name}";
            $body  = "\"{$step->name}\" on {$this->dealRef($deal)} has been overdue for {$daysOverdue} day(s)"
                . " and is being escalated to the {$roleLabel}.";

            $this->fireLevel(
                $step, $levelKey, 'escalation',
                $recipients->unique('id'),
                $title, $body,
                severity: 'overdue',
                context: ['days_overdue' => $daysOverdue, 'role' => $role],
                commsArchive: true, // outbound escalation email → comms archive (WS4/WS5)
            );
            $fired++;
        }

        return $fired;
    }

    /** WS0 stub (DealPipelineService L252) — a step needs BM approval. */
    public function notifyBmApprovalPending(DealStepInstance $step): void
    {
        $deal = $step->deal;
        if (! $deal) {
            return;
        }
        $this->fireLevel(
            $step, "approval_pending:{$step->id}", 'approval',
            $this->recipientsForRole('branch_manager', $deal),
            "Approval needed: {$step->name}",
            "\"{$step->name}\" on {$this->dealRef($deal)} is waiting for your approval.",
            severity: 'warning',
            context: [],
            commsArchive: false,
        );
    }

    /** WS0 stub (DealPipelineService L328) — BM rejected a step; tell the agent. */
    public function notifyAgentStepRejected(DealStepInstance $step, string $reason): void
    {
        $deal = $step->deal;
        if (! $deal) {
            return;
        }
        $this->fireLevel(
            $step, "rejected:{$step->id}", 'rejection',
            $this->responsibleAgents($deal),
            "Step returned: {$step->name}",
            "\"{$step->name}\" on {$this->dealRef($deal)} was sent back: {$reason}",
            severity: 'warning',
            context: ['reason' => $reason],
            commsArchive: false,
        );
    }

    // ---- Firing + idempotency ---------------------------------------------

    /**
     * Notify every recipient not already recorded for this (step, level), record
     * one log row per recipient (idempotency + deal-timeline evidence), and —
     * for outbound-email levels — mirror the email into the comms archive.
     */
    private function fireLevel(
        DealStepInstance $step, string $levelKey, string $kind, Collection $recipients,
        string $title, string $body, string $severity, array $context, bool $commsArchive
    ): void {
        foreach ($recipients->filter()->unique('id') as $user) {
            // Per-recipient idempotency. withoutGlobalScopes: these commands run
            // system-scope (no authenticated agency), so the BelongsToAgency scope
            // must NOT hide a row that already exists for another agency's tenant
            // context — otherwise the exists-check misses it and re-notifies.
            $already = DealStepEscalation::withoutGlobalScopes()
                ->where('deal_step_instance_id', $step->id)
                ->where('level_key', $levelKey)
                ->where('recipient_user_id', $user->id)
                ->exists();
            if ($already) {
                continue;
            }

            $emailAllowed = $this->emailAllowedFor($user);
            $channels = array_values(array_filter(['in_app', $emailAllowed ? 'email' : null]));

            $user->notify(new DealStepAlertNotification(
                step: $step, kind: $kind, title: $title, body: $body,
                severity: $severity, allowEmail: $emailAllowed,
            ));

            if ($commsArchive && $emailAllowed && $user->email) {
                $this->comms->logDistribution(
                    agencyId: (int) $step->agency_id,
                    ownerUserId: $step->deal?->listing_agent_id ?? $step->deal?->created_by_id,
                    recipientEmail: $user->email,
                    subject: $title,
                    body: $body,
                    linkModels: array_values(array_filter([$step->deal])),
                    attachments: [],
                );
            }

            DealStepEscalation::create([
                'agency_id'             => $step->agency_id,
                'deal_id'               => $step->deal_id,
                'deal_step_instance_id' => $step->id,
                'level_key'             => $levelKey,
                'kind'                  => $kind,
                'recipient_user_id'     => $user->id,
                'channels'              => $channels,
                'context'               => $context,
                'notified_at'           => now(),
            ]);
        }

        // Deal-timeline evidence (once per level, only if anyone was notified now).
        if ($this->levelHasFired($step, $levelKey)) {
            DealActivityLog::create([
                'agency_id'             => $step->agency_id,
                'deal_id'               => $step->deal_id,
                'deal_step_instance_id' => $step->id,
                'user_id'               => null,
                'action'                => 'notification',
                'description'           => $title,
                'metadata'              => ['level' => $levelKey, 'kind' => $kind] + $context,
            ]);
        }
    }

    private function levelHasFired(DealStepInstance $step, string $levelKey): bool
    {
        return DealStepEscalation::withoutGlobalScopes()
            ->where('deal_step_instance_id', $step->id)
            ->where('level_key', $levelKey)
            ->exists();
    }

    // ---- Ladder + recipients ----------------------------------------------

    /** The escalation ladder for a step: its template override, else the config default. */
    private function ladderFor(DealStepInstance $step): array
    {
        $override = $step->pipelineStep?->escalation_config['levels'] ?? null;
        $levels = is_array($override) && $override !== []
            ? $override
            : (array) config('deals.escalation.levels', []);

        // Ascending by threshold so rungs fire in order.
        usort($levels, fn ($a, $b) => (int) ($a['days_overdue'] ?? 0) <=> (int) ($b['days_overdue'] ?? 0));

        return $levels;
    }

    private function roleEnabledForStep(string $role, DealStepInstance $step): bool
    {
        return match ($role) {
            'branch_manager' => (bool) $step->notify_bm,
            'admin'          => (bool) $step->notify_admin,
            'agent'          => (bool) $step->notify_agent,
            default          => true,
        };
    }

    private function recipientsForRole(string $role, DealV2 $deal): Collection
    {
        return match ($role) {
            'agent'          => $this->responsibleAgents($deal),
            'branch_manager' => $this->branchManagers($deal),
            'admin'          => $this->agencyAdmins($deal),
            default          => collect(),
        };
    }

    private function responsibleAgents(DealV2 $deal): Collection
    {
        $deal->loadMissing(['listingAgent', 'sellingAgent', 'agents']);

        return collect([$deal->listingAgent, $deal->sellingAgent])
            ->merge($deal->agents ?? collect())
            ->filter()
            ->filter(fn (User $u) => (bool) $u->is_active)
            ->keyBy('id') // dedup by user id (listing agent may also be a pivot agent)
            ->values();
    }

    private function branchManagers(DealV2 $deal): Collection
    {
        if (! $deal->branch_id) {
            return collect();
        }

        return User::withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id)
            ->where('role', 'branch_manager')
            ->where('branch_id', $deal->branch_id)
            ->where('is_active', true)
            ->get();
    }

    private function agencyAdmins(DealV2 $deal): Collection
    {
        return User::withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id)
            ->where('role', 'admin')
            ->where('is_active', true)
            ->get();
    }

    // ---- Small helpers -----------------------------------------------------

    private function emailAllowedFor(User $user): bool
    {
        if (! in_array('email', (array) config('deals.escalation.channels', ['in_app', 'email']), true)) {
            return false;
        }
        $settings = UserDashboardSetting::getEffective($user);

        return (bool) ($settings->notify_email ?? true);
    }

    private function dealRef(DealV2 $deal): string
    {
        return $deal->reference ?: ('Deal #' . $deal->id);
    }
}
