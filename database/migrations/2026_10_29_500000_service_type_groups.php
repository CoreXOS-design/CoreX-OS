<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 13 Oct 2026: "if I add a service type in settings now there's no way to say if it's property sales or your trades".
 *
 * (1) agency_service_type_groups - an agency's own groups for its service types (soft delete, order). Seeded per agency with the two groups that already existed as hard-coded
 *     labels on the supplier form: "Your trades" and "Property sales".
 * (2) agency_service_types.group_id - every existing service type goes in "Your trades".
 * (3) agency_service_types.legacy_specialty - the property-sales ROLES (transfer attorney, bond attorney, conveyancer, bond originator, external agency) become real rows in
 *     "Property sales". Their stored value stays EXACTLY what it was (`agency_service_providers.specialty` = that string, which the deals pipelines, the DR attorney matching and
 *     the attorney / bond-originator logic key on): `legacy_specialty` records which stored value a role row stands for. A role row is never ticked on a supplier and never offered
 *     in the work-order / pipeline dropdowns.
 * Additive and re-runnable: nothing is deleted or changed except group_id on rows that have none.
 */
return new class extends Migration
{
    private const ROLES = [
        ['transfer_attorney', 'Transfer Attorney'], ['bond_attorney', 'Bond Attorney'], ['conveyancer', 'Conveyancer'],
        ['bond_originator', 'Bond Originator'], ['external_agency', 'External Agency'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('agency_service_type_groups')) {
            Schema::create('agency_service_type_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('code', 40);
                $t->string('label', 100);
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->softDeletes();
                $t->index(['agency_id', 'code']);
            });
        }
        Schema::table('agency_service_types', function (Blueprint $t) {
            if (! Schema::hasColumn('agency_service_types', 'group_id')) {
                $t->unsignedBigInteger('group_id')->nullable()->index();
            }
            if (! Schema::hasColumn('agency_service_types', 'legacy_specialty')) {
                $t->string('legacy_specialty', 40)->nullable();
            }
        });

        $now = now();
        foreach (DB::table('agencies')->pluck('id') as $agencyId) {
            $trades = $this->group($agencyId, 'trades', 'Your trades', 1, $now);
            $sales = $this->group($agencyId, 'property_sales', 'Property sales', 2, $now);

            DB::table('agency_service_types')->where('agency_id', $agencyId)->whereNull('group_id')->update(['group_id' => $trades]);

            $sort = (int) DB::table('agency_service_types')->where('agency_id', $agencyId)->max('sort_order');
            foreach (self::ROLES as [$code, $label]) {
                $existing = DB::table('agency_service_types')->where('agency_id', $agencyId)->where('code', $code)->first();
                if ($existing) {
                    continue;   // an agency's own type that happens to share the code is left exactly as it is
                }
                DB::table('agency_service_types')->insert([
                    'agency_id' => $agencyId, 'code' => $code, 'label' => $label, 'sort_order' => ++$sort, 'is_active' => true,
                    'group_id' => $sales, 'legacy_specialty' => $code, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function group(int $agencyId, string $code, string $label, int $sort, $now): int
    {
        $row = DB::table('agency_service_type_groups')->where('agency_id', $agencyId)->where('code', $code)->first();
        if ($row) {
            return (int) $row->id;
        }

        return (int) DB::table('agency_service_type_groups')->insertGetId([
            'agency_id' => $agencyId, 'code' => $code, 'label' => $label, 'sort_order' => $sort, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // additive data + two columns + one table: left in place (a rollback would orphan the groups an agency has configured)
    }
};
