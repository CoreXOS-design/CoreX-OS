<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permanently drop the old (event_id, contact_id) unique index that blocks
 * multi-property feedback. The new (event_id, contact_id, property_id) unique
 * covers the same use case with property granularity.
 */
return new class extends Migration {
    public function up(): void
    {
        // Already dropped via Tinker during testing — this migration ensures
        // it's dropped on all environments consistently.
        $exists = DB::select("SHOW INDEX FROM calendar_event_feedback WHERE Key_name = 'cef_event_contact_unique'");
        if (!empty($exists)) {
            DB::statement('ALTER TABLE calendar_event_feedback DROP INDEX cef_event_contact_unique');
        }
    }

    public function down(): void
    {
        // Recreating would break multi-property feedback — intentionally not reversible
    }
};
