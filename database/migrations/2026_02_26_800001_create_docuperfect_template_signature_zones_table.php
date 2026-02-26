<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_template_signature_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('docuperfect_templates')->cascadeOnDelete();
            $table->integer('page_index'); // 0-based to match fields_json convention
            $table->decimal('x_position', 8, 4);
            $table->decimal('y_position', 8, 4);
            $table->decimal('width', 8, 4)->default(25);
            $table->decimal('height', 8, 4)->default(6);
            $table->enum('type', ['signature', 'initial'])->default('signature');
            $table->json('assigned_parties'); // ["agent", "tenant", "landlord"]
            $table->string('label')->nullable();
            $table->boolean('required')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'page_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_template_signature_zones');
    }
};
