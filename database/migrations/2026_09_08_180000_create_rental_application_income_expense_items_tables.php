<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Round 9 (item 5) — Johan: "the income and expense rows... filling
 * the last row auto-adds a fresh empty one, income and expenses both, total
 * recalculating live." A fixed monthly_income/other_monthly_income/
 * monthly_expenses cannot grow rows — replaced with two real line-item
 * tables, matching the existing PayrollPayslipLine precedent for a
 * financial audit ledger belonging to one parent record (SoftDeletes,
 * non-negotiable #1 — an agent removing a line is a real, recoverable
 * event, not a hard delete).
 *
 * Existing captured data is migrated, not discarded: any assessment with
 * monthly_income/other_monthly_income/monthly_expenses already filled in
 * becomes one income/expense line each, preserving real agent-entered
 * amounts (confirmed real data exists on QA1 at the time of writing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_income_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            // Explicit short FK name — the auto-generated
            // '..._rental_application_assessment_id_foreign' exceeds
            // MySQL's 64-char identifier limit.
            $table->foreignId('rental_application_assessment_id')
                ->constrained('rental_application_assessments', 'id', 'rai_items_assessment_fk')
                ->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rental_application_expense_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_application_assessment_id')
                ->constrained('rental_application_assessments', 'id', 'rae_items_assessment_fk')
                ->cascadeOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        DB::table('rental_application_assessments')
            ->whereNotNull('monthly_income')
            ->orWhereNotNull('other_monthly_income')
            ->orWhereNotNull('monthly_expenses')
            ->orderBy('id')
            ->chunkById(200, function ($assessments) use ($now) {
                $incomeRows = [];
                $expenseRows = [];

                foreach ($assessments as $assessment) {
                    $sort = 0;
                    if ($assessment->monthly_income !== null) {
                        $incomeRows[] = [
                            'agency_id' => $assessment->agency_id,
                            'rental_application_assessment_id' => $assessment->id,
                            'description' => 'Monthly income',
                            'amount' => $assessment->monthly_income,
                            'sort_order' => $sort++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($assessment->other_monthly_income !== null) {
                        $incomeRows[] = [
                            'agency_id' => $assessment->agency_id,
                            'rental_application_assessment_id' => $assessment->id,
                            'description' => 'Other income',
                            'amount' => $assessment->other_monthly_income,
                            'sort_order' => $sort++,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($assessment->monthly_expenses !== null) {
                        $expenseRows[] = [
                            'agency_id' => $assessment->agency_id,
                            'rental_application_assessment_id' => $assessment->id,
                            'description' => 'Monthly expenses',
                            'amount' => $assessment->monthly_expenses,
                            'sort_order' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($incomeRows) {
                    DB::table('rental_application_income_items')->insert($incomeRows);
                }
                if ($expenseRows) {
                    DB::table('rental_application_expense_items')->insert($expenseRows);
                }
            });

        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->dropColumn(['monthly_income', 'other_monthly_income', 'monthly_expenses']);
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->decimal('monthly_income', 12, 2)->nullable();
            $table->decimal('other_monthly_income', 12, 2)->nullable();
            $table->decimal('monthly_expenses', 12, 2)->nullable();
        });

        // Best-effort, lossy reversal — every income line's amount rolls up
        // into monthly_income (other_monthly_income left null), every
        // expense line's amount rolls up into monthly_expenses. Individual
        // line descriptions do not survive a rollback; the totals do.
        DB::table('rental_application_assessments')->orderBy('id')->chunkById(200, function ($assessments) {
            foreach ($assessments as $assessment) {
                $incomeTotal = DB::table('rental_application_income_items')
                    ->where('rental_application_assessment_id', $assessment->id)
                    ->whereNull('deleted_at')
                    ->sum('amount');
                $expenseTotal = DB::table('rental_application_expense_items')
                    ->where('rental_application_assessment_id', $assessment->id)
                    ->whereNull('deleted_at')
                    ->sum('amount');

                DB::table('rental_application_assessments')->where('id', $assessment->id)->update([
                    'monthly_income' => $incomeTotal > 0 ? $incomeTotal : null,
                    'monthly_expenses' => $expenseTotal > 0 ? $expenseTotal : null,
                ]);
            }
        });

        Schema::dropIfExists('rental_application_income_items');
        Schema::dropIfExists('rental_application_expense_items');
    }
};
