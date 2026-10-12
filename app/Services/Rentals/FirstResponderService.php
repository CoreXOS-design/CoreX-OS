<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\RentalFirstResponder;
use App\Models\RentalFirstResponderChange;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.2 - "Who looks at faults first": ONE place that reads, resolves and saves the rule.
 *
 * Which rule is in effect for a fault of type T (forProperty): the property's own entries that cover T; if there are none, the owner's default entries
 * that cover T (the property's landlord contacts, primary first); if there are none, nobody. A property entry therefore overrides the owner's for the
 * fault types it covers and the owner's default still covers the rest. Entries keep their saved order; a supplier that is archived or inactive is never
 * offered. Nothing here sends anything - the fault flow (cc6) reads {@see self::forProperty()} through Property::firstRespondersFor().
 */
class FirstResponderService
{
    private const LANDLORD_ROLES = ['landlord', 'owner', 'seller', 'lessor'];

    // ── reading ──────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return Collection<int, RentalFirstResponder> the property's own list, in order */
    public function propertyEntries(Property $property): Collection
    {
        return $this->entries('property_id', (int) $property->id, (int) $property->agency_id);
    }

    /** @return Collection<int, RentalFirstResponder> the owner's default list, in order */
    public function ownerEntries(Contact $contact): Collection
    {
        return $this->entries('contact_id', (int) $contact->id, (int) $contact->agency_id);
    }

    private function entries(string $column, int $id, int $agencyId): Collection
    {
        return RentalFirstResponder::withoutGlobalScopes()->where('agency_id', $agencyId)->where($column, $id)->whereNull('deleted_at')
            ->with('supplier')->orderBy('position')->orderBy('id')->get();
    }

    /** The property's landlord-side contacts (strict roles, no guessing), primary first. @return Collection<int, Contact> */
    public function landlordsOf(Property $property): Collection
    {
        return $property->contacts()->get()
            ->filter(fn ($c) => in_array(strtolower(trim((string) ($c->pivot->role ?? ''))), self::LANDLORD_ROLES, true))
            ->sortBy([fn ($a, $b) => (int) ($b->pivot->is_primary ?? 0) <=> (int) ($a->pivot->is_primary ?? 0), fn ($a, $b) => $a->id <=> $b->id])
            ->values();
    }

    /**
     * The owner whose default applies to this property, with that owner's entries: the first landlord (primary first) who has a default list.
     *
     * @return array{contact: ?Contact, entries: Collection<int, RentalFirstResponder>}
     */
    public function ownerDefaultFor(Property $property): array
    {
        foreach ($this->landlordsOf($property) as $landlord) {
            $entries = $this->ownerEntries($landlord);
            if ($entries->isNotEmpty()) {
                return ['contact' => $landlord, 'entries' => $entries];
            }
        }

        return ['contact' => null, 'entries' => collect()];
    }

    /**
     * THE rule: who looks first at a fault of this type on this property, in order. Each item:
     * supplier_id, name, kind, kind_label, tell_mode, tell_label, source (property|owner), source_label, position, callout_line, phone, has_contact.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forProperty(Property $property, RentalFaultType|int|null $faultType = null): Collection
    {
        $typeId = $faultType instanceof RentalFaultType ? (int) $faultType->id : ($faultType !== null ? (int) $faultType : null);

        $own = $this->usable($this->propertyEntries($property))->filter(fn ($e) => $e->covers($typeId))->values();
        if ($own->isNotEmpty()) {
            return $own->map(fn ($e) => $this->item($e, 'property', 'Set for this property'))->values();
        }

        $owner = $this->ownerDefaultFor($property);
        $theirs = $this->usable($owner['entries'])->filter(fn ($e) => $e->covers($typeId))->values();
        if ($theirs->isEmpty()) {
            return collect();
        }
        $who = $owner['contact'] ? trim(($owner['contact']->first_name ?? '') . ' ' . ($owner['contact']->last_name ?? '')) : '';

        return $theirs->map(fn ($e) => $this->item($e, 'owner', 'From owner default' . ($who !== '' ? ' - ' . $who : '')))->values();
    }

    /** Entries whose supplier is still there and active (an archived or inactive supplier is never offered). */
    private function usable(Collection $entries): Collection
    {
        return $entries->filter(fn ($e) => $e->supplier && ! $e->supplier->trashed() && $e->supplier->is_active)->values();
    }

    private function item(RentalFirstResponder $e, string $source, string $sourceLabel): array
    {
        $s = $e->supplier;

        return [
            'supplier_id' => (int) $s->id,
            'name' => (string) $s->name,
            'kind' => (string) ($s->kind ?: AgencyServiceProvider::KIND_CONTRACTOR),
            'kind_label' => AgencyServiceProvider::kindLabel($s->kind),
            'tell_mode' => $e->tell_mode,
            'tell_label' => RentalFirstResponder::tellLabel($e->tell_mode),
            'source' => $source,
            'source_label' => $sourceLabel,
            'position' => (int) $e->position,
            // cc4 owns the supplier call-out fee fields; the wording is read from the supplier's own method once it exists ("call-out R750" / "no call-out fee" / "call-out fee not known").
            'callout_line' => method_exists($s, 'calloutFeeSuffix') ? ($s->calloutFeeSuffix() ?: null) : null,
            'phone' => $s->phone ?: null,
            'has_contact' => (bool) ($s->email || $s->phone),
        ];
    }

    /**
     * What is in effect on a property, for the panel's "In effect" line.
     *
     * @return array{source: string, owner: ?Contact, property_entries: Collection, owner_entries: Collection, owner_fills_rest: bool}
     */
    public function inEffect(Property $property): array
    {
        $own = $this->propertyEntries($property);
        $owner = $this->ownerDefaultFor($property);
        $source = $own->isNotEmpty() ? 'property' : ($owner['entries']->isNotEmpty() ? 'owner' : 'none');
        // the property only covers some fault types, and the owner's default covers others
        $ownCoversAll = $own->contains(fn ($e) => $e->fault_scope !== RentalFirstResponder::SCOPE_TYPES);

        return [
            'source' => $source,
            'owner' => $owner['contact'],
            'property_entries' => $own,
            'owner_entries' => $owner['entries'],
            'owner_fills_rest' => $source === 'property' && ! $ownCoversAll && $owner['entries']->isNotEmpty(),
        ];
    }

    /**
     * What the list editor offers: the agency's active suppliers who can look at a fault (never attorneys / bond originators), and its active fault types.
     *
     * @return array{suppliers: array<int, array<string, mixed>>, faultTypes: array<int, array<string, mixed>>}
     */
    public function editorData(int $agencyId): array
    {
        $suppliers = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', true)
            ->maintenanceContractors()->orderBy('name')->get(['id', 'name', 'kind', 'company'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name, 'kind' => (string) ($s->kind ?: AgencyServiceProvider::KIND_CONTRACTOR),
                'kind_label' => AgencyServiceProvider::kindLabel($s->kind), 'company' => $s->company])->values()->all();
        $types = RentalFaultType::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('category')->orderBy('name')->get(['id', 'name', 'category'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name, 'category' => $t->category])->values()->all();

        return ['suppliers' => $suppliers, 'faultTypes' => $types];
    }

    /** One entry as the editor's row (used to offer the owner's default as the starting point for a property). */
    public function editorRow(RentalFirstResponder $e): array
    {
        return ['supplier_id' => (int) $e->agency_service_provider_id, 'fault_scope' => $e->fault_scope, 'fault_type_ids' => array_map('intval', (array) $e->fault_type_ids), 'tell_mode' => $e->tell_mode];
    }

    // ── saving ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Save a whole list for a property or an owner. $rows = [['supplier_id'=>, 'fault_scope'=>'all'|'types', 'fault_type_ids'=>[], 'tell_mode'=>'inspect'|'info_only'], ...]
     * (at most 3, no supplier twice). Rows taken off the list are soft-deleted; the change (before / after, who, when, how agreed) is logged.
     *
     * @return bool whether anything changed
     */
    public function save(Property|Contact $subject, array $rows, User $by, ?string $agreedWith = null): bool
    {
        $agencyId = (int) $subject->agency_id;
        $isProperty = $subject instanceof Property;
        $column = $isProperty ? 'property_id' : 'contact_id';
        $existing = $isProperty ? $this->propertyEntries($subject) : $this->ownerEntries($subject);
        $clean = $this->validated($rows, $agencyId, $existing->pluck('agency_service_provider_id')->map(fn ($i) => (int) $i)->all());
        $before = $this->summary($existing, $agencyId);

        $changed = false;
        DB::transaction(function () use ($subject, $clean, $existing, $column, $agencyId, $by, $before, $agreedWith, &$changed) {
            $keep = [];
            foreach ($clean as $i => $row) {
                $match = $existing->first(fn ($e) => (int) $e->agency_service_provider_id === $row['supplier_id'] && ! isset($keep[$e->id]));
                $values = [
                    'position' => $i + 1, 'fault_scope' => $row['fault_scope'], 'fault_type_ids' => $row['fault_scope'] === RentalFirstResponder::SCOPE_TYPES ? $row['fault_type_ids'] : null,
                    'tell_mode' => $row['tell_mode'],
                ];
                if ($match) {
                    $keep[$match->id] = true;
                    $match->fill($values)->save();
                } else {
                    $new = new RentalFirstResponder($values + [$column => $subject->id, 'agency_service_provider_id' => $row['supplier_id'], 'created_by_user_id' => $by->id]);
                    $new->agency_id = $agencyId; // the subject's own agency (same as the acting user's for a scoped user)
                    $new->save();
                    $keep[$new->id] = true;
                }
            }
            foreach ($existing as $e) {
                if (! isset($keep[$e->id])) {
                    $e->delete();
                }
            }

            $after = $this->summary($column === 'property_id' ? $this->propertyEntries($subject) : $this->ownerEntries($subject), $agencyId);
            if ($after === $before) {
                return;
            }
            $changed = true;
            $log = new RentalFirstResponderChange([
                $column => $subject->id, 'changed_by_user_id' => $by->id, 'changed_at' => now(), 'old_summary' => $before, 'new_summary' => $after,
                'agreed_with' => $agreedWith !== null && trim($agreedWith) !== '' ? trim($agreedWith) : null,
            ]);
            $log->agency_id = $agencyId;
            $log->save();
        });

        return $changed;
    }

    /** @return array<int, array{supplier_id:int, fault_scope:string, fault_type_ids:array<int,int>, tell_mode:string}> */
    private function validated(array $rows, int $agencyId, array $alreadyOnList): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && (int) ($r['supplier_id'] ?? 0) > 0));
        if (count($rows) > RentalFirstResponder::MAX_ENTRIES) {
            throw ValidationException::withMessages(['first_responders' => 'You can name at most ' . RentalFirstResponder::MAX_ENTRIES . ' people or companies.']);
        }
        $typeIds = RentalFaultType::withoutGlobalScopes()->where('agency_id', $agencyId)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['supplier_id'];
            if (isset($seen[$sid])) {
                throw ValidationException::withMessages(['first_responders' => 'The same supplier is on the list twice. Each one can only be listed once.']);
            }
            $seen[$sid] = true;
            // a supplier the agency really has (never an attorney / bond originator - they do not look at faults), still active unless it was already on this list
            $ok = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->where('id', $sid)
                ->where(fn ($q) => $q->maintenanceContractors())
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $alreadyOnList))->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['first_responders' => 'One of the suppliers chosen is not in your supplier list.']);
            }
            $scope = ($r['fault_scope'] ?? '') === RentalFirstResponder::SCOPE_TYPES ? RentalFirstResponder::SCOPE_TYPES : RentalFirstResponder::SCOPE_ALL;
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($r['fault_type_ids'] ?? [])), fn ($i) => in_array($i, $typeIds, true))));
            if ($scope === RentalFirstResponder::SCOPE_TYPES && $ids === []) {
                throw ValidationException::withMessages(['first_responders' => 'Tick at least one type of fault, or choose "All faults".']);
            }
            $tell = ($r['tell_mode'] ?? '') === RentalFirstResponder::TELL_INFO_ONLY ? RentalFirstResponder::TELL_INFO_ONLY : RentalFirstResponder::TELL_INSPECT;
            $out[] = ['supplier_id' => $sid, 'fault_scope' => $scope, 'fault_type_ids' => $ids, 'tell_mode' => $tell];
        }

        return $out;
    }

    /** The list in plain English, one line per entry - what the change log stores before and after. */
    public function summary(Collection $entries, int $agencyId): string
    {
        if ($entries->isEmpty()) {
            return 'Nobody set';
        }
        $types = RentalFaultType::withoutGlobalScopes()->where('agency_id', $agencyId)->pluck('name', 'id');

        return $entries->sortBy('position')->values()->map(function ($e, $i) use ($types) {
            $s = $e->supplier;
            $for = $e->fault_scope === RentalFirstResponder::SCOPE_TYPES
                ? 'only ' . collect((array) $e->fault_type_ids)->map(fn ($id) => $types[(int) $id] ?? 'a removed fault type')->implode(', ')
                : 'all faults';

            return ($i + 1) . '. ' . ($s?->name ?? 'a removed supplier') . ' (' . AgencyServiceProvider::kindLabel($s?->kind) . ') - ' . $for . ' - ' . strtolower(RentalFirstResponder::tellLabel($e->tell_mode));
        })->implode("\n");
    }
}
