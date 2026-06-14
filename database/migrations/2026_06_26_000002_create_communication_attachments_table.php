<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication attachments (AT-32, spec §4.2). Content-hash dedup: identical
 * files share one stored object; rows reference it via storage_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_att_agency_fk')->cascadeOnDelete();
            $table->foreignId('communication_id')->constrained('communications', 'id', 'comm_att_comm_fk')->cascadeOnDelete();

            $table->string('filename', 512)->nullable();
            $table->string('mime', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('content_hash', 64);
            $table->string('storage_path', 1024);

            $table->timestamps();
            $table->softDeletes();

            $table->index('content_hash', 'comm_att_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_attachments');
    }
};
