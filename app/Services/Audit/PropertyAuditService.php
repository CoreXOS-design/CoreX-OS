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
    ): PropertyAuditLog {
        $user ??= auth()->user();

        return PropertyAuditLog::create([
            'property_id'    => $property->id,
            'user_id'        => $user?->id,
            'agency_id'      => $property->agency_id ?? $user?->agency_id ?? 1,
            'branch_id'      => $property->branch_id,
            'event_category' => $eventCategory,
            'event_type'     => $eventType,
            'old_values'     => $oldValues,
            'new_values'     => $newValues,
            'metadata'       => $metadata,
            'human_summary'  => $humanSummary ?? $this->defaultSummary($eventType, $oldValues, $newValues),
            'created_at'     => now(),
        ]);
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

    public function logShareAction(Property $property, string $channel, ?User $user = null, ?string $recipientContext = null): PropertyAuditLog
    {
        // Also write to marketing_share_log for backward compat
        \Illuminate\Support\Facades\DB::table('marketing_share_log')->insert([
            'property_id' => $property->id,
            'user_id' => $user?->id ?? auth()->id(),
            'agency_id' => $property->agency_id ?? 1,
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
