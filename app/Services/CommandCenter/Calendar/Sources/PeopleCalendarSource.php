<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 6 people-domain event classes:
 *   agent_birthday          — computed annual from users.date_of_birth
 *   contact_birthday        — computed annual from contacts.birthday
 *   employment_anniversary  — computed annual from users.employment_date
 *   employee_termination    — stored payroll_employees.termination_date
 *   leave_cycle_end         — stored leave_entitlements.cycle_end_date
 *   rmcp_ack_expiry         — stored rmcp_acknowledgements.valid_until
 *
 * Schema notes:
 *   - users has `name` (no first_name), `is_active`, `date_of_birth`, `employment_date`
 *   - contacts has no agent_id — uses created_by_user_id as managing agent
 *   - leave_entitlements has user_id directly, no deleted_at column
 */
class PeopleCalendarSource implements CalendarSourceContract
{
    public function name(): string
    {
        return 'PeopleCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->agentBirthday())
            ->merge($this->contactBirthday())
            ->merge($this->employmentAnniversary())
            ->merge($this->employeeTermination())
            ->merge($this->leaveCycleEnd())
            ->merge($this->rmcpAckExpiry());
    }

    // ── Computed annuals ──

    private function agentBirthday(): Collection
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('date_of_birth')
            ->select('id', 'name', 'date_of_birth', 'agency_id', 'branch_id')
            ->get()
            ->map(function ($u) {
                $next = $this->nextAnnualOccurrence(Carbon::parse($u->date_of_birth));
                return $this->buildAnnualEvent(
                    eventClass: 'agent_birthday',
                    title: "Birthday — {$u->name}",
                    date: $next,
                    personId: $u->id,
                    userId: $u->id,
                    agencyId: $u->agency_id,
                    branchId: $u->branch_id,
                );
            })
            ->filter();
    }

    private function contactBirthday(): Collection
    {
        return DB::table('contacts')
            ->whereNull('deleted_at')
            ->whereNotNull('birthday')
            ->select('id', 'first_name', 'last_name', 'birthday', 'agency_id', 'branch_id', 'created_by_user_id')
            ->get()
            ->map(function ($c) {
                $next = $this->nextAnnualOccurrence(Carbon::parse($c->birthday));
                $displayName = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                $displayName = $displayName ?: "contact #{$c->id}";

                return $this->buildAnnualEvent(
                    eventClass: 'contact_birthday',
                    title: "Birthday — {$displayName}",
                    date: $next,
                    personId: $c->id,
                    userId: $c->created_by_user_id,
                    agencyId: $c->agency_id,
                    branchId: $c->branch_id,
                    contactId: $c->id,
                );
            })
            ->filter();
    }

    private function employmentAnniversary(): Collection
    {
        return DB::table('users')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('employment_date')
            ->select('id', 'name', 'employment_date', 'agency_id', 'branch_id')
            ->get()
            ->map(function ($u) {
                $start = Carbon::parse($u->employment_date);
                $next = $this->nextAnnualOccurrence($start);

                // Skip if anniversary is in the first year (no anniversary yet)
                if ($next->year === $start->year) {
                    return null;
                }

                $years = $next->year - $start->year;

                return $this->buildAnnualEvent(
                    eventClass: 'employment_anniversary',
                    title: "{$years}-year anniversary — {$u->name}",
                    date: $next,
                    personId: $u->id,
                    userId: $u->id,
                    agencyId: $u->agency_id,
                    branchId: $u->branch_id,
                    metadata: ['years' => $years],
                );
            })
            ->filter();
    }

    // ── Stored expiries ──

    private function employeeTermination(): Collection
    {
        return DB::table('payroll_employees as pe')
            ->whereNull('pe.deleted_at')
            ->whereNotNull('pe.termination_date')
            ->where('pe.termination_date', '>=', now()->subDays(30))
            ->leftJoin('users as u', 'u.id', '=', 'pe.user_id')
            ->select(
                'pe.id',
                'pe.user_id',
                'pe.termination_date',
                'pe.agency_id',
                'pe.branch_id',
                'u.name AS user_name',
            )
            ->get()
            ->map(fn ($e) => [
                'event_type'  => 'people',
                'category'    => 'employee_termination',
                'title'       => 'Last working day — ' . ($e->user_name ?: "employee #{$e->id}"),
                'event_date'  => Carbon::parse($e->termination_date)->startOfDay(),
                'source_type' => \App\Models\Payroll\PayrollEmployee::class,
                'source_id'   => $e->id,
                'user_id'     => $e->user_id,
                'agency_id'   => $e->agency_id,
                'branch_id'   => $e->branch_id,
            ]);
    }

    private function leaveCycleEnd(): Collection
    {
        // leave_entitlements has no deleted_at column
        return DB::table('leave_entitlements as le')
            ->whereNotNull('le.cycle_end_date')
            ->where('le.cycle_end_date', '>=', now()->subDays(30))
            ->leftJoin('users as u', 'u.id', '=', 'le.user_id')
            ->select(
                'le.id',
                'le.user_id',
                'le.cycle_end_date',
                'le.agency_id',
                'le.branch_id',
                'u.name AS user_name',
            )
            ->get()
            ->map(fn ($le) => [
                'event_type'  => 'people',
                'category'    => 'leave_cycle_end',
                'title'       => 'Leave cycle ends — ' . ($le->user_name ?: "user #{$le->user_id}"),
                'event_date'  => Carbon::parse($le->cycle_end_date)->startOfDay(),
                'source_type' => \App\Models\Leave\LeaveEntitlement::class,
                'source_id'   => $le->id,
                'user_id'     => $le->user_id,
                'agency_id'   => $le->agency_id,
                'branch_id'   => $le->branch_id,
            ]);
    }

    private function rmcpAckExpiry(): Collection
    {
        return DB::table('rmcp_acknowledgements as ra')
            ->whereNull('ra.deleted_at')
            ->whereNotNull('ra.valid_until')
            ->where('ra.valid_until', '>=', now()->subDays(30))
            ->leftJoin('users as u', 'u.id', '=', 'ra.user_id')
            ->select(
                'ra.id',
                'ra.user_id',
                'ra.valid_until',
                'ra.agency_id',
                'ra.branch_id',
                'u.name AS user_name',
            )
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'people',
                'category'    => 'rmcp_ack_expiry',
                'title'       => 'RMCP acknowledgement expires — ' . ($r->user_name ?: "user #{$r->user_id}"),
                'event_date'  => Carbon::parse($r->valid_until)->startOfDay(),
                'source_type' => \App\Models\Compliance\RmcpAcknowledgement::class,
                'source_id'   => $r->id,
                'user_id'     => $r->user_id,
                'agency_id'   => $r->agency_id,
                'branch_id'   => $r->branch_id,
            ]);
    }

    // ── Helpers ──

    /**
     * Next upcoming occurrence of an annual date.
     * Handles 29 Feb gracefully (falls back to 28 Feb in non-leap years).
     */
    private function nextAnnualOccurrence(Carbon $sourceDate): Carbon
    {
        $today = now()->startOfDay();
        $month = $sourceDate->month;
        $day = $sourceDate->day;

        foreach ([$today->year, $today->year + 1] as $candidateYear) {
            $useDay = $day;
            if ($month === 2 && $day === 29 && !Carbon::create($candidateYear, 1, 1)->isLeapYear()) {
                $useDay = 28;
            }
            $candidate = Carbon::create($candidateYear, $month, $useDay)->startOfDay();
            if ($candidate->gte($today)) {
                return $candidate;
            }
        }

        return $today->copy()->addYear();
    }

    /**
     * Build synthetic annual event with deterministic source_id.
     */
    private function buildAnnualEvent(
        string $eventClass,
        string $title,
        Carbon $date,
        int $personId,
        ?int $userId,
        ?int $agencyId,
        ?int $branchId,
        ?int $contactId = null,
        array $metadata = [],
    ): array {
        $key = $eventClass . '|' . $personId . '|' . $date->year;
        $sourceId = abs(crc32($key));

        $payload = [
            'event_type'  => 'people',
            'category'    => $eventClass,
            'title'       => $title,
            'event_date'  => $date,
            'source_type' => 'synthetic:people',
            'source_id'   => $sourceId,
            'user_id'     => $userId,
            'agency_id'   => $agencyId,
            'branch_id'   => $branchId,
        ];

        if ($contactId !== null) $payload['contact_id'] = $contactId;
        if (!empty($metadata)) $payload['metadata'] = $metadata;

        return $payload;
    }
}
