<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('mode', 20)->default('escalating'); // 'escalating' or 'simple'

            // Escalating mode
            $table->unsignedTinyInteger('gentle_after_days')->default(2);
            $table->unsignedTinyInteger('firm_after_days')->default(5);
            $table->unsignedTinyInteger('team_alert_after_days')->default(7);
            $table->unsignedTinyInteger('final_after_days')->default(10);
            $table->unsignedTinyInteger('max_escalating_reminders')->default(3);

            // Simple mode
            $table->unsignedTinyInteger('interval_days')->default(3);
            $table->unsignedTinyInteger('max_simple_reminders')->default(5);

            // Custom email template
            $table->text('email_subject')->nullable();
            $table->text('email_body')->nullable();

            // Audit
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('rental_reminder_settings')->insert([
            'enabled' => true,
            'mode' => 'escalating',
            'gentle_after_days' => 2,
            'firm_after_days' => 5,
            'team_alert_after_days' => 7,
            'final_after_days' => 10,
            'max_escalating_reminders' => 3,
            'interval_days' => 3,
            'max_simple_reminders' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reminder_settings');
    }
};
