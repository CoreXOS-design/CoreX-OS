<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tva_contact_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->foreignId('captured_by_user_id')->constrained('users');
            // Matched suspense record — TVA person ID == an owner ID on this
            // TrackedProperty's owners (tracked_property_owners.id_number).
            // Nullable: an unmatched capture lands standalone.
            $table->foreignId('tracked_property_id')->nullable()
                ->constrained('tracked_properties')->nullOnDelete();
            // Hard dedup match (ContactDuplicateService, id_number) at capture
            // time — an EXISTING contact this capture will enrich, never
            // auto-merged, never overwritten. Separate from tracked_property_id:
            // a person can match an existing Contact with no suspense record, or
            // vice versa.
            $table->foreignId('matched_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            $table->string('id_number', 20);
            $table->string('first_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('source', 20)->default('tva');
            $table->string('consent_status')->nullable();

            $table->timestamps();

            $table->index(['agency_id', 'id_number']);
            $table->index(['tracked_property_id']);
        });

        Schema::create('tva_contact_capture_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tva_contact_capture_id')->constrained('tva_contact_captures')->cascadeOnDelete();

            $table->string('type', 10); // cell | tel | email
            $table->string('value');
            $table->date('date')->nullable();
            $table->date('link_date')->nullable();
            // Always false in the current safe model (opted-out rows are dropped
            // client-side before they're ever sent) — column exists so a future
            // retain-but-admin-lock mode is a config flag, not a schema change.
            $table->boolean('opted_out')->default(false);

            // Set once an agent ticks + ingests this value into a Contact.
            $table->timestamp('ingested_at')->nullable();
            $table->foreignId('ingested_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->timestamps();

            $table->index(['tva_contact_capture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tva_contact_capture_items');
        Schema::dropIfExists('tva_contact_captures');
    }
};
