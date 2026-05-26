<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar event class foundation: actor_role + completion_behaviour.
 * Also adds event completion reason columns to calendar_events.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Add actor_role + completion_behaviour to class settings
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->string('actor_role', 20)->default('neither')->after('buyer_facing');
            $table->string('completion_behaviour', 20)->default('freeform')->after('actor_role');
        });

        // 2. Add completion reason columns to calendar_events
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('completion_reason_code', 50)->nullable()->after('status');
            $table->text('completion_reason')->nullable()->after('completion_reason_code');
        });

        // 3. Seed existing classes with actor_role + completion_behaviour
        $seeds = [
            'viewing' => ['actor_role' => 'buyer_action', 'completion_behaviour' => 'require_feedback'],
            'listing_presentation' => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback'],
            'property_evaluation' => ['actor_role' => 'seller_action', 'completion_behaviour' => 'require_feedback'],
            'meeting' => ['actor_role' => 'both', 'completion_behaviour' => 'freeform'],
            'other' => ['actor_role' => 'both', 'completion_behaviour' => 'freeform'],
            'task' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            // Informational events
            'leave_annual' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            'leave_sick' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            'agent_birthday' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            'contact_birthday' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            'leave_cycle_end' => ['actor_role' => 'neither', 'completion_behaviour' => 'freeform'],
            // Compliance events
            'ffc_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'mandate_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'portal_listing_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'signature_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'lease_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'tax_clearance_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
            'pi_insurance_expiry' => ['actor_role' => 'neither', 'completion_behaviour' => 'require_reason'],
        ];

        foreach ($seeds as $eventClass => $values) {
            DB::table('calendar_event_class_settings')
                ->where('event_class', $eventClass)
                ->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['completion_reason_code', 'completion_reason']);
        });

        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn(['actor_role', 'completion_behaviour']);
        });
    }
};
