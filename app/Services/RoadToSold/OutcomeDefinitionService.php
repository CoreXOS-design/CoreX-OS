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
     * The eight seeded outcomes (Johan, 13 Oct). Keys are stable and stored; labels are editable.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['key' => 'no_answer', 'label' => 'No answer', 'kind' => Def::KIND_ATTEMPT,
             'next_action' => Def::ACTION_AFTER_DAYS, 'delay_days' => 7, 'counts_as_no_answer' => true],
            ['key' => 'spoke_evaluation_booked', 'label' => 'Spoke - evaluation booked', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_APPOINTMENT],
            ['key' => 'spoke_call_back', 'label' => 'Spoke - call back', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_PICKED_DATE],
            ['key' => 'spoke_not_interested', 'label' => 'Spoke - not interested', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_PICKED_DATE],
            ['key' => 'listed_other_agency_open', 'label' => 'Listed with another agency - open mandate', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_PICKED_DATE],
            ['key' => 'mandated_other_agency_sole', 'label' => 'Mandated with another agency - sole mandate', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_EXPIRY_ELSE],
            ['key' => 'wrong_number', 'label' => 'Wrong number', 'kind' => Def::KIND_ATTEMPT,
             'next_action' => Def::ACTION_MOVE_TO_LIST, 'list_tag' => 'Find the right contact'],
            ['key' => 'do_not_contact', 'label' => 'Do not contact', 'kind' => Def::KIND_CONVERSATION,
             'next_action' => Def::ACTION_NEVER_AGAIN, 'records_do_not_contact' => true],
        ];
    }

    /**
     * Seed the eight outcomes the first time an agency+track is used. Idempotent; a trashed or
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
        if ($action === Def::ACTION_AFTER_DAYS) {
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

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $attrs = [
            'label'                  => $label,
            'kind'                   => $kind,
            'next_action'            => $action,
            'delay_days'             => $delay,
            'list_tag'               => $tag,
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
