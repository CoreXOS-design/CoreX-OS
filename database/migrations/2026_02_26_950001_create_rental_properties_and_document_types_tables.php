<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // --- Rental Properties ---
        Schema::create('rental_properties', function (Blueprint $table) {
            $table->id();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('suburb')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('province')->nullable()->default('KwaZulu-Natal');
            $table->string('full_address')->nullable();
            $table->string('property_type')->nullable();
            $table->string('landlord_name')->nullable();
            $table->string('landlord_email')->nullable();
            $table->string('landlord_phone')->nullable();
            $table->decimal('monthly_rental', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // --- Rental Document Types ---
        Schema::create('rental_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color')->nullable()->default('#6B7280');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_lease')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // --- Rejection fields on signature_templates ---
        if (!Schema::hasColumn('signature_templates', 'rejected_at')) {
            Schema::table('signature_templates', function (Blueprint $table) {
                $table->timestamp('rejected_at')->nullable()->after('completed_at');
                $table->text('rejection_reason')->nullable()->after('rejected_at');
                $table->unsignedBigInteger('rejected_by')->nullable()->after('rejection_reason');
            });
        }

        // --- Document type and property on docuperfect_documents ---
        if (!Schema::hasColumn('docuperfect_documents', 'document_type')) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                $table->string('document_type')->nullable()->after('archived_at');
                $table->string('property_address')->nullable()->after('document_type');
                $table->unsignedBigInteger('property_id')->nullable()->after('property_address');
            });
        }

        // --- Add 'rejected' to signature_templates status CHECK constraint (SQLite) ---
        if (DB::getDriverName() === 'sqlite') {
            // Get current CREATE TABLE SQL
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='signature_templates'");
            if ($row && $row->sql) {
                $sql = $row->sql;
                // Add 'rejected' to the status CHECK constraint if not already present
                if (strpos($sql, "'rejected'") === false && strpos($sql, "status") !== false) {
                    // Match the CHECK constraint for status and add 'rejected'
                    $updated = preg_replace(
                        "/('declined')\)/",
                        "'declined','rejected')",
                        $sql
                    );
                    if ($updated !== $sql) {
                        DB::statement('PRAGMA writable_schema = ON');
                        DB::statement("UPDATE sqlite_master SET sql = ? WHERE type = 'table' AND name = 'signature_templates'", [$updated]);
                        DB::statement('PRAGMA writable_schema = OFF');
                        DB::statement('PRAGMA integrity_check');
                    }
                }
            }
        } else {
            // MySQL — modify the ENUM to include 'rejected'
            DB::statement("ALTER TABLE signature_templates MODIFY COLUMN status ENUM('draft','ready','signing','awaiting_tenant','awaiting_landlord','pending_agent_approval','completed','expired','declined','rejected') DEFAULT 'draft'");
        }

        // --- Seed default document types ---
        $defaults = [
            ['name' => 'Lease Agreement', 'slug' => 'lease_agreement', 'color' => '#059669', 'is_system' => true, 'is_lease' => true, 'sort_order' => 1],
            ['name' => 'Mandate', 'slug' => 'mandate', 'color' => '#2563EB', 'is_system' => true, 'is_lease' => false, 'sort_order' => 2],
            ['name' => 'Addendum', 'slug' => 'addendum', 'color' => '#7C3AED', 'is_system' => true, 'is_lease' => false, 'sort_order' => 3],
            ['name' => 'Notice', 'slug' => 'notice', 'color' => '#DC2626', 'is_system' => true, 'is_lease' => false, 'sort_order' => 4],
            ['name' => 'Inspection Report', 'slug' => 'inspection_report', 'color' => '#D97706', 'is_system' => false, 'is_lease' => false, 'sort_order' => 5],
            ['name' => 'Power of Attorney', 'slug' => 'power_of_attorney', 'color' => '#4B5563', 'is_system' => false, 'is_lease' => false, 'sort_order' => 6],
            ['name' => 'Disclosure', 'slug' => 'disclosure', 'color' => '#0891B2', 'is_system' => false, 'is_lease' => false, 'sort_order' => 7],
            ['name' => 'Other', 'slug' => 'other', 'color' => '#6B7280', 'is_system' => true, 'is_lease' => false, 'sort_order' => 99],
        ];

        foreach ($defaults as $type) {
            DB::table('rental_document_types')->updateOrInsert(
                ['slug' => $type['slug']],
                array_merge($type, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_properties');
        Schema::dropIfExists('rental_document_types');

        if (Schema::hasColumn('signature_templates', 'rejected_at')) {
            Schema::table('signature_templates', function (Blueprint $table) {
                $table->dropColumn(['rejected_at', 'rejection_reason', 'rejected_by']);
            });
        }

        if (Schema::hasColumn('docuperfect_documents', 'document_type')) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                $table->dropColumn(['document_type', 'property_address', 'property_id']);
            });
        }
    }
};
