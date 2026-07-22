<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-assistant display title. An "assistant" is not always called an
 * assistant — an agency may run the same relationship as a "PA",
 * "Receptionist", "Secretary", etc. This is a free-text LABEL only; it does
 * NOT touch `users.role`, which stays pinned to 'assistant' (the zero-grant
 * resolver hook — AT-267 H6). Null falls back to "Assistant" on display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('assistant_title', 60)->nullable()->after('fica_required');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assistant_title');
        });
    }
};
