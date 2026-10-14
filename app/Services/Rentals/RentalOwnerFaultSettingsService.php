<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalOwnerFaultSettingsMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalPropertyFaultSetting as Row;
use App\Models\RentalPropertyFaultSettingChange as Change;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - every WRITE to an owner's "How I want faults handled": the agent's capture (with evidence),
 * "Send to owner to confirm", the owner's confirm / change, the owner's "I do not agree" and the owner's request to change who looks at faults first.
 * Each writes the history in the same transaction; the fault flow only ever READS, through {@see RentalOwnerFaultSettings}.
 */
class RentalOwnerFaultSettingsService
{
    public const FIELDS = ['repair_limit', 'emergency_limit', 'callout_mode', 'callout_limit'];

    // ── the agent captures FOR the owner ─────────────────────────────────────

    /** @param array{repair_limit:mixed, emergency_limit:mixed, callout_mode:string, callout_limit:mixed} $values */
    public function capture(Property $property, User $agent, array $values, string $basis, \DateTimeInterface $occurredAt, string $note, ?UploadedFile $file = null): Row
    {
        $values = $this->clean($values);
        $note = trim($note);

        $row = DB::transaction(function () use ($property, $agent, $values, $basis, $occurredAt, $note, $file) {
            $row = $this->lockedRow($property);
            $old = $this->valuesOf($row);
            $path = $name = null;
            if ($file) {
                $name = mb_substr($file->getClientOriginalName(), 0, 191);
                $path = $file->storeAs('rental-fault-settings/' . $property->id, now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . ($file->getClientOriginalExtension() ?: 'dat'), 'local');
            }
            $row->forceFill($values + [
                'status' => Row::STATUS_CAPTURED, 'version' => (int) $row->version + 1, 'capture_basis' => $basis, 'capture_occurred_at' => $occurredAt,
                'capture_note' => $note, 'capture_file_path' => $path ?? $row->capture_file_path, 'capture_file_name' => $name ?? $row->capture_file_name,
                'captured_by_user_id' => $agent->id, 'captured_at' => now(),
                'confirmed_at' => null, 'confirmed_by_contact_id' => null, 'disputed_at' => null, 'disputed_by_contact_id' => null, 'dispute_note' => null,
            ])->save();
            $this->log($row, 'captured', $old, $this->valuesOf($row), ['user_id' => $agent->id, 'basis' => $basis, 'occurred_at' => $occurredAt, 'note' => $note]);

            return $row;
        });

        $this->emailOwners($row);

        return $row;
    }

    /** "Send to owner to confirm": the same email as a capture, again. */
    public function sendToOwner(Property $property, User $agent): ?Row
    {
        $row = Row::withoutGlobalScopes()->where('property_id', $property->id)->first();
        if (! $row || $row->status === Row::STATUS_CONFIRMED) {
            return null;
        }
        $row->forceFill(['last_sent_to_owner_at' => now()])->save();
        $this->log($row, 'sent_to_owner', $this->valuesOf($row), $this->valuesOf($row), ['user_id' => $agent->id, 'note' => 'Sent to the owner to confirm.']);
        $this->emailOwners($row);

        return $row;
    }

    // ── the owner ────────────────────────────────────────────────────────────

    /** The owner saves all three answers as THEIR OWN (a changed value is logged as a change). @return 'confirmed'|'changed'|'unchanged' */
    public function ownerConfirm(Property $property, Contact $owner, array $values): string
    {
        $values = $this->clean($values);

        return DB::transaction(function () use ($property, $owner, $values) {
            $row = $this->lockedRow($property);
            $old = $this->valuesOf($row);
            $priorStatus = $row->status;
            $valuesSame = $old == $this->valuesOf($row->replicate()->forceFill($values));
            if ($row->status === Row::STATUS_CONFIRMED && $valuesSame) {
                return 'unchanged';
            }
            $row->forceFill($values + [
                'status' => Row::STATUS_CONFIRMED, 'version' => (int) $row->version + 1, 'confirmed_at' => now(), 'confirmed_by_contact_id' => $owner->id,
                'disputed_at' => null, 'disputed_by_contact_id' => null, 'dispute_note' => null,
            ])->save();
            // the first time an owner answers there is nothing to have "changed": they confirm their own values
            $changed = $priorStatus !== Row::STATUS_NOT_SET && ! $valuesSame;
            $this->log($row, $changed ? 'owner_changed' : 'confirmed', $old, $this->valuesOf($row), ['contact_id' => $owner->id]);

            return $changed ? 'changed' : 'confirmed';
        });
    }

    /**
     * "I do not agree with this". $version is the version the owner's email was written for; a link for an older version is refused (returns
     * 'stale'). Records who/when/why, sets `disputed`, and alerts both lease agents. @return 'recorded'|'already'|'stale'|'none'
     */
    public function dispute(Property $property, Contact $owner, int $version, ?string $reason): string
    {
        $row = null;
        $result = DB::transaction(function () use ($property, $owner, $version, $reason, &$row) {
            $row = Row::withoutGlobalScopes()->where('property_id', $property->id)->lockForUpdate()->first();
            if (! $row) {
                return 'none';
            }
            if ($row->status === Row::STATUS_DISPUTED) {
                return 'already';
            }
            if ((int) $row->version !== $version) {
                return 'stale';
            }
            $old = $this->valuesOf($row);
            $reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 1000) : null;
            $row->forceFill(['status' => Row::STATUS_DISPUTED, 'disputed_at' => now(), 'disputed_by_contact_id' => $owner->id, 'dispute_note' => $reason])->save();
            $this->log($row, 'disputed', $old, $this->valuesOf($row), ['contact_id' => $owner->id, 'note' => $reason]);

            return 'recorded';
        });

        if ($result === 'recorded') {
            $name = trim($owner->first_name . ' ' . $owner->last_name) ?: 'The owner';
            $title = 'Owner does not agree with the fault-handling settings - ' . $property->buildDisplayAddress();
            $body = $name . ' pressed "I do not agree" on the settings recorded for this property' . ($row->dispute_note ? ': "' . $row->dispute_note . '"' : '.')
                . ' Until they confirm, or you record new evidence, every decision goes to the owner.';
            $this->alertAgents($property, 'rental_fault_settings.disputed', $row, $title, $body);
            $this->emailAgents($property, $title, $body);
        }

        return $result;
    }

    /** The owner asks, from their portal, to change who looks at faults first (nothing changes by itself - both lease agents are told). */
    public function requestFirstResponderChange(Property $property, Contact $owner, ?string $note): void
    {
        $row = $this->lockedRow($property, create: false) ?? Row::withoutGlobalScopes()->where('property_id', $property->id)->first();
        $note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 1000) : null;
        if ($row) {
            $this->log($row, 'change_requested', $this->valuesOf($row), $this->valuesOf($row), ['contact_id' => $owner->id, 'note' => $note]);
        } else {
            DB::table('rental_property_fault_setting_changes')->insert([
                'agency_id' => $property->agency_id, 'property_id' => $property->id, 'action' => 'change_requested', 'contact_id' => $owner->id,
                'status_after' => Row::STATUS_NOT_SET, 'note' => $note, 'created_at' => now(),
            ]);
        }
        $name = trim($owner->first_name . ' ' . $owner->last_name) ?: 'The owner';
        $this->alertAgents($property, 'rental_fault_settings.change_requested', $row ?? $this->touchRow($property),
            'Owner asks to change who looks at faults first - ' . $property->buildDisplayAddress(),
            $name . ' would like a change to who looks at faults first.' . ($note ? ' "' . $note . '"' : ''));
    }

    // ── reading, for the two screens ─────────────────────────────────────────

    /** The history in plain words, newest first. @return Collection<int, array{at:string, text:string}> */
    public function history(Property $property, int $limit = 30): Collection
    {
        return Change::withoutGlobalScopes()->with(['user', 'contact'])->where('property_id', $property->id)->orderByDesc('id')->limit($limit)->get()->map(fn (Change $c) => [
            'at' => $c->created_at?->format('j M Y H:i') ?? '',
            'text' => $this->sentence($c),
        ]);
    }

    public function sentence(Change $c): string
    {
        $who = $c->user?->name ?: ($c->contact ? trim($c->contact->first_name . ' ' . $c->contact->last_name) . ' (owner)' : 'The agency');
        $vals = $this->valuesSentence($c->new_values ?? []);
        $basis = $c->basis === Row::BASIS_CARRIED_OVER ? 'carried over from the existing work terms' : (Row::BASES[$c->basis] ?? null);
        $evidence = $basis ? ' Told to us by ' . strtolower($basis) . ($c->occurred_at ? ' on ' . $c->occurred_at->format('j M Y') : '') . '.' : '';
        $note = $c->note ? ' "' . $c->note . '"' : '';

        return match ($c->action) {
            'captured' => $who . ' recorded these for the owner: ' . $vals . '.' . $evidence . $note,
            'carried_over' => 'Carried over from the existing work terms: ' . $vals . '. Not yet confirmed by the owner.',
            'confirmed' => $who . ' confirmed: ' . $vals . '.',
            'owner_changed' => $who . ' changed and confirmed: ' . $vals . '.',
            'disputed' => $who . ' does not agree with these settings.' . $note,
            'sent_to_owner' => $who . ' sent these to the owner to confirm.',
            'change_requested' => $who . ' asked to change who looks at faults first.' . $note,
            default => $who . ' ' . $c->action . '.',
        };
    }

    /** "repairs up to R1 000, emergency repairs up to R5 000, call-out fees: yes, up to R750" - the same words in screens, emails and history. */
    public function valuesSentence(array $v): string
    {
        $money = fn ($x) => $x === null ? 'not set' : 'up to ' . \App\Models\DealV2\AgencyServiceProvider::formatRand((float) $x);
        $callout = ($v['callout_mode'] ?? null) === 'yes' ? 'yes, ' . $money($v['callout_limit'] ?? null) : (($v['callout_mode'] ?? null) === 'no' ? 'no, ask first' : 'not set');

        return 'repairs ' . $money($v['repair_limit'] ?? null) . ', emergency repairs ' . $money($v['emergency_limit'] ?? null) . ', call-out fees: ' . $callout;
    }

    /** The owners of a property with an email address (the lease's landlords, else the property's landlord / lessor contacts). */
    public function owners(Property $property): Collection
    {
        $lease = $this->leaseOf($property);
        $owners = $lease ? $lease->landlordContacts() : collect();
        if ($owners->isEmpty()) {
            $owners = $property->contactsForRole('landlord')->merge($property->contactsForRole('lessor'))->merge($property->contactsForRole('owner'))->unique('id');
        }

        return $owners->filter(fn ($c) => $c->email)->values();
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function emailOwners(Row $row): void
    {
        $property = Property::withoutGlobalScopes()->find($row->property_id);
        if (! $property) {
            return;
        }
        $row->forceFill(['last_sent_to_owner_at' => now()])->save();
        foreach ($this->owners($property) as $owner) {
            try {
                Mail::to($owner->email)->send(new RentalOwnerFaultSettingsMail($row->fresh(), $property, $owner));
            } catch (\Throwable $e) {
                Log::warning('Owner fault-settings email failed', ['property' => $property->id, 'contact' => $owner->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function alertAgents(Property $property, string $event, Row $subject, string $title, string $body): void
    {
        try {
            $lease = $this->leaseOf($property);
            $agents = app(LeaseAgentService::class)->recipientsForEvent($lease, $property, (int) $property->agency_id, null);
            foreach ($agents as $agent) {
                app(NotificationDispatcher::class)->fire($agent, $event, $subject, [
                    'title' => \App\Support\RentalAlertTitle::withProperty($title, $property), 'body' => $body,
                    'action_url' => route('corex.properties.show', $property) . '?tab=rental', 'severity' => 'warning', 'threshold_hit_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Owner fault-settings alert failed', ['property' => $property->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** A dispute is also EMAILED to both lease agents directly (in-app alone depends on each agent's own notification switches). */
    private function emailAgents(Property $property, string $title, string $body): void
    {
        try {
            $agents = app(LeaseAgentService::class)->recipientsForEvent($this->leaseOf($property), $property, (int) $property->agency_id, null);
            foreach ($agents->filter(fn ($a) => $a->email)->unique('email') as $agent) {
                Mail::to($agent->email)->send(new \App\Mail\Rentals\RentalFaultSettingsDisputedMail($agent->name, $title, $body, route('corex.properties.show', $property) . '?tab=rental'));
            }
        } catch (\Throwable $e) {
            Log::warning('Owner fault-settings dispute email failed', ['property' => $property->id, 'error' => $e->getMessage()]);
        }
    }

    private function leaseOf(Property $property): ?Lease
    {
        return Lease::withoutGlobalScopes()->where('property_id', $property->id)->whereNull('deleted_at')
            ->orderByRaw("status = 'active' desc")->orderByDesc('id')->first();
    }

    private function lockedRow(Property $property, bool $create = true): ?Row
    {
        $row = Row::withoutGlobalScopes()->where('property_id', $property->id)->lockForUpdate()->first();
        if (! $row && $create) {
            $row = Row::withoutGlobalScopes()->create(['agency_id' => $property->agency_id, 'property_id' => $property->id, 'status' => Row::STATUS_NOT_SET, 'version' => 0]);
        }

        return $row;
    }

    /** a row with no answers yet, only so an alert has something to point at */
    private function touchRow(Property $property): Row
    {
        return Row::withoutGlobalScopes()->firstOrCreate(['property_id' => $property->id], ['agency_id' => $property->agency_id, 'status' => Row::STATUS_NOT_SET, 'version' => 0]);
    }

    /** @return array{repair_limit:?float, emergency_limit:?float, callout_mode:?string, callout_limit:?float} */
    private function clean(array $v): array
    {
        $num = fn ($x) => $x === null || $x === '' ? null : round((float) str_replace([' ', ','], ['', '.'], (string) $x), 2);
        $mode = in_array($v['callout_mode'] ?? null, ['yes', 'no'], true) ? $v['callout_mode'] : null;

        return [
            'repair_limit' => $num($v['repair_limit'] ?? null), 'emergency_limit' => $num($v['emergency_limit'] ?? null),
            'callout_mode' => $mode, 'callout_limit' => $mode === 'yes' ? $num($v['callout_limit'] ?? null) : null,
        ];
    }

    private function valuesOf(Row $row): array
    {
        return [
            'repair_limit' => $row->repair_limit !== null ? (float) $row->repair_limit : null, 'emergency_limit' => $row->emergency_limit !== null ? (float) $row->emergency_limit : null,
            'callout_mode' => $row->callout_mode, 'callout_limit' => $row->callout_limit !== null ? (float) $row->callout_limit : null,
        ];
    }

    private function log(Row $row, string $action, array $old, array $new, array $extra = []): void
    {
        Change::withoutGlobalScopes()->create([
            'agency_id' => $row->agency_id, 'property_id' => $row->property_id, 'action' => $action, 'user_id' => $extra['user_id'] ?? null, 'contact_id' => $extra['contact_id'] ?? null,
            'old_values' => $old, 'new_values' => $new, 'status_after' => $row->status, 'basis' => $extra['basis'] ?? null, 'occurred_at' => $extra['occurred_at'] ?? null,
            'note' => $extra['note'] ?? null,
        ]);
    }
}
