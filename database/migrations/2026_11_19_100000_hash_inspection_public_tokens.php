<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 B22 - the inspection report's public link token (`rental_inspections.public_token`) was stored in plain text, so a
 * database dump or backup held working links to whole reports (photos, signatures) for the life of the link. Same fix the per-party signing
 * links got in 2026_11_16_100000 (A-L4): the link is looked up by the SHA-256 HASH of its token (`public_token_hash`), and a copy encrypted
 * with the application key (`public_token_encrypted`) lets the agent's screen show / print / resend the same link - useless to anyone holding
 * only the database.
 *
 * Every existing row is converted IN PLACE (hash + ciphertext written, the plain token cleared), so a link already emailed or printed keeps
 * working until it expires - nothing is revoked and no expiry changes. Idempotent: the conversion only touches rows that still hold a plain token.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_inspections', 'public_token_hash')) {
            Schema::table('rental_inspections', function (Blueprint $table) {
                $table->char('public_token_hash', 64)->nullable()->unique()->after('public_token');
                $table->text('public_token_encrypted')->nullable()->after('public_token_hash');
            });
        }

        DB::table('rental_inspections')->whereNotNull('public_token')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('rental_inspections')->where('id', $row->id)->update([
                    'public_token_hash' => hash('sha256', $row->public_token),
                    'public_token_encrypted' => Crypt::encryptString($row->public_token),
                    'public_token' => null,
                ]);
            }
        });
    }

    public function down(): void
    {
        // put the plain token back for every row that still has its ciphertext (rows are never deleted to make this fit)
        DB::table('rental_inspections')->whereNotNull('public_token_encrypted')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('rental_inspections')->where('id', $row->id)->update(['public_token' => Crypt::decryptString($row->public_token_encrypted)]);
            }
        });

        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropUnique(['public_token_hash']);
            $table->dropColumn(['public_token_hash', 'public_token_encrypted']);
        });
    }
};
