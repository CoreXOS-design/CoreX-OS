<?php

use App\Support\SaPhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill — canonicalise every existing user's phone/cell/fax to the
 * digits-only, leading-zero SA format Private Property requires.
 *
 * Numbers stored as "076 901 7397" caused PP107 ("Agent cell phone number was
 * in an incorrect format") on UpdateAgent, so agent profiles were never
 * created on Private Property. From now on the User model mutators keep new
 * writes clean; this migration cleans the data already in the table.
 *
 * Bypasses Eloquent (raw DB) on purpose — running the model mutators per row
 * would fire the agency-admin guard and other booted() hooks. We only want to
 * rewrite the three phone columns. Soft-deleted users are included so their
 * data is consistent if restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select('id', 'phone', 'cell', 'fax')
            ->orderBy('id')
            ->chunkById(500, function ($users) {
                foreach ($users as $u) {
                    $update = [];

                    foreach (['phone', 'cell', 'fax'] as $col) {
                        $current = $u->{$col};
                        $normalized = SaPhoneNumber::normalize($current === null ? null : (string) $current);
                        if ($normalized !== $current) {
                            $update[$col] = $normalized;
                        }
                    }

                    if ($update !== []) {
                        DB::table('users')->where('id', $u->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        // Canonicalisation is not reversible — the original formatting
        // (spaces, "+27", parentheses) is intentionally discarded and not
        // recoverable. No-op.
    }
};
