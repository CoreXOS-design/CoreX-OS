<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold page layout v2 (road-to-sold.md 23.31): what one user chose to keep open on their own page - today the belt (get / maintain / sell) that was open last.
 * One row per user, a column per remembered choice; additive, nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rts_user_preferences')) {
            return;
        }
        Schema::create('rts_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('belt_open', 16)->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_user_preferences');
    }
};
