<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §18 — the lease's own NOTICE and EARLY-CANCELLATION terms: the one place that knows the fields, the
 * agency defaults, the validation, how they are written (audited) and how dates are worked out from them.
 *
 * Stored on `lease_agreement_terms` (one row per lease) next to the existing `earliest_termination_date`. The same columns are
 * what the lease agreement document is filled from (config/lease-agreement-fields.php, group "notice") and what the tenant /
 * owner portal FAQ is answered from (RentalPortalFaqService) — so the signed lease and the portal read ONE value.
 *
 * Defaults are the AGENCY's (LeaseSetting) — nothing is hardcoded here beyond the neutral fall-backs the settings model owns.
 * A save writes only what changed and logs one `lease_notice_terms_changed` row: who, which terms, from, to, when.
 */
class LeaseNoticeTermsService
{
    /** The notice terms proper (registry group "notice"). */
    public const KEYS = [
        'notice_period', 'notice_period_unit', 'earliest_notice_date',
        'early_cancellation_allowed', 'early_cancellation_notice', 'early_cancellation_notice_unit', 'early_cancellation_penalty',
    ];

    /** Everything the notice block edits: the keys above plus the agreement's "earliest date notice may expire". */
    public const EDIT_KEYS = [...self::KEYS, 'earliest_termination_date'];

    public const LABELS = [
        'notice_period' => 'Notice period',
        'notice_period_unit' => 'Notice period unit',
        'earliest_notice_date' => 'Earliest date notice may be given',
        'earliest_termination_date' => 'Earliest date the lease may end',
        'early_cancellation_allowed' => 'Early cancellation allowed',
        'early_cancellation_notice' => 'Notice needed to cancel early',
        'early_cancellation_notice_unit' => 'Early-cancellation notice unit',
        'early_cancellation_penalty' => 'Early-cancellation penalty',
    ];

    public const SOURCE_CAPTURED = 'captured';
    public const SOURCE_EDITED = 'edited';
    public const SOURCE_CARRIED_FORWARD = 'carried_forward';
    public const SOURCE_AGENCY_DEFAULT = 'agency_default';

    private const DATE_KEYS = ['earliest_notice_date', 'earliest_termination_date'];

    // ── Reading ───────────────────────────────────────────────────────────────────────────────

    /** The lease's terms row, or null (a soft-deleted row is "none"). Agency and lease pinned, scope off — safe from a portal request. */
    public function termsOf(Lease $lease): ?LeaseAgreementTerms
    {
        return LeaseAgreementTerms::withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $lease->agency_id)->where('lease_id', $lease->id)->first();
    }

    /**
     * What the lease holds, key => value (dates as Y-m-d, absent = null). Includes `earliest_termination_date`.
     *
     * @return array<string,mixed>
     */
    public function stored(?LeaseAgreementTerms $terms): array
    {
        $out = [];
        foreach (self::EDIT_KEYS as $key) {
            $out[$key] = $this->plain($key, $terms?->{$key});
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function forLease(Lease $lease): array
    {
        return $this->stored($this->termsOf($lease));
    }

    /** Does the lease hold ANY notice or cancellation term of its own? */
    public function hasAny(Lease $lease): bool
    {
        return $this->anyIn($this->forLease($lease));
    }

    /**
     * Has an agent confirmed this lease's notice terms against the signed lease (leases.md §18.7)? Only confirmed terms are ever
     * stated to a tenant or owner. (What the FAQ then says still depends on the terms the lease holds: none = nothing to say.)
     */
    public function isConfirmed(Lease $lease): bool
    {
        $terms = $this->termsOf($lease);

        return $terms !== null && $terms->notice_terms_confirmed_at !== null;
    }

    /** @param array<string,mixed> $values */
    public function anyIn(array $values): bool
    {
        foreach (self::EDIT_KEYS as $key) {
            if (($values[$key] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    // ── The agency's defaults ─────────────────────────────────────────────────────────────────

    /**
     * What a lease starting on `$start` begins with, from the AGENCY's settings. The earliest termination date is not defaulted
     * (it is a fact of that lease's agreement). The earliest notice date is the start plus the agency's "not before" months, when set.
     *
     * @return array<string,mixed>
     */
    public function defaultsFor(int $agencyId, ?CarbonInterface $start = null): array
    {
        $length = LeaseSetting::tenantNoticePeriodDaysFor($agencyId);
        $unit = LeaseSetting::tenantNoticePeriodUnitFor($agencyId);
        $allowed = LeaseSetting::earlyCancellationAllowedFor($agencyId);
        $months = LeaseSetting::earliestNoticeMonthsFor($agencyId);

        $ecNotice = LeaseSetting::earlyCancellationNoticeFor($agencyId);
        $ecUnit = LeaseSetting::earlyCancellationNoticeUnitFor($agencyId);
        if ($ecNotice === null) { // "the same as the ordinary notice"
            $ecNotice = $length;
            $ecUnit = $unit;
        }

        return [
            'notice_period' => $length,
            'notice_period_unit' => $unit,
            'earliest_notice_date' => $start && $months ? $this->addPeriod($start, $months, 'months')->toDateString() : null,
            'earliest_termination_date' => null,
            'early_cancellation_allowed' => $allowed,
            'early_cancellation_notice' => $allowed === 'yes' ? $ecNotice : null,
            'early_cancellation_notice_unit' => $allowed === 'yes' ? ($ecUnit ?? $unit) : null,
            'early_cancellation_penalty' => $allowed === 'yes' ? LeaseSetting::earlyCancellationPenaltyFor($agencyId) : null,
        ];
    }

    // ── Validation / normalising ──────────────────────────────────────────────────────────────

    /** Laravel rules for a `notice[...]` array (every key optional: an absent key is left alone). */
    public function rules(string $prefix = 'notice'): array
    {
        $units = 'in:' . implode(',', LeaseSetting::NOTICE_UNITS);

        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.notice_period" => ['nullable', 'integer', 'min:1', 'max:999'],
            "{$prefix}.notice_period_unit" => ['nullable', 'string', $units],
            "{$prefix}.earliest_notice_date" => ['nullable', 'date'],
            "{$prefix}.earliest_termination_date" => ['nullable', 'date'],
            "{$prefix}.early_cancellation_allowed" => ['nullable', 'string', 'in:yes,no'],
            "{$prefix}.early_cancellation_notice" => ['nullable', 'integer', 'min:1', 'max:999'],
            "{$prefix}.early_cancellation_notice_unit" => ['nullable', 'string', $units],
            "{$prefix}.early_cancellation_penalty" => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(string $prefix = 'notice'): array
    {
        return [
            "{$prefix}.notice_period.integer" => 'The notice period must be a whole number.',
            "{$prefix}.notice_period.min" => 'The notice period must be at least 1.',
            "{$prefix}.notice_period_unit.in" => 'Choose days, weeks or months for the notice period.',
            "{$prefix}.early_cancellation_allowed.in" => 'Early cancellation is either yes or no.',
            "{$prefix}.early_cancellation_notice.integer" => 'The early-cancellation notice must be a whole number.',
            "{$prefix}.early_cancellation_notice_unit.in" => 'Choose days, weeks or months for the early-cancellation notice.',
            "{$prefix}.earliest_notice_date.date" => 'The earliest date notice may be given must be a date.',
            "{$prefix}.earliest_termination_date.date" => 'The earliest date the lease may end must be a date.',
        ];
    }

    /**
     * Cross-field checks a plain rule cannot do: the dates against the lease's start. Returns field => message.
     *
     * @param array<string,mixed> $values normalised
     * @return array<string,string>
     */
    public function crossErrors(array $values, ?string $leaseStart, string $prefix = 'notice'): array
    {
        $errors = [];
        if ($leaseStart) {
            foreach (['earliest_notice_date', 'earliest_termination_date'] as $key) {
                if (($values[$key] ?? null) !== null && $values[$key] < $leaseStart) {
                    $errors["{$prefix}.{$key}"] = self::LABELS[$key] . ' cannot be before the lease starts.';
                }
            }
        }
        if (($values['earliest_notice_date'] ?? null) !== null && ($values['earliest_termination_date'] ?? null) !== null
            && $values['earliest_notice_date'] > $values['earliest_termination_date']) {
            $errors["{$prefix}.earliest_notice_date"] = 'Notice cannot first be given after the earliest date the lease may end.';
        }

        return $errors;
    }

    /**
     * Trim and shape what was typed: blanks become null, units are lower-case, a length with no unit takes the agency's unit,
     * and "no" early cancellation carries no notice or penalty. Only the keys PRESENT in the input are returned.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function normalise(array $input, int $agencyId): array
    {
        $out = [];
        foreach (self::EDIT_KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $v = $input[$key];
            $v = is_string($v) ? trim($v) : $v;
            if ($v === '' || $v === null) {
                $out[$key] = null;

                continue;
            }
            $out[$key] = match (true) {
                in_array($key, self::DATE_KEYS, true) => Carbon::parse((string) $v)->toDateString(),
                in_array($key, ['notice_period', 'early_cancellation_notice'], true) => (int) $v,
                in_array($key, ['notice_period_unit', 'early_cancellation_notice_unit', 'early_cancellation_allowed'], true) => strtolower((string) $v),
                default => (string) $v,
            };
        }

        if (($out['notice_period'] ?? null) !== null && ($out['notice_period_unit'] ?? null) === null && ! array_key_exists('notice_period_unit', $out)) {
            $out['notice_period_unit'] = LeaseSetting::tenantNoticePeriodUnitFor($agencyId);
        }
        if (array_key_exists('notice_period', $out) && $out['notice_period'] === null) {
            $out['notice_period_unit'] = null; // no length, no unit
        }
        if (($out['early_cancellation_notice'] ?? null) !== null && ($out['early_cancellation_notice_unit'] ?? null) === null && ! array_key_exists('early_cancellation_notice_unit', $out)) {
            $out['early_cancellation_notice_unit'] = LeaseSetting::tenantNoticePeriodUnitFor($agencyId);
        }
        if (array_key_exists('early_cancellation_notice', $out) && $out['early_cancellation_notice'] === null) {
            $out['early_cancellation_notice_unit'] = null;
        }
        if (($out['early_cancellation_allowed'] ?? null) === 'no') {
            foreach (['early_cancellation_notice', 'early_cancellation_notice_unit', 'early_cancellation_penalty'] as $k) {
                $out[$k] = null;
            }
        }

        return $out;
    }

    // ── Writing (audited) ─────────────────────────────────────────────────────────────────────

    /**
     * Write the terms (only what changed), audited. `$confirmed` says whether this save counts as an agent's confirmation: null =
     * by source (an agent capturing or editing them confirms them; a carried-forward or agency-default fill does not).
     * `$reason` is recorded with the change (required by the caller on a lease whose agreement is already signed).
     *
     * @param array<string,mixed> $input
     * @return array<string,array{from:mixed,to:mixed}> the changes made (empty = nothing to write)
     */
    public function save(Lease $lease, array $input, ?User $actor, string $source = self::SOURCE_EDITED, bool $log = true, ?string $reason = null, ?bool $confirmed = null): array
    {
        $input = array_intersect_key($input, array_flip(self::EDIT_KEYS));
        $confirm = $confirmed ?? in_array($source, [self::SOURCE_CAPTURED, self::SOURCE_EDITED], true);
        $explicit = $confirmed === true;

        return DB::transaction(function () use ($lease, $input, $actor, $source, $log, $reason, $confirm, $explicit) {
            $terms = LeaseAgreementTerms::forLease($lease);
            $changes = [];

            foreach ($input as $key => $to) {
                $from = $this->plain($key, $terms->{$key});
                if ($from === $to) {
                    continue;
                }
                $changes[$key] = ['from' => $from, 'to' => $to];
                $terms->{$key} = $to;
            }

            if ($changes === []) {
                // Nothing differs, but the caller explicitly confirms (the capture screen showed these very terms): record that.
                if ($explicit && $terms->exists && $terms->notice_terms_confirmed_at === null && $this->anyIn($this->stored($terms))) {
                    $terms->notice_terms_confirmed_at = now();
                    $terms->notice_terms_confirmed_by = $actor?->id;
                    $terms->save();
                }

                return [];
            }

            $terms->notice_terms_source = $source;
            $terms->notice_terms_confirmed_at = $confirm ? now() : null;
            $terms->notice_terms_confirmed_by = $confirm ? $actor?->id : null;
            $terms->save();

            if ($log) {
                $reason = $reason !== null ? trim($reason) : null;
                LeaseEvent::create([
                    'lease_id' => $lease->id,
                    'event_type' => LeaseEvent::TYPE_NOTICE_TERMS_CHANGED,
                    'description' => mb_substr($this->describeChanges($changes) . ($reason ? ' — reason: ' . $reason : ''), 0, 500),
                    'actor_user_id' => $actor?->id,
                    'metadata' => ['source' => $source, 'changes' => $changes, 'confirmed' => $confirm, 'reason' => $reason ?: null],
                    'occurred_at' => now(),
                    'created_at' => now(),
                ]);
            }

            return $changes;
        });
    }

    /**
     * An agent confirms the terms the lease holds against its signed copy (one click, audited). False = nothing to confirm
     * (the lease holds no terms, or they are already confirmed).
     */
    public function confirm(Lease $lease, ?User $actor): bool
    {
        return DB::transaction(function () use ($lease, $actor) {
            $terms = $this->termsOf($lease);
            if (! $terms || $terms->notice_terms_confirmed_at !== null || ! $this->anyIn($this->stored($terms))) {
                return false;
            }

            $terms->notice_terms_confirmed_at = now();
            $terms->notice_terms_confirmed_by = $actor?->id;
            $terms->save();

            LeaseEvent::create([
                'lease_id' => $lease->id,
                'event_type' => LeaseEvent::TYPE_NOTICE_TERMS_CONFIRMED,
                'description' => mb_substr('Notice terms confirmed against the signed lease', 0, 500),
                'actor_user_id' => $actor?->id,
                'metadata' => ['source' => $terms->notice_terms_source, 'terms' => $this->stored($terms)],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return true;
        });
    }

    /** @param array<string,array{from:mixed,to:mixed}> $changes */
    public function describeChanges(array $changes): string
    {
        $parts = [];
        foreach ($changes as $key => $c) {
            $parts[] = (self::LABELS[$key] ?? $key) . ': ' . $this->show($key, $c['from']) . ' → ' . $this->show($key, $c['to']);
        }

        return 'Notice terms changed — ' . implode('; ', $parts);
    }

    private function show(string $key, mixed $v): string
    {
        return $v === null || $v === '' ? 'not on record' : (string) $v;
    }

    // ── The renewal: the previous term's terms carry forward (dates shift with the start) ─────

    /**
     * What a renewal starts with: the term being renewed (dates moved by the same offset as the start date), else the agency's
     * defaults for the new start. Returns [values, source].
     *
     * @return array{0: array<string,mixed>, 1: string}
     */
    public function forRenewal(Lease $previous, CarbonInterface $newStart): array
    {
        $prev = $this->forLease($previous);
        if (! $this->anyIn($prev)) {
            return [$this->defaultsFor((int) $previous->agency_id, $newStart), self::SOURCE_AGENCY_DEFAULT];
        }

        if ($previous->start_date) {
            $offset = (int) $previous->start_date->copy()->startOfDay()->diffInDays($newStart->copy()->startOfDay(), false);
            foreach (self::DATE_KEYS as $key) {
                if ($prev[$key] !== null) {
                    $prev[$key] = Carbon::parse($prev[$key])->addDays($offset)->toDateString();
                }
            }
        }

        return [$prev, self::SOURCE_CARRIED_FORWARD];
    }

    // ── Dates worked out from the terms ───────────────────────────────────────────────────────

    public function addPeriod(CarbonInterface $date, int $length, string $unit): Carbon
    {
        $d = Carbon::instance($date)->startOfDay();

        return match ($unit) {
            'weeks' => $d->addWeeks($length),
            'months' => $d->addMonthsNoOverflow($length),
            default => $d->addDays($length),
        };
    }

    public function subPeriod(CarbonInterface $date, int $length, string $unit): Carbon
    {
        $d = Carbon::instance($date)->startOfDay();

        return match ($unit) {
            'weeks' => $d->subWeeks($length),
            'months' => $d->subMonthsNoOverflow($length),
            default => $d->subDays($length),
        };
    }

    /** "30 days", "1 month", "2 weeks"; null when there is no length. */
    public function periodText(?int $length, ?string $unit): ?string
    {
        if ($length === null || $length <= 0) {
            return null;
        }
        $unit = in_array($unit, LeaseSetting::NOTICE_UNITS, true) ? $unit : 'days';

        return $length . ' ' . ($length === 1 ? rtrim($unit, 's') : $unit);
    }

    /**
     * The real dates for ONE lease, worked out from what it holds (null when it lacks what the date needs):
     *  - notice_by      the last day notice can be given for the lease to end on its end date (end date less the notice period);
     *  - earliest_notice the first day notice can be given: the lease's own earliest notice date, else the earliest date the lease may end
     *                    less the notice period;
     *  - earliest_end   the earliest date the lease may end (the agreement's own date).
     *
     * @param array<string,mixed> $values stored()
     * @return array{notice_by:?string, earliest_notice:?string, earliest_end:?string}
     */
    public function workedDates(Lease $lease, array $values): array
    {
        $length = $values['notice_period'] ?? null;
        $unit = $values['notice_period_unit'] ?? 'days';

        $noticeBy = null;
        if ($length && $lease->end_date && ! $lease->is_month_to_month) {
            $noticeBy = $this->subPeriod($lease->end_date, (int) $length, (string) $unit)->toDateString();
        }

        $earliestEnd = $values['earliest_termination_date'] ?? null;
        $earliestNotice = $values['earliest_notice_date'] ?? null;
        if ($earliestNotice === null && $earliestEnd !== null && $length) {
            $earliestNotice = $this->subPeriod(Carbon::parse((string) $earliestEnd), (int) $length, (string) $unit)->toDateString();
        }

        return ['notice_by' => $noticeBy, 'earliest_notice' => $earliestNotice, 'earliest_end' => $earliestEnd];
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    private function plain(string $key, mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->toDateString();
        }
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return null;
        }

        return in_array($key, ['notice_period', 'early_cancellation_notice'], true) ? (int) $v : (is_string($v) ? trim($v) : $v);
    }
}
