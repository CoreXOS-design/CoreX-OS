<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-447 follow-up (spec §11.8, phase c) — hand-signed copies uploaded by the agency. Never hard-deleted: replaced files become "superseded". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_esign_wetink_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('platform_esign_documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('batch')->default(1);       // one upload action = one batch; a new batch supersedes the previous one
            $t->string('original_name');
            $t->string('stored_path');
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->string('uploaded_ip', 45)->nullable();
            $t->timestamp('superseded_at')->nullable();
            $t->timestamps();
            $t->index(['document_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_esign_wetink_files');
    }
};
