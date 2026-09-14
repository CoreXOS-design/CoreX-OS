<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Rules\ExistsInScope;
use Illuminate\Http\Request;

class ContactPropertyController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesContactAccess;

    /** Search properties (AJAX JSON) for the link picker — by address/title/suburb. */
    public function search(Request $request, Contact $contact)
    {
        $q = trim($request->query('q', ''));

        $query = Property::whereNotIn('id', $contact->properties()->pluck('properties.id'))
            ->with('agent')
            ->latest() // newest-first (CoreX never deletes — current listings on top)
            ->limit(10);

        if ($q !== '') {
            $query->searchAddress($q);
        }

        return response()->json(
            $query->get()->map(fn ($p) => $p->toSearchResult([
                'title'   => $p->title,
                'address' => $p->buildDisplayAddress(),
                'price'   => $p->formattedPrice(),
            ]))
        );
    }

    /** Link a property to the contact. */
    public function link(Request $request, Contact $contact)
    {
        $this->authorizeContact($contact);
        $data = $request->validate([
            // ExistsInScope (not `exists:`) so AgencyScope is enforced — a property
            // id from another agency must NOT be linkable to this contact.
            'property_id' => ['required', new ExistsInScope(Property::class)],
            'role'        => 'nullable|string|max:50',
        ]);

        // If no explicit role, derive from contact type's esign_role
        $role = $data['role'] ?? null;
        if (empty($role)) {
            $esignRole = $contact->type?->esign_role;
            $roleMap = [
                'seller' => 'owner',
                'lessor' => 'lessor',
                'buyer' => 'buyer',
                'lessee' => 'tenant',
            ];
            $role = $roleMap[$esignRole] ?? null;
        }

        // AT-398 — the owner set behind an open deal cannot move underneath it.
        $property = Property::find((int) $data['property_id']);
        if ($property) {
            try {
                app(\App\Services\Property\PropertyOwnershipGuard::class)->assertCanLink($property, $role);
            } catch (\App\Exceptions\Property\OwnershipLockedException $e) {
                return back()->withErrors(['role' => $e->getMessage()])->with('tab', 'properties');
            }
        }

        // ContactPropertyLinker, not a bare syncWithoutDetaching() — Johan:
        // one contact holds exactly one role per property, ever ("if that
        // scenario happens the contact will be changed"). Restores the one
        // existing row (trashed or not) instead of ever blind-inserting a
        // second one. See .ai/specs/rental-applications.md, "The
        // contact_property hard-delete fix".
        $linkResult = \App\Services\Property\ContactPropertyLinker::link($contact->id, (int) $data['property_id'], $role);
        $alreadyLinked = ! $linkResult->isNew;

        // Auto-create seller live link if seller role
        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'])) {
            \App\Models\PropertySellerLink::ensureExists((int) $data['property_id'], $contact->id);
        }

        // Domain event — only on new link (not on no-op re-attach).
        // Spec: .ai/specs/corex-domain-events-spec.md
        if (!$alreadyLinked) {
            $property = Property::find((int) $data['property_id']);
            if ($property) {
                event(new \App\Events\Contact\ContactLinkedToProperty(
                    contact: $contact,
                    property: $property,
                    role: (string) ($role ?? 'unknown'),
                    actorUserId: auth()->id(),
                ));
            }
        }

        // A role change (or a restore into a new role) is a real business
        // event, not a silent field update — Johan's ruling, same as the
        // rental tenant-link path. This controller had NO audit trail at
        // all before this fix.
        $auditEventType = $linkResult->isNew ? 'linked' : ($linkResult->roleChanged ? 'role_changed' : 'relinked_no_op');
        app(\App\Services\Audit\ContactAuditService::class)->log(
            $contact,
            eventCategory: 'contact_property',
            eventType: $auditEventType,
            user: auth()->user(),
            oldValues: $linkResult->roleChanged ? ['property_id' => (int) $data['property_id'], 'role' => $linkResult->previousRole] : null,
            newValues: ['property_id' => (int) $data['property_id'], 'role' => $role],
            humanSummary: $linkResult->roleChanged
                ? "Role on property #{$data['property_id']} changed from {$linkResult->previousRole} to " . ($role ?? 'unknown')
                : 'Linked to property #' . $data['property_id'] . ' as ' . ($role ?? 'unknown'),
        );

        return back()->with('success', 'Property linked to contact.')->with('tab', 'properties');
    }

    /** Unlink a property from the contact. */
    public function unlink(Contact $contact, Property $property)
    {
        $this->authorizeContact($contact);

        // AT-398 — the owner set behind an open deal cannot move underneath it.
        try {
            app(\App\Services\Property\PropertyOwnershipGuard::class)->assertCanUnlink($property, $contact->id);
        } catch (\App\Exceptions\Property\OwnershipLockedException $e) {
            return back()->withErrors(['contact' => $e->getMessage()])->with('tab', 'properties');
        }

        // Soft-delete via ContactPropertyLinker — Johan: "corex is a no
        // delete system." See .ai/specs/rental-applications.md, "The
        // contact_property hard-delete fix". This controller had NO audit
        // trail at all before this fix.
        $removed = \App\Services\Property\ContactPropertyLinker::unlink($contact->id, $property->id);
        if ($removed !== null) {
            app(\App\Services\Audit\ContactAuditService::class)->log(
                $contact,
                eventCategory: 'contact_property',
                eventType: 'unlinked',
                user: auth()->user(),
                oldValues: ['property_id' => $property->id, 'role' => $removed->role],
                newValues: ['property_id' => null],
                humanSummary: 'Unlinked from property #' . $property->id . ' (was ' . ($removed->role ?? 'unknown') . ')',
            );
        }

        return back()->with('success', 'Property unlinked.')->with('tab', 'properties');
    }
}
