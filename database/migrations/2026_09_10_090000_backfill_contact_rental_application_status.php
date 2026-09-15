<?php

use App\Models\Contact;
use App\Models\RentalApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-401 — Johan: "i did approve a rental and look at the contact and it
 * did not show approved?" Root cause: App\Listeners\Contact\
 * RecomputeRentalApplicationStatus (deployed 2026-09-10 04:24:19, commit
 * 9f9124e90) only runs when RentalApplicationApproved/Declined/Submitted/
 * Reopened FIRES — it was never backfilled for applications that had
 * already reached their current status before the listener existed. 43 of
 * 49 contacts with rental-application history were stuck at 'none',
 * including real approved and declined outcomes.
 *
 * This is a ONE-TIME catch-up, not new logic: the exact same derivation
 * the listener already uses, applied once to every contact with rental-
 * application history. Idempotent — re-running finds nothing left to fix
 * and updates zero rows. Never overwrites a contact that already holds the
 * correct value (the WHERE clause below only ever touches a row whose
 * stored value differs from the freshly-derived one — a contact already
 * correct is never written).
 */
return new class extends Migration
{
    public function up(): void
    {
        $corrected = 0;

        DB::transaction(function () use (&$corrected) {
            $contactIds = RentalApplication::withoutGlobalScopes()
                ->whereNotNull('contact_id')
                ->distinct()
                ->pluck('contact_id');

            foreach ($contactIds as $contactId) {
                $contact = Contact::withoutGlobalScopes()->find($contactId);
                if (! $contact) {
                    continue;
                }

                $latest = RentalApplication::withoutGlobalScopes()
                    ->where('contact_id', $contactId)
                    ->orderByDesc('updated_at')
                    ->first();

                if (! $latest) {
                    continue;
                }

                $status = match ($latest->status) {
                    'approved' => 'approved',
                    'declined' => 'declined',
                    'withdrawn' => 'withdrawn',
                    'returned', 'under_assessment', 'reopened', 'in_progress' => 'in_progress',
                    'draft', 'sent' => 'invited',
                    default => 'invited',
                };

                // A contact already holding the correct value is left
                // untouched — never rewritten, never re-timestamped.
                if ($contact->rental_application_status === $status) {
                    continue;
                }

                $contact->rental_application_status = $status;
                $contact->rental_application_status_updated_at = $latest->updated_at ?? now();
                $contact->saveQuietly();
                $corrected++;
            }
        });

        \Log::info("AT-401 rental_application_status backfill: corrected {$corrected} contacts.");
    }

    /**
     * Not reversible to a specific prior state — the prior state for most
     * of these rows was simply wrong ('none' when a real outcome existed),
     * so there is nothing correct to roll back to. A no-op down() is
     * deliberate here, not an oversight: re-running up() again is always
     * safe (idempotent), which is the actual undo path this needs.
     */
    public function down(): void
    {
        //
    }
};
