<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC price guard (data-quality). When an incoming portal capture reports a price
 * that is an implausible order-of-magnitude jump vs the stored price for that
 * listing ref, the importer QUARANTINES it here instead of overwriting good MIC
 * data. Each row is a rejected write, kept for review — never a silent drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospecting_price_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospecting_listing_id')
                  ->constrained('prospecting_listings')->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('portal_source', 10);           // p24 | pp
            $table->string('portal_ref')->nullable();
            $table->integer('stored_price')->nullable();     // the good price we KEPT
            $table->integer('rejected_price')->nullable();   // the implausible price we REFUSED
            $table->decimal('jump_factor', 8, 2)->nullable();// signed: + = increase, - = decrease
            $table->string('search_url', 2000)->nullable();
            $table->string('status', 20)->default('pending'); // pending | confirmed_bad | confirmed_real
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_price_anomalies');
    }
};
