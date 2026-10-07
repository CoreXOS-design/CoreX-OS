<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform E-Sign signers: the signer's ID / passport number is POPIA personal information and is now encrypted at rest (the same
 * way the agreement's bank details are). The column is widened to TEXT (a ciphertext does not fit in 40 characters) and every existing
 * plaintext value is encrypted in place. Idempotent: a value that already decrypts is left alone, so a re-run never double-encrypts.
 *
 * Also adds signers.previous_token_hash — the SHA-256 of the agency link that was retired when an agreement completed, so the old
 * invite link can show a neutral "this agreement is complete" page instead of a bare 404. It is a hash: it can never open anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_esign_signers', function (Blueprint $t) {
            $t->text('id_number')->nullable()->change();
        });
        if (!Schema::hasColumn('platform_esign_signers', 'previous_token_hash')) {
            Schema::table('platform_esign_signers', function (Blueprint $t) {
                $t->string('previous_token_hash', 64)->nullable()->index();
            });
        }

        DB::table('platform_esign_signers')->whereNotNull('id_number')->where('id_number', '!=', '')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($this->isEncrypted((string) $row->id_number)) {
                    continue;
                }
                DB::table('platform_esign_signers')->where('id', $row->id)->update(['id_number' => Crypt::encryptString((string) $row->id_number)]);
            }
        });
    }

    public function down(): void
    {
        // Put the plaintext back first (values that do not decrypt are already plaintext), then shrink the column.
        DB::table('platform_esign_signers')->whereNotNull('id_number')->where('id_number', '!=', '')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                try {
                    $plain = Crypt::decryptString((string) $row->id_number);
                } catch (\Throwable $e) {
                    continue;
                }
                DB::table('platform_esign_signers')->where('id', $row->id)->update(['id_number' => mb_substr($plain, 0, 40)]);
            }
        });

        if (Schema::hasColumn('platform_esign_signers', 'previous_token_hash')) {
            Schema::table('platform_esign_signers', function (Blueprint $t) {
                $t->dropIndex(['previous_token_hash']);
                $t->dropColumn('previous_token_hash');
            });
        }
        Schema::table('platform_esign_signers', function (Blueprint $t) {
            $t->string('id_number', 40)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
