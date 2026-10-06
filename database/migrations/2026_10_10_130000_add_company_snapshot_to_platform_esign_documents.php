<?php

use App\Models\Platform\PlatformCompany;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sent Subscription Agreement keeps the RR Technologies letterhead + party details it was sent with
 * (spec .ai/specs/platform-company-profile.md §7a). `company_snapshot` = the non-sensitive company fields + logo version
 * at send time. Agreements already sent before this column existed are pinned to the company record as it is NOW.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('platform_esign_documents', 'company_snapshot')) {
            Schema::table('platform_esign_documents', function (Blueprint $t) {
                $t->json('company_snapshot')->nullable();
            });
        }

        if (Schema::hasTable('platform_company') && DB::table('platform_company')->exists()) {
            $snap = json_encode(PlatformCompany::current()->snapshot());
            DB::table('platform_esign_documents')->whereNotNull('wording_version_id')->whereNull('company_snapshot')
                ->update(['company_snapshot' => $snap]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_esign_documents', 'company_snapshot')) {
            Schema::table('platform_esign_documents', fn (Blueprint $t) => $t->dropColumn('company_snapshot'));
        }
    }
};
