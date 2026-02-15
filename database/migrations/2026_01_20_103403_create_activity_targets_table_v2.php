<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_targets')) return;

        Schema::create('activity_targets', function (Blueprint $table) {
            $table->id();

            $table->string('period', 7); // YYYY-MM
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Monthly targets (leading indicators)
            $table->integer('calls_made_target')->default(0);
            $table->integer('doors_knocked_target')->default(0);
            $table->integer('whatsapps_sent_target')->default(0);
            $table->integer('referrals_asked_target')->default(0);
            $table->integer('flyers_dropped_target')->default(0);

            $table->integer('presentations_booked_target')->default(0);
            $table->integer('presentations_done_target')->default(0);

            $table->integer('oats_signed_target')->default(0);
            $table->integer('eats_signed_target')->default(0);

            $table->integer('buyer_leads_target')->default(0);
            $table->integer('seller_leads_target')->default(0);
            $table->integer('portal_leads_target')->default(0);
            $table->integer('referral_leads_target')->default(0);

            $table->integer('buyer_appointments_target')->default(0);

            $table->integer('otps_written_target')->default(0);
            $table->integer('otps_accepted_target')->default(0);
            $table->integer('otps_collapsed_target')->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_targets');
    }
};
