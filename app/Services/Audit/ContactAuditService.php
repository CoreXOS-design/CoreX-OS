<?php

namespace App\Services\Audit;

use App\Models\Contact;
use App\Models\ContactAuditLog;
use App\Models\User;
use App\Support\Audit\AuditContext;

/**
 * AT-321-C — the contact audit writer. Mirror of PropertyAuditService, sharing the
 * pillar-agnostic AuditContext for attribution and the generic AuditWriteFailed
 * event + contact_audit channel for robustness.
 */
class ContactAuditService
{
    public function log(
        Contact $contact,
        string $eventCategory,
        string $eventType,
        ?User $user = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $humanSummary = null,
    ): ?ContactAuditLog {
        // AT-321-C — ROBUSTNESS: an audit write must NEVER break a contact save, and
        // a failure must NEVER be silent. Everything below is wrapped; on any throw
        // we log to the contact_audit channel and raise AuditWriteFailed, then
        // return null (no caller uses the return value).
        try {
            // Attribution resolves via the shared AuditContext: explicit user, else
            // auth()->user(), else the source stamped by the job/console/raw site,
            // else 'unattributed' — NEVER a blank contextless "System".
            $actor = AuditContext::resolve($user);

            // Rule 17 — file under the CONTACT's tenant, never a hardcoded 1.
            // agency_id is nullable: a null-agency row is honest and still visible in
            // the per-contact History tab, far better than dropping a change.
            $agencyId = $contact->agency_id
                ?? $user?->agency_id
                ?? auth()->user()?->agency_id;

            return ContactAuditLog::create([
                'contact_id'     => $contact->id,
                'user_id'        => $actor['user_id'],
                'actor_type'     => $actor['actor_type'],
                'actor_label'    => $actor['actor_label'],
                'source'         => $actor['source'],
                'agency_id'      => $agencyId,
                'branch_id'      => $contact->branch_id,
                'event_category' => $eventCategory,
                'event_type'     => $eventType,
                'old_values'     => $oldValues,
                'new_values'     => $newValues,
                'metadata'       => $metadata,
                'human_summary'  => $humanSummary ?? $this->defaultSummary($eventType),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($contact->id ?? 0, $eventType, $e);

            return null;
        }
    }

    /**
     * AT-321-C — generic dirty-field diff writer. One consolidated 'contact_updated'
     * row carrying every changed (non-excluded) column as old->new. Called by the
     * observer for all fields that don't have a dedicated rich event.
     *
     * @param array<string, mixed> $old  column => original value
     * @param array<string, mixed> $new  column => new value
     */
    public function logFieldChanges(Contact $contact, array $old, array $new, ?User $user = null): ?ContactAuditLog
    {
        if (empty($new)) {
            return null;
        }

        $fields = array_keys($new);
        $labels = implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $fields));

        return $this->log(
            $contact,
            'contact',
            'contact_updated',
            $user,
            oldValues: $old,
            newValues: $new,
            metadata: ['fields' => $fields],
            humanSummary: 'Updated ' . $labels,
        );
    }

    /** Dedicated rich event for an agent reassignment (the #3492-analog). */
    public function logAgentAssignment(Contact $contact, $oldAgentId, $newAgentId, ?User $user = null): ?ContactAuditLog
    {
        return $this->log($contact, 'contact', 'agent_assigned', $user,
            ['agent_id' => $oldAgentId], ['agent_id' => $newAgentId],
            humanSummary: 'Contact agent reassigned'
                . ($oldAgentId ? ' from #' . $oldAgentId : '')
                . ($newAgentId ? ' to #' . $newAgentId : ''),
        );
    }

    public function logContactCreated(Contact $contact, ?User $user = null): ?ContactAuditLog
    {
        $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'Unnamed';

        return $this->log($contact, 'contact', 'contact_created', $user,
            humanSummary: 'Contact created: ' . $name,
        );
    }

    /**
     * AT-321-C — a swallowed audit failure is recorded and surfaced, never lost.
     * Both paths are best-effort so even THIS cannot throw out of the save path.
     */
    private function reportFailure(int $contactId, string $eventType, \Throwable $e): void
    {
        try {
            \Log::channel('contact_audit')->error('AT-321-C contact audit write failed', [
                'contact_id' => $contactId,
                'event'      => $eventType,
                'error'      => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            try {
                \Log::error("AT-321-C contact audit write failed (contact #{$contactId}, {$eventType}): {$e->getMessage()}");
            } catch (\Throwable) {
                // give up quietly — we are already the last line of defence.
            }
        }

        try {
            event(new \App\Events\Audit\AuditWriteFailed('contact', $contactId, $eventType, $e->getMessage()));
        } catch (\Throwable) {
            // never let failure-reporting break the save.
        }
    }

    private function defaultSummary(string $eventType): string
    {
        return ucfirst(str_replace('_', ' ', $eventType));
    }
}
