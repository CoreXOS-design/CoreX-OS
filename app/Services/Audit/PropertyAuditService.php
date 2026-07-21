<?php

namespace App\Services\Audit;

use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\User;

class PropertyAuditService
{
    public function log(
        Property $property,
        string $eventCategory,
        string $eventType,
        ?User $user = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $humanSummary = null,
    ): ?PropertyAuditLog {
        // AT-321 — ROBUSTNESS: an audit write must NEVER break a property save, and
        // a failure must NEVER be silent. Everything below is wrapped; on any throw
        // we log to the property_audit channel and raise PropertyAuditWriteFailed,
        // then return null (no caller uses the return value).
        try {
            // AT-321 — attribution resolves via PropertyAuditContext: explicit user,
            // else auth()->user(), else the source stamped by the job/console/raw
            // site, else 'unattributed' — NEVER a blank contextless "System".
            $actor = \App\Support\Audit\PropertyAuditContext::resolve($user);

            // AT-253 Rule 17 — file under the PROPERTY's tenant, never a hardcoded 1.
            // agency_id is nullable since AT-321: a null-agency row is honest and
            // still visible in the per-property History tab, and is far better than
            // dropping a change entirely (the mandate is: log EVERY change).
            $agencyId = $property->agency_id
                ?? $user?->agency_id
                ?? auth()->user()?->agency_id;

            return PropertyAuditLog::create([
                'property_id'    => $property->id,
                'user_id'        => $actor['user_id'],
                'actor_type'     => $actor['actor_type'],
                'actor_label'    => $actor['actor_label'],
                'source'         => $actor['source'],
                'agency_id'      => $agencyId,
                'branch_id'      => $property->branch_id,
                'event_category' => $eventCategory,
                'event_type'     => $eventType,
                'old_values'     => $oldValues,
                'new_values'     => $newValues,
                'metadata'       => $metadata,
                'human_summary'  => $humanSummary ?? $this->defaultSummary($eventType, $oldValues, $newValues),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($property->id ?? 0, $eventType, $e);

            return null;
        }
    }

    /**
     * AT-321 — generic dirty-field diff writer. One consolidated 'property_updated'
     * row carrying every changed (non-excluded) column as old->new. Called by the
     * observer for all fields that don't have a dedicated rich event.
     *
     * @param array<string, mixed> $old  column => original value
     * @param array<string, mixed> $new  column => new value
     */
    public function logFieldChanges(Property $property, array $old, array $new, ?User $user = null): ?PropertyAuditLog
    {
        if (empty($new)) {
            return null;
        }

        $fields = array_keys($new);
        $labels = implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $fields));

        return $this->log(
            $property,
            'property',
            'property_updated',
            $user,
            oldValues: $old,
            newValues: $new,
            metadata: ['fields' => $fields],
            humanSummary: 'Updated ' . $labels,
        );
    }

    /**
     * AT-321 — a swallowed audit failure is recorded and surfaced, never lost.
     * Plain event + dedicated channel; both are best-effort so even THIS cannot
     * throw out of the save path.
     */
    private function reportFailure(int $propertyId, string $eventType, \Throwable $e): void
    {
        try {
            \Log::channel('property_audit')->error('AT-321 property audit write failed', [
                'property_id' => $propertyId,
                'event'       => $eventType,
                'error'       => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // channel may be unconfigured in some contexts — fall back to default.
            try {
                \Log::error("AT-321 property audit write failed (property #{$propertyId}, {$eventType}): {$e->getMessage()}");
            } catch (\Throwable) {
                // give up quietly — we are already the last line of defence.
            }
        }

        try {
            event(new \App\Events\Property\PropertyAuditWriteFailed($propertyId, $eventType, $e->getMessage()));
        } catch (\Throwable) {
            // never let failure-reporting break the save.
        }
    }

    public function logPriceChange(Property $property, $oldPrice, $newPrice, ?User $user = null): PropertyAuditLog
    {
        return $this->log($property, 'property', 'price_changed', $user,
            ['price' => $oldPrice], ['price' => $newPrice],
            humanSummary: 'Price changed from R ' . number_format((int) $oldPrice) . ' to R ' . number_format((int) $newPrice),
        );
    }

    public function logStatusChange(Property $property, $oldStatus, $newStatus, ?User $user = null): PropertyAuditLog
    {
        return $this->log($property, 'property', 'status_changed', $user,
            ['status' => $oldStatus], ['status' => $newStatus],
            humanSummary: 'Status changed from ' . ucfirst($oldStatus ?: 'none') . ' to ' . ucfirst($newStatus),
        );
    }

    public function logSyndication(Property $property, string $portal, string $action, ?User $user = null, ?array $metadata = null): PropertyAuditLog
    {
        $type = $portal . '_syndication_' . $action;
        return $this->log($property, 'syndication', $type, $user,
            metadata: $metadata,
            humanSummary: ucfirst($portal) . ' syndication: ' . str_replace('_', ' ', $action),
        );
    }

    public function logComplianceSnapshot(Property $property, ?User $user = null, ?array $snapshotData = null): PropertyAuditLog
    {
        return $this->log($property, 'compliance', 'compliance_snapshot_taken', $user,
            metadata: $snapshotData ? ['snapshot_keys' => array_keys($snapshotData)] : null,
            humanSummary: 'Compliance snapshot taken — property is now marketing-ready',
        );
    }

    public function logShareAction(Property $property, string $channel, ?User $user = null, ?string $recipientContext = null): ?PropertyAuditLog
    {
        // AT-253 Rule 17 — same rule: no tenant on the property, no share row anywhere.
        if (! $property->agency_id) {
            \Log::warning('AT-253 property share-log skipped: property has no agency', [
                'property_id' => $property->id, 'channel' => $channel,
            ]);

            return null;
        }

        // Also write to marketing_share_log for backward compat
        \Illuminate\Support\Facades\DB::table('marketing_share_log')->insert([
            'property_id' => $property->id,
            'user_id' => $user?->id ?? auth()->id(),
            'agency_id' => $property->agency_id,   // AT-253 Rule 17

            'channel' => $channel,
            'recipient_context' => $recipientContext,
            'created_at' => now(),
        ]);

        return $this->log($property, 'marketing', $channel . '_share', $user,
            metadata: ['channel' => $channel, 'recipient_context' => $recipientContext],
            humanSummary: 'Shared via ' . str_replace('_', ' ', $channel),
        );
    }

    public function logMediaChange(Property $property, string $action, ?User $user = null, ?int $fileCount = null): PropertyAuditLog
    {
        return $this->log($property, 'media', 'photo_' . $action, $user,
            metadata: $fileCount ? ['file_count' => $fileCount] : null,
            humanSummary: $fileCount ? $fileCount . ' photo(s) ' . $action : 'Photos ' . $action,
        );
    }

    public function logDocumentEvent(Property $property, string $documentType, string $action, ?User $user = null, ?int $docId = null): PropertyAuditLog
    {
        return $this->log($property, 'document', $documentType . '_' . $action, $user,
            metadata: $docId ? ['document_id' => $docId] : null,
            humanSummary: ucfirst(str_replace('_', ' ', $documentType)) . ' ' . $action,
        );
    }

    private function defaultSummary(string $eventType, ?array $old, ?array $new): string
    {
        return ucfirst(str_replace('_', ' ', $eventType));
    }
}
