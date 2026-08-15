<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Rules\ExistsInScope;
use App\Services\ContactDuplicateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entity-type foundation (.ai/specs/contact-entity-type.md) — the entity
 * <-> natural-person representative link, reachable from BOTH ends of the
 * Contact edit screen: the entity's "Representatives" panel AND the natural
 * person's "Linked Entities" panel.
 *
 * ONE relationship, two views (Johan's explicit guardrail): every method
 * here reads/writes the SAME contact_representatives pivot regardless of
 * which page it was called from — $contact is always the ENTITY side of the
 * pivot and $representative/$representative_contact_id is always the
 * NATURAL-PERSON side, no matter whose "show" page the request originated
 * on. The natural-person page's forms simply submit with the ENTITY's id as
 * the route {contact} — there is no second link concept, no parallel table.
 */
class ContactRepresentativeController extends Controller
{
    /** Search natural-person contacts (AJAX JSON) for the ENTITY's representative picker. */
    public function search(Request $request, Contact $contact)
    {
        $q = trim($request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $existingIds = $contact->representatives()->pluck('contacts.id');

        $rows = Contact::withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
            ->where('agency_id', $contact->agency_id)
            ->where('contact_kind', Contact::TYPE_NATURAL_PERSON)
            ->whereNotIn('id', $existingIds)
            ->with(['phones', 'emails'])
            ->search($q)
            ->limit(10)
            ->get()
            ->map(fn (Contact $c) => $c->toSearchResult($q, [
                'name'  => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
            ]));

        return response()->json($rows);
    }

    /**
     * Search entity contacts (AJAX JSON) for the NATURAL PERSON's "Linked
     * Entities" picker — the mirror of search() above, opposite direction
     * over the same pivot.
     */
    public function searchEntities(Request $request, Contact $contact)
    {
        $q = trim($request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $existingIds = $contact->representedEntities()->pluck('contacts.id');

        $rows = Contact::withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
            ->where('agency_id', $contact->agency_id)
            ->where('contact_kind', Contact::TYPE_ENTITY)
            ->whereNotIn('id', $existingIds)
            ->search($q)
            ->limit(10)
            ->get()
            ->map(fn (Contact $c) => $c->toSearchResult($q, [
                'name'         => $c->full_name,
                'registration' => $c->entity_reg_no,
            ]));

        return response()->json($rows);
    }

    /** Link a natural-person Contact as a representative of this entity Contact. */
    public function link(Request $request, Contact $contact)
    {
        abort_unless($contact->isEntity(), 422, 'Representatives can only be linked to an entity contact.');

        $data = $request->validate([
            'representative_contact_id' => ['required', new ExistsInScope(Contact::class)],
            'is_primary'                => 'nullable|boolean',
            'capacity'                  => ['nullable', Rule::in(ContactRepresentative::CAPACITIES)],
            'signs_as_proxy'            => 'nullable|boolean',
        ]);

        $representative = Contact::findOrFail($data['representative_contact_id']);
        abort_unless(
            $representative->contact_kind === Contact::TYPE_NATURAL_PERSON,
            422,
            'Only a natural-person contact can be linked as a representative.'
        );
        abort_unless(
            (int) $representative->agency_id === (int) $contact->agency_id,
            422,
            'The representative must be in the same agency.'
        );

        $this->attachRepresentative(
            $contact,
            $representative,
            $request->boolean('is_primary'),
            $data['capacity'] ?? null,
            $request->boolean('signs_as_proxy'),
        );

        return back()->with('success', 'Representative linked.')->with('tab', 'info');
    }

    /**
     * Match-or-Create (Non-Negotiable #10) a natural-person Contact and link
     * it as a representative of this entity Contact in one step — the
     * ENTITY page's "create representative on the fly" flow.
     */
    public function createAndLinkRepresentative(Request $request, Contact $contact)
    {
        abort_unless($contact->isEntity(), 422, 'Representatives can only be linked to an entity contact.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'email'      => ['nullable', 'email', 'max:150'],
            'id_number'  => ['nullable', 'string', 'max:20'],
            'is_primary' => ['nullable', 'boolean'],
            'capacity'       => ['nullable', Rule::in(ContactRepresentative::CAPACITIES)],
            'signs_as_proxy' => ['nullable', 'boolean'],
        ]);
        $isPrimary    = (bool) ($data['is_primary'] ?? false);
        $capacity     = $data['capacity'] ?? null;
        $signsAsProxy = (bool) ($data['signs_as_proxy'] ?? false);
        unset($data['is_primary'], $data['capacity'], $data['signs_as_proxy']);

        $data['contact_kind'] = Contact::TYPE_NATURAL_PERSON;
        $representative = $this->resolveOrCreateContact($data, $contact->agency_id);

        $this->attachRepresentative($contact, $representative, $isPrimary, $capacity, $signsAsProxy);

        return back()->with('success', 'Representative created and linked.')->with('tab', 'info');
    }

    /**
     * Match-or-Create (Non-Negotiable #10) an entity Contact and link THIS
     * natural-person Contact as its representative in one step — the
     * NATURAL PERSON page's "create entity on the fly" flow.
     */
    public function createAndLinkEntity(Request $request, Contact $contact)
    {
        abort_if($contact->isEntity(), 422, 'Only a natural-person contact can be linked as a representative.');

        $data = $request->validate([
            'entity_name'   => ['required', 'string', 'max:255'],
            'entity_reg_no' => ['nullable', 'string', 'max:255'],
            'is_primary'    => ['nullable', 'boolean'],
            'capacity'       => ['nullable', Rule::in(ContactRepresentative::CAPACITIES)],
            'signs_as_proxy' => ['nullable', 'boolean'],
        ]);
        $isPrimary    = (bool) ($data['is_primary'] ?? false);
        $capacity     = $data['capacity'] ?? null;
        $signsAsProxy = (bool) ($data['signs_as_proxy'] ?? false);
        unset($data['is_primary'], $data['capacity'], $data['signs_as_proxy']);

        $data['contact_kind'] = Contact::TYPE_ENTITY;
        $data['first_name']   = '';
        $data['last_name']    = '';
        $entity = $this->resolveOrCreateContact($data, $contact->agency_id);

        $this->attachRepresentative($entity, $contact, $isPrimary, $capacity, $signsAsProxy);

        return back()->with('success', 'Entity created and linked.')->with('tab', 'info');
    }

    /** Unlink a representative from an entity Contact (soft-delete — Non-Negotiable #1). Symmetric: works from either page. */
    public function unlink(Contact $contact, Contact $representative)
    {
        $contact->representatives()->detach($representative->id);

        return back()->with('success', 'Link removed.')->with('tab', 'info');
    }

    /**
     * Edit the capacity / primary / proxy on an EXISTING entity<->rep link
     * without re-linking. {contact} is the ENTITY, {representative} the natural
     * person. Enforces single-proxy + single-primary per entity (below).
     */
    public function updateRepresentative(Request $request, Contact $contact, Contact $representative)
    {
        abort_unless($contact->isEntity(), 422, 'Representatives can only be edited on an entity contact.');
        abort_unless(
            $contact->representatives()->where('contacts.id', $representative->id)->exists(),
            404,
            'That contact is not a representative of this entity.'
        );

        $data = $request->validate([
            'is_primary'     => ['nullable', 'boolean'],
            'capacity'       => ['nullable', Rule::in(ContactRepresentative::CAPACITIES)],
            'signs_as_proxy' => ['nullable', 'boolean'],
        ]);

        $isPrimary    = $request->boolean('is_primary');
        $signsAsProxy = $request->boolean('signs_as_proxy');

        if ($isPrimary) {
            $this->demotePivotFlag($contact, 'is_primary');
        }
        if ($signsAsProxy) {
            $this->demotePivotFlag($contact, 'signs_as_proxy');
        }

        $contact->representatives()->updateExistingPivot($representative->id, [
            'is_primary'     => $isPrimary,
            'capacity'       => $data['capacity'] ?? null,
            'signs_as_proxy' => $signsAsProxy,
        ]);

        return back()->with('success', 'Representative updated.')->with('tab', 'info');
    }

    /**
     * Shared attach step for every link path (direct link, create-on-the-fly
     * from either side). $entity/$representative are ALWAYS resolved to the
     * correct pivot side before this runs — never swapped.
     */
    private function attachRepresentative(
        Contact $entity,
        Contact $representative,
        bool $isPrimary,
        ?string $capacity = null,
        bool $signsAsProxy = false
    ): void {
        if ($isPrimary) {
            // Only one primary signatory per entity — demote any existing one.
            $this->demotePivotFlag($entity, 'is_primary');
        }
        if ($signsAsProxy) {
            // Only one proxy per entity — the proxy signs for ALL reps, so a
            // second proxy is contradictory. Demote any existing one.
            $this->demotePivotFlag($entity, 'signs_as_proxy');
        }

        // syncWithoutDetaching() issues a raw INSERT for a pair not already
        // present — but the pivot's unique index doesn't exclude soft-deleted
        // rows, so re-linking a PREVIOUSLY unlinked pair would hit a duplicate-
        // key error. Restore-or-create explicitly instead (Non-Negotiable #1 —
        // soft delete, never lose the audit row; withTrashed() sees it).
        $pivot = ContactRepresentative::withTrashed()
            ->where('entity_contact_id', $entity->id)
            ->where('representative_contact_id', $representative->id)
            ->first();

        if ($pivot) {
            $pivot->restore();
            $pivot->update([
                'is_primary'     => $isPrimary,
                'capacity'       => $capacity,
                'signs_as_proxy' => $signsAsProxy,
            ]);
        } else {
            ContactRepresentative::create([
                'entity_contact_id'         => $entity->id,
                'representative_contact_id' => $representative->id,
                'is_primary'                => $isPrimary,
                'capacity'                  => $capacity,
                'signs_as_proxy'            => $signsAsProxy,
            ]);
        }
    }

    /**
     * Demote a single-per-entity pivot flag (is_primary / signs_as_proxy) on
     * every current representative of the entity, so a newly-set flag is unique.
     */
    private function demotePivotFlag(Contact $entity, string $flag): void
    {
        foreach ($entity->representatives()->pluck('contacts.id') as $existingId) {
            $entity->representatives()->updateExistingPivot($existingId, [$flag => false]);
        }
    }

    /**
     * Match-or-Create a Contact (Non-Negotiable #10) — mirrors the same
     * dedup-then-create shape as DealRegisterController::contactInline() /
     * EvaluationCertificateController::contactInline() (entity dedups on
     * entity_reg_no, natural person on phone/email/id_number — both already
     * wired into ContactDuplicateService::findDuplicates()). Auto-links to
     * the first match rather than surfacing a 409 picker — this endpoint is
     * reached from an inline "create on the fly" action, not a dedicated
     * duplicate-review screen, so silently reusing an existing match is the
     * correct, lower-friction behaviour here.
     */
    private function resolveOrCreateContact(array $data, int $agencyId): Contact
    {
        $service = app(ContactDuplicateService::class);
        $dupes = $service->findDuplicates($data, $agencyId);
        if ($dupes->isNotEmpty()) {
            return $dupes->first();
        }

        $data['agency_id']           = $agencyId;
        $data['created_by_user_id']  = auth()->id();

        return Contact::create($data);
    }
}
