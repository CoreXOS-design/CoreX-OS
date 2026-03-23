<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            if (!Schema::hasColumn('flows', 'pack_id')) {
                $table->unsignedBigInteger('pack_id')->nullable()->after('template_id');
                $table->string('pack_type')->nullable()->after('pack_id'); // 'web' or 'pdf'
                $table->unsignedInteger('flow_sequence')->default(0)->after('pack_type');
                $table->unsignedBigInteger('parent_flow_id')->nullable()->after('flow_sequence');
                $table->string('pack_status')->nullable()->after('parent_flow_id'); // 'in_progress', 'completed'

                $table->index(['pack_id', 'flow_sequence']);
                $table->index(['parent_flow_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $columns = ['pack_id', 'pack_type', 'flow_sequence', 'parent_flow_id', 'pack_status'];
            $existing = array_filter($columns, fn($col) => Schema::hasColumn('flows', $col));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
