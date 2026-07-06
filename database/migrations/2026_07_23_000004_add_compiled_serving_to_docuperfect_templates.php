<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-177 / WS6 — the per-template CUTOVER switch (spec §8.3, §9).
 *
 * `compiled_serving` = when true, SigningController serves this template's document from its
 * published compiled CDS via the render-only runtime, bypassing the entire legacy merged_html
 * + compensator chain. `compiled_family` names the published `compiled_templates` family the
 * cutover binds to (e.g. '116'). Template-level and agency-blind by design — a template either
 * serves compiled everywhere or serves legacy everywhere.
 *
 * DUAL-PATH: templates with compiled_serving=false are completely unaffected — the legacy path
 * stays intact. This is the safe, per-template, reversible cutover mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->boolean('compiled_serving')->default(false)->after('render_type')
                ->comment('AT-177/WS6: serve from published compiled CDS instead of legacy merged_html');
            $table->string('compiled_family', 120)->nullable()->after('compiled_serving')
                ->comment('the published compiled_templates family this cutover binds to');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn(['compiled_serving', 'compiled_family']);
        });
    }
};
