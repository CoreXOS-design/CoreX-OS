<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('signature_zones')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite does not support ALTER TABLE MODIFY COLUMN.
            // zone_type is stored as a string so no schema change needed — the
            // model constant TYPE_OTHER_CONDITIONS = 'other_conditions' is sufficient.
            return;
        }

        DB::statement("ALTER TABLE signature_zones MODIFY COLUMN zone_type ENUM('signature', 'initial', 'other_conditions') DEFAULT 'signature'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('signature_zones')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE signature_zones MODIFY COLUMN zone_type ENUM('signature', 'initial') DEFAULT 'signature'");
    }
};
