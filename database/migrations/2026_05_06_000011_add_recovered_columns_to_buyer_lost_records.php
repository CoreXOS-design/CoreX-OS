<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('buyer_lost_records', function (Blueprint $table) {
            $table->timestamp('recovered_at')->nullable()->after('preapproval_amount_at_loss');
            $table->foreignId('recovered_by_user_id')->nullable()->after('recovered_at')->constrained('users')->nullOnDelete();
            $table->text('recovered_notes')->nullable()->after('recovered_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_lost_records', function (Blueprint $table) {
            $table->dropColumn(['recovered_at', 'recovered_by_user_id', 'recovered_notes']);
        });
    }
};
