<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** System-owner "Revenue": what signed agencies pay CoreX (billing records) and the manual prospect pipeline. Platform-level, owner only. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('revenue_billing_records')) {
            Schema::create('revenue_billing_records', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->nullable()->index();   // the agency once it exists on CoreX
                $t->string('name', 150);
                $t->unsignedInteger('users')->nullable();
                $t->unsignedSmallInteger('branches')->default(1);
                $t->decimal('override_monthly', 12, 2)->nullable();          // special deal / Founding Partner: replaces the price list
                $t->string('override_note', 150)->nullable();
                $t->date('start_date')->nullable();
                $t->unsignedSmallInteger('term_months')->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('revenue_prospects')) {
            Schema::create('revenue_prospects', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('contact', 150)->nullable();
                $t->string('stage', 20)->default('talking');                 // talking | agreement_sent | agreement_back | signed
                $t->unsignedInteger('users_min')->nullable();
                $t->unsignedInteger('users_max')->nullable();
                $t->decimal('monthly_override', 12, 2)->nullable();          // a special price for this prospect (else the price list)
                $t->unsignedTinyInteger('chance_percent')->default(50);
                $t->date('expected_start_month')->nullable();               // first day of the month
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->unsignedBigInteger('billing_record_id')->nullable();    // set when converted to signed
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_prospects');
        Schema::dropIfExists('revenue_billing_records');
    }
};
