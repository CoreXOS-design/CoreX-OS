<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_sold_comps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('presentation_id');
            $table->unsignedBigInteger('source_upload_id')->nullable();
            $table->date('sold_date')->nullable();
            $table->unsignedBigInteger('sold_price_inc')->nullable();
            $table->string('suburb')->nullable();
            $table->string('property_type')->nullable();
            $table->unsignedSmallInteger('beds')->nullable();
            $table->unsignedSmallInteger('baths')->nullable();
            $table->unsignedSmallInteger('size_m2')->nullable();
            $table->date('listed_date')->nullable();
            $table->text('raw_row_json');
            $table->string('parser_version', 50);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('presentation_id')
                  ->references('id')->on('presentations')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_sold_comps');
    }
};
