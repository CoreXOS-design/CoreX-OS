<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AT-268 — rotate every existing pending-invite password off the publicly-known constant.
 *
 * Before the code fix, invited users were created with password = 'INVITE_PENDING' (hashed by the
 * cast into a valid bcrypt hash of a value in the source, tests and ticket). Any such row is loginable
 * by anyone who types the constant. This scrambles each one to an unusable 72-char random hash.
 *
 * Nothing is lost: these accounts have never had a real password — the invitee sets it via the signed
 * account.setup link, which still works after this. Only rows whose password genuinely IS the constant
 * are touched (verified with Hash::check), so a user who has already accepted is never disturbed.
 *
 * Irreversible by design: down() is a no-op. Restoring a known-constant password would re-open the
 * exact hole this closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only invites can hold the constant; scanning the unverified set keeps the Hash::check cost
        // tiny (bcrypt is deliberately slow). A verified user never carries it.
        $candidates = DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNotNull('password')
            ->select('id', 'password')
            ->get();

        $rotated = 0;

        foreach ($candidates as $row) {
            if (! Hash::check('INVITE_PENDING', $row->password)) {
                continue; // Not the sentinel — leave it strictly alone.
            }

            DB::table('users')->where('id', $row->id)->update([
                'password'   => Hash::make(User::pendingInvitePassword()),
                'updated_at' => now(),
            ]);
            $rotated++;
        }

        Log::warning("AT-268: rotated {$rotated} pending-invite password(s) off the 'INVITE_PENDING' constant.");
        // Surfaced on the migration run so the deploy hand can report the count to Johan.
        if (app()->runningInConsole()) {
            fwrite(STDOUT, "AT-268: rotated {$rotated} pending-invite password(s).\n");
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — the previous value was a known constant and must never return.
    }
};
