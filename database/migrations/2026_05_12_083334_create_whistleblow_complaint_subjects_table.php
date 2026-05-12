<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblow_complaint_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')
                  ->constrained('whistleblow_complaints')
                  ->cascadeOnDelete();
            $table->string('agency_name');
            $table->string('practitioner_name')->nullable();
            $table->string('portal_url');
            $table->enum('portal_source', ['p24', 'pp', 'other']);
            $table->string('portal_listing_ref')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblow_complaint_subjects');
    }
};
