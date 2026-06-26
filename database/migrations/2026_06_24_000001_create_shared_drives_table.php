<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_drives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->string('name');
            // Restricted = visible only to creator + explicit access list (+ owners/managers).
            $table->boolean('is_restricted')->default(false);
            // Exactly one default "General" drive per agency — cannot be deleted or restricted.
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['agency_id', 'deleted_at']);
            $table->index(['agency_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_drives');
    }
};
