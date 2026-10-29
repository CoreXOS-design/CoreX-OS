<?php

use App\Services\Docuperfect\LeaseFieldSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * esign-lease-field-source.md - the template builder gets a "Lease" data source. A named field's source_type is an enum, so
 * 'lease' must be allowed first; then the lease fields (rent, dates, adults, escalation, ...) are written as named-field rows so
 * a mapping can point at them. Both steps are safe to repeat. The rows travel with the migration, so every environment (QA1,
 * Staging, live) gets the same ones on its next deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('docuperfect_named_fields')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE docuperfect_named_fields MODIFY source_type ENUM('property','contact','agent','deal','static','computed','manual','lease') NULL DEFAULT 'manual'");
        }

        LeaseFieldSource::ensureNamedFields();
    }

    public function down(): void
    {
        // Additive: the lease rows and the enum value stay (a template may point at them); nothing to undo.
    }
};
