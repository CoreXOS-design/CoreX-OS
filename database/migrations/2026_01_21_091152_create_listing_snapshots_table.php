<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('period'); // YYYY-MM
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id');

            $table->integer('listing_count')->default(0);
            $table->decimal('avg_listing_price', 12, 2)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['period','user_id']);
            $table->index(['period','branch_id']);

            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_snapshots');
    }
};
