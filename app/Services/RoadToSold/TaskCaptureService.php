<?php

namespace App\Services\RoadToSold;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Support\AppDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 2 - the capture form of an agency's custom ("captured") task (road-to-sold.md sections 5.1 Type B, 5.3, 10.5).
 *
 * A custom task is not a tickbox: the agent says what they found and CoreX makes the real records.
 *   property   each property goes through TrackedPropertyMatchOrCreateService::matchOrCreate (source "area_drive", appended to the
 *              source chain - the Universal Match-or-Create Rule): a property CoreX already knows is matched and enriched, never doubled
 *   contact    each person is found by phone or email first (ContactDuplicateService) and only made when new, through the normal
 *              Contact save (so the contact.created event, audit and phone/email rows all happen as everywhere else)
 *   both       a property plus its owner: the contact is linked as the property's owner when it has none (which raises the
 *              "owner found" event, so step 2 ticks by itself)
 *   none       a note (and a photo when the agency asks for one) - for an activity, not a find
 * The completion lists every record it produced; the properties are put on the agent's list for today and on each night's list until
 * worked (ListBuilderService), so a find flows into the next steps.
 *
 * Whose work: always the list owner's. An assistant's capture is recorded under their agent with the assistant as the one who did it.
 * Idempotent: the form carries a token, so a second press of Save saves nothing more. Nothing is hard deleted - archive and restore.
 *
 * Every refusal is a ValidationException keyed by the form field, so the page can link each message to the spot to fix.
 */
class TaskCaptureService
{
    public const MAX_PROPERTIES = 25;
    public const MAX_CONTACTS   = 25;
    public const MAX_PHOTOS     = 5;
    public const MAX_PHOTO_KB   = 8192;

    public function __construct(
        private readonly TaskCompletionService $engine,
        private readonly TrackedPropertyMatchOrCreateService $match,
        private readonly ContactDuplicateService $dupes,
        private readonly ListBuilderService $lists,
    ) {
    }

    /** The captured tasks this agent can capture today: active, in their level, step on. @return Collection<int,RtsTask> */
    public function capturableTasks(User $agent, string $track): Collection
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: $agent->agency_id ?: 0);
        $level = $agencyId > 0 ? $this->engine->levelFor($agencyId, $track, $agent) : null;
        if (! $level) {
            return collect();
        }
        $inLevel = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->pluck('task_id');
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('is_enabled', true)->select('id');

        return RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->where('type', RtsTask::TYPE_CUSTOM)->where('is_active', true)
            ->whereIn('id', $inLevel)->whereIn('step_id', $steps)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * Save one capture for $agent (the list owner). $actor is who pressed Save.
     *
     * @param  array<string,mixed> $input  task_id, token, area_label, occurred_at, note, quantity, properties[], contacts[], photos[]
     * @return array{completion:?RtsTaskCompletion,duplicate:bool,properties:int,contacts:int,listed:int,created:int}
     * @throws ValidationException
     */
    public function capture(User $actor, User $agent, array $input, string $track = 'sales'): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: $agent->agency_id ?: 0);
        $task = $this->capturableTasks($agent, $track)->firstWhere('id', (int) ($input['task_id'] ?? 0));
        if (! $task) {
            throw ValidationException::withMessages(['task_id' => 'That task is not part of your recipe, so nothing was saved. Pick one from the list.']);
        }

        $token = trim((string) ($input['token'] ?? '')) ?: (string) Str::uuid();
        $none = ['completion' => null, 'duplicate' => true, 'properties' => 0, 'contacts' => 0, 'listed' => 0, 'created' => 0];
        if ($this->engine->hasSubmission((int) $agent->id, (int) $task->id, $token)) {
            return $none; // the same press of Save twice - already saved
        }

        $settings = SettingsService::forAgency($agencyId, $track);
        $tz = AppDate::agencyTimezone(Agency::query()->withoutGlobalScopes()->find($agencyId));
        $occurredAt = $this->occurredAt($input['occurred_at'] ?? null, $tz, $settings->captureBackDateHours());
        $properties = $this->propertyRows($input['properties'] ?? [], $task);
        $contacts   = $this->contactRows($input['contacts'] ?? [], $task);
        $photos     = array_values(array_filter((array) ($input['photos'] ?? []), fn ($f) => $f instanceof UploadedFile));
        $note       = trim((string) ($input['note'] ?? ''));

        $errors = $this->rules($task, $properties, $contacts, $photos, $note);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $stored = [];
        try {
            foreach ($photos as $photo) {
                $stored[] = Storage::disk('local')->putFile("rts-evidence/{$agencyId}/{$agent->id}", $photo);
            }

            return DB::transaction(function () use ($actor, $agent, $agencyId, $settings, $task, $track, $token, $occurredAt, $properties, $contacts, $note, $stored, $input) {
                $records = [];
                $trackedIds = [];
                $created = 0;
                $contactCount = 0;
                $areaLabel = trim((string) ($input['area_label'] ?? '')) ?: null;

                foreach ($properties as $i => $row) {
                    $tp = $this->property($agencyId, $actor, $task, $token, $i, $row, $areaLabel);
                    $created += $tp->wasRecentlyCreated ? 1 : 0;
                    $trackedIds[] = (int) $tp->id;
                    $records[] = [TrackedProperty::class, (int) $tp->id];
                    if ($task->capture_kind === 'both' && $row['owner_name'] !== '') {
                        $contact = $this->contact($agencyId, $actor, $agent, $task, $row['owner_name'], $row['owner_phone'], $row['owner_email']);
                        $records[] = [Contact::class, (int) $contact->id];
                        $contactCount++;
                        $this->linkOwner($tp, $contact);
                    }
                }
                if ($task->capture_kind === 'contact') {
                    foreach ($contacts as $row) {
                        $contact = $this->contact($agencyId, $actor, $agent, $task, $row['name'], $row['phone'], $row['email']);
                        $records[] = [Contact::class, (int) $contact->id];
                        $contactCount++;
                    }
                }

                $quantity = $task->capture_kind === 'none' ? max(1, min(1000, (int) ($input['quantity'] ?? 1))) : 1;
                $kind = (int) $actor->id === (int) $agent->id ? RtsTaskCompletion::KIND_AGENT : RtsTaskCompletion::KIND_ASSISTANT;
                $completion = $this->engine->complete($task, $agent, $records[0] ?? null, [
                    'recorded_by'   => $actor,
                    'kind'          => $kind,
                    'occurred_at'   => $occurredAt,
                    'source'        => RtsTaskCompletion::SOURCE_CAPTURED,
                    'state'         => $this->heldForReview($agent, $settings) ? RtsTaskCompletion::STATE_PROVISIONAL : RtsTaskCompletion::STATE_CONFIRMED,
                    'quantity'      => $quantity,
                    'token'         => $token,
                    'records'       => $records,
                    'evidence_note' => $note !== '' ? $note : null,
                    'evidence'      => array_filter([
                        'area_label'       => $areaLabel,
                        'properties_found' => count($properties) ?: null,
                        'contacts_made'    => $contactCount ?: null,
                        'photos'           => $stored ?: null,
                    ]) ?: null,
                ]);
                if (! $completion) {
                    throw ValidationException::withMessages(['task_id' => 'That task is not part of your recipe, so nothing was saved. Pick one from the list.']);
                }

                $listed = $trackedIds !== []
                    ? $this->lists->addFound($agent, $trackedIds, 'Found by you: ' . $task->label, $track)
                    : 0;

                return ['completion' => $completion, 'duplicate' => false, 'properties' => count($trackedIds), 'contacts' => $contactCount, 'listed' => $listed, 'created' => $created];
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /**
     * Does this capture wait for a manager? Only when the agency asks for review, and never for someone who is themselves allowed to
     * review (a manager's own work would wait for a reviewer that may not exist, and nobody reviews their own work - TaskReviewService).
     */
    private function heldForReview(User $agent, SettingsService $settings): bool
    {
        return $settings->captureNeedsReview() && ! $agent->hasPermission('road_to_sold.review_completions');
    }

    // ── Archive, restore, change the note ─────────────────────────────────

    /** Only a captured completion can be changed by hand; an automatic one is the record of something that really happened. */
    public function canChange(RtsTaskCompletion $c): bool
    {
        return $c->source === RtsTaskCompletion::SOURCE_CAPTURED;
    }

    public function archive(RtsTaskCompletion $c): void
    {
        abort_unless($this->canChange($c), 403);
        $c->delete();
    }

    public function restore(RtsTaskCompletion $c): void
    {
        abort_unless($this->canChange($c), 403);
        $c->restore();
    }

    /** Change the note of a captured completion; a note the task requires cannot be emptied. */
    public function updateNote(RtsTaskCompletion $c, ?string $note): RtsTaskCompletion
    {
        abort_unless($this->canChange($c), 403);
        $note = trim((string) $note);
        $task = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()->find($c->task_id);
        $hasPhoto = ! empty($c->evidence['photos'] ?? null);
        if ($note === '' && $task && ($task->evidence_required === 'note' || ($task->evidence_required === 'note_or_photo' && ! $hasPhoto))) {
            throw ValidationException::withMessages(['note' => 'This task needs a note' . ($task->evidence_required === 'note_or_photo' ? ' or a photo' : '') . ' - write what you found.']);
        }
        if (mb_strlen($note) > 5000) {
            throw ValidationException::withMessages(['note' => 'Keep the note under 5000 characters.']);
        }
        $c->evidence_note = $note !== '' ? $note : null;
        $c->save();

        return $c;
    }

    // ── Input ─────────────────────────────────────────────────────────────

    /** @return Carbon the moment, ready to store */
    private function occurredAt(mixed $value, string $tz, int $backHours): Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return now();
        }
        try {
            $at = AppDate::parse($value, $tz);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['occurred_at' => 'We could not read that date and time. Pick it again.']);
        }
        if ($at->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => 'That time is in the future. Pick when it really happened.']);
        }
        if ($at->lt(now()->subHours($backHours))) {
            throw ValidationException::withMessages(['occurred_at' => "A capture can be back-dated by up to {$backHours} hours. Pick a later time."]);
        }

        return $at;
    }

    /** @return array<int,array<string,string>> the property rows the agent filled in, blank rows dropped (keys keep the form's row number) */
    private function propertyRows(mixed $raw, RtsTask $task): array
    {
        if (! in_array($task->capture_kind, ['property', 'both'], true)) {
            return [];
        }
        $out = [];
        foreach (array_slice((array) $raw, 0, self::MAX_PROPERTIES, true) as $i => $r) {
            $r = (array) $r;
            $row = [];
            foreach (['street_number', 'street_name', 'suburb', 'complex_name', 'unit_number', 'erf_number', 'owner_name', 'owner_phone', 'owner_email'] as $f) {
                $row[$f] = trim((string) ($r[$f] ?? ''));
            }
            if ($task->capture_kind !== 'both') {
                $row['owner_name'] = $row['owner_phone'] = $row['owner_email'] = '';
            }
            if (implode('', $row) !== '') {
                $out[(int) $i] = $row;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,string>> */
    private function contactRows(mixed $raw, RtsTask $task): array
    {
        if ($task->capture_kind !== 'contact') {
            return [];
        }
        $out = [];
        foreach (array_slice((array) $raw, 0, self::MAX_CONTACTS, true) as $i => $r) {
            $r = (array) $r;
            $row = [];
            foreach (['name', 'phone', 'email'] as $f) {
                $row[$f] = trim((string) ($r[$f] ?? ''));
            }
            if (implode('', $row) !== '') {
                $out[(int) $i] = $row;
            }
        }

        return $out;
    }

    /** @return array<string,string> field key => plain message (empty = fine) */
    private function rules(RtsTask $task, array $properties, array $contacts, array $photos, string $note): array
    {
        $errors = [];
        $n = fn (int $i) => '#' . ($i + 1);

        if (in_array($task->capture_kind, ['property', 'both'], true)) {
            if ($properties === []) {
                $errors['properties.0.street_name'] = 'Add at least one property you found - a street name or an erf number.';
            }
            foreach ($properties as $i => $r) {
                if ($r['street_name'] === '' && $r['erf_number'] === '') {
                    $errors["properties.{$i}.street_name"] = "Property {$n($i)}: add a street name or an erf number.";
                }
                if (($r['owner_phone'] !== '' || $r['owner_email'] !== '') && $r['owner_name'] === '') {
                    $errors["properties.{$i}.owner_name"] = "Property {$n($i)}: add the owner's name, or clear their phone and email.";
                }
                if ($r['owner_email'] !== '' && ! filter_var($r['owner_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors["properties.{$i}.owner_email"] = "Property {$n($i)}: that email address does not look right.";
                }
                foreach (['street_number' => 20, 'street_name' => 120, 'suburb' => 120, 'complex_name' => 120, 'unit_number' => 20, 'erf_number' => 40, 'owner_name' => 160, 'owner_phone' => 40, 'owner_email' => 190] as $f => $max) {
                    if (mb_strlen($r[$f]) > $max) {
                        $errors["properties.{$i}.{$f}"] = "Property {$n($i)}: that is too long (up to {$max} characters).";
                    }
                }
            }
        }
        if ($task->capture_kind === 'contact') {
            if ($contacts === []) {
                $errors['contacts.0.name'] = 'Add at least one person you met.';
            }
            foreach ($contacts as $i => $r) {
                if ($r['name'] === '') {
                    $errors["contacts.{$i}.name"] = "Person {$n($i)}: add a name.";
                }
                if ($r['email'] !== '' && ! filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors["contacts.{$i}.email"] = "Person {$n($i)}: that email address does not look right.";
                }
                foreach (['name' => 160, 'phone' => 40, 'email' => 190] as $f => $max) {
                    if (mb_strlen($r[$f]) > $max) {
                        $errors["contacts.{$i}.{$f}"] = "Person {$n($i)}: that is too long (up to {$max} characters).";
                    }
                }
            }
        }
        if (mb_strlen($note) > 5000) {
            $errors['note'] = 'Keep the note under 5000 characters.';
        }
        if (count($photos) > self::MAX_PHOTOS) {
            $errors['photos'] = 'You can attach up to ' . self::MAX_PHOTOS . ' photos.';
        }
        foreach ($photos as $p) {
            if (! str_starts_with((string) $p->getMimeType(), 'image/') || $p->getSize() > self::MAX_PHOTO_KB * 1024) {
                $errors['photos'] = 'Photos must be pictures of up to ' . (self::MAX_PHOTO_KB / 1024) . ' MB each.';
            }
        }
        if ($task->evidence_required === 'note' && $note === '') {
            $errors['note'] = 'This task needs a note - write what you found.';
        } elseif ($task->evidence_required === 'note_or_photo' && $note === '' && $photos === []) {
            $errors['note'] = 'This task needs a note or a photo of what you found.';
        }

        return $errors;
    }

    // ── Records ───────────────────────────────────────────────────────────

    /** @param array<string,string> $row */
    private function property(int $agencyId, User $actor, RtsTask $task, string $token, int $i, array $row, ?string $areaLabel): TrackedProperty
    {
        $street = trim($row['street_number'] . ' ' . $row['street_name']);
        $address = implode(', ', array_filter([
            $row['unit_number'] !== '' ? 'Unit ' . $row['unit_number'] : null, $row['complex_name'] ?: null, $street ?: null, $row['suburb'] ?: null,
        ]));

        return $this->match->matchOrCreate(
            agencyId: $agencyId,
            facts: array_filter([
                'address'       => $address ?: ($row['erf_number'] !== '' ? 'Erf ' . $row['erf_number'] : null),
                'street_number' => $row['street_number'],
                'street_name'   => $row['street_name'],
                'suburb'        => $row['suburb'],
                'complex_name'  => $row['complex_name'],
                'unit_number'   => $row['unit_number'],
                'erf_number'    => $row['erf_number'],
            ], fn ($v) => $v !== null && $v !== ''),
            source: [
                'type'    => 'area_drive',
                'ref'     => "rts-capture:{$token}:{$i}",
                'payload' => array_filter(['task_id' => (int) $task->id, 'task' => $task->label, 'area_label' => $areaLabel]),
            ],
            actorUserId: (int) $actor->id,
        );
    }

    /** A person, found by phone or email first and only made when new. */
    private function contact(int $agencyId, User $actor, User $agent, RtsTask $task, string $name, string $phone, string $email): Contact
    {
        if ($phone !== '' || $email !== '') {
            $existing = $this->dupes->findDuplicatesForIdentifiers($phone !== '' ? [$phone] : [], $email !== '' ? [$email] : [], null, $agencyId)->first();
            if ($existing) {
                return $existing;
            }
        }
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];
        $first = array_shift($parts);

        $c = new Contact([
            'first_name'         => $first,
            'last_name'          => implode(' ', $parts),
            'phone'              => $phone,
            'email'              => $email !== '' ? $email : null,
            'notes'              => 'Found on: ' . $task->label,
            'branch_id'          => $agent->branch_id,
            'agent_id'           => $agent->id,
            'created_by_user_id' => $actor->id,
        ]);
        $c->agency_id = $agencyId;
        $c->save();

        return $c;
    }

    /** Make the contact the property's owner when it has none (which raises "owner found"); an owner already on file is never replaced. */
    private function linkOwner(TrackedProperty $tp, Contact $contact): void
    {
        $hasOwner = ! empty($tp->owner_contact_id);
        TrackedPropertyOwner::firstOrCreate(
            ['tracked_property_id' => $tp->id, 'contact_id' => $contact->id],
            [
                'name'             => trim($contact->first_name . ' ' . $contact->last_name),
                'is_primary'       => ! $hasOwner,
                'role'             => TrackedPropertyOwner::ROLE_OWNER,
                'ownership_status' => TrackedPropertyOwner::OWNERSHIP_CURRENT,
            ]
        );
        if (! $hasOwner) {
            $tp->update(['owner_contact_id' => $contact->id]);
        }
    }
}
