<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->enum('portal', ['p24', 'pp']);
            $table->string('lead_type', 32);

            $table->foreignId('listing_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->string('listing_portal_ref', 64)->nullable();

            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->boolean('contact_exists')->default(false);
            $table->foreignId('existing_contact_agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_whatsapp')->default(false);

            $table->json('lead_source_raw');

            $table->timestamp('received_at')->index();
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'received_at']);
            $table->index(['portal', 'listing_portal_ref', 'received_at'], 'pl_portal_ref_recv_idx');
            $table->index(['agency_id', 'notified_at']);
        });

        // Seed "Property24" contact source if not present (PP path already seeds "Private Property").
        if (Schema::hasTable('contact_sources')) {
            $exists = DB::table('contact_sources')->where('name', 'Property24')->exists();
            if (! $exists) {
                DB::table('contact_sources')->insert([
                    'name'       => 'Property24',
                    'color'      => '#ef4444',
                    'sort_order' => 20,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_leads');
        // Intentionally do NOT remove the "Property24" contact_source on rollback —
        // it may already be referenced by Contact rows.
    }
};
