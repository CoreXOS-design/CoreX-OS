<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prospecting_buyer_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospecting_listing_id')->constrained('prospecting_listings')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('score')->default(0)->comment('Match score 0-100');
            $table->enum('tier', ['perfect', 'strong', 'approximate'])->default('approximate');
            $table->json('matched_features')->nullable()->comment('What criteria matched');
            $table->json('missing_features')->nullable()->comment('What criteria are missing/gap');
            $table->timestamp('matched_at');
            $table->timestamp('last_recompute_at')->nullable();
            $table->timestamp('agent_notified_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prospecting_listing_id', 'contact_id'], 'pbm_listing_contact_unique');
            $table->index(['prospecting_listing_id', 'score'], 'pbm_listing_score');
            $table->index(['contact_id', 'score'], 'pbm_contact_score');
            $table->index(['tier', 'matched_at'], 'pbm_tier_date');
        });

        // Add preapproval fields to buyer_preferences
        if (Schema::hasTable('buyer_preferences') && !Schema::hasColumn('buyer_preferences', 'preapproval_amount')) {
            Schema::table('buyer_preferences', function (Blueprint $table) {
                $table->decimal('preapproval_amount', 14, 2)->nullable()->after('deal_breakers')
                    ->comment('Pre-approved amount in ZAR');
                $table->date('preapproval_expires_at')->nullable()->after('preapproval_amount');
                $table->string('preapproval_institution', 100)->nullable()->after('preapproval_expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_buyer_matches');
        if (Schema::hasTable('buyer_preferences')) {
            Schema::table('buyer_preferences', function (Blueprint $table) {
                $table->dropColumn(['preapproval_amount', 'preapproval_expires_at', 'preapproval_institution']);
            });
        }
    }
};
