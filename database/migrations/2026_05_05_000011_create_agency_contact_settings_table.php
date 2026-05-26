<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_contact_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained('agencies')->cascadeOnDelete();

            $table->enum('sharing_mode', ['open', 'branch', 'closed'])->default('branch');
            $table->enum('duplicate_mode', ['hard_block', 'soft_warn', 'auto_link'])->default('soft_warn');
            $table->json('duplicate_match_fields')->nullable();

            // Buyer freshness windows (drives Module 4 lifecycle states)
            $table->unsignedInteger('buyer_warm_days')->default(14);
            $table->unsignedInteger('buyer_cold_days')->default(30);
            $table->unsignedInteger('buyer_lost_days')->default(60);

            // Retention policy (POPIA + FICA + PPA = 5 years industry standard)
            $table->unsignedInteger('contact_retention_years')->default(5);
            $table->unsignedInteger('consent_retention_years')->default(5);
            $table->unsignedInteger('access_log_retention_years')->default(5);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_contact_settings');
    }
};
