<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->integer('page_number');
            $table->decimal('x_position', 8, 4);
            $table->decimal('y_position', 8, 4);
            $table->decimal('width', 8, 4)->default(20);
            $table->decimal('height', 8, 4)->default(5);
            $table->enum('type', ['signature', 'initial', 'date', 'text'])->default('signature');
            $table->string('assigned_party');
            $table->string('assigned_email')->nullable();
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->index(['signature_template_id', 'page_number']);
            $table->index(['assigned_party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_markers');
    }
};
