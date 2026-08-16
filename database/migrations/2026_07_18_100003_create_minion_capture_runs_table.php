<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-284 — per-session run log. Soft-deletes, no hard delete.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('minion_capture_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('p24_suburb_id')->nullable();
            $table->string('area_label')->nullable();              // e.g. "Margate (Ray Nkonyeni)"
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('running');      // running|ok|partial|failed
            $table->string('triggered_by', 20)->default('manual'); // manual|schedule
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->unsignedInteger('pages_attempted')->default(0);
            $table->unsignedInteger('captured')->default(0);
            $table->unsignedInteger('listings_new')->default(0);
            $table->unsignedInteger('listings_updated')->default(0);
            $table->unsignedInteger('listings_deactivated')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->json('failures_json')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minion_capture_runs');
    }
};
