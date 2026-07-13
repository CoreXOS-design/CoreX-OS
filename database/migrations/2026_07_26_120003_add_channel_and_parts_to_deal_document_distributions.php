<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-228 — a distribution now carries a CHANNEL (email|whatsapp) and, when a send is
 * auto-split over the size limit, its PART within the group. One "Send" is one group_key
 * of N parts; never split a single document across parts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_document_distributions', function (Blueprint $table) {
            $table->enum('channel', ['email', 'whatsapp'])->default('email')->after('delivery_mode');
            $table->string('group_key', 40)->nullable()->after('channel');   // ties the parts of one Send
            $table->unsignedSmallInteger('part_no')->default(1)->after('group_key');
            $table->unsignedSmallInteger('part_of')->default(1)->after('part_no');
            $table->index(['agency_id', 'group_key'], 'ddd_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deal_document_distributions', function (Blueprint $table) {
            $table->dropIndex('ddd_group_idx');
            $table->dropColumn(['channel', 'group_key', 'part_no', 'part_of']);
        });
    }
};
