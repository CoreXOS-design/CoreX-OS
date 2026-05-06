<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE buyer_activity_log MODIFY COLUMN activity_type ENUM('viewing_completed','presentation','contact_access','note_added','call_logged','email_sent','whatsapp_sent','manual','retention_action') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE buyer_activity_log MODIFY COLUMN activity_type ENUM('viewing_completed','presentation','contact_access','note_added','call_logged','email_sent','whatsapp_sent','manual') NOT NULL");
    }
};
