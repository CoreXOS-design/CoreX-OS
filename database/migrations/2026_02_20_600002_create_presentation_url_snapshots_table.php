<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_url_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->text('url');
            $table->longText('snapshot_html')->nullable();
            $table->string('source_type', 50)->default('other'); // p24_search, p24_listing, private_property, article, other
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->char('content_hash', 64)->nullable(); // sha256 hex
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('presentation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_url_snapshots');
    }
};
