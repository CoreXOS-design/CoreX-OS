<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign evidence (Johan's signature-evidence rule, 9 Sep 2026): a repeat section acceptance used to
 * updateOrCreate over the same (signature_request_id, section_index) row, destroying the earlier initial_image /
 * initialled_at. Each acceptance (or rejection) is now its own row: `generation` counts them, the superseded row
 * is stamped `superseded_at` and kept, and ip/user agent are recorded per row. Existing rows are generation 1.
 *
 * The new unique key is added BEFORE the old one is dropped: the signature_request_id foreign key needs an index
 * that starts with that column at every moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section_acceptances', function (Blueprint $table) {
            if (! Schema::hasColumn('section_acceptances', 'generation')) {
                $table->unsignedInteger('generation')->default(1)->after('section_index');
                $table->timestamp('superseded_at')->nullable()->after('initial_image');
                $table->string('ip_address', 45)->nullable()->after('superseded_at');
                $table->text('user_agent')->nullable()->after('ip_address');
            }
        });

        Schema::table('section_acceptances', function (Blueprint $table) {
            $table->unique(['signature_request_id', 'section_index', 'generation'], 'section_accept_req_idx_gen_unique');
        });

        Schema::table('section_acceptances', function (Blueprint $table) {
            $table->dropUnique('section_accept_req_idx_unique');
        });
    }

    public function down(): void
    {
        // Intentionally one-way: collapsing generations back to one row would destroy evidence.
    }
};
