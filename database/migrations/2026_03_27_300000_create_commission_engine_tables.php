<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agency commission settings
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->integer('commission_split_agent')->default(80);
            $table->integer('commission_split_agency')->default(20);
            $table->decimal('annual_cap', 12, 2)->default(160000.00);
            $table->decimal('post_cap_transaction_fee', 10, 2)->default(2500.00);
            $table->decimal('post_cap_fee_cap', 10, 2)->default(50000.00);
            $table->decimal('post_cap_reduced_fee', 10, 2)->default(750.00);
            $table->decimal('monthly_platform_fee', 10, 2)->default(850.00);
            $table->integer('mentor_extra_split')->default(20);
            $table->integer('mentor_transactions')->default(3);
            $table->decimal('risk_management_fee', 10, 2)->default(400.00);
            $table->decimal('risk_management_cap', 10, 2)->default(5000.00);
            $table->boolean('revenue_share_enabled')->default(true);
            $table->integer('revenue_share_pool_percent')->default(50);

            // Revenue share tier percentages (7 tiers)
            $table->decimal('tier_1_percent', 5, 2)->default(3.50);
            $table->decimal('tier_2_percent', 5, 2)->default(4.00);
            $table->decimal('tier_3_percent', 5, 2)->default(2.50);
            $table->decimal('tier_4_percent', 5, 2)->default(1.50);
            $table->decimal('tier_5_percent', 5, 2)->default(1.00);
            $table->decimal('tier_6_percent', 5, 2)->default(0.50);
            $table->decimal('tier_7_percent', 5, 2)->default(0.25);

            // FLQA requirements for tiers 4-7 (tiers 1-3 are automatic)
            $table->integer('tier_4_flqa_requirement')->default(5);
            $table->integer('tier_5_flqa_requirement')->default(10);
            $table->integer('tier_6_flqa_requirement')->default(15);
            $table->integer('tier_7_flqa_requirement')->default(20);

            $table->timestamps();

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->unique('agency_id');
        });

        // 2. Agent sponsorship tree
        Schema::create('agent_sponsorships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('sponsor_user_id');
            $table->date('sponsored_at');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('agent_user_id');
            $table->index('sponsor_user_id');
            $table->foreign('agent_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('sponsor_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // 3. Agent cap tracking (per anniversary year)
        Schema::create('agent_cap_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('agency_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('cap_amount', 12, 2);
            $table->decimal('company_dollar_paid', 12, 2)->default(0.00);
            $table->boolean('is_capped')->default(false);
            $table->timestamp('capped_at')->nullable();
            $table->decimal('post_cap_fees_paid', 10, 2)->default(0.00);
            $table->decimal('risk_fees_paid', 10, 2)->default(0.00);
            $table->integer('transactions_count')->default(0);
            $table->integer('transactions_mentored')->default(0);
            $table->decimal('gross_commission_income', 14, 2)->default(0.00);
            $table->timestamps();

            $table->index(['user_id', 'period_start']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
        });

        // 4. Commission ledger (every commission event)
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('cap_period_id');
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->enum('transaction_type', ['sale', 'rental_letting', 'rental_management', 'referral', 'other']);
            $table->string('description', 500);
            $table->decimal('gross_commission', 12, 2);
            $table->decimal('vat_amount', 10, 2)->default(0.00);
            $table->decimal('commission_excl_vat', 12, 2);
            $table->integer('agent_split_percent');
            $table->decimal('agent_amount', 12, 2);
            $table->decimal('agency_amount', 12, 2);
            $table->decimal('transaction_fee', 10, 2)->default(0.00);
            $table->decimal('risk_fee', 10, 2)->default(0.00);
            $table->decimal('mentor_fee', 10, 2)->default(0.00);
            $table->boolean('is_post_cap')->default(false);
            $table->decimal('net_agent_amount', 12, 2);
            $table->decimal('company_dollar', 12, 2);
            $table->decimal('revenue_share_pool', 12, 2)->default(0.00);
            $table->enum('status', ['pending', 'confirmed', 'paid', 'cancelled'])->default('pending');
            $table->date('deal_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['agency_id', 'created_at']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('cap_period_id')->references('id')->on('agent_cap_periods')->cascadeOnDelete();
        });

        // 5. Revenue share distributions
        Schema::create('revenue_share_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_ledger_id');
            $table->unsignedBigInteger('producing_agent_id');
            $table->unsignedBigInteger('receiving_agent_id');
            $table->integer('tier');
            $table->decimal('company_dollar', 12, 2);
            $table->decimal('share_percent', 5, 2);
            $table->decimal('share_amount', 10, 2);
            $table->enum('status', ['calculated', 'confirmed', 'paid'])->default('calculated');
            $table->date('period_month');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['receiving_agent_id', 'period_month']);
            $table->index('producing_agent_id');
            $table->foreign('commission_ledger_id')->references('id')->on('commission_ledger')->cascadeOnDelete();
            $table->foreign('producing_agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('receiving_agent_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // 6. Mentor assignments
        Schema::create('agent_mentors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mentee_user_id');
            $table->unsignedBigInteger('mentor_user_id');
            $table->date('assigned_at');
            $table->date('graduated_at')->nullable();
            $table->integer('transactions_completed')->default(0);
            $table->integer('transactions_required')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('mentee_user_id');
            $table->foreign('mentee_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('mentor_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Seed default commission settings for agency_id = 1
        $agencyExists = DB::table('agencies')->where('id', 1)->exists();
        if ($agencyExists) {
            DB::table('commission_settings')->insert([
                'agency_id' => 1,
                'commission_split_agent' => 80,
                'commission_split_agency' => 20,
                'annual_cap' => 160000.00,
                'post_cap_transaction_fee' => 2500.00,
                'post_cap_fee_cap' => 50000.00,
                'post_cap_reduced_fee' => 750.00,
                'monthly_platform_fee' => 850.00,
                'mentor_extra_split' => 20,
                'mentor_transactions' => 3,
                'risk_management_fee' => 400.00,
                'risk_management_cap' => 5000.00,
                'revenue_share_enabled' => true,
                'revenue_share_pool_percent' => 50,
                'tier_1_percent' => 3.50,
                'tier_2_percent' => 4.00,
                'tier_3_percent' => 2.50,
                'tier_4_percent' => 1.50,
                'tier_5_percent' => 1.00,
                'tier_6_percent' => 0.50,
                'tier_7_percent' => 0.25,
                'tier_4_flqa_requirement' => 5,
                'tier_5_flqa_requirement' => 10,
                'tier_6_flqa_requirement' => 15,
                'tier_7_flqa_requirement' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_mentors');
        Schema::dropIfExists('revenue_share_ledger');
        Schema::dropIfExists('commission_ledger');
        Schema::dropIfExists('agent_cap_periods');
        Schema::dropIfExists('agent_sponsorships');
        Schema::dropIfExists('commission_settings');
    }
};
