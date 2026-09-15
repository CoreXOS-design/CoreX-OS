<?php

namespace App\Services\RentalApplications;

use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\User;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\Log;

/**
 * AT-392 authoriser flow — the rental-application audit writer. Mirrors
 * app/Services/Audit/ContactAuditService.php's log() shape and its use of
 * the shared, pillar-agnostic App\Support\Audit\AuditContext for
 * attribution (never a blank "System" — explicit user, else auth()->user(),
 * else 'unattributed'). Simpler than ContactAuditService: no
 * logFieldChanges() generic diff writer (this module only has a handful of
 * named authorisation events, not free-form field edits) and no DB-trigger
 * backstop (see the migration's own docblock for why that doesn't apply
 * here) — a defensible, deliberate simplification of the mirrored pattern,
 * not a different one.
 */
class RentalApplicationAuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        RentalApplication $application,
        string $eventCategory,
        string $eventType,
        ?User $user = null,
        bool $isOverride = false,
        ?string $reason = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $humanSummary = null,
    ): ?RentalApplicationAuditLog {
        // An audit write must never break the real action (approve/decline/etc
        // already happened by the time this is called) — wrapped, logged on
        // failure, never thrown back at the caller.
        try {
            $actor = AuditContext::resolve($user);

            return RentalApplicationAuditLog::create([
                'rental_application_id' => $application->id,
                'agency_id' => $application->agency_id,
                'branch_id' => $application->branch_id,
                'user_id' => $actor['user_id'],
                'actor_type' => $actor['actor_type'],
                'actor_label' => $actor['actor_label'],
                'source' => $actor['source'],
                'event_category' => $eventCategory,
                'event_type' => $eventType,
                'is_override' => $isOverride,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'metadata' => $metadata,
                'reason' => $reason,
                'human_summary' => $humanSummary,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AT-392 rental application audit write failed', [
                'rental_application_id' => $application->id ?? null,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
