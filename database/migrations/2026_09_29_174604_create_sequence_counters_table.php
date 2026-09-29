<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic atomic sequence allocator (duplicate-deal fix, 2026-09-29).
 * One row per named scope (e.g. "deal_no:agency:3", "deal_v2_reference:agency:3:year:2026");
 * App\Services\Sequencing\AtomicSequenceService locks a row FOR UPDATE inside the
 * caller's own transaction and increments it — the same idiom ProformaNumberService
 * already uses for proforma numbers, generalised so any feature needing a race-safe
 * consecutive number reuses one mechanism instead of inventing its own
 * read-max-then-increment (which is exactly what produced duplicate deals
 * #1826/#1827 on live: no lock, two concurrent reads of the same max).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequence_counters', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->unique();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_counters');
    }
};
