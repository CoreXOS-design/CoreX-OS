<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('property_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('agency_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->string('event_category', 40);
            $table->string('event_type', 80);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('human_summary', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['property_id', 'created_at']);
            $table->index(['property_id', 'event_category']);
            $table->index(['agency_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_audit_log');
    }
};
