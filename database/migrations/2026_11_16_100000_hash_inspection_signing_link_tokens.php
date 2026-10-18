<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-18 A-L4 - inspection signing-link tokens were stored in plain text: anyone who could read the database or a backup held
 * working sign / decline links. A link is now looked up by the SHA-256 HASH of its token (`token_hash`, like the other secure links). The
 * agent's screen still has to show the same link again (copy / QR / resend), so the token is also kept ENCRYPTED with the application key
 * (`token_encrypted`) - useless to anyone holding only the database or a backup.
 *
 * Every existing row is converted IN PLACE here (hash + ciphertext written, the plain `token` cleared), so a link already emailed or
 * printed keeps working until it expires - nothing is revoked and no expiry changes. `token` becomes nullable (new rows never write it).
 */
return new class extends Migration
{
    public function up(): void
    {
        // idempotent: the columns are added once, the conversion below only touches rows that still hold a plain token
        if (! Schema::hasColumn('rental_inspection_signing_links', 'token_hash')) {
            Schema::table('rental_inspection_signing_links', function (Blueprint $table) {
                $table->char('token_hash', 64)->nullable()->unique()->after('token');
                $table->text('token_encrypted')->nullable()->after('token_hash');
                $table->string('token', 64)->nullable()->change();
            });
        }

        DB::table('rental_inspection_signing_links')->whereNotNull('token')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('rental_inspection_signing_links')->where('id', $row->id)->update([
                    'token_hash' => hash('sha256', $row->token),
                    'token_encrypted' => Crypt::encryptString($row->token),
                    'token' => null,
                ]);
            }
        });
    }

    public function down(): void
    {
        // put the plain token back for every row that still has its ciphertext (rows are never deleted to make this fit)
        DB::table('rental_inspection_signing_links')->whereNotNull('token_encrypted')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('rental_inspection_signing_links')->where('id', $row->id)->update(['token' => Crypt::decryptString($row->token_encrypted)]);
            }
        });

        Schema::table('rental_inspection_signing_links', function (Blueprint $table) {
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'token_encrypted']);
        });
    }
};
