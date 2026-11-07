<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 phase 2 (.ai/specs/auctions.md §4.8): enquiries from the public auction site. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_enquiries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id')->nullable();
            $t->unsignedBigInteger('lot_id')->nullable();
            $t->unsignedBigInteger('property_id')->nullable();
            $t->unsignedBigInteger('client_user_id')->nullable();   // null = a guest enquired without an account
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('name', 190);
            $t->string('email', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('message')->nullable();
            $t->string('status', 12)->default('new');              // new | contacted | closed
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('source', 24)->default('public_site');
            $t->dateTime('consent_popia_at')->nullable();
            $t->dateTime('contacted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['agency_id', 'status', 'created_at'], 'au2_enq_list_ix');
            $t->index('lot_id', 'au2_enq_lot_ix');
            $t->index('assigned_user_id', 'au2_enq_assigned_ix');
            $t->foreign('agency_id', 'au2_enq_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_enq_auction_fk')->references('id')->on('auctions')->nullOnDelete();
            $t->foreign('lot_id', 'au2_enq_lot_fk')->references('id')->on('auction_lots')->nullOnDelete();
            $t->foreign('contact_id', 'au2_enq_contact_fk')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_enquiries');
    }
};
