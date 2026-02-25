<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_pool', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('title');
            $table->text('url');
            $table->char('url_hash', 64)->unique();   // sha256 of url — dedup key
            $table->text('snippet')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('tags_json')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_pool');
    }
};
