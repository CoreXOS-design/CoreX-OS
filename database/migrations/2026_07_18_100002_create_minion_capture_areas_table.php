<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-284 — the ticked capture universe (agency x p24 suburb). Soft-deletes, no hard delete.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('minion_capture_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('p24_suburb_id')->index();  // -> p24_suburbs.id
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamp('last_captured_at')->nullable();     // drives the nightly slice ordering
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['agency_id', 'p24_suburb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minion_capture_areas');
    }
};
