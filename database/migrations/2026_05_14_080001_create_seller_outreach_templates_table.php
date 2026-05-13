<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_outreach_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->string('name', 150);
            $table->enum('channel', ['whatsapp', 'email']);
            $table->string('subject', 255)->nullable();
            $table->text('body');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default_for_channel')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();

            $table->index(['agency_id', 'channel', 'is_active'], 'outreach_tmpl_agency_chan_active_idx');
            $table->index(['agency_id', 'is_default_for_channel'], 'outreach_tmpl_agency_default_idx');
            $table->index('deleted_at', 'outreach_tmpl_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_templates', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('outreach_tmpl_agency_chan_active_idx');
            $table->dropIndex('outreach_tmpl_agency_default_idx');
            $table->dropIndex('outreach_tmpl_deleted_idx');
        });
        Schema::dropIfExists('seller_outreach_templates');
    }
};
