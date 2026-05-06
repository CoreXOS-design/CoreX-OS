<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_sold_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('external_property_id', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('suburb', 100)->nullable();
            $table->string('area', 100)->nullable();
            $table->decimal('sold_price', 14, 2);
            $table->date('sold_date');
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 3, 1)->nullable();
            $table->decimal('sqm', 8, 2)->nullable();
            $table->string('property_type', 50)->nullable();
            $table->unsignedInteger('days_on_market')->nullable();
            $table->decimal('listing_price_at_sale', 14, 2)->nullable();
            $table->enum('source', ['manual', 'tva_api', 'p24_capture', 'pp_capture', 'deeds_office'])->default('manual');
            $table->string('source_reference', 255)->nullable();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['suburb', 'sold_date']);
            $table->index(['area', 'sold_date']);
            $table->index(['agency_id', 'sold_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sold_records');
    }
};
