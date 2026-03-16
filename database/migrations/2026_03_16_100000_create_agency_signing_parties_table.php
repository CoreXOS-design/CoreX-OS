<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_signing_parties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id')->references('id')->on('agencies')->onDelete('cascade');
            $table->index(['agency_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_signing_parties');
    }
};
