<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_outreach_clicks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('send_id');
            $table->timestamp('clicked_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->char('geo_country', 2)->nullable();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->foreign('send_id')
                ->references('id')->on('seller_outreach_sends')
                ->cascadeOnDelete();

            $table->index(['agency_id', 'send_id', 'clicked_at'], 'outreach_click_agency_send_idx');
            $table->index(['send_id', 'clicked_at'], 'outreach_click_send_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_clicks', function (Blueprint $table) {
            $table->dropForeign(['send_id']);
            $table->dropForeign(['agency_id']);
            $table->dropIndex('outreach_click_agency_send_idx');
            $table->dropIndex('outreach_click_send_idx');
        });
        Schema::dropIfExists('seller_outreach_clicks');
    }
};
