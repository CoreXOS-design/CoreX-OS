<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\LeaseTenant;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAttendance;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — the ONE place attendance rules live: who is
 * EXPECTED at an inspection, what has been RECORDED, what the invitation trail says about each party,
 * and the writes (record / correct / withdraw an attendance fact, record an invitation given off-system).
 * The web controller is a thin call into this; a future mobile endpoint calls the same methods.
 *
 * Everything here is recorded as dated FACTS with who recorded them (§45.2 principle 1). No string
 * produced here says what attendance means for anyone's rights — the printed wording is Johan's
 * (§45.11), so the lines below are plain statements of what is on record.
 */
class RentalInspectionAttendanceService
{
    /** Quick-pick methods offered for a manual invitation (free text is always allowed). */
    public const INVITATION_METHODS = ['Phone call', 'WhatsApp (typed by hand)', 'In person', 'SMS', 'E-mail (sent outside CoreX)'];

    /** A recorded invitation time later than now + this is a typo, not a fact. */
    private const CLOCK_SKEW_MINUTES = 5;

    // ═══ Who is expected ═══════════════════════════════════════════════════════

    /**
     * Every party expected at this inspection: each tenant on its lease, each landlord invited to it,
     * and the inspector (falling back to whoever created the inspection). Landlords use the SAME
     * resolver the scheduling invitations use (RentalInspectionNotificationService::landlordContacts()),
     * plus the landlord who signs (Property::sellerOwnerContact()) so the person who signs is never
     * missing from the list.
     *
     * @return Collection<int, array{key: string, party_role: string, contact_id: ?int, user_id: ?int, name: string}>
     */
    public function expectedParties(RentalInspection $inspection): Collection
    {
        $notifier = app(RentalInspectionNotificationService::class);
        $parties = collect();

        foreach ($notifier->tenantContacts($inspection) as $contact) {
            $parties->push($this->contactParty(RentalInspectionAttendance::PARTY_TENANT, $contact));
        }

        $landlords = $notifier->landlordContacts($inspection);
        $signing = $inspection->property?->sellerOwnerContact();
        if ($signing && ! $landlords->contains('id', $signing->id)) {
            $landlords->push($signing);
        }
        foreach ($landlords as $contact) {
            $parties->push($this->contactParty(RentalInspectionAttendance::PARTY_LANDLORD, $contact));
        }

        $agent = $inspection->inspector ?? $inspection->createdBy;
        if ($agent) {
            $parties->push([
                'key' => 'agent:' . $agent->id,
                'party_role' => RentalInspectionAttendance::PARTY_AGENT,
                'contact_id' => null,
                'user_id' => $agent->id,
                'name' => (string) $agent->name,
            ]);
        }

        return $parties->unique('key')->values();
    }

    private function contactParty(string $role, Contact $contact): array
    {
        return [
            'key' => $role . ':' . $contact->id,
            'party_role' => $role,
            'contact_id' => $contact->id,
            'user_id' => null,
            'name' => (string) $contact->full_name,
        ];
    }

    private function keyFor(RentalInspectionAttendance $row): ?string
    {
        return match ($row->party_role) {
            RentalInspectionAttendance::PARTY_TENANT, RentalInspectionAttendance::PARTY_LANDLORD => $row->party_contact_id ? $row->party_role . ':' . $row->party_contact_id : null,
            RentalInspectionAttendance::PARTY_AGENT => $row->party_user_id ? 'agent:' . $row->party_user_id : null,
            default => null,
        };
    }

    // ═══ What is on record ═════════════════════════════════════════════════════

    /**
     * The attendance board for one inspection — what the panel, the signed PDF, the public page and the
     * agency inspection page all render, built once so they can never disagree.
     *
     * @return array{rows: array<int, array<string, mixed>>, others: array<int, array<string, mixed>>, expected: int, recorded: int, attended: int, complete: bool, missing: array<int, array<string, mixed>>}
     */
    public function board(RentalInspection $inspection): array
    {
        $live = RentalInspectionAttendance::query()
            ->where('rental_inspection_id', $inspection->id)
            ->live()
            ->with('recordedBy')
            ->orderBy('id')
            ->get();

        $notifications = RentalInspectionNotification::query()
            ->where('rental_inspection_id', $inspection->id)
            ->with('sentBy')
            ->orderBy('id')
            ->get();

        $expected = $this->expectedParties($inspection);
        $byKey = $live->mapWithKeys(fn (RentalInspectionAttendance $a) => $this->keyFor($a) ? [$this->keyFor($a) => $a] : []);

        $rows = $expected->map(function (array $party) use ($byKey, $notifications) {
            /** @var RentalInspectionAttendance|null $attendance */
            $attendance = $byKey->get($party['key']);

            return $party + [
                'attendance' => $attendance ? $this->present($attendance) : null,
                'invitation' => $this->invitationLines($notifications, $party),
            ];
        })->all();

        // Anyone in the room who was not an expected party, plus any live row whose party is no longer
        // expected (e.g. a tenant removed from the lease after the walkthrough) — never silently dropped.
        $expectedKeys = $expected->pluck('key');
        $others = $live
            ->filter(fn (RentalInspectionAttendance $a) => $this->keyFor($a) === null || ! $expectedKeys->contains($this->keyFor($a)))
            ->map(fn (RentalInspectionAttendance $a) => $this->present($a))
            ->values()
            ->all();

        $recorded = collect($rows)->filter(fn ($r) => $r['attendance'] !== null)->count();
        $attended = collect($rows)->filter(fn ($r) => ($r['attendance']['outcome'] ?? null) === RentalInspectionAttendance::OUTCOME_ATTENDED)->count();
        $missing = collect($rows)->filter(fn ($r) => $r['attendance'] === null)
            ->map(fn ($r) => ['key' => $r['key'], 'party_role' => $r['party_role'], 'name' => $r['name']])->values()->all();

        return [
            'rows' => $rows,
            'others' => $others,
            'expected' => count($rows),
            'recorded' => $recorded,
            'attended' => $attended,
            'complete' => $missing === [],
            'missing' => $missing,
        ];
    }

    /** The parties still without an outcome — the completion guard's list. */
    public function missingParties(RentalInspection $inspection): array
    {
        $liveKeys = RentalInspectionAttendance::query()
            ->where('rental_inspection_id', $inspection->id)
            ->live()
            ->get()
            ->map(fn (RentalInspectionAttendance $a) => $this->keyFor($a))
            ->filter()
            ->all();

        return $this->expectedParties($inspection)
            ->reject(fn (array $p) => in_array($p['key'], $liveKeys, true))
            ->map(fn (array $p) => ['key' => $p['key'], 'party_role' => $p['party_role'], 'name' => $p['name']])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(RentalInspectionAttendance $a): array
    {
        return [
            'id' => $a->id,
            'party_role' => $a->party_role,
            'party_contact_id' => $a->party_contact_id,
            'party_user_id' => $a->party_user_id,
            'attendee_name' => $a->attendee_name,
            'attended_as' => $a->attended_as,
            'represents_party_role' => $a->represents_party_role,
            'outcome' => $a->outcome,
            'arrived_at' => $a->arrived_at ? substr((string) $a->arrived_at, 0, 5) : null,
            'note' => $a->note,
            'recorded_by' => $a->recordedBy?->name,
            'recorded_by_user_id' => $a->recorded_by_user_id,
            'recorded_at' => $a->recorded_at?->toIso8601String(),
            'recorded_at_label' => $a->recorded_at?->format('d M Y H:i'),
        ];
    }

    // ═══ Invitation trail ══════════════════════════════════════════════════════

    /**
     * What the notification log says about this party, as plain statements of fact. Each line carries
     * `printable` — the signed PDF and public report print only invitations that were really given
     * (sent by e-mail, or recorded manually); failures and reminders appear on screen only, because
     * whether they belong on the printed record is Johan's call (§45.11 item 2).
     *
     * @param  Collection<int, RentalInspectionNotification>  $notifications
     * @param  array{party_role: string, contact_id: ?int, user_id: ?int}  $party
     * @return array{lines: array<int, array{text: string, printable: bool}>, recorded: bool}
     */
    public function invitationLines(Collection $notifications, array $party): array
    {
        $notificationRole = $party['party_role'] === RentalInspectionAttendance::PARTY_AGENT
            ? RentalInspectionNotification::PARTY_INSPECTOR
            : $party['party_role'];

        $mine = $notifications->filter(function (RentalInspectionNotification $n) use ($party, $notificationRole) {
            if ($n->party_role !== $notificationRole) {
                return false;
            }

            return $party['party_role'] === RentalInspectionAttendance::PARTY_AGENT
                ? (int) $n->recipient_user_id === (int) $party['user_id']
                : (int) $n->recipient_contact_id === (int) $party['contact_id'];
        });

        $lines = [];
        $given = false;

        foreach ($mine as $n) {
            $when = $n->happenedAt();
            $stamp = $when?->format('j M Y H:i') ?? '';

            if ($n->event === RentalInspectionNotification::EVENT_INVITATION_MANUAL) {
                $by = $n->sentBy?->name;
                $lines[] = [
                    'text' => 'Invitation recorded manually' . ($by ? ' by ' . $by : '') . ($n->method ? ', ' . $n->method : '') . ($stamp ? ', ' . $stamp : ''),
                    'printable' => true,
                ];
                $given = true;
                continue;
            }

            $viaMail = $n->channel === RentalInspectionNotification::CHANNEL_MAIL;

            if ($n->status === RentalInspectionNotification::STATUS_SENT && $viaMail) {
                [$text, $printable] = match ($n->event) {
                    RentalInspectionNotification::EVENT_SCHEDULED => ['Invited by email on ' . $stamp, true],
                    RentalInspectionNotification::EVENT_RESCHEDULED => ['Told of the new date by email on ' . $stamp, true],
                    RentalInspectionNotification::EVENT_CANCELLED => ['Told of the cancellation by email on ' . $stamp, false],
                    RentalInspectionNotification::EVENT_REMINDER => ['Reminder sent ' . ($when?->format('j M') ?? ''), false],
                    default => [null, false],
                };
                if ($text !== null) {
                    $lines[] = ['text' => $text, 'printable' => $printable];
                    if (in_array($n->event, [RentalInspectionNotification::EVENT_SCHEDULED, RentalInspectionNotification::EVENT_RESCHEDULED], true)) {
                        $given = true;
                    }
                }
            } elseif ($n->status === RentalInspectionNotification::STATUS_FAILED && $viaMail) {
                $lines[] = ['text' => 'An e-mail to ' . ($n->recipient ?: 'this party') . ' could not be sent (' . $stamp . ')', 'printable' => false];
            }
            // skipped (no address on file) and queued WhatsApp are not statements about the party being told.
        }

        if ($lines === []) {
            $lines[] = ['text' => 'No invitation recorded', 'printable' => true];
        }

        return ['lines' => $lines, 'recorded' => $given];
    }

    /** The printable invitation statement for one board row — what the signed record and public page show. */
    public function printableInvitation(array $row): string
    {
        $lines = collect($row['invitation']['lines'] ?? [])->where('printable', true)->pluck('text');

        return $lines->isEmpty() ? 'No invitation recorded' : $lines->implode(' · ');
    }

    // ═══ Writes ════════════════════════════════════════════════════════════════

    /**
     * Record (or CORRECT) one party's attendance. A correction supersedes the live row for that party —
     * the old row is kept, marked superseded and pointing at its replacement. Idempotent on
     * `client_idempotency_key`: a retry returns the existing row unchanged.
     *
     * @param  array<string, mixed>  $data  party_role, party_contact_id|party_user_id, outcome, attended_as, attendee_name, arrived_at, note, client_idempotency_key
     *
     * @throws \InvalidArgumentException  a plain-language message the controller returns as a 422
     */
    public function record(RentalInspection $inspection, array $data, User $by): RentalInspectionAttendance
    {
        $inspection->assertRecordable();

        $key = isset($data['client_idempotency_key']) && is_string($data['client_idempotency_key']) && $data['client_idempotency_key'] !== ''
            ? substr($data['client_idempotency_key'], 0, 64) : null;
        if ($key !== null) {
            $existing = RentalInspectionAttendance::where('rental_inspection_id', $inspection->id)->where('client_idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }
        }

        $role = (string) ($data['party_role'] ?? '');
        $outcome = (string) ($data['outcome'] ?? '');
        $attendedAs = (string) ($data['attended_as'] ?? RentalInspectionAttendance::AS_SELF);
        $name = trim((string) ($data['attendee_name'] ?? ''));
        $note = trim((string) ($data['note'] ?? ''));
        $arrived = $this->normaliseTime($data['arrived_at'] ?? null);

        if (! in_array($outcome, [RentalInspectionAttendance::OUTCOME_ATTENDED, RentalInspectionAttendance::OUTCOME_DID_NOT_ATTEND], true)) {
            throw new \InvalidArgumentException('Choose whether this person attended or did not attend.');
        }
        if (! in_array($attendedAs, RentalInspectionAttendance::attendedAsKeys(), true)) {
            throw new \InvalidArgumentException('That is not a recognised way of attending.');
        }

        $contactId = null;
        $userId = null;
        $represents = null;
        $superseded = false;

        if ($role === RentalInspectionAttendance::PARTY_OTHER) {
            if ($outcome !== RentalInspectionAttendance::OUTCOME_ATTENDED) {
                throw new \InvalidArgumentException('Only someone who was there is added here — a person who did not come is recorded against their own row.');
            }
            if (! in_array($attendedAs, [RentalInspectionAttendance::AS_CO_OCCUPANT, RentalInspectionAttendance::AS_OTHER], true)) {
                throw new \InvalidArgumentException('Say whether this person is a co-occupant or someone else.');
            }
            if ($name === '') {
                throw new \InvalidArgumentException('Enter the name of the person who attended.');
            }
        } else {
            if (! in_array($role, [RentalInspectionAttendance::PARTY_TENANT, RentalInspectionAttendance::PARTY_LANDLORD, RentalInspectionAttendance::PARTY_AGENT], true)) {
                throw new \InvalidArgumentException('That is not a recognised party.');
            }

            $expected = $this->expectedParties($inspection);
            $party = $expected->first(fn (array $p) => $p['party_role'] === $role
                && ($role === RentalInspectionAttendance::PARTY_AGENT
                    ? (int) $p['user_id'] === (int) ($data['party_user_id'] ?? 0)
                    : (int) $p['contact_id'] === (int) ($data['party_contact_id'] ?? 0)));
            if (! $party) {
                throw new \InvalidArgumentException('That person is not one of the parties expected at this inspection.');
            }
            $contactId = $party['contact_id'];
            $userId = $party['user_id'];

            if (! in_array($attendedAs, [RentalInspectionAttendance::AS_SELF, RentalInspectionAttendance::AS_REPRESENTATIVE], true)) {
                throw new \InvalidArgumentException('A party either attended themselves or was represented.');
            }
            if ($outcome === RentalInspectionAttendance::OUTCOME_DID_NOT_ATTEND) {
                // Nobody stood in for them: that is "attended, represented", a different fact.
                $attendedAs = RentalInspectionAttendance::AS_SELF;
                $name = '';
                $arrived = null;
            } elseif ($attendedAs === RentalInspectionAttendance::AS_REPRESENTATIVE) {
                if ($name === '') {
                    throw new \InvalidArgumentException('Enter the name of the person who attended on their behalf.');
                }
                $represents = $role;
            } else {
                $name = '';
            }
        }

        $row = DB::transaction(function () use ($inspection, $by, $role, $contactId, $userId, $outcome, $attendedAs, $name, $note, $arrived, $represents, $key, &$superseded) {
            $row = RentalInspectionAttendance::create([
                'agency_id' => $inspection->agency_id,
                'rental_inspection_id' => $inspection->id,
                'party_role' => $role,
                'party_contact_id' => $contactId,
                'party_user_id' => $userId,
                'attendee_name' => $name !== '' ? $name : null,
                'attended_as' => $attendedAs,
                'represents_party_role' => $represents,
                'outcome' => $outcome,
                'arrived_at' => $arrived,
                'note' => $note !== '' ? $note : null,
                'recorded_by_user_id' => $by->id,
                'recorded_at' => now(),
                'client_idempotency_key' => $key,
                'created_at' => now(),
            ]);

            // A correction supersedes the live row for the SAME party; an `other` person is its own row.
            if ($role !== RentalInspectionAttendance::PARTY_OTHER) {
                $superseded = RentalInspectionAttendance::where('rental_inspection_id', $inspection->id)
                    ->where('id', '!=', $row->id)
                    ->where('party_role', $role)
                    ->when($contactId, fn ($q) => $q->where('party_contact_id', $contactId))
                    ->when($userId, fn ($q) => $q->where('party_user_id', $userId))
                    ->live()
                    ->update(['superseded_at' => now(), 'superseded_by_id' => $row->id]) > 0;
            }

            return $row;
        });

        // §45.8 (Build I-6b) — recorded vs corrected are different facts in the history.
        $who = $row->party_role === RentalInspectionAttendance::PARTY_OTHER
            ? ($row->attendee_name ?: 'Another person')
            : ($this->expectedParties($inspection)->first(fn (array $p) => $p['party_role'] === $row->party_role
                && ($row->party_role === RentalInspectionAttendance::PARTY_AGENT ? (int) $p['user_id'] === (int) $row->party_user_id : (int) $p['contact_id'] === (int) $row->party_contact_id))['name'] ?? ucfirst($row->party_role));
        RentalInspectionAuditLog::record(
            $inspection,
            $superseded ? RentalInspectionAuditLog::EVENT_ATTENDANCE_CORRECTED : RentalInspectionAuditLog::EVENT_ATTENDANCE_RECORDED,
            $who . ': ' . ($row->outcome === RentalInspectionAttendance::OUTCOME_ATTENDED ? 'attended' : 'did not attend')
                . ($row->attended_as === RentalInspectionAttendance::AS_REPRESENTATIVE && $row->attendee_name ? ' (represented by ' . $row->attendee_name . ')' : '')
                . ($superseded ? ' — corrected.' : '.'),
            null,
            ['party_role' => $row->party_role, 'outcome' => $row->outcome, 'attended_as' => $row->attended_as],
            $by,
        );

        return $row;
    }

    /** The live record for one expected party, if any — what a correction would supersede. */
    public function liveRecordFor(RentalInspection $inspection, string $role, ?int $contactId, ?int $userId): ?RentalInspectionAttendance
    {
        if (! in_array($role, [RentalInspectionAttendance::PARTY_TENANT, RentalInspectionAttendance::PARTY_LANDLORD, RentalInspectionAttendance::PARTY_AGENT], true)) {
            return null;
        }

        return RentalInspectionAttendance::query()
            ->where('rental_inspection_id', $inspection->id)
            ->where('party_role', $role)
            ->when($contactId, fn ($q) => $q->where('party_contact_id', $contactId))
            ->when($userId, fn ($q) => $q->where('party_user_id', $userId))
            ->live()
            ->latest('id')
            ->first();
    }

    /** Withdraw a live record without replacing it (a wrongly added person, a recorded-in-error outcome). */
    public function withdraw(RentalInspectionAttendance $attendance): void
    {
        $attendance->inspection->assertRecordable();

        if (! $attendance->isLive()) {
            return;
        }

        $attendance->forceFill(['superseded_at' => now(), 'superseded_by_id' => null])->save();

        RentalInspectionAuditLog::record(
            $attendance->inspection,
            RentalInspectionAuditLog::EVENT_ATTENDANCE_WITHDRAWN,
            'An attendance record was withdrawn (' . $attendance->party_role . ', ' . str_replace('_', ' ', $attendance->outcome) . ').',
            ['party_role' => $attendance->party_role, 'outcome' => $attendance->outcome],
        );
    }

    /**
     * Record an invitation that was given OFF the system, with the real time it was given. Logged in the
     * same append-only table as the system's own invitations, so the party's line shows both.
     *
     * @throws \InvalidArgumentException
     */
    public function recordInvitation(RentalInspection $inspection, array $data, User $by): RentalInspectionNotification
    {
        $inspection->assertRecordable();

        $role = (string) ($data['party_role'] ?? '');
        $method = trim((string) ($data['method'] ?? ''));
        if ($method === '') {
            throw new \InvalidArgumentException('Say how the invitation was given (for example a phone call).');
        }
        if (mb_strlen($method) > 60) {
            throw new \InvalidArgumentException('Keep the method short — 60 characters at most.');
        }

        try {
            $occurred = isset($data['occurred_at']) && trim((string) $data['occurred_at']) !== '' ? Carbon::parse((string) $data['occurred_at'], config('app.timezone')) : now();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('That date and time could not be read.');
        }
        if ($occurred->gt(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            throw new \InvalidArgumentException('An invitation cannot have been given in the future.');
        }

        $party = $this->expectedParties($inspection)->first(fn (array $p) => $p['party_role'] === $role
            && ($role === RentalInspectionAttendance::PARTY_AGENT
                ? (int) $p['user_id'] === (int) ($data['party_user_id'] ?? 0)
                : (int) $p['contact_id'] === (int) ($data['party_contact_id'] ?? 0)));
        if (! $party) {
            throw new \InvalidArgumentException('That person is not one of the parties expected at this inspection.');
        }

        $notification = RentalInspectionNotification::create([
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'event' => RentalInspectionNotification::EVENT_INVITATION_MANUAL,
            'party_role' => $role === RentalInspectionAttendance::PARTY_AGENT ? RentalInspectionNotification::PARTY_INSPECTOR : $role,
            'recipient_contact_id' => $party['contact_id'],
            'recipient_user_id' => $party['user_id'],
            'channel' => RentalInspectionNotification::CHANNEL_MANUAL,
            'recipient' => null,
            'status' => RentalInspectionNotification::STATUS_SENT,
            'error' => null,
            'sent_by_user_id' => $by->id,
            'occurred_at' => $occurred,
            'method' => $method,
        ]);

        RentalInspectionAuditLog::record(
            $inspection,
            RentalInspectionAuditLog::EVENT_INVITATION_RECORDED,
            'Invitation given to ' . $party['name'] . ' recorded (' . $method . ', ' . $occurred->format('j M Y H:i') . ').',
            null,
            ['party_role' => $role, 'method' => $method, 'occurred_at' => $occurred->toIso8601String()],
            $by,
        );

        return $notification;
    }

    private function normaliseTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m)) {
            throw new \InvalidArgumentException('The arrival time must look like 14:30.');
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    // ═══ List-screen helpers ═══════════════════════════════════════════════════

    /** Live attended count per inspection id (expected parties only — never the extra people in the room). */
    public function attendedCountsFor(array $inspectionIds): array
    {
        if ($inspectionIds === []) {
            return [];
        }

        return RentalInspectionAttendance::query()
            ->whereIn('rental_inspection_id', $inspectionIds)
            ->whereIn('party_role', [RentalInspectionAttendance::PARTY_TENANT, RentalInspectionAttendance::PARTY_LANDLORD, RentalInspectionAttendance::PARTY_AGENT])
            ->where('outcome', RentalInspectionAttendance::OUTCOME_ATTENDED)
            ->live()
            ->selectRaw('rental_inspection_id, COUNT(*) as c')
            ->groupBy('rental_inspection_id')
            ->pluck('c', 'rental_inspection_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * The ids, among an already own/branch/agency-scoped inspection query, whose attendance is not fully
     * recorded: an in/out inspection (ad_hoc and cancelled never count) with fewer recorded outcomes than
     * expected parties. Resolved in PHP over the scoped id set because "expected parties" is not a column.
     *
     * @return array<int, int>
     */
    public function incompleteInspectionIds(\Illuminate\Database\Eloquent\Builder $scopedQuery): array
    {
        $candidates = (clone $scopedQuery)
            ->setEagerLoads([])
            ->where('rental_inspections.type', '!=', RentalInspection::TYPE_AD_HOC)
            ->where('rental_inspections.status', '!=', RentalInspection::STATUS_CANCELLED)
            ->get(['rental_inspections.id', 'rental_inspections.lease_id', 'rental_inspections.property_id', 'rental_inspections.inspector_user_id', 'rental_inspections.created_by_user_id']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $expected = $this->expectedCountsFor($candidates);
        $recorded = RentalInspectionAttendance::query()
            ->whereIn('rental_inspection_id', $candidates->pluck('id')->all())
            ->whereIn('party_role', [RentalInspectionAttendance::PARTY_TENANT, RentalInspectionAttendance::PARTY_LANDLORD, RentalInspectionAttendance::PARTY_AGENT])
            ->live()
            ->selectRaw('rental_inspection_id, COUNT(*) as c')
            ->groupBy('rental_inspection_id')
            ->pluck('c', 'rental_inspection_id');

        return $candidates
            ->filter(fn ($i) => (int) ($recorded[$i->id] ?? 0) < (int) ($expected[$i->id] ?? 0))
            ->pluck('id')
            ->all();
    }

    /**
     * Expected-party count per inspection id for a page of inspections — two grouped queries rather than
     * a board() per row. Same rule as expectedParties(): tenants on the lease + invited landlords (or the
     * signing landlord) + one inspector.
     *
     * @param  Collection<int, RentalInspection>  $inspections
     * @return array<int, int>
     */
    public function expectedCountsFor(Collection $inspections): array
    {
        $leaseIds = $inspections->pluck('lease_id')->filter()->unique()->all();
        $propertyIds = $inspections->pluck('property_id')->filter()->unique()->all();

        $tenantCounts = $leaseIds === [] ? collect() : LeaseTenant::query()
            ->whereIn('lease_id', $leaseIds)->selectRaw('lease_id, COUNT(DISTINCT contact_id) as c')->groupBy('lease_id')->pluck('c', 'lease_id');

        $landlordRows = $propertyIds === [] ? collect() : ContactProperty::query()
            ->whereIn('property_id', $propertyIds)->whereIn('role', ['landlord', 'lessor', 'seller', 'owner'])
            ->get(['property_id', 'contact_id', 'role'])->groupBy('property_id');

        $out = [];
        foreach ($inspections as $inspection) {
            $rows = $landlordRows->get($inspection->property_id, collect());
            $tagged = $rows->whereIn('role', ['landlord', 'lessor'])->pluck('contact_id')->unique()->count();
            $landlords = $tagged > 0 ? $tagged : $rows->whereIn('role', ['seller', 'owner'])->pluck('contact_id')->unique()->count();
            $out[$inspection->id] = (int) ($tenantCounts[$inspection->lease_id] ?? 0) + $landlords + (($inspection->inspector_user_id || $inspection->created_by_user_id) ? 1 : 0);
        }

        return $out;
    }
}
