<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->boolean('created_by_ai')->default(false)->after('created_by_id');
            $table->string('ai_source', 32)->nullable()->after('created_by_ai')
                ->comment('ellie_voice, ellie_chat, future sources');
            $table->text('ai_transcript')->nullable()->after('ai_source')
                ->comment('Raw voice transcript or AI input for audit');
            $table->index('created_by_ai');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['created_by_ai']);
            $table->dropColumn(['created_by_ai', 'ai_source', 'ai_transcript']);
        });
    }
};
