<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_template_id')->constrained()->cascadeOnDelete();
            $table->enum('zone_type', ['signature', 'initial'])->default('signature');
            $table->string('party_role'); // seller, buyer, landlord, tenant, agent, supervisor, witness
            $table->integer('page_number'); // 1-based
            $table->decimal('x_position', 8, 4);
            $table->decimal('y_position', 8, 4);
            $table->decimal('width', 8, 4)->default(25);
            $table->decimal('height', 8, 4)->default(8);
            $table->boolean('is_auto_placed')->default(false);
            $table->enum('source', ['template', 'setup'])->default('setup');
            $table->string('label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['signature_template_id', 'page_number']);
            $table->index(['party_role']);
        });

        // Add from_zone_id to signature_markers to link expanded markers back to zones
        if (!Schema::hasColumn('signature_markers', 'from_zone_id')) {
            Schema::table('signature_markers', function (Blueprint $table) {
                $table->foreignId('from_zone_id')->nullable()->after('from_template_zone_id')
                    ->constrained('signature_zones')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('signature_markers', 'from_zone_id')) {
            Schema::table('signature_markers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('from_zone_id');
            });
        }

        Schema::dropIfExists('signature_zones');
    }
};
