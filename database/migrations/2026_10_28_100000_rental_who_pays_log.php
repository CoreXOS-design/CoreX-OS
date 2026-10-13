<?php

use App\Models\RentalFaultType;
use App\Models\RentalFaultTypeCause;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.13 (stage 4, "Who pays": a LOGGED ruling - nothing is charged or invoiced).
 *  - `rental_fault_type_causes`: per fault type, the self-checks and the causes, each with a default "who normally pays".
 *  - on `rental_work_orders`: what was found, the cause, who reported it back, and the current ruling's payer (for the list and its filter).
 *  - `rental_payer_rulings`: the ruling itself, append-only (a newer one supersedes; nothing is edited or deleted).
 * The seeded defaults for every EXISTING agency's default fault types are back-filled here (seeders never run on a git-pull deploy);
 * RentalFaultType::seedDefaultsFor() does the same for a new agency. Idempotent: every step checks before it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_fault_type_causes')) {
            Schema::create('rental_fault_type_causes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_fault_type_id');
                $table->string('kind', 12);                          // self_check | cause
                $table->string('label', 191);
                $table->string('default_payer', 30)->nullable();     // owner | tenant | shared | insurance_body_corporate | null = no default
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['rental_fault_type_id', 'kind'], 'rftc_type_kind_idx');
            });
        }

        if (! Schema::hasColumn('rental_work_orders', 'found_text')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->text('found_text')->nullable();
                $table->unsignedBigInteger('cause_id')->nullable();
                $table->string('cause_label', 191)->nullable();      // snapshot of the picked list row
                $table->string('cause_note', 500)->nullable();       // free text
                $table->timestamp('reported_back_at')->nullable();
                $table->string('reported_back_by', 191)->nullable();
                $table->string('who_pays', 30)->nullable()->index(); // the CURRENT ruling's payer
                $table->unsignedBigInteger('who_pays_ruling_id')->nullable();
            });
        }

        if (! Schema::hasTable('rental_payer_rulings')) {
            Schema::create('rental_payer_rulings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_work_order_id')->index();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable()->index();
                $table->string('payer', 30);                         // owner | tenant | shared | insurance_body_corporate
                $table->unsignedTinyInteger('tenant_percent')->nullable();
                $table->unsignedTinyInteger('owner_percent')->nullable();
                $table->text('reason');
                $table->string('evidence_path', 500)->nullable();
                $table->string('evidence_name', 191)->nullable();
                $table->text('found_text')->nullable();              // snapshots at the moment of ruling
                $table->string('cause_text', 700)->nullable();
                $table->string('suggested_payer', 30)->nullable();
                $table->unsignedBigInteger('ruled_by_user_id')->nullable();
                $table->timestamp('ruled_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();
                $table->timestamp('owner_told_at')->nullable();
                $table->timestamp('tenant_told_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // Back-fill the seeded defaults on every existing agency's default fault types (idempotent).
        RentalFaultType::withoutGlobalScopes()->where('is_default', true)->get()->each(
            fn (RentalFaultType $type) => RentalFaultTypeCause::seedForType($type)
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_payer_rulings');
        Schema::dropIfExists('rental_fault_type_causes');
        if (Schema::hasColumn('rental_work_orders', 'found_text')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->dropIndex(['who_pays']);
                $table->dropColumn(['found_text', 'cause_id', 'cause_label', 'cause_note', 'reported_back_at', 'reported_back_by', 'who_pays', 'who_pays_ruling_id']);
            });
        }
    }
};
