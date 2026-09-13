<?php

declare(strict_types=1);

namespace App\Services\Property;

use App\Models\ContactProperty;

/**
 * The one place every contact_property write goes through. Johan: one
 * contact holds exactly one role per property, ever — "if that scenario
 * happens the contact will be changed." This restores the single existing
 * row (trashed or not) rather than ever inserting a second one, and tells
 * the caller exactly what happened (new link / restore / role change) so
 * the audit trail can record a role change as the real business event
 * Johan says it is, not a silent field update.
 *
 * Generalises Deal::properties()'s own withTrashed()-then-restore-or-create
 * convention (app/Models/Deal.php) into one place, because contact_property
 * has roughly 15 write call sites, not two — see .ai/specs/
 * rental-applications.md, "The contact_property hard-delete fix", for the
 * full investigation this exists to close.
 *
 * BUILD_STANDARD.md §5a: any table combining a UNIQUE constraint with
 * SoftDeletes needs its write path proven via create -> soft-delete ->
 * recreate, not just a fresh-fixture happy path — see
 * tests/Unit/Services/Property/ContactPropertyLinkerTest.php.
 */
class ContactPropertyLinker
{
    /**
     * @param  array<string, mixed>  $extra  additional pivot columns (e.g. 'source', 'is_primary')
     */
    public static function link(int $contactId, int $propertyId, ?string $role, array $extra = []): ContactPropertyLinkResult
    {
        $existing = ContactProperty::withTrashed()
            ->where('contact_id', $contactId)
            ->where('property_id', $propertyId)
            ->first();

        if ($existing === null) {
            $row = ContactProperty::create(array_merge([
                'contact_id' => $contactId,
                'property_id' => $propertyId,
                'role' => $role,
            ], $extra));

            return new ContactPropertyLinkResult(
                row: $row,
                isNew: true,
                wasRestored: false,
                roleChanged: false,
                previousRole: null,
            );
        }

        $wasTrashed = $existing->trashed();
        $previousRole = $existing->role;
        $roleChanged = $previousRole !== $role;

        if ($wasTrashed) {
            $existing->restore();
        }
        if ($roleChanged || $extra !== []) {
            $existing->fill(array_merge(['role' => $role], $extra));
            $existing->save();
        }

        return new ContactPropertyLinkResult(
            row: $existing,
            isNew: false,
            wasRestored: $wasTrashed,
            roleChanged: $roleChanged,
            previousRole: $roleChanged ? $previousRole : null,
        );
    }

    /**
     * Soft-delete the one row for this pair. Pass $role to only remove a
     * link if it currently holds that role (mirrors the rental-application
     * tenant unlink's own scoping) — omit it to remove whatever role is
     * there. Returns the removed row (still readable — soft-deleted, not
     * gone) so the caller can log its role/id before it's out of scope, or
     * null if there was nothing to remove.
     */
    public static function unlink(int $contactId, int $propertyId, ?string $role = null): ?ContactProperty
    {
        $query = ContactProperty::where('contact_id', $contactId)->where('property_id', $propertyId);
        if ($role !== null) {
            $query->where('role', $role);
        }

        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $row->delete();

        return $row;
    }
}
