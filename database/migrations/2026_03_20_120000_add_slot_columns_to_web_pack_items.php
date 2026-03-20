<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_pack_items', function (Blueprint $table) {
            $table->string('slot_type', 20)->default('required')->after('sort_order');
            $table->unsignedInteger('slot_group')->nullable()->after('slot_type');
            $table->string('slot_label')->nullable()->after('slot_group');
        });
    }

    public function down(): void
    {
        Schema::table('web_pack_items', function (Blueprint $table) {
            $table->dropColumn(['slot_type', 'slot_group', 'slot_label']);
        });
    }
};
