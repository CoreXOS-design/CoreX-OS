<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_captures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('presentation_id')->nullable();
            $table->string('source_site', 100);       // e.g. property24.com, privateproperty.co.za
            $table->string('page_type', 20);           // search | property | unknown
            $table->text('source_url');
            $table->text('final_url');
            $table->string('page_title', 500)->nullable();
            $table->timestamp('captured_at');
            $table->string('extractor_version', 50);   // portal_ext_v1
            $table->char('dom_hash_sha256', 64);
            $table->unsignedInteger('html_bytes');
            $table->string('raw_html_path', 255);
            $table->string('screenshot_path', 255)->nullable();
            $table->string('parse_status', 30);        // parsed | unparsed_jsonld_missing | unparsed_error
            $table->json('extracted_fields_json')->nullable();
            $table->json('jsonld_json')->nullable();
            $table->json('found_image_urls_json')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            $table->foreign('presentation_id')
                  ->references('id')->on('presentations')
                  ->onDelete('set null');

            $table->index(['presentation_id', 'source_site']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_captures');
    }
};
