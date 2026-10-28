<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** signed-document-distribution.md "The settings screen" - who changed who-receives-which-document, and what exactly. Append-only. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_distribution_audit_entries')) {
            return;
        }
        Schema::create('document_distribution_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('summary', 500);
            $table->json('changes');
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'created_at'], 'dd_audit_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_distribution_audit_entries');
    }
};
