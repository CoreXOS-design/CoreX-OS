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
 *
 * link() and unlink() are DELIBERATELY asymmetric on a role mismatch, per
 * the conductor's ruling, 2026-09-13 — this looks inconsistent at a glance,
 * so the reason is written down here rather than left to be "fixed" later:
 *   - link() CHANGES the role on the existing row, quietly and correctly,
 *     never throws. Johan's rule is that a contact takes exactly one role
 *     per property — arriving with a different role than the one already
 *     there is not an error, it's the normal case this rule describes.
 *   - unlink() THROWS on a role mismatch (ContactPropertyRoleMismatchException).
 *     Removing a link is destructive to the caller's belief about what
 *     they just did — if the role changed under them since they last read
 *     it, silently matching nothing would leave a live link the caller
 *     thinks is gone (e.g. a tenant staying attached to a property they
 *     moved out of, because the row had since become 'owner' and the
 *     'tenant'-scoped unlink quietly found nothing). Loud failure, not
 *     silent data loss of the caller's intent.
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
     * Soft-delete the one row for this pair. There is only ever one row per
     * (contactId, propertyId) — role is not part of the unique key, and its
     * value can have changed since the caller last read it (Johan: one
     * contact holds exactly one role per property, ever; the role on the
     * link changes rather than a new link being created).
     *
     * $expectedRole, when given, is an ASSERTION, not a filter — the
     * conductor's ruling, 2026-09-13: a mismatch throws
     * ContactPropertyRoleMismatchException rather than silently matching
     * nothing. A silent no-op here is exactly how a tenant stays attached
     * to a property they moved out of, if the row's role happened to have
     * changed to something else in the meantime — the caller believes the
     * link is gone and it never was.
     *
     * Returns the removed row (still readable — soft-deleted, not gone) so
     * the caller can log its role/id before it's out of scope, or null if
     * there was nothing to remove at all (an idempotent no-op — calling
     * unlink twice, or unlinking something never linked, is not an error).
     *
     * @throws ContactPropertyRoleMismatchException
     */
    public static function unlink(int $contactId, int $propertyId, ?string $expectedRole = null): ?ContactProperty
    {
        $row = ContactProperty::where('contact_id', $contactId)->where('property_id', $propertyId)->first();
        if ($row === null) {
            return null;
        }

        if ($expectedRole !== null && $row->role !== $expectedRole) {
            throw new \App\Exceptions\Property\ContactPropertyRoleMismatchException(
                contactId: $contactId,
                propertyId: $propertyId,
                expectedRole: $expectedRole,
                actualRole: (string) $row->role,
            );
        }

        $row->delete();

        return $row;
    }
}
