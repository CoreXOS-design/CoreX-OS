<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('buyer_match_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // Score-based cutoffs (0-100). Classification:
            //   score >= strong_min_score → strong
            //   score >= mid_min_score  AND  < strong_min_score → mid
            //   score >= weak_min_score AND  < mid_min_score    → weak
            //   score <  weak_min_score → excluded (not counted, not shown)
            $table->unsignedTinyInteger('strong_min_score')->default(80);
            $table->unsignedTinyInteger('mid_min_score')->default(50);
            $table->unsignedTinyInteger('weak_min_score')->default(0);

            // Agency-customisable labels (defaults: Strong / Mid / Weak).
            $table->string('strong_label', 30)->default('Strong');
            $table->string('mid_label', 30)->default('Mid');
            $table->string('weak_label', 30)->default('Weak');

            // Toggle: when false, the row badge hides the ⚪ weak count
            // (side panel still shows them — only the at-a-glance badge collapses).
            $table->boolean('show_weak_in_badge')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('agency_id', 'unq_buyer_match_tiers_agency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_match_tiers');
    }
};
