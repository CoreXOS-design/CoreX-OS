<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Contact;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use Illuminate\Validation\ValidationException;

/**
 * AT-479 / rental-takeon-import.md R4 — the review screen's choices, validated and saved. Every change is saved the
 * moment it is made (the list is paginated and the choices must survive paging, reloads and a second visit), then the
 * row's blockers are worked out again. Plain-language messages only.
 */
class ReosDecisionService
{
    public function __construct(
        private readonly ReosRowResolver $resolver,
        private readonly ReosAgentMatcher $agents,
    ) {
    }

    /**
     * @param array<string,mixed> $in  property_create?, property_choice?, suburb_id?, owner?{action,contact_id}, tenant?{...}, confirm? (owner|tenant:
     *                                 "same person" / "use as is"), person?, agent_user_id?, tenant_wallet_ref?, retry? (try a failed lease again);
     * @throws ValidationException
     */
    public function apply(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, array $in): RentalTakeOnImportRow
    {
        $source = 'user';
        if ($row->status === RentalTakeOnImportRow::STATUS_CONFIRMED) {
            throw ValidationException::withMessages(['row' => 'This lease has already been imported — it can no longer be changed here.']);
        }

        $d = $row->decisions_json ?? [];
        $agencyId = (int) $run->agency_id;
        $suburbChanged = false;

        // Which CoreX property is this? One of the candidates the lookup found, or "none of these — create a new one".
        if (array_key_exists('property_choice', $in)) {
            $choice = $in['property_choice'];
            $candidates = collect($d['property']['candidates'] ?? []);
            if ($choice === 'new') {
                $d['property']['link_id'] = null;
                $d['property']['choice'] = 'new';
                $d['property']['create'] = true;
                $row->property_match_action = RentalTakeOnImportRow::ACTION_CREATE;
                $row->property_match_label = 'Not in CoreX — you chose to create a new property';
            } else {
                $picked = $candidates->firstWhere('id', (int) $choice);
                if (! $picked) {
                    throw ValidationException::withMessages(['property_choice' => 'Pick one of the properties listed, or "none of these".']);
                }
                $d['property']['link_id'] = (int) $picked['id'];
                $d['property']['choice'] = 'link';
                $row->property_match_action = RentalTakeOnImportRow::ACTION_MATCH;
                $row->property_match_label = 'In CoreX: ' . $picked['label'] . ($picked['suburb'] !== '' ? ' (' . $picked['suburb'] . ')' : '');
            }
        }

        if (array_key_exists('property_create', $in)) {
            $d['property']['create'] = (bool) $in['property_create'];
        }

        if (array_key_exists('suburb_id', $in)) {
            if ($in['suburb_id'] === null || $in['suburb_id'] === '') {
                $d['property'] = array_merge($d['property'] ?? [], ['suburb_id' => null, 'suburb' => null, 'town' => null, 'province' => null, 'city_id' => null, 'province_id' => null]);
            } else {
                $s = ReosSuburbLookup::find((int) $in['suburb_id']);
                if (! $s) {
                    throw ValidationException::withMessages(['suburb_id' => 'Pick a suburb from the list.']);
                }
                $d['property'] = array_merge($d['property'] ?? [], ['suburb_id' => $s['id'], 'suburb' => $s['suburb'], 'town' => $s['town'], 'province' => $s['province'], 'city_id' => $s['city_id'], 'province_id' => $s['province_id']]);
            }
            $d['property']['suburb_source'] = ($in['suburb_id'] === null || $in['suburb_id'] === '') ? null : $source;
            $suburbChanged = true;
        }

        foreach (['owner', 'tenant'] as $party) {
            if (isset($in[$party]) && is_array($in[$party])) {
                $people = $d[$party]['people'] ?? [];
                $d[$party] = array_merge($this->party($in[$party], $agencyId), ['people' => $people]);
                // Choosing the contact that matched, on purpose, is the same as saying "same person".
                $m = ReosQuestions::match($row, $party);
                if ($m && ReosQuestions::usesMatched($d[$party], $m)) {
                    $d[$party]['confirmed'] = true;
                }
                // The people belong to a COMPANY: if this party is no longer one (left out, a person linked, …) they go with it.
                $row->decisions_json = $d;
                if (! ReosCompanyPeople::isCompanyParty($row, $party)) {
                    $d[$party]['people'] = [];
                }
            }
        }

        if (isset($in['person']) && is_array($in['person'])) {
            $row->decisions_json = $d;
            $d = $this->personOp($row, $d, $agencyId, $in['person']);
        }

        if (! empty($in['confirm'])) {
            $party = (string) $in['confirm'];
            $m = in_array($party, ['owner', 'tenant'], true) ? ReosQuestions::match($row, $party) : null;
            if (! $m || ! ReosQuestions::usesMatched($d[$party] ?? [], $m)) {
                throw ValidationException::withMessages(['confirm' => 'There is nothing to confirm for that person any more.']);
            }
            $d[$party]['confirmed'] = true;
        }

        if (array_key_exists('agent_user_id', $in)) {
            $id = $in['agent_user_id'] === null || $in['agent_user_id'] === '' ? null : (int) $in['agent_user_id'];
            if ($id !== null && ! collect($this->agents->users($agencyId))->contains('id', $id)) {
                throw ValidationException::withMessages(['agent_user_id' => 'Pick the agent from the list.']);
            }
            $d['agent_user_id'] = $id;
            $d['agent_source'] = $id ? $source : null;
            $d['agent_note'] = $id ? 'Chosen by you.' : ($d['agent_note'] ?? null);
        }

        if (array_key_exists('tenant_wallet_ref', $in)) {
            $ref = trim((string) $in['tenant_wallet_ref']);
            if (mb_strlen($ref) > 100) {
                throw ValidationException::withMessages(['tenant_wallet_ref' => 'A wallet reference is at most 100 characters.']);
            }
            $d['tenant_wallet_ref'] = $ref === '' ? null : $ref;
        }

        if (! empty($in['retry']) && $row->import_error) {
            $row->import_error = null;
        }

        // The agent / suburb chosen "for all" reach every lease that has none of its own (and follow a change of that choice).
        $row->decisions_json = $d;
        [$d, $defaultSuburbChanged] = $this->fillDefaults($row, ReosRunDefaults::get($run));
        $suburbChanged = $suburbChanged || $defaultSuburbChanged;

        $row->decisions_json = $d;
        $row->save();

        // A suburb (or its removal) can change whether CoreX already has the property.
        return $suburbChanged ? $this->resolver->rematchProperty($row) : ReosBlockers::refresh($row);
    }

    /**
     * The batch's "for all" defaults (agent, suburb for new properties): save them on the run and give them to every waiting lease that
     * has no choice of its own — or whose current value came from the defaults. A lease whose agent came from reOS or from the user keeps it.
     *
     * @param array{agent_user_id?:?int, suburb_id?:?int} $in  only the keys present are changed
     * @return int  leases changed
     */
    public function setDefaults(RentalTakeOnImportRun $run, array $in): int
    {
        $agencyId = (int) $run->agency_id;
        $defaults = ReosRunDefaults::get($run);

        if (array_key_exists('agent_user_id', $in)) {
            $id = $in['agent_user_id'] === null || $in['agent_user_id'] === '' ? null : (int) $in['agent_user_id'];
            if ($id !== null && ! collect($this->agents->users($agencyId))->contains('id', $id)) {
                throw ValidationException::withMessages(['agent_user_id' => 'Pick the agent from the list.']);
            }
            $defaults['agent_user_id'] = $id;
        }
        if (array_key_exists('suburb_id', $in)) {
            $id = $in['suburb_id'] === null || $in['suburb_id'] === '' ? null : (int) $in['suburb_id'];
            $found = $id !== null ? ReosSuburbLookup::find($id) : null;
            if ($id !== null && ! $found) {
                throw ValidationException::withMessages(['suburb_id' => 'Pick a suburb from the list.']);
            }
            $defaults['suburb_id'] = $id;
            $defaults['suburb_label'] = $found ? trim($found['suburb'] . ($found['town'] ? ', ' . $found['town'] : '')) : null;
        }
        ReosRunDefaults::store($run, $defaults);

        $n = 0;
        RentalTakeOnImportRow::query()
            ->where('run_id', $run->id)->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($defaults, &$n) {
                foreach ($rows as $row) {
                    if ($row->status === RentalTakeOnImportRow::STATUS_ERROR) {
                        continue;
                    }
                    $before = $row->decisions_json;
                    [$d, $suburbChanged] = $this->fillDefaults($row, $defaults);
                    if ($d === $before) {
                        continue;
                    }
                    $row->decisions_json = $d;
                    $row->save();
                    $suburbChanged ? $this->resolver->rematchProperty($row) : ReosBlockers::refresh($row);
                    $n++;
                }
            });

        return $n;
    }

    /**
     * @param array<string,mixed> $defaults  ReosRunDefaults::get()
     * @return array{0: array<string,mixed>, 1: bool}  the row's decisions with the defaults laid in, and whether the suburb changed
     */
    private function fillDefaults(RentalTakeOnImportRow $row, array $defaults): array
    {
        $d = $row->decisions_json ?? [];
        $suburbChanged = false;
        if ($row->existing_lease_id !== null) {
            return [$d, false];   // a lease already in CoreX keeps its agents and its property
        }

        // Agent
        $own = ! empty($d['agent_user_id']) && ($d['agent_source'] ?? null) !== 'default';
        if (! $own) {
            $want = $defaults['agent_user_id'] ?? null;
            if ((int) ($d['agent_user_id'] ?? 0) !== (int) $want) {
                $d['agent_user_id'] = $want;
                $d['agent_source'] = $want ? 'default' : null;
                $d['agent_note'] = $want ? 'The agent chosen for all leases.' : ($d['agent_note'] ?? null);
            }
        }

        // Suburb — only for a property that is going to be created
        $creating = $row->property_match_action === RentalTakeOnImportRow::ACTION_CREATE && (bool) ($d['property']['create'] ?? true);
        $ownSuburb = ! empty($d['property']['suburb_id']) && ($d['property']['suburb_source'] ?? null) !== 'default';
        if ($creating && ! $ownSuburb) {
            $wantId = $defaults['suburb_id'] ?? null;
            if ((int) ($d['property']['suburb_id'] ?? 0) !== (int) $wantId) {
                $s = $wantId ? ReosSuburbLookup::find((int) $wantId) : null;
                $d['property'] = array_merge($d['property'] ?? [], $s
                    ? ['suburb_id' => $s['id'], 'suburb' => $s['suburb'], 'town' => $s['town'], 'province' => $s['province'], 'city_id' => $s['city_id'], 'province_id' => $s['province_id'], 'suburb_source' => 'default']
                    : ['suburb_id' => null, 'suburb' => null, 'town' => null, 'province' => null, 'city_id' => null, 'province_id' => null, 'suburb_source' => null]);
                $suburbChanged = true;
            }
        }

        return [$d, $suburbChanged];
    }

    /**
     * The company ↔ people choices of one owner / tenant (spec R6a): add (an existing person, or a new one), remove, change the
     * role, or the one-click "create the company and link the matched person as its contact person".
     *
     * @param array<string,mixed> $d  decisions
     * @param array<string,mixed> $in  party, op, and the op's fields
     * @return array<string,mixed> decisions
     */
    private function personOp(RentalTakeOnImportRow $row, array $d, int $agencyId, array $in): array
    {
        $party = (string) ($in['party'] ?? '');
        $op = (string) ($in['op'] ?? '');
        if (! in_array($party, ['owner', 'tenant'], true)) {
            throw ValidationException::withMessages(['person' => 'Say whether this is for the owner or the tenant.']);
        }
        $matches = $party === 'owner' ? $row->landlord_match_json : $row->tenant_match_json;
        $m = $matches[1] ?? null;
        $people = $d[$party]['people'] ?? [];
        $capacity = $in['capacity'] ?? null;
        if ($capacity !== null && $capacity !== '' && ! in_array($capacity, ReosCompanyPeople::capacities(), true)) {
            throw ValidationException::withMessages(['capacity' => 'Pick a role from the list.']);
        }

        if ($op === 'convert') {
            // The company matched a PERSON by phone / e-mail: create the company, and link that person to it — reused, not duplicated.
            $person = $m && ! empty($m['name_flag']) && $m['name_flag'] === ReosNameCompare::PERSON_FOR_COMPANY && ! empty($m['existing_contact_id'])
                ? Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $m['existing_contact_id']) : null;
            if (! $person || ReosContactNames::isCompanyContact($person)) {
                throw ValidationException::withMessages(['person' => 'This company did not match a person, so there is nobody to link.']);
            }
            $entry = ['contact_id' => (int) $person->id, 'label' => (string) $person->full_name, 'capacity' => ReosCompanyPeople::CONTACT_PERSON_CAPACITY, 'via' => 'matched_person'];
            $entry['key'] = ReosCompanyPeople::keyFor($entry);
            $kept = array_values(array_filter($people, fn ($p) => $p['key'] !== $entry['key']));
            $kept[] = $entry;
            $d[$party] = ['action' => 'create', 'contact_id' => null, 'people' => $kept];

            return $d;
        }

        if (! ReosCompanyPeople::isCompanyParty($row, $party)) {
            throw ValidationException::withMessages(['person' => 'People can only be added to a company.']);
        }

        if ($op === 'add') {
            if (count($people) >= 10) {
                throw ValidationException::withMessages(['person' => 'A company can have at most 10 people added here.']);
            }
            if (! empty($in['contact_id'])) {
                $c = Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find((int) $in['contact_id']);
                if (! $c || ReosContactNames::isCompanyContact($c)) {
                    throw ValidationException::withMessages(['contact_id' => 'Pick a person (not a company) from the list.']);
                }
                $entry = ['contact_id' => (int) $c->id, 'label' => (string) $c->full_name];
            } else {
                $first = trim((string) ($in['first_name'] ?? ''));
                $email = trim((string) ($in['email'] ?? ''));
                if ($first === '') {
                    throw ValidationException::withMessages(['first_name' => 'Give the person\'s name.']);
                }
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['email' => 'That e-mail address does not look right.']);
                }
                $entry = [
                    'first_name' => mb_substr($first, 0, 100),
                    'last_name' => mb_substr(trim((string) ($in['last_name'] ?? '')), 0, 100),
                    'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30),
                    'email' => mb_substr($email, 0, 150),
                ];
            }
            $entry['capacity'] = $capacity ?: 'Director';
            $entry['key'] = ReosCompanyPeople::keyFor($entry);
            $people = array_values(array_filter($people, fn ($p) => $p['key'] !== $entry['key']));   // adding the same person again just updates the role
            $people[] = $entry;
        } elseif ($op === 'remove') {
            $people = array_values(array_filter($people, fn ($p) => $p['key'] !== (string) ($in['key'] ?? '')));
        } elseif ($op === 'set_capacity') {
            if (! $capacity) {
                throw ValidationException::withMessages(['capacity' => 'Pick a role from the list.']);
            }
            foreach ($people as &$p) {
                if ($p['key'] === (string) ($in['key'] ?? '')) {
                    $p['capacity'] = $capacity;
                }
            }
            unset($p);
        } else {
            throw ValidationException::withMessages(['person' => 'That is not something that can be done with the people of a company.']);
        }

        $d[$party]['people'] = array_values($people);

        return $d;
    }

    /** @return array<string,mixed> */
    private function party(array $in, int $agencyId): array
    {
        $action = (string) ($in['action'] ?? '');
        if (! in_array($action, ['link', 'create', 'skip'], true)) {
            throw ValidationException::withMessages(['party' => 'Choose what to do with this person.']);
        }
        if ($action !== 'link') {
            return ['action' => $action, 'contact_id' => null];
        }

        $id = (int) ($in['contact_id'] ?? 0);
        $contact = $id > 0 ? Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->find($id) : null;
        if (! $contact) {
            throw ValidationException::withMessages(['contact_id' => 'Pick the contact from the list.']);
        }

        return ['action' => 'link', 'contact_id' => (int) $contact->id, 'label' => $contact->full_name];
    }
}
