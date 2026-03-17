<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE docuperfect_named_fields MODIFY COLUMN source_type ENUM('property','contact','agent','deal','static','computed','manual') DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE docuperfect_named_fields MODIFY COLUMN source_type ENUM('property','contact','agent','static','computed','manual') DEFAULT 'manual'");
    }
};
