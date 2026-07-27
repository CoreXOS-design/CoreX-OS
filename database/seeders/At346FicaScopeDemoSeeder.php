<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

/**
 * AT-346 — demo fixtures proving per-user FICA visibility scoping (own/branch/company).
 *
 * Creates, inside ONE agency (so AgencyScope never hides the rows from each other),
 * three dedicated demo roles + users at the three tiers, two branches, four contacts,
 * and four FICA submissions arranged so the three logins visibly differ:
 *
 *   at346-agent@qa.test  (role at346_agent_own,   Branch A) → sees 2  (only its own requests)
 *   at346-bm@qa.test     (role at346_bm_branch,   Branch A) → sees 3  (all of Branch A)
 *   at346-admin@qa.test  (role at346_admin_all,   Branch A) → sees 4  (whole company)
 *
 * All logins share the password: Password123!
 *
 * Idempotent (updateOrInsert on stable keys) and self-contained: it invents its own
 * roles so it never mutates the agency's standard agent/branch_manager/admin roles.
 * QA1-only demo data — the weekly live→qa1 sync wipes it; re-run after a sync.
 */
class At346FicaScopeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $cols = function (string $table): array {
            $db = DB::getDatabaseName();
            return collect(DB::select(
                'SELECT COLUMN_NAME as name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, $table]
            ))->pluck('name')->all();
        };
        $only = fn (array $data, array $columns): array => array_intersect_key($data, array_flip($columns));

        $agencyId = (int) (DB::table('agencies')->orderBy('id')->value('id') ?? 1);
        $now = now();

        // ── Branches ───────────────────────────────────────────────────────────
        $branchCols = $cols('branches');
        $ensureBranch = function (string $name) use ($agencyId, $branchCols, $only, $now): int {
            $existing = DB::table('branches')->where('name', $name)
                ->when(in_array('agency_id', $branchCols, true), fn ($q) => $q->where('agency_id', $agencyId))
                ->value('id');
            if ($existing) {
                return (int) $existing;
            }
            return (int) DB::table('branches')->insertGetId($only([
                'name'       => $name,
                'agency_id'  => $agencyId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $branchCols));
        };
        $branchA = $ensureBranch('AT346 Branch A');
        $branchB = $ensureBranch('AT346 Branch B');

        // ── Roles (dedicated — never touches the standard agent/bm/admin roles) ──
        $roleCols = $cols('roles');
        $ensureRole = function (string $name, string $label) use ($agencyId, $roleCols, $only, $now) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name, 'agency_id' => $agencyId],
                $only([
                    'name'          => $name,
                    'label'         => $label,
                    'description'   => 'AT-346 FICA scope demo role',
                    'agency_id'     => $agencyId,
                    'is_owner'      => 0,
                    'can_be_deleted' => 1,
                    'sort_order'    => 900,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ], $roleCols)
            );
        };
        $ensureRole('at346_agent_own', 'AT346 Agent (own)');
        $ensureRole('at346_bm_branch', 'AT346 Branch Manager (branch)');
        $ensureRole('at346_admin_all', 'AT346 Admin (company)');

        // ── Role permissions: access to the page + the fica.view visibility tier ──
        $grant = function (string $role, string $key, ?string $scope) use ($agencyId, $now) {
            DB::table('role_permissions')->updateOrInsert(
                ['role' => $role, 'permission_key' => $key, 'agency_id' => $agencyId],
                ['scope' => $scope, 'updated_at' => $now, 'created_at' => $now]
            );
        };
        foreach (['at346_agent_own' => 'own', 'at346_bm_branch' => 'branch', 'at346_admin_all' => 'all'] as $role => $scope) {
            $grant($role, 'access_compliance', null); // reach the FICA page (route gate)
            $grant($role, 'fica.view', $scope);       // the own/branch/company tier
        }

        // ── Users ────────────────────────────────────────────────────────────────
        $userCols = $cols('users');
        $ensureUser = function (string $email, string $name, string $role, ?int $branchId) use ($agencyId, $userCols, $now): User {
            $u = User::withoutGlobalScopes()->where('email', $email)->first();
            if (! $u) {
                $u = new User();
                $u->email = $email;
                $u->password = Hash::make('Password123!');
            }
            $u->name = $name;
            $u->role = $role;
            $u->agency_id = $agencyId;
            if (in_array('branch_id', $userCols, true)) {
                $u->branch_id = $branchId;
            }
            if (in_array('email_verified_at', $userCols, true) && empty($u->email_verified_at)) {
                $u->email_verified_at = $now;
            }
            $u->save();
            // Belt-and-suspenders: force agency/branch directly in case a model hook
            // rewrote them (BelongsToAgency stamps from the null auth context).
            DB::table('users')->where('id', $u->id)->update(array_intersect_key([
                'agency_id' => $agencyId,
                'branch_id' => $branchId,
                'role'      => $role,
                'updated_at' => $now,
            ], array_flip($userCols)));
            return $u;
        };
        $uOwn   = $ensureUser('at346-agent@qa.test', 'AT346 Agent (own)',          'at346_agent_own', $branchA);
        $uBm    = $ensureUser('at346-bm@qa.test',    'AT346 Branch Manager (branch)', 'at346_bm_branch', $branchA);
        $uAdmin = $ensureUser('at346-admin@qa.test', 'AT346 Admin (company)',       'at346_admin_all', $branchA);

        // ── Contacts (so the list shows names) ────────────────────────────────────
        $contactCols = $cols('contacts');
        $ensureContact = function (string $marker, string $first, string $last, int $branchId, int $ownerId) use ($agencyId, $contactCols, $only, $now): int {
            $existing = DB::table('contacts')->where('email', $marker)
                ->when(in_array('agency_id', $contactCols, true), fn ($q) => $q->where('agency_id', $agencyId))
                ->value('id');
            if ($existing) {
                return (int) $existing;
            }
            return (int) DB::table('contacts')->insertGetId($only([
                'agency_id'          => $agencyId,
                'branch_id'          => $branchId,
                'first_name'         => $first,
                'last_name'          => $last,
                'email'              => $marker,
                'created_by_user_id' => $ownerId,
                'agent_id'           => $ownerId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ], $contactCols));
        };
        $c1 = $ensureContact('at346-c1@qa.test', 'Aya',   'Own-A',    $branchA, $uOwn->id);
        $c2 = $ensureContact('at346-c2@qa.test', 'Bo',    'Own-A',    $branchA, $uOwn->id);
        $c3 = $ensureContact('at346-c3@qa.test', 'Cara',  'Branch-A', $branchA, $uBm->id);
        $c4 = $ensureContact('at346-c4@qa.test', 'Dax',   'Branch-B', $branchB, $uAdmin->id);

        // ── FICA submissions (the records the tiers filter) ───────────────────────
        $subCols = $cols('fica_submissions');
        $ensureSub = function (string $token, int $contactId, int $requestedBy, int $branchId, string $status) use ($agencyId, $subCols, $only, $now) {
            DB::table('fica_submissions')->updateOrInsert(
                ['token' => $token],
                $only([
                    'contact_id'       => $contactId,
                    'agency_id'        => $agencyId,
                    'branch_id'        => $branchId,
                    'requested_by'     => $requestedBy,
                    'token'            => $token,
                    'token_expires_at' => $now->copy()->addDays(30),
                    'entity_type'      => 'natural',
                    'status'           => $status,
                    'updated_at'       => $now,
                    'created_at'       => $now,
                ], $subCols)
            );
        };
        $ensureSub('AT346-DEMO-S1', $c1, $uOwn->id,   $branchA, 'submitted'); // Branch A · agent's own
        $ensureSub('AT346-DEMO-S2', $c2, $uOwn->id,   $branchA, 'approved');  // Branch A · agent's own
        $ensureSub('AT346-DEMO-S3', $c3, $uBm->id,    $branchA, 'submitted'); // Branch A · the BM's own
        $ensureSub('AT346-DEMO-S4', $c4, $uAdmin->id, $branchB, 'submitted'); // Branch B · company only

        echo "AT-346 FICA scope demo seeded in agency {$agencyId} (branches A={$branchA}, B={$branchB}).\n";
        echo "Logins (Password123!):\n";
        echo "  at346-agent@qa.test  → OWN     → expects 2 FICA rows\n";
        echo "  at346-bm@qa.test     → BRANCH  → expects 3 FICA rows\n";
        echo "  at346-admin@qa.test  → COMPANY → expects 4 FICA rows\n";
    }
}
