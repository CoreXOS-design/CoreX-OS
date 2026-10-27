<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sold stock snapshots + listing cycles — .ai/specs/sold-stock.md §3 (AT-512).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('sold_stock', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('deal_id')->nullable()->index();
            $table->unsignedSmallInteger('cycle_no')->default(1);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->unsignedSmallInteger('beds')->nullable();
            $table->unsignedSmallInteger('baths')->nullable();
            $table->unsignedInteger('size_m2')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('suburb', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('property_type', 100)->nullable();
            $table->string('listing_type', 50)->nullable();
            $table->decimal('asking_price', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->date('sale_date')->nullable();
            $table->date('listed_date')->nullable();
            $table->unsignedInteger('days_on_market')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('second_agent_id')->nullable();
            $table->json('agent_ids')->nullable();
            $table->json('seller_contact_ids')->nullable();
            $table->json('buyer_contact_ids')->nullable();
            $table->json('photos')->nullable();
            $table->string('source', 20)->default('status');
            $table->unsignedBigInteger('snapshotted_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'cycle_no'], 'sold_stock_property_cycle_unique');
            $table->index(['agency_id', 'sale_date'], 'sold_stock_agency_sale_date_idx');
            $table->index(['agency_id', 'suburb'], 'sold_stock_agency_suburb_idx');
            $table->index(['agency_id', 'branch_id'], 'sold_stock_agency_branch_idx');
        });

        Schema::create('property_listing_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('property_id');
            $table->unsignedSmallInteger('cycle_no');
            $table->timestamp('started_at')->nullable();
            $table->string('start_reason', 20)->default('initial');
            $table->unsignedBigInteger('started_by_user_id')->nullable();
            $table->string('status_at_start', 100)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 50)->nullable();
            $table->unsignedBigInteger('sold_stock_id')->nullable();
            $table->json('previous_owner_contact_ids')->nullable();
            $table->json('previous_buyer_contact_ids')->nullable();
            $table->unsignedBigInteger('previous_agent_id')->nullable();
            $table->unsignedBigInteger('suggested_seller_contact_id')->nullable();
            $table->string('suggestion_state', 20)->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'cycle_no'], 'property_listing_cycles_unique');
        });

        Schema::create('property_contact_role_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('role', 50)->nullable();
            $table->unsignedSmallInteger('cycle_no');
            $table->string('ended_reason', 50)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'cycle_no'], 'pcrh_property_cycle_idx');
            $table->index('contact_id', 'pcrh_contact_idx');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedSmallInteger('listing_cycle')->default(1)->after('status');
            $table->date('portal_sold_until')->nullable()->after('listing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['listing_cycle', 'portal_sold_until']);
        });
        Schema::dropIfExists('property_contact_role_history');
        Schema::dropIfExists('property_listing_cycles');
        Schema::dropIfExists('sold_stock');
    }
};
