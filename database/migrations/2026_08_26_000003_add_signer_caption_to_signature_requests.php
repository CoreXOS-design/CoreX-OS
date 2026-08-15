<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESIGN recipient builder (Johan, 2026-08-15) — per-signer caption rendered
 * UNDER the signature mark to attribute an entity representative's signature to
 * the entity, e.g. "on behalf of Estate Late John Smith (Executor)".
 *
 * Additive + nullable: only entity-representative signers carry a caption;
 * every existing/ordinary signer stays NULL and renders exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->text('signer_caption')->nullable()->after('signer_name');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('signer_caption');
        });
    }
};
