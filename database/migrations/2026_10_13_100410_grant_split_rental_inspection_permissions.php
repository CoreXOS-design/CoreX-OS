<?php

use App\Models\RolePermission;
use Illuminate\Database\Migrations\Migration;

/**
 * .ai/specs/rental-inspections.md §45.8 (Build I-6b) — `rental_inspections.create` used to gate nearly every
 * write on an inspection: editing its details, cancelling, rescheduling, archiving, restoring, issuing the public
 * link. Those are now separate permissions, so an agency can give (or withhold) each on its own.
 *
 * So that NOBODY LOSES AN ACTION THEY HAVE TODAY, every agency/role that holds `.create` is granted the six
 * equivalents (same scope as its `.create` row), and every agency/role that holds `.view` is granted `.export`
 * (listing/printing was never behind `.create`). The one deliberate exception: archiving a COMPLETED, signed
 * inspection — evidence — is the new `.archive_completed`, granted only to the roles that already hold
 * `.resolve_discrepancy` (the managerial tier). Everyone else loses exactly that one thing, as ruled.
 *
 * BUILD_STANDARD §5a — role_permissions has a unique (role, permission_key, agency_id) index with no soft-delete
 * awareness, so rows are found with withTrashed()->firstOrNew() and restored, never blindly created. Idempotent.
 */
return new class extends Migration
{
    private const FROM_CREATE = [
        'rental_inspections.edit_details', 'rental_inspections.archive', 'rental_inspections.restore',
        'rental_inspections.cancel', 'rental_inspections.reschedule', 'rental_inspections.public_link',
    ];

    public function up(): void
    {
        $this->grant('rental_inspections.create', self::FROM_CREATE);
        $this->grant('rental_inspections.view', ['rental_inspections.export']);
        $this->grant('rental_inspections.resolve_discrepancy', ['rental_inspections.archive_completed']);
    }

    /** @param array<int, string> $newKeys */
    private function grant(string $sourceKey, array $newKeys): void
    {
        foreach (RolePermission::where('permission_key', $sourceKey)->get(['agency_id', 'role', 'scope']) as $source) {
            foreach ($newKeys as $key) {
                $row = RolePermission::withTrashed()->firstOrNew([
                    'agency_id' => $source->agency_id,
                    'role' => $source->role,
                    'permission_key' => $key,
                ]);
                if ($row->trashed()) {
                    $row->restore();
                }
                if (! $row->exists) {
                    $row->scope = $source->scope;
                }
                $row->save();
            }
        }
    }

    public function down(): void
    {
        RolePermission::whereIn('permission_key', array_merge(self::FROM_CREATE, ['rental_inspections.export', 'rental_inspections.archive_completed']))->delete();
    }
};
