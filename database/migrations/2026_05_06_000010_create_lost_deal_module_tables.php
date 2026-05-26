<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_lost_deal_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label', 150);
            $table->enum('category', ['price', 'location', 'property', 'financial', 'timing', 'agent_service', 'competition', 'other']);
            $table->boolean('applies_to_buyers')->default(true);
            $table->boolean('applies_to_sellers')->default(false);
            $table->boolean('requires_notes')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['agency_id', 'code']);
        });

        Schema::create('buyer_lost_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('reason_code', 50);
            $table->string('reason_label', 150);
            $table->text('notes')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->string('source', 30)->default('manual');
            $table->string('buyer_state_at_loss', 20)->nullable();
            $table->unsignedInteger('days_in_pipeline_at_loss')->nullable();
            $table->unsignedInteger('days_since_last_activity_at_loss')->nullable();
            $table->foreignId('agent_owner_user_id_at_loss')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id_at_loss')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('preapproval_amount_at_loss', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'recorded_at']);
            $table->index(['reason_code', 'recorded_at']);
        });

        Schema::create('seller_mandate_lost_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('mandate_type', 30)->nullable();
            $table->string('reason_code', 50);
            $table->string('reason_label', 150);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->string('source', 30)->default('manual');
            $table->decimal('listing_value_at_loss', 14, 2)->nullable();
            $table->unsignedInteger('days_listed_at_loss')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'recorded_at']);
        });

        // Seed default reasons for HFC (agency_id=1)
        $this->seedDefaultReasons(1);
    }

    private function seedDefaultReasons(int $agencyId): void
    {
        $now = now();
        $reasons = [
            ['code' => 'price_too_high', 'label' => 'Buyer found price too high', 'category' => 'price', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 1],
            ['code' => 'found_alternative', 'label' => 'Buyer chose another property', 'category' => 'competition', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => true, 'display_order' => 2],
            ['code' => 'mortgage_decline', 'label' => 'Mortgage application declined', 'category' => 'financial', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 3],
            ['code' => 'timing_changed', 'label' => 'Buyer timing changed', 'category' => 'timing', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 4],
            ['code' => 'lost_interest', 'label' => 'Buyer lost interest, no specific reason', 'category' => 'other', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 5],
            ['code' => 'service_complaint', 'label' => 'Buyer cited service issue', 'category' => 'agent_service', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => true, 'display_order' => 6],
            ['code' => 'property_condition', 'label' => 'Property condition concerns unresolved', 'category' => 'property', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 7],
            ['code' => 'area_concerns', 'label' => 'Buyer concerned about area/location', 'category' => 'location', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 8],
            ['code' => 'no_activity', 'label' => 'No activity (auto-transitioned)', 'category' => 'other', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => false, 'display_order' => 9],
            ['code' => 'other_reason', 'label' => 'Other (specify in notes)', 'category' => 'other', 'applies_to_buyers' => true, 'applies_to_sellers' => false, 'requires_notes' => true, 'display_order' => 10],
            // Seller-side
            ['code' => 'pricing_disagreement', 'label' => 'Seller wanted higher price than recommended', 'category' => 'price', 'applies_to_buyers' => false, 'applies_to_sellers' => true, 'requires_notes' => false, 'display_order' => 11],
            ['code' => 'found_alternative_agent', 'label' => 'Seller appointed competing agency', 'category' => 'competition', 'applies_to_buyers' => false, 'applies_to_sellers' => true, 'requires_notes' => true, 'display_order' => 12],
            ['code' => 'delisted_no_intent', 'label' => 'Seller delisted, no longer selling', 'category' => 'other', 'applies_to_buyers' => false, 'applies_to_sellers' => true, 'requires_notes' => false, 'display_order' => 13],
            ['code' => 'mandate_expired_unrenewed', 'label' => 'Mandate expired without renewal', 'category' => 'other', 'applies_to_buyers' => false, 'applies_to_sellers' => true, 'requires_notes' => false, 'display_order' => 14],
        ];

        foreach ($reasons as $r) {
            DB::table('agency_lost_deal_reasons')->insert(array_merge($r, [
                'agency_id' => $agencyId, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_mandate_lost_records');
        Schema::dropIfExists('buyer_lost_records');
        Schema::dropIfExists('agency_lost_deal_reasons');
    }
};
