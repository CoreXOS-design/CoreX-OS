<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_listings', function (Blueprint $table) {
            $table->id();
            $table->string('p24_listing_number')->unique();
            $table->decimal('asking_price', 15, 2);
            $table->string('property_type')->nullable();
            $table->string('suburb')->nullable();
            $table->string('area')->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('garages')->nullable();
            $table->boolean('is_mandated')->default(false);
            $table->string('listing_status')->default('active');
            $table->string('p24_url')->nullable();
            $table->date('first_seen_date');
            $table->date('last_seen_date');
            $table->decimal('original_price', 15, 2)->nullable();
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamps();

            $table->index('suburb');
            $table->index('property_type');
            $table->index('asking_price');
            $table->index('first_seen_date');
            $table->index(['suburb', 'first_seen_date']);
        });

        Schema::create('p24_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('p24_listings')->onDelete('cascade');
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->date('change_date');
            $table->timestamps();
        });

        Schema::create('p24_import_log', function (Blueprint $table) {
            $table->id();
            $table->string('email_uid')->unique();
            $table->string('email_subject');
            $table->dateTime('email_date');
            $table->unsignedInteger('listings_found')->default(0);
            $table->unsignedInteger('listings_new')->default(0);
            $table->unsignedInteger('listings_updated')->default(0);
            $table->enum('status', ['success', 'error', 'skipped'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_price_changes');
        Schema::dropIfExists('p24_import_log');
        Schema::dropIfExists('p24_listings');
    }
};
