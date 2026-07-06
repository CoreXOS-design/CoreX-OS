<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-197 — the event-class settings screen now shows a plain-language description per
 * class (what it is · trigger · routing · example). The old VARCHAR(255) `description`
 * is too small for that detail; widen it to TEXT. Nullable, no data change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->change();
        });
    }
};
