<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform E-Sign — evidence anchor. `content_hash` is the SHA-256 of what the signers were shown, frozen at send
 * (merged wording / source PDF bytes / placed fields). It is checked again before the document is sealed, so the sealed copy can only
 * ever be built from exactly what was sent. Documents sent before this column existed keep NULL (nothing to compare against).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('platform_esign_documents', 'content_hash')) {
            Schema::table('platform_esign_documents', function (Blueprint $t) {
                $t->string('content_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_esign_documents', 'content_hash')) {
            Schema::table('platform_esign_documents', fn (Blueprint $t) => $t->dropColumn('content_hash'));
        }
    }
};
