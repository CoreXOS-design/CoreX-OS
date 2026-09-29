<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.2 amendment, 2026-09-25 — a fact the checklist snapshot query
 * alone cannot express: zero RentalApplicationChecklistSection rows means
 * EITHER "never snapshotted" (needs one, lazily, on next view) OR
 * "snapshotted, but the agency's current template had zero non-archived
 * sections at the time" (correctly stays empty forever, per §5 — do not
 * create a snapshot that can never be filled). Both leave the same zero
 * rows behind; only a durable marker on the application itself tells them
 * apart. Nullable timestamp, same null-means-"not yet" convention as every
 * other one-shot marker on this table — set exactly once, inside the same
 * lockForUpdate()-guarded transaction that performs the snapshot (see
 * RentalApplicationChecklistService::ensureSnapshotFor()), so it is also
 * the race-safety claim: two concurrent requests for the same application
 * cannot both see it null and both snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->timestamp('checklist_snapshotted_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('checklist_snapshotted_at');
        });
    }
};
