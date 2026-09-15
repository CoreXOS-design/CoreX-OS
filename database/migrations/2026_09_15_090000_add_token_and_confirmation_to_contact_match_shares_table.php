<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AT-Core-Matches, Johan's dated-link ruling — each share mints its OWN
 * resolvable link carrying its generation date, rather than reusing one
 * static permanent link forever. This table already had the right shape
 * (one row per share event, its own shared_at); it was only missing a
 * public token and a way to tell "the agent opened the composer" (a row
 * exists) apart from "the agent actually sent it" (confirmed_at is set).
 *
 * confirmed_at, not channel-is-null, is the pending/sent gate: channel was
 * already nullable for its own reason (e.g. a copy-link share where we
 * don't know which app the agent pasted it into), so overloading it here
 * would collide with a legitimately channel-less CONFIRMED share.
 *
 * Every downstream reader (badge counts, "last shared", the property
 * snapshot diff) must filter on whereNotNull('confirmed_at') — a minted-
 * but-never-sent row must never count as a share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_match_shares', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->after('id');
            $table->timestamp('confirmed_at')->nullable()->after('shared_at');
        });

        // Backfill: every row that already exists was created by the old
        // one-step record() flow, which was a genuine confirmed send —
        // never leave a real historical share silently looking "pending".
        DB::table('contact_match_shares')->whereNull('confirmed_at')->orderBy('id')->each(function ($row) {
            DB::table('contact_match_shares')->where('id', $row->id)->update([
                'token'        => (string) Str::ulid(),
                'confirmed_at' => $row->shared_at,
            ]);
        });

        DB::statement('ALTER TABLE contact_match_shares MODIFY token VARCHAR(64) NOT NULL');
        Schema::table('contact_match_shares', function (Blueprint $table) {
            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::table('contact_match_shares', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'confirmed_at']);
        });
    }
};
