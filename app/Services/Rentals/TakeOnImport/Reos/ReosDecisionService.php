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
     * @param array<string,mixed> $in  selected?, property_create?, suburb_id?, owner?{action,contact_id}, tenant?{...}, agent_user_id?, tenant_wallet_ref?
     * @throws ValidationException
     */
    public function apply(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, array $in): RentalTakeOnImportRow
    {
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
            $suburbChanged = true;
        }

        foreach (['owner', 'tenant'] as $party) {
            if (isset($in[$party]) && is_array($in[$party])) {
                $d[$party] = $this->party($in[$party], $agencyId);
            }
        }

        if (array_key_exists('agent_user_id', $in)) {
            $id = $in['agent_user_id'] === null || $in['agent_user_id'] === '' ? null : (int) $in['agent_user_id'];
            if ($id !== null && ! collect($this->agents->users($agencyId))->contains('id', $id)) {
                throw ValidationException::withMessages(['agent_user_id' => 'Pick the agent from the list.']);
            }
            $d['agent_user_id'] = $id;
            $d['agent_note'] = $id ? 'Chosen by you.' : ($d['agent_note'] ?? null);
        }

        if (array_key_exists('tenant_wallet_ref', $in)) {
            $ref = trim((string) $in['tenant_wallet_ref']);
            if (mb_strlen($ref) > 100) {
                throw ValidationException::withMessages(['tenant_wallet_ref' => 'A wallet reference is at most 100 characters.']);
            }
            $d['tenant_wallet_ref'] = $ref === '' ? null : $ref;
        }

        $row->decisions_json = $d;
        if (array_key_exists('selected', $in)) {
            $row->selected = (bool) $in['selected'];
        }
        $row->save();

        // A suburb (or its removal) can change whether CoreX already has the property.
        return $suburbChanged ? $this->resolver->rematchProperty($row) : ReosBlockers::refresh($row);
    }

    /**
     * Bulk actions over the whole run ("Tick all ready", "Apply suburb to all selected"…), optionally limited to the list's
     * current filter. Returns how many rows were changed.
     *
     * @param array<string,mixed> $filters  ReosRowQuery filters
     * @param array<string,mixed> $args     suburb_id | agent_user_id
     */
    public function bulk(RentalTakeOnImportRun $run, string $action, array $filters, array $args): int
    {
        $base = fn () => ReosRowQuery::apply(
            RentalTakeOnImportRow::query()->where('run_id', $run->id)->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED),
            $filters
        );
        $changed = 0;

        switch ($action) {
            case 'tick_ready':
                $changed = $base()->where('is_blocked', false)->where('selected', false)->update(['selected' => true]);
                break;
            case 'tick_all':
                // Rows the file itself rejected can never be imported; they are not ticked.
                $changed = $base()->where('status', '!=', RentalTakeOnImportRow::STATUS_ERROR)->where('selected', false)->update(['selected' => true]);
                break;
            case 'untick_all':
                $changed = $base()->where('selected', true)->update(['selected' => false]);
                break;
            case 'apply_suburb':
                $changed = $this->applyToSelected($run, fn (RentalTakeOnImportRow $r) => $r->existing_lease_id === null && $r->property_match_action !== RentalTakeOnImportRow::ACTION_MATCH && $r->status !== RentalTakeOnImportRow::STATUS_ERROR,
                    ['suburb_id' => $args['suburb_id'] ?? null]);
                break;
            case 'apply_agent':
                $changed = $this->applyToSelected($run, fn (RentalTakeOnImportRow $r) => $r->status !== RentalTakeOnImportRow::STATUS_ERROR,
                    ['agent_user_id' => $args['agent_user_id'] ?? null]);
                break;
            default:
                throw ValidationException::withMessages(['action' => 'That bulk action is not recognised.']);
        }

        return (int) $changed;
    }

    private function applyToSelected(RentalTakeOnImportRun $run, \Closure $eligible, array $values): int
    {
        if (($values['suburb_id'] ?? 'x') === null || ($values['agent_user_id'] ?? 'x') === null) {
            throw ValidationException::withMessages(['value' => 'Pick a value first.']);
        }
        $n = 0;
        RentalTakeOnImportRow::query()
            ->where('run_id', $run->id)->where('selected', true)->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($run, $eligible, $values, &$n) {
                foreach ($rows as $r) {
                    if ($eligible($r)) {
                        $this->apply($r, $run, $values);
                        $n++;
                    }
                }
            });

        return $n;
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
