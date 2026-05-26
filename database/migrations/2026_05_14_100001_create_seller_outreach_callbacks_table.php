<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * seller_outreach_callbacks — callback requests submitted via the public
 * landing page. Created by Prompt 06.
 *
 * Each row is the seller asking the agency to call them back about the
 * pitch they received. Surfaces on the agent's dashboard (post-Prompt 06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_outreach_callbacks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('send_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('requester_name', 150)->nullable();
            $table->string('requester_phone', 30)->nullable();
            $table->string('requester_email', 255)->nullable();
            $table->string('preferred_time', 100)->nullable();
            $table->text('message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->foreign('send_id')
                ->references('id')->on('seller_outreach_sends')
                ->cascadeOnDelete();
            $table->foreign('contact_id')
                ->references('id')->on('contacts')
                ->cascadeOnDelete();
            $table->foreign('handled_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['agency_id', 'status', 'created_at'], 'outreach_cb_agency_status_idx');
            $table->index('send_id', 'outreach_cb_send_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_callbacks', function (Blueprint $table) {
            $table->dropForeign(['handled_by_user_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['send_id']);
            $table->dropForeign(['agency_id']);
            $table->dropIndex('outreach_cb_agency_status_idx');
            $table->dropIndex('outreach_cb_send_idx');
        });
        Schema::dropIfExists('seller_outreach_callbacks');
    }
};
