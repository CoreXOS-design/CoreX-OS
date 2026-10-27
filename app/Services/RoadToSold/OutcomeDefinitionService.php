<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 — the agency's outcome list: seed, read, add, rename, re-time, reorder, switch off,
 * archive, restore. Spec: .ai/specs/road-to-sold.md §9. Never hard-deletes.
 */
class OutcomeDefinitionService
{
    /** @var array<string,bool> per-request memo of "already seeded" (agency|track). */
    private static array $seeded = [];

    /**
     * The seeded outcomes (Johan, 13 Oct; v3 ruling R6, 27 Oct). Keys are stable and stored; labels are editable.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'no_answer', 'label' => 'No answer', 'kind' => Def::KIND_ATTEMPT,
             'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 7, 'counts_as_no_answer' => true],
            ['key' => 'spoke_evaluation_booked', 'label' => 'Spoke - evaluation booked', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_APPOINTMENT, 'stage_after' => 'appointment_set'],
            ['key' => 'spoke_call_back', 'label' => 'Spoke - call back', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_PICKED_DATE, 'stage_after' => 'contacted'],
            ['key' => 'spoke_not_interested', 'label' => 'Spoke - not now, maybe later', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_AFTER_MONTHS, 'choice_months' => [3, 6, 12], 'default_months' => 6, 'stage_after' => 'contacted'],
            ['key' => 'listed_other_agency_open', 'label' => 'Listed with another agency - open mandate', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_EXPIRY_ELSE_DAYS, 'fallback_days' => 90, 'stage_after' => 'contacted'],
            ['key' => 'mandated_other_agency_sole', 'label' => 'Mandated with another agency - sole mandate', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_EXPIRY_ELSE, 'stage_after' => 'contacted'],
            ['key' => 'wrong_number', 'label' => 'Wrong number', 'kind' => Def::KIND_ATTEMPT,
             'next_action' => Def::ACTION_FIND_OWNER, 'delay_days' => 3, 'list_tag' => 'Find the right contact',
             'max_tries' => 2, 'after_max_tries' => Def::AFTER_PARK, 'park_reason_key' => 'no_owner_found'],
            ['key' => 'no_owner_found', 'label' => 'No owner found', 'kind' => Def::KIND_ATTEMPT,
             'next_action' => Def::ACTION_FIND_OWNER, 'delay_days' => 3, 'list_tag' => 'Find the right contact',
             'max_tries' => 2, 'after_max_tries' => Def::AFTER_PARK, 'park_reason_key' => 'no_owner_found'],
            ['key' => 'do_not_contact', 'label' => 'Do not contact', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_NEVER_AGAIN, 'records_do_not_contact' => true],
            // after the evaluation (road-to-sold.md 22.7)
            ['key' => 'eval_undecided', 'label' => 'Evaluation done - seller undecided', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 7, 'stage_after' => 'evaluation_done'],
            ['key' => 'eval_chose_other', 'label' => 'Seller chose another agency', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 90, 'park_reason_key' => 'chose_other_agency'],
            ['key' => 'eval_selling_privately', 'label' => 'Owner selling privately', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 30, 'park_reason_key' => 'not_selling_now'],
        ];
    }

    /** The outcomes that only make sense once the evaluation has been booked (hidden from the pop-up before that stage). */
    public const AFTER_EVALUATION_KEYS = ['eval_undecided', 'eval_chose_other', 'eval_selling_privately'];

    /**
     * The seeded values before v3, per key: a row still exactly like this has never been edited, so the upgrade may change it.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function previousDefaults(): array
    {
        return [
            'no_answer'                  => ['label' => 'No answer', 'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 7],
            'spoke_evaluation_booked'    => ['label' => 'Spoke - evaluation booked', 'next_action' => Def::ACTION_APPOINTMENT, 'delay_days' => null],
            'spoke_call_back'            => ['label' => 'Spoke - call back', 'next_action' => Def::ACTION_PICKED_DATE, 'delay_days' => null],
            'spoke_not_interested'       => ['label' => 'Spoke - not interested', 'next_action' => Def::ACTION_PICKED_DATE, 'delay_days' => null],
            'listed_other_agency_open'   => ['label' => 'Listed with another agency - open mandate', 'next_action' => Def::ACTION_PICKED_DATE, 'delay_days' => null],
            'mandated_other_agency_sole' => ['label' => 'Mandated with another agency - sole mandate', 'next_action' => Def::ACTION_EXPIRY_ELSE, 'delay_days' => null],
            'wrong_number'               => ['label' => 'Wrong number', 'next_action' => Def::ACTION_MOVE_TO_LIST, 'delay_days' => null],
            'do_not_contact'             => ['label' => 'Do not contact', 'next_action' => Def::ACTION_NEVER_AGAIN, 'delay_days' => null],
        ];
    }

    /**
     * Bring an agency's outcomes up to the v3 defaults without touching its edits: a row that is still exactly the old seed is updated; a
     * row the agency changed is left alone; the new outcomes are added when missing. $apply false = report only.
     *
     * @return array{updated:string[],added:string[],kept:string[]}
     */
    public function upgradeDefaults(int $agencyId, string $track = 'sales', bool $apply = false): array
    {
        $out = ['updated' => [], 'added' => [], 'kept' => []];
        if ($agencyId <= 0) {
            return $out;
        }
        $this->ensureDefaults($agencyId, $track);
        $rows = Def::withTrashed()->where('agency_id', $agencyId)->where('track', $track)->get()->keyBy('key');
        $max = (int) $rows->max('sort_order');
        $old = self::previousDefaults();

        foreach (self::defaults() as $new) {
            $row = $rows->get($new['key']);
            if (! $row) {
                $out['added'][] = $new['key'];
                if ($apply) {
                    $max += 10;
                    Def::create($new + ['agency_id' => $agencyId, 'track' => $track, 'is_active' => true, 'is_system' => true, 'sort_order' => $max]);
                }
                continue;
            }
            $was = $old[$new['key']] ?? null;
            $untouched = $row->is_system && $was !== null && $row->label === $was['label'] && $row->next_action === $was['next_action']
                && ($row->delay_days === $was['delay_days'] || (int) $row->delay_days === (int) $was['delay_days']);
            if (! $untouched) {
                $out['kept'][] = $new['key'];
                continue;
            }
            $out['updated'][] = $new['key'];
            if ($apply) {
                $row->forceFill(array_diff_key($new, ['key' => 1]))->save();
            }
        }

        return $out;
    }

    /**
     * The outcomes an agent may pick for a prospect at $stage: the evaluation-only ones appear once an appointment has been set.
     *
     * @return Collection<int, Def>
     */
    public function availableFor(int $agencyId, string $track = 'sales', ?string $stage = null): Collection
    {
        $all = $this->list($agencyId, $track);
        if ($stage !== null && ProspectStages::order($stage) >= ProspectStages::order(ProspectStages::APPOINTMENT_SET)) {
            return $all;
        }

        return $all->reject(fn (Def $d) => in_array($d->key, self::AFTER_EVALUATION_KEYS, true))->values();
    }

    /**
     * Seed the outcomes the first time an agency+track is used. Idempotent; a trashed or
     * switched-off row counts as "already seeded" (an agency that archived them keeps its choice).
     * agency_id <= 0 never writes (STANDARDS Rule 17).
     */
    public function ensureDefaults(int $agencyId, string $track = 'sales'): void
    {
        if ($agencyId <= 0) {
            return;
        }
        $memo = $agencyId . '|' . $track;
        if (isset(self::$seeded[$memo])) {
            return;
        }

        $exists = Def::withTrashed()->where('agency_id', $agencyId)->where('track', $track)->exists();
        if (! $exists) {
            foreach (self::defaults() as $i => $row) {
                Def::create($row + [
                    'agency_id'  => $agencyId,
                    'track'      => $track,
                    'is_active'  => true,
                    'is_system'  => true,
                    'sort_order' => ($i + 1) * 10,
                ]);
            }
        }
        self::$seeded[$memo] = true;
    }

    public static function forgetMemo(): void
    {
        self::$seeded = [];
    }

    /** @return Collection<int, Def> in agency order. */
    public function list(int $agencyId, string $track = 'sales', bool $activeOnly = true, bool $withArchived = false): Collection
    {
        $this->ensureDefaults($agencyId, $track);
        if ($agencyId <= 0) {
            return collect();
        }

        $q = $withArchived ? Def::withTrashed() : Def::query();
        $q->where('agency_id', $agencyId)->where('track', $track);
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Add or update an outcome. Validates the whole input space and throws ValidationException
     * with messages an agent understands.
     *
     * @param  array<string, mixed>  $in  label, kind, next_action, delay_days?, list_tag?, counts_as_no_answer?, is_active?
     */
    public function save(int $agencyId, ?int $userId, array $in, ?Def $existing = null, string $track = 'sales'): Def
    {
        $this->ensureDefaults($agencyId, $track);
        abort_if($agencyId <= 0, 404);

        $label = trim((string) ($in['label'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        $action = (string) ($in['next_action'] ?? '');
        $errors = [];

        if ($label === '') {
            $errors['label'] = 'Give the outcome a name.';
        } elseif (mb_strlen($label) > 120) {
            $errors['label'] = 'Keep the name under 120 characters.';
        }
        if (! in_array($kind, Def::KINDS, true)) {
            $errors['kind'] = 'Choose whether this counts as an attempt or a conversation.';
        }
        if (! in_array($action, Def::ACTIONS, true)) {
            $errors['next_action'] = 'Choose what happens next.';
        }

        $delay = null;
        if (in_array($action, [Def::ACTION_AFTER_DAYS, Def::ACTION_FIND_OWNER], true)) {
            $raw = trim((string) ($in['delay_days'] ?? ''));
            if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 3650) {
                $errors['delay_days'] = 'Enter the number of days as a whole number from 1 to 3650.';
            } else {
                $delay = (int) $raw;
            }
        }

        $tag = null;
        if ($action === Def::ACTION_MOVE_TO_LIST) {
            $tag = trim((string) ($in['list_tag'] ?? ''));
            if ($tag === '' || mb_strlen($tag) > 60) {
                $errors['list_tag'] = 'Name the list this outcome moves the property to (up to 60 characters).';
            }
        }

        // v3 (road-to-sold.md 22.7): months to choose from, a fallback in days, a try limit, a park rule, the stage it moves to.
        // A form that does not send a field (the older screen) keeps what is stored; it never wipes it.
        $get = fn (string $k) => array_key_exists($k, $in) ? $in[$k] : $existing?->{$k};
        $choiceMonths = null;
        $defaultMonths = null;
        if ($action === Def::ACTION_AFTER_MONTHS) {
            $raw = $get('choice_months') ?? '';
            $list = is_array($raw) ? $raw : preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
            $list = array_values(array_unique(array_map('intval', array_filter($list, fn ($v) => ctype_digit((string) $v)))));
            if ($list === [] || count($list) > 8 || min($list) < 1 || max($list) > 60) {
                $errors['choice_months'] = 'Enter the months the agent can choose from, for example 3, 6, 12 (1 to 60).';
            } else {
                sort($list);
                $choiceMonths = $list;
                $d = (int) ($get('default_months') ?? 0);
                $defaultMonths = in_array($d, $list, true) ? $d : $list[0];
            }
        }
        $fallback = null;
        if ($action === Def::ACTION_EXPIRY_ELSE_DAYS) {
            $raw = trim((string) ($get('fallback_days') ?? ''));
            if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 3650) {
                $errors['fallback_days'] = 'Enter the days to wait when no mandate end date is known, from 1 to 3650.';
            } else {
                $fallback = (int) $raw;
            }
        }
        $maxTries = null;
        $afterMax = null;
        $rawTries = trim((string) ($get('max_tries') ?? ''));
        if ($rawTries !== '') {
            if (! ctype_digit($rawTries) || (int) $rawTries < 1 || (int) $rawTries > 20) {
                $errors['max_tries'] = 'Enter how many tries are allowed, from 1 to 20.';
            } else {
                $maxTries = (int) $rawTries;
                $afterMax = (string) ($get('after_max_tries') ?? '');
                if (! in_array($afterMax, Def::AFTER_MAX_TRIES, true)) {
                    $errors['after_max_tries'] = 'Choose what happens when the tries are used up.';
                }
            }
        }
        $parkKey = trim((string) ($get('park_reason_key') ?? '')) ?: null;
        if ($parkKey !== null && mb_strlen($parkKey) > 40) {
            $errors['park_reason_key'] = 'Pick a reason from the list.';
        }
        if ($maxTries !== null && $afterMax === Def::AFTER_PARK && $parkKey === null) {
            $errors['park_reason_key'] = 'Pick why it is parked when the tries are used up.';
        }
        $stageAfter = trim((string) ($get('stage_after') ?? '')) ?: null;
        if ($stageAfter !== null && ! ProspectStages::exists($stageAfter)) {
            $errors['stage_after'] = 'Pick a stage from the list.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $attrs = [
            'label'                  => $label,
            'kind'                   => $kind,
            'next_action'            => $action,
            'delay_days'             => $delay,
            'list_tag'               => $tag ?? (trim((string) ($in['list_tag'] ?? '')) ?: null),
            'stage_after'            => $stageAfter,
            'max_tries'              => $maxTries,
            'after_max_tries'        => $maxTries !== null ? $afterMax : null,
            'park_reason_key'        => $parkKey,
            'choice_months'          => $choiceMonths,
            'default_months'         => $defaultMonths,
            'fallback_days'          => $fallback,
            'counts_as_no_answer'    => $kind === Def::KIND_ATTEMPT && ! empty($in['counts_as_no_answer']),
            'records_do_not_contact' => $action === Def::ACTION_NEVER_AGAIN && ! empty($in['records_do_not_contact']),
            'is_active'              => array_key_exists('is_active', $in) ? (bool) $in['is_active'] : true,
            'updated_by_user_id'     => $userId,
        ];

        if ($existing) {
            $existing->fill($attrs)->save();

            return $existing;
        }

        // New outcome: generated stable key; re-use (restore) an archived row with the same key.
        $key = $this->uniqueKey($agencyId, $track, $label);
        $trashed = Def::onlyTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('key', $key)->first();
        if ($trashed) {
            $trashed->restore();
            $trashed->fill($attrs)->save();

            return $trashed;
        }

        $max = (int) Def::withTrashed()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order');

        return Def::create($attrs + [
            'agency_id'  => $agencyId,
            'track'      => $track,
            'key'        => $key,
            'is_system'  => false,
            'sort_order' => $max + 10,
        ]);
    }

    /** A slug from the label; collides only with ACTIVE rows (an archived one is restored instead). */
    private function uniqueKey(int $agencyId, string $track, string $label): string
    {
        $base = Str::limit(Str::slug($label, '_'), 50, '') ?: 'outcome';
        $key = $base;
        $n = 2;
        while (Def::where('agency_id', $agencyId)->where('track', $track)->where('key', $key)->exists()) {
            $key = $base . '_' . $n++;
        }

        return $key;
    }

    public function setActive(Def $def, bool $active, ?int $userId = null): void
    {
        $def->forceFill(['is_active' => $active, 'updated_by_user_id' => $userId])->save();
    }

    public function archive(Def $def, ?int $userId = null): void
    {
        $def->forceFill(['updated_by_user_id' => $userId])->save();
        $def->delete();
    }

    public function restore(Def $def, ?int $userId = null): void
    {
        $def->restore();
        $def->forceFill(['updated_by_user_id' => $userId])->save();
    }

    /** @param  array<int,int|string>  $orderedIds ids in the new order; unknown ids ignored. */
    public function reorder(int $agencyId, array $orderedIds, string $track = 'sales'): void
    {
        $rows = Def::withTrashed()->where('agency_id', $agencyId)->where('track', $track)
            ->whereIn('id', array_map('intval', $orderedIds))->get()->keyBy('id');

        $pos = 10;
        foreach (array_unique(array_map('intval', $orderedIds)) as $id) {
            if ($rows->has($id)) {
                $rows[$id]->forceFill(['sort_order' => $pos])->save();
                $pos += 10;
            }
        }
    }
}
