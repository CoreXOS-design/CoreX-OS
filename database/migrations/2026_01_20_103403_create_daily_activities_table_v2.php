<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_activities')) return;

        Schema::create('daily_activities', function (Blueprint $table) {
            $table->id();

            $table->date('activity_date');
            $table->string('period', 7); // YYYY-MM

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Leading indicators (manual)
            $table->integer('calls_made')->default(0);
            $table->integer('doors_knocked')->default(0);
            $table->integer('whatsapps_sent')->default(0);
            $table->integer('referrals_asked')->default(0);
            $table->integer('flyers_dropped')->default(0);

            $table->integer('presentations_booked')->default(0);
            $table->integer('presentations_done')->default(0);

            $table->integer('oats_signed')->default(0);
            $table->integer('eats_signed')->default(0);

            $table->integer('buyer_leads')->default(0);
            $table->integer('seller_leads')->default(0);
            $table->integer('portal_leads')->default(0);
            $table->integer('referral_leads')->default(0);

            $table->integer('buyer_appointments')->default(0);

            $table->integer('otps_written')->default(0);
            $table->integer('otps_accepted')->default(0);
            $table->integer('otps_collapsed')->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['activity_date', 'user_id']);
            $table->index(['period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activities');
    }
};
