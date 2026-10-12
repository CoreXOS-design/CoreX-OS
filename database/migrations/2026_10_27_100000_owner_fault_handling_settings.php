<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - "How I want faults handled": the OWNER's per-property settings. Additive only: two new
 * tables, two notification event types, and a one-time carry-over of the existing per-property limit as `captured_by_agent` (NO owner is emailed).
 * The old column is left exactly as it is.
 */
return new class extends Migration
{
    private const EVENTS = [
        'rental_fault_settings.disputed' => ['Owner does not agree with the fault-handling settings', 'An owner pressed "I do not agree" on the settings their agent recorded for their rental property. Until the owner confirms (or you record new evidence) every decision goes to the owner.'],
        'rental_fault_settings.change_requested' => ['Owner asks to change who looks at faults first', 'An owner asked, from their portal, for a change to who looks at faults first on their rental property.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('rental_property_fault_settings')) {
            Schema::create('rental_property_fault_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('property_id')->unique('rpfs_property_uq');
                $table->decimal('repair_limit', 10, 2)->nullable();
                $table->decimal('emergency_limit', 10, 2)->nullable();
                $table->string('callout_mode', 3)->nullable();               // yes | no | null = no answer yet
                $table->decimal('callout_limit', 10, 2)->nullable();         // only when callout_mode = yes
                $table->string('status', 24)->default('not_set');            // not_set | captured_by_agent | confirmed_by_owner | disputed
                $table->unsignedInteger('version')->default(1);
                $table->string('capture_basis', 16)->nullable();             // phone_call | whatsapp | email | signed_mandate | carried_over
                $table->date('capture_occurred_at')->nullable();
                $table->text('capture_note')->nullable();
                $table->string('capture_file_path', 500)->nullable();
                $table->string('capture_file_name', 191)->nullable();
                $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('captured_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('confirmed_by_contact_id')->nullable();
                $table->timestamp('disputed_at')->nullable();
                $table->unsignedBigInteger('disputed_by_contact_id')->nullable();
                $table->text('dispute_note')->nullable();
                $table->timestamp('last_sent_to_owner_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rental_property_fault_setting_changes')) {
            Schema::create('rental_property_fault_setting_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('property_id')->index('rpfsc_property_idx');
                $table->string('action', 24);                                // captured | owner_changed | confirmed | disputed | carried_over | sent_to_owner | change_requested
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('status_after', 24);
                $table->string('basis', 16)->nullable();
                $table->date('occurred_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('notification_event_types')) {
            foreach (self::EVENTS as $key => [$label, $description]) {
                if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('notification_event_types')->insert([
                    'key' => $key, 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => $label, 'description' => $description,
                    'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                    'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                    'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // One-time carry-over: an existing per-property limit becomes the repair limit, captured by the agent, basis "carried over". No owner is emailed.
        if (Schema::hasColumn('properties', 'rental_no_approval_spend_threshold')) {
            $rows = DB::table('properties')->whereNotNull('rental_no_approval_spend_threshold')->whereNotNull('agency_id')
                ->whereNotIn('id', DB::table('rental_property_fault_settings')->select('property_id'))
                ->get(['id', 'agency_id', 'rental_no_approval_spend_threshold', 'rental_work_terms_updated_at', 'rental_work_terms_updated_by_user_id']);
            foreach ($rows as $p) {
                $at = $p->rental_work_terms_updated_at ?: now();
                DB::table('rental_property_fault_settings')->insert([
                    'agency_id' => $p->agency_id, 'property_id' => $p->id, 'repair_limit' => $p->rental_no_approval_spend_threshold,
                    'status' => 'captured_by_agent', 'version' => 1, 'capture_basis' => 'carried_over',
                    'capture_note' => 'Carried over from the existing work terms', 'captured_by_user_id' => $p->rental_work_terms_updated_by_user_id,
                    'captured_at' => $at, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('rental_property_fault_setting_changes')->insert([
                    'agency_id' => $p->agency_id, 'property_id' => $p->id, 'action' => 'carried_over', 'user_id' => null,
                    'old_values' => null, 'new_values' => json_encode(['repair_limit' => (float) $p->rental_no_approval_spend_threshold]),
                    'status_after' => 'captured_by_agent', 'basis' => 'carried_over', 'note' => 'Carried over from the existing work terms', 'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_property_fault_setting_changes');
        Schema::dropIfExists('rental_property_fault_settings');
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->whereIn('key', array_keys(self::EVENTS))->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
