<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // Cross-driver enum widen (MySQL prod + SQLite test DB). Column created
    // via $table->enum() in 2026_05_05_000020_buyer_crm_foundation.
    public function up(): void
    {
        Schema::table('buyer_activity_log', function (Blueprint $table) {
            $table->enum('activity_type', ['viewing_completed', 'presentation', 'contact_access', 'note_added', 'call_logged', 'email_sent', 'whatsapp_sent', 'manual', 'retention_action'])
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('buyer_activity_log', function (Blueprint $table) {
            $table->enum('activity_type', ['viewing_completed', 'presentation', 'contact_access', 'note_added', 'call_logged', 'email_sent', 'whatsapp_sent', 'manual'])
                ->change();
        });
    }
};
