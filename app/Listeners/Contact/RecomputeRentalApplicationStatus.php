<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\AbstractDomainEvent;
use App\Events\RentalApplication\RentalApplicationApproved;
use App\Events\RentalApplication\RentalApplicationDeclined;
use App\Events\RentalApplication\RentalApplicationReopened;
use App\Events\RentalApplication\RentalApplicationSubmitted;
use App\Models\Contact;
use App\Models\RentalApplication;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Contact::rental_application_status as a read-optimised cache over
 * the real record (the contact's own RentalApplication rows) — never the
 * source of truth itself. Idempotent: recomputes from the contact's current
 * application set every time, so running it twice for the same state is a
 * no-op. A failure here must never break the action that fired the event
 * (BUILD_STANDARD §4) — caught and logged, not rethrown.
 *
 * Spec: .ai/specs/rental-applications.md — Contact status section (§1,
 * "Mechanism — domain events, per non-negotiable #9").
 */
final class RecomputeRentalApplicationStatus
{
    public function handle(AbstractDomainEvent $event): void
    {
        if (! $event instanceof RentalApplicationSubmitted
            && ! $event instanceof RentalApplicationApproved
            && ! $event instanceof RentalApplicationDeclined
            && ! $event instanceof RentalApplicationReopened
        ) {
            return;
        }

        try {
            $contactId = $event->application->contact_id;
            if (! $contactId) {
                return;
            }

            $contact = Contact::withoutGlobalScopes()->find($contactId);
            if (! $contact) {
                return;
            }

            $status = $this->deriveStatus($contactId);

            if ($contact->rental_application_status !== $status) {
                $contact->rental_application_status = $status;
                $contact->rental_application_status_updated_at = now();
                $contact->saveQuietly();
            }
        } catch (\Throwable $e) {
            Log::warning('AT-392 RecomputeRentalApplicationStatus failed', [
                'event' => get_class($event),
                'application_id' => $event->application->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mirrors whichever RentalApplication is most recently active for this
     * contact. "Most recent" is by updated_at, not created_at — a reopened,
     * older application becomes the current one again, exactly as Johan's
     * reopen-after-decline requirement expects.
     */
    private function deriveStatus(int $contactId): string
    {
        $latest = RentalApplication::withoutGlobalScopes()
            ->where('contact_id', $contactId)
            ->orderByDesc('updated_at')
            ->first();

        if (! $latest) {
            return 'none';
        }

        return match ($latest->status) {
            'approved' => 'approved',
            'declined' => 'declined',
            'withdrawn' => 'withdrawn',
            'returned', 'under_assessment', 'reopened', 'in_progress' => 'in_progress',
            'draft', 'sent' => 'invited',
            default => 'invited',
        };
    }
}
