<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate-2 classification columns on communication_pending (AT-36, triage addendum
 * §4.1). Added now so Phase B (the Ellie/keyword classifier) needs no migration.
 * LEFT UNUSED in Phase A.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_pending', function (Blueprint $table) {
            $table->enum('classification', ['real_estate', 'personal', 'uncertain'])->nullable()->after('source_ref');
            $table->boolean('ai_is_real_estate')->nullable()->after('classification');
            $table->decimal('ai_confidence', 4, 3)->nullable()->after('ai_is_real_estate');
            $table->timestamp('classified_at')->nullable()->after('ai_confidence');
            $table->enum('classifier', ['keyword', 'ai', 'manual'])->nullable()->after('classified_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_pending', function (Blueprint $table) {
            $table->dropColumn(['classification', 'ai_is_real_estate', 'ai_confidence', 'classified_at', 'classifier']);
        });
    }
};
