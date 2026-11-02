<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-517 - the Co-applicant on one rental application. The contact picked as Co-applicant when the agent starts the
 * application (or created and linked from the review screen when the applicant typed the Co-applicant's details
 * themselves). Nullable: most applications have none, and nothing may block a save.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_applications', 'co_applicant_contact_id')) {
            Schema::table('rental_applications', function (Blueprint $table) {
                $table->unsignedBigInteger('co_applicant_contact_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_applications', 'co_applicant_contact_id')) {
            Schema::table('rental_applications', function (Blueprint $table) {
                $table->dropIndex(['co_applicant_contact_id']);
                $table->dropColumn('co_applicant_contact_id');
            });
        }
    }
};
