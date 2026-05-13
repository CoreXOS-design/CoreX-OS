<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_outreach_sends', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('property_id');
            // agent_id is nullable so the FK SET NULL action is valid against
            // a true hard-delete of a user; sends survive for audit per the
            // spec's "agent unavailable" landing-page mode. The application
            // service layer always populates agent_id at send time.
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->enum('channel', ['whatsapp', 'email']);
            $table->string('subject_snapshot', 255)->nullable();
            $table->text('body_snapshot');
            $table->json('facts_snapshot');
            $table->char('tracking_short_code', 6);
            $table->string('recipient_phone_snapshot', 30)->nullable();
            $table->string('recipient_email_snapshot', 255)->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('first_clicked_at')->nullable();
            $table->enum('outcome', [
                'sent', 'clicked', 'replied', 'booked',
                'no_response', 'not_interested', 'bounced',
            ])->default('sent');
            $table->text('outcome_note')->nullable();
            $table->unsignedBigInteger('outcome_set_by_user_id')->nullable();
            $table->timestamp('outcome_set_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->foreign('contact_id')
                ->references('id')->on('contacts')
                ->cascadeOnDelete();
            $table->foreign('property_id')
                ->references('id')->on('properties')
                ->cascadeOnDelete();
            $table->foreign('agent_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('template_id')
                ->references('id')->on('seller_outreach_templates')
                ->nullOnDelete();
            $table->foreign('outcome_set_by_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->unique(['agency_id', 'tracking_short_code'], 'outreach_send_agency_code_unique');
            $table->index(['agency_id', 'contact_id', 'sent_at'], 'outreach_send_contact_idx');
            $table->index(['agency_id', 'property_id', 'sent_at'], 'outreach_send_property_idx');
            $table->index(['agency_id', 'agent_id', 'sent_at'], 'outreach_send_agent_idx');
            $table->index(['agency_id', 'outcome'], 'outreach_send_outcome_idx');
            $table->index('tracking_short_code', 'outreach_send_code_idx');
            $table->index('deleted_at', 'outreach_send_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_sends', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropForeign(['outcome_set_by_user_id']);
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['property_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['agency_id']);

            $table->dropUnique('outreach_send_agency_code_unique');
            $table->dropIndex('outreach_send_contact_idx');
            $table->dropIndex('outreach_send_property_idx');
            $table->dropIndex('outreach_send_agent_idx');
            $table->dropIndex('outreach_send_outcome_idx');
            $table->dropIndex('outreach_send_code_idx');
            $table->dropIndex('outreach_send_deleted_idx');
        });
        Schema::dropIfExists('seller_outreach_sends');
    }
};
