<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-182 (thread de-duplication) — the DERIVED display body of an archived message.
 *
 * The thread conversation view renders this (email reply-quote stripped) so each message
 * shows only its NEW content; the raw `body_text` (immutable compliance record) is never
 * modified, and "Open" still shows the full original. Nullable: WhatsApp rows and emails with
 * no strippable quote leave it null and fall back to `body_text` at display time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->text('body_display')
                ->nullable()
                ->after('body_preview')
                ->comment('AT-182 derived display body (email quote stripped); raw body_text untouched. Null → use body_text.');
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropColumn('body_display');
        });
    }
};
