<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign compliance approval gate — spec .ai/specs/esign-compliance-approval-gate.md §5.1 / §5.3.
 *
 * Per-module RO / CO registry (ruling 3: one CO + unlimited ROs per module, for e-sign and for
 * compliance reporting). Mirrors the shape of fica_officer_appointments (which stays untouched):
 * an appointment row per person per role, ended by date, never deleted.
 *
 * Backfill: the legacy whistleblow_approver_user_ids JSON list becomes appointments — the first
 * listed admin (else super_admin, else branch manager) → CO, everyone else → RO — and
 * whistleblow_ro_can_submit is switched on whenever more than the CO was listed (or no CO could be
 * chosen), so everyone who could send onward yesterday still can today. The JSON column is left in
 * place for retention; nothing reads it for a decision any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('officer_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('module', 32);   // esign | whistleblow
            $table->string('role', 16);     // ro | co

            // Historical copies — the user row may change or leave.
            $table->string('full_name', 200);
            $table->string('email', 255)->nullable();

            $table->date('appointed_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'module', 'role', 'ended_on'], 'officer_appt_agency_module_role_idx');
            $table->index(['user_id', 'module', 'ended_on'], 'officer_appt_user_module_idx');
        });

        // The agencies column the backfill flips must exist before we run it. It is added by
        // 2026_09_14_100002 — but a fresh migrate runs files in name order, so guard here too.
        if (! Schema::hasColumn('agencies', 'whistleblow_ro_can_submit')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->boolean('whistleblow_ro_can_submit')->default(false)->after('whistleblow_tier_recipients');
            });
        }

        $this->backfillWhistleblowApprovers();
    }

    public function down(): void
    {
        Schema::dropIfExists('officer_appointments');
    }

    private function backfillWhistleblowApprovers(): void
    {
        if (! Schema::hasColumn('agencies', 'whistleblow_approver_user_ids')) {
            return;
        }

        $agencies = DB::table('agencies')
            ->whereNotNull('whistleblow_approver_user_ids')
            ->get(['id', 'whistleblow_approver_user_ids']);

        $today = now()->toDateString();

        foreach ($agencies as $agency) {
            $ids = json_decode((string) $agency->whistleblow_approver_user_ids, true);
            if (! is_array($ids)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) {
                continue;
            }

            $users = DB::table('users')
                ->whereIn('id', $ids)
                ->where('agency_id', $agency->id)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'email', 'branch_id', 'role'])
                ->keyBy('id');

            // The appointment is the authority (an appointed CO decides and sees every report
            // whatever their role), so the first listed approver who is still a member becomes the
            // CO — an admin or branch manager first when one is listed, only because that is who the
            // legacy list was for. Everyone else listed becomes an RO.
            $coId = null;
            foreach (['admin', 'super_admin', 'branch_manager', null] as $role) {
                foreach ($ids as $userId) {
                    $u = $users->get($userId);
                    if ($u && ($role === null || ($u->role ?? '') === $role)) {
                        $coId = (int) $u->id;
                        break 2;
                    }
                }
            }

            foreach ($ids as $userId) {
                $u = $users->get($userId);
                if (! $u) {
                    continue; // foreign or deleted id — never appoint it
                }
                DB::table('officer_appointments')->insert([
                    'agency_id'    => $agency->id,
                    'branch_id'    => $u->branch_id,
                    'user_id'      => $u->id,
                    'module'       => 'whistleblow',
                    'role'         => (int) $u->id === $coId ? 'co' : 'ro',
                    'full_name'    => $u->name,
                    'email'        => $u->email,
                    'appointed_on' => $today,
                    'appointed_by' => null,
                    'notes'        => 'Backfilled from the legacy approver list (2026-09-14).',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            // Everyone listed could send onward yesterday; ROs keep that right whenever the list had
            // more than the CO in it, or no CO could be chosen at all.
            if (count($ids) > 1 || $coId === null) {
                DB::table('agencies')->where('id', $agency->id)->update(['whistleblow_ro_can_submit' => true]);
            }
        }
    }
};
