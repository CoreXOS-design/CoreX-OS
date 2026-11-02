<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-534 — Campaigns (.ai/specs/campaigns.md §3). CoreX's own outbound email sequences: platform data, owner-only, NO agency_id.
 * `email_active_key` columns are generated (email while not archived, NULL once archived) so a UNIQUE index dedupes live rows while
 * archived rows never block a re-import. campaign_sends' UNIQUE (step, lead) is the never-send-twice guarantee (§6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_senders')) {
            Schema::create('campaign_senders', function (Blueprint $table) {
                $table->id();
                $table->string('email', 254);
                $table->string('from_name', 150);
                $table->text('signature')->nullable();
                $table->string('smtp_host', 255);
                $table->unsignedInteger('smtp_port')->default(587);
                $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls');
                $table->string('username', 255);
                $table->text('encrypted_password');
                $table->string('imap_host', 255)->nullable();
                $table->unsignedInteger('imap_port')->default(993);
                $table->enum('imap_encryption', ['ssl', 'tls', 'none'])->default('ssl');
                $table->boolean('is_default')->default(false);
                $table->string('last_send_error', 100)->nullable();
                $table->timestamp('last_send_error_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('last_inbox_check_at')->nullable();
                $table->string('last_inbox_error', 100)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_snd_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_snd_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->string('email_active_key', 254)->nullable()->storedAs('IF(`deleted_at` IS NULL, `email`, NULL)');
                $table->unique('email_active_key', 'cmp_snd_email_active_uq');
            });
        }

        if (! Schema::hasTable('campaign_lists')) {
            Schema::create('campaign_lists', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('source_filename', 255)->nullable();
                $table->timestamp('last_imported_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_lst_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_lst_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('campaign_leads')) {
            Schema::create('campaign_leads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_list_id')->constrained('campaign_lists', 'id', 'cmp_lead_list_fk');
                $table->string('first_name', 150)->default('');
                $table->string('last_name', 150)->default('');
                $table->string('company', 150)->default('');
                $table->string('town', 150)->default('');
                $table->string('email', 254);
                $table->string('phone', 40)->default('');
                $table->text('notes')->nullable();
                $table->enum('status', ['new', 'queued', 'sent', 'replied', 'stopped', 'bounced', 'suppressed'])->default('new');
                $table->string('status_reason', 100)->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_lead_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_lead_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->string('email_active_key', 254)->nullable()->storedAs('IF(`deleted_at` IS NULL, `email`, NULL)');
                $table->unique('email_active_key', 'cmp_lead_email_active_uq');
                $table->index(['campaign_list_id', 'status'], 'cmp_lead_list_status_idx');
                $table->index('email', 'cmp_lead_email_idx');
            });
        }

        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->foreignId('campaign_list_id')->constrained('campaign_lists', 'id', 'cmp_list_fk');
                $table->foreignId('campaign_sender_id')->constrained('campaign_senders', 'id', 'cmp_sender_fk');
                $table->enum('status', ['draft', 'running', 'paused', 'done'])->default('draft');
                $table->json('send_days');
                $table->time('send_from');
                $table->time('send_to');
                $table->unsignedInteger('daily_limit');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('done_at')->nullable();
                $table->string('paused_reason', 255)->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index('status', 'cmp_status_idx');
            });
        }

        if (! Schema::hasTable('campaign_steps')) {
            Schema::create('campaign_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('campaigns', 'id', 'cmp_step_campaign_fk');
                $table->unsignedTinyInteger('step_number');
                $table->string('subject', 200);
                $table->text('body');
                $table->unsignedSmallInteger('day_gap')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_step_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_step_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['campaign_id', 'step_number'], 'cmp_step_order_idx');
            });
        }

        if (! Schema::hasTable('campaign_sends')) {
            Schema::create('campaign_sends', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('campaigns', 'id', 'cmp_send_campaign_fk');
                $table->foreignId('campaign_step_id')->constrained('campaign_steps', 'id', 'cmp_send_step_fk');
                $table->foreignId('campaign_lead_id')->constrained('campaign_leads', 'id', 'cmp_send_lead_fk');
                $table->enum('status', ['sending', 'sent', 'failed', 'unknown'])->default('sending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('to_email', 254);
                $table->string('from_email', 254);
                $table->string('subject', 255);
                $table->text('body');
                $table->string('message_id', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('error_reason', 100)->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_send_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_send_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['campaign_step_id', 'campaign_lead_id'], 'cmp_send_step_lead_uq');
                $table->index(['campaign_id', 'status', 'created_at'], 'cmp_send_campaign_status_idx');
                $table->index('message_id', 'cmp_send_message_id_idx');
            });
        }

        if (! Schema::hasTable('campaign_suppressions')) {
            Schema::create('campaign_suppressions', function (Blueprint $table) {
                $table->id();
                $table->string('email', 254);
                $table->enum('reason', ['reply_stop', 'unsubscribe', 'hard_bounce', 'manual']);
                $table->foreignId('campaign_send_id')->nullable()->constrained('campaign_sends', 'id', 'cmp_sup_send_fk')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'cmp_sup_created_fk')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'cmp_sup_updated_fk')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->string('email_active_key', 254)->nullable()->storedAs('IF(`deleted_at` IS NULL, `email`, NULL)');
                $table->unique('email_active_key', 'cmp_sup_email_active_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_suppressions');
        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaign_steps');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('campaign_leads');
        Schema::dropIfExists('campaign_lists');
        Schema::dropIfExists('campaign_senders');
    }
};
