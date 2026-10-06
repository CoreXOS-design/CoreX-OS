<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — Agency Timeline (platform-owner onboarding plan per agency).
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §7.3
 *
 * None of these tables is tenant-scoped: they are platform-owned (System
 * Developer), `agency_id` is a plain FK naming the agency a timeline is for.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('agency_timeline_default_items')) {
            Schema::create('agency_timeline_default_items', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 12);                       // block | milestone
                $t->string('title', 255);
                $t->text('body')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->unsignedInteger('offset_days')->nullable(); // milestones only
                $t->boolean('is_public')->default(true);
                $t->boolean('is_go_live')->default(false);
                $t->string('auto_complete_trigger', 40)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['kind', 'sort_order']);
            });
        }

        if (!Schema::hasTable('agency_timelines')) {
            Schema::create('agency_timelines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('token', 64)->unique();
                $t->date('start_date');
                $t->string('status', 12)->default('running');   // running | live | paused
                $t->boolean('public_link_enabled')->default(true);
                $t->unsignedBigInteger('started_by')->nullable();
                $t->timestamp('live_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('agency_id');
            });
        }

        if (!Schema::hasTable('agency_timeline_items')) {
            Schema::create('agency_timeline_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('timeline_id');
                $t->string('kind', 12);
                $t->string('title', 255);
                $t->text('body')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->date('due_date')->nullable();
                $t->unsignedInteger('offset_days')->nullable();
                $t->boolean('is_public')->default(true);
                $t->boolean('is_go_live')->default(false);
                $t->string('auto_complete_trigger', 40)->nullable();
                $t->string('status', 12)->default('pending');   // pending | done | skipped
                $t->timestamp('completed_at')->nullable();
                $t->unsignedBigInteger('completed_by')->nullable();
                $t->string('completed_source', 40)->nullable();
                $t->boolean('is_custom')->default(false);
                $t->unsignedBigInteger('source_default_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['timeline_id', 'kind', 'sort_order']);
            });
        }

        if (!Schema::hasTable('agency_timeline_events')) {
            Schema::create('agency_timeline_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('timeline_id');
                $t->unsignedBigInteger('item_id')->nullable();
                $t->string('event', 40);
                $t->string('summary', 500);
                $t->json('before')->nullable();
                $t->json('after')->nullable();
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('source', 40)->default('manual');
                $t->timestamp('created_at')->useCurrent();
                $t->index(['timeline_id', 'created_at']);
            });
        }

        $this->seedDefaults();
    }

    /**
     * Johan's example plan, as editable defaults (offsets are days after the
     * start date). Idempotent: only seeds into an EMPTY table, so an owner's
     * later edits are never overwritten on a re-run / re-deploy.
     */
    private function seedDefaults(): void
    {
        if (DB::table('agency_timeline_default_items')->count() > 0) {
            return;
        }

        $now = now();
        $rows = [
            // ── Info blocks ──
            ['block', 'Where we are', "Training is complete. The first month is your take-on month: we use it to set CoreX up for {{agency_name}}, bring your data across and get everyone comfortable on the system.", null, true, false, null],
            ['block', 'Training going forward', "Ad hoc training is free and unlimited. Anyone in your team can ask for a session at any time, as often as needed, until everyone is confident on the system. This applies during the take-on month and after go-live.", null, true, false, null],
            ['block', 'What we need from you', "1. Complete the take-on questionnaire. This is where you choose how CoreX works for {{agency_name}} - your processes, roles, documents and preferences - so we set it up your way, not ours.\n\n2. Your data from your current CRM. Please export whatever your current CRM allows (listings, contacts, buyers, sellers, deals, documents) and send it to us. If you are not sure how, we will help: we can connect via AnyDesk to one of your team's computers and find the best way together. Once you have given notice to your current provider, we contact Property24 for the data transfer document; you sign it, P24 releases your listing data to us, and we import it into CoreX.\n\n3. Your website decision - either we link CoreX to your current website, or we build you a new one.\n\n4. Sign the CoreX Subscription Agreement and debit order form. We will send the agreement with the debit order form attached for signature.", null, true, false, null],
            ['block', 'Finances', "No charge for the take-on month. Billing starts on {{billing_start_date}}.", null, true, false, null],
            // ── Milestones ──
            ['milestone', 'Take-on questionnaire completed', 'Choose how CoreX works for your agency.', 3, true, false, null],
            ['milestone', 'CRM data exported and sent to us', 'AnyDesk session if needed. Notice given to current provider and Property24 transfer document signed.', 10, true, false, null],
            ['milestone', 'Sign the CoreX Subscription Agreement and debit order form', 'To be signed before billing starts.', 10, true, false, 'contract_signed'],
            ['milestone', 'Data imported, CoreX set up per your questionnaire, website work starts', null, 13, true, false, null],
            ['milestone', 'Agency setup wizard completed', 'Walk through the guided setup in CoreX.', 20, true, false, 'setup_wizard_completed'],
            ['milestone', 'Team works in CoreX alongside ad hoc training', null, 29, true, false, null],
            ['milestone', '{{agency_name}} live on CoreX', 'Billing starts the day after go-live.', 30, true, true, null],
        ];

        $order = ['block' => 0, 'milestone' => 0];
        foreach ($rows as [$kind, $title, $body, $offset, $public, $live, $trigger]) {
            $order[$kind] += 10;
            DB::table('agency_timeline_default_items')->insert([
                'kind' => $kind, 'title' => $title, 'body' => $body,
                'sort_order' => $order[$kind], 'offset_days' => $offset,
                'is_public' => $public, 'is_go_live' => $live,
                'auto_complete_trigger' => $trigger,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_timeline_events');
        Schema::dropIfExists('agency_timeline_items');
        Schema::dropIfExists('agency_timelines');
        Schema::dropIfExists('agency_timeline_default_items');
    }
};
