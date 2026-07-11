<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — T&C versions (IMMUTABLE).
 *
 * Spec: .ai/specs/demo-access-control.md §4.1
 *
 * Published rows are NEVER updated. "Edit" in the admin UI publishes a NEW
 * version (version = max+1). There is deliberately no update path.
 *
 * WHY: an acceptance record that points at text which has since been edited is
 * worthless as evidence — that is the entire point of clickwrap. If this row is
 * mutable, the feature is a lie.
 *
 * Lives on the PRIMARY database. The demo database is destroyed every 3 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_tnc_versions')) {
            return;
        }

        Schema::create('demo_tnc_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->longText('body');
            $table->timestamp('published_at');
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('published_by_user_id', 'demo_tnc_versions_publisher_fk')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_tnc_versions');
    }
};
