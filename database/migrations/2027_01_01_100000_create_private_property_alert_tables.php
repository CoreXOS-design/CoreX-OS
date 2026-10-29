<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private Property email alerts (property-identity.md section 15, step C1): the same shape as the Property24 alert tables,
 * per agency, with the property-file link and its confidence saved on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pp_alert_listings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->string('pp_listing_number', 20);
            $t->string('canonical_ref', 40)->nullable();
            $t->decimal('asking_price', 15, 2);
            $t->decimal('original_price', 15, 2)->nullable();
            $t->string('property_type')->nullable();
            $t->string('suburb')->nullable();
            $t->string('area')->nullable();
            $t->unsignedTinyInteger('bedrooms')->nullable();
            $t->unsignedTinyInteger('bathrooms')->nullable();
            $t->unsignedTinyInteger('garages')->nullable();
            $t->boolean('is_mandated')->default(false);
            $t->string('listing_status')->default('active');
            $t->string('pp_url', 500)->nullable();
            $t->date('first_seen_date');
            $t->date('last_seen_date');
            $t->unsignedInteger('times_seen')->default(1);
            $t->unsignedBigInteger('tracked_property_id')->nullable();
            $t->string('identity_confidence', 10)->nullable();
            $t->string('identity_method', 30)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['agency_id', 'pp_listing_number'], 'uq_pp_alert_agency_number');
            $t->index('canonical_ref', 'idx_pp_alert_canonical');
            $t->index('tracked_property_id', 'idx_pp_alert_tp');
            $t->index(['agency_id', 'suburb'], 'idx_pp_alert_agency_suburb');
        });

        Schema::create('pp_alert_price_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->decimal('old_price', 15, 2);
            $t->decimal('new_price', 15, 2);
            $t->date('change_date');
            $t->timestamps();
            $t->softDeletes();

            $t->index('listing_id', 'idx_pp_alert_pc_listing');
        });

        Schema::create('pp_import_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->string('email_uid');
            $t->string('email_subject');
            $t->dateTime('email_date');
            $t->unsignedInteger('listings_found')->default(0);
            $t->unsignedInteger('listings_new')->default(0);
            $t->unsignedInteger('listings_updated')->default(0);
            $t->string('status', 10)->default('success');
            $t->text('error_message')->nullable();
            $t->timestamps();

            $t->unique(['agency_id', 'email_uid'], 'uq_pp_import_agency_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_import_log');
        Schema::dropIfExists('pp_alert_price_changes');
        Schema::dropIfExists('pp_alert_listings');
    }
};
