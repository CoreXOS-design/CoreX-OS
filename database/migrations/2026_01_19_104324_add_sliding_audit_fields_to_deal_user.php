<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_user', function (Blueprint $table) {
            $table->text('sliding_granted_month')->nullable();
            $table->integer('sliding_sequence_in_month')->nullable();
            $table->decimal('sliding_applied_cut_percent', 5, 2)->nullable();
            $table->dateTime('sliding_applied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_user', function (Blueprint $table) {
            $table->dropColumn([
                'sliding_granted_month',
                'sliding_sequence_in_month',
                'sliding_applied_cut_percent',
                'sliding_applied_at',
            ]);
        });
    }
};
