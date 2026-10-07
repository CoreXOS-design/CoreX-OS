<?php

namespace App\Services\Rentals;

use App\Events\Document\DocumentUploaded;
use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.2 / §15.4 / §15.6 (Build L2). The ONE writer behind the single capture screen:
 * lease + tenants + agreement terms in one transaction, for a new lease and for a renewal, for "Create
 * lease only", the signed paper copy and "Create lease & prepare for signing".
 *
 * "Create lease & prepare for signing" in THIS build (conductor ruling, 7 Oct 2026, on the spec gap that
 * the L2 plan never says what the button does before the launcher exists): it checks the agency has a
 * linked, ready lease agreement and that every agreement detail the agency's own lease marks required is
 * filled in, creates the lease as a DRAFT remembering which agreement it is for, and stops — NO e-sign
 * document is created and nothing is sent. Opening the document is Build L3a's, on the same intent.
 *
 * Everything written for one capture sits in ONE transaction: an activation refused by the overlap guard
 * (§3.5) rolls the whole capture back, so a half-created lease can never be left behind (§15.22 #1).
 * A hidden `capture_key` makes a double-click or a back-and-resubmit return the same lease (§15.4).
 */
class LeaseCaptureService
{
    public const INTENT_LEASE_ONLY = 'lease_only';
    public const INTENT_LEASE_AND_SIGN = 'lease_and_sign';
    public const INTENT_PAPER_COPY = 'paper_copy';

    public const INTENTS = [self::INTENT_LEASE_ONLY, self::INTENT_LEASE_AND_SIGN, self::INTENT_PAPER_COPY];

    /** Registry groups the capture screen asks the agent to type; the rest come from the lease, contacts or a calculation. */
    private const TYPED_GROUPS = ['agreement', 'schedule'];

    public function __construct(
        private readonly LeaseAgreementTemplateGuard $guard,
        private readonly LeaseAgreementValuesReader $reader,
        private readonly PreviousTermValuesReader $previousTerms,
    ) {
    }

    // ── What the screen needs to know about the agency's lease agreement ──────────────────────

    /**
     * Whether the agency has a linked, ready lease agreement (R3).
     *   ready     — at least one passes the guard; `agreements` lists them, the default first.
     *   attention — it has one, but none passes the guard now (archived, un-mapped, no signing place…);
     *               `reason` is the first problem, in plain words (§15.16 "needs attention").
     *   none      — it has never set one up (the real starting state of EVERY agency, F1).
     *
     * @return array{state: string, agreements: Collection<int, RentalLeaseTemplate>, reason: ?string}
     */
    public function agreementStateFor(int $agencyId): array
    {
        $ready = $this->guard->readyAgreementsFor($agencyId);
        if ($ready->isNotEmpty()) {
            return ['state' => 'ready', 'agreements' => $ready, 'reason' => null];
        }

        $row = RentalLeaseTemplate::query()
            ->where('agency_id', $agencyId)
            ->where('category', RentalLeaseTemplate::CATEGORY_RESIDENTIAL)
            ->where('is_active', true)
            ->with('template')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (! $row) {
            return ['state' => 'none', 'agreements' => collect(), 'reason' => null];
        }

        $problems = $this->guard->statusFor($row, $agencyId)['problems'];

        return ['state' => 'attention', 'agreements' => collect(), 'reason' => $problems[0] ?? null];
    }

    /**
     * The agreement details the agency's own lease carries — and ONLY those (§15.3). Known registry
     * fields in registry order, then any agency-specific extras the map declares. A key that is a
     * per-party member of an indexed family (tenant_name_2) or comes from the lease, a contact or a
     * calculation is not asked for here.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, column: ?string, extra: bool, max: int}>
     */
    public function agreementFields(RentalLeaseTemplate $agreement): array
    {
        $registry = (array) config('lease-agreement-fields.fields', []);
        $mapped = $this->reader->normaliseMap((array) ($agreement->field_map ?? []));
        $fields = [];

        $build = function (string $key, array $entry, ?array $def): array {
            $type = $def['type'] ?? 'text';
            $column = $def['column'] ?? null;

            return [
                'key' => $key,
                'label' => $entry['label'] ?? ($def['label'] ?? Str::headline($key)),
                'type' => $type,
                'required' => (bool) $entry['required'],
                'column' => $column,
                'extra' => $column === null,
                'max' => match (true) {
                    $type === 'longtext' => 5000,
                    $column === 'electricity_arrangement' => 500,
                    $column === null => 1000,
                    default => 255,
                },
            ];
        };

        foreach ($registry as $key => $def) {
            if (isset($mapped[$key]) && in_array($def['group'] ?? '', self::TYPED_GROUPS, true)) {
                $fields[] = $build($key, $mapped[$key], $def);
            }
        }
        foreach ($mapped as $key => $entry) {
            if (isset($registry[$key])) {
                continue;
            }
            // tenant_name_2 / landlord_name_3 … belong to an indexed registry family, not to the agreement.
            if (preg_match('/^(.*)_\d+$/', $key, $m) && ! empty($registry[$m[1]]['indexed'])) {
                continue;
            }
            $fields[] = $build($key, $entry, null);
        }

        return $fields;
    }

    /**
     * §15.4 step 1 (agreement part) — the required agreement details still blank.
     *
     * @param array<string,mixed> $values the typed agreement values, key => value
     * @return array<int, array{key: string, label: string}>
     */
    public function missingForSigning(RentalLeaseTemplate $agreement, array $values): array
    {
        $missing = [];
        foreach ($this->agreementFields($agreement) as $field) {
            if ($field['required'] && $this->normalise($values[$field['key']] ?? null) === null) {
                $missing[] = ['key' => $field['key'], 'label' => $field['label']];
            }
        }

        return $missing;
    }

    /**
     * What a renewal pre-fills from the previous term (§15.3 precedence 2 + 4, §15.6.3/4): the previous
     * rent, deposit, lease type, a start date the day after it ended (today when it had no end), and the
     * previous term's agreement details for the fields the CURRENT agreement carries. Dates shift to the
     * new term — the earliest termination date moves by the same offset as the start date.
     *
     * `on_record` says, per agreement field, whether the previous term holds a value (the screen marks
     * the ones it does not "not on record" — a wet-ink first lease starts with none of them).
     *
     * @param array<int, array<string,mixed>> $fields from agreementFields()
     * @return array{start_date: string, rental_amount: ?string, deposit_amount: ?string, lease_type: ?string, agreement: array<string,mixed>, on_record: array<string,bool>}
     */
    public function renewalDefaults(Lease $previous, array $fields): array
    {
        $newStart = $previous->end_date && ! $previous->is_month_to_month
            ? $previous->end_date->copy()->addDay()
            : now()->startOfDay();

        $terms = $this->previousTerms->for($previous);
        $values = [];
        $onRecord = [];

        foreach ($fields as $field) {
            $value = $terms ? $this->termValue($terms, $field) : null;
            if ($value instanceof \DateTimeInterface) {
                $date = Carbon::instance($value);
                if ($field['key'] === 'earliest_termination_date' && $previous->start_date) {
                    $date = $date->copy()->addDays((int) $previous->start_date->diffInDays($newStart, false));
                }
                $value = $date->format('Y-m-d');
            }

            $values[$field['key']] = $value;
            $onRecord[$field['key']] = $this->normalise($value) !== null;
        }

        return [
            'start_date' => $newStart->toDateString(),
            'rental_amount' => $previous->rental_amount !== null ? (string) $previous->rental_amount : null,
            'deposit_amount' => $previous->deposit_amount !== null ? (string) $previous->deposit_amount : null,
            'lease_type' => $previous->lease_type,
            'agreement' => $values,
            'on_record' => $onRecord,
        ];
    }

    // ── The writer ────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $input    the validated capture-screen fields (§15.3): property_id and
     *                                      tenant_contact_ids (new only), rental_amount, deposit_amount,
     *                                      start_date, end_date, is_month_to_month, lease_type,
     *                                      rental_application_id, activate_immediately, agreement_id,
     *                                      agreement (key => value), capture_key, signed_document (file)
     * @param string              $intent   one of the INTENT_* constants
     * @param Lease|null          $previous the lease being renewed, null for a new lease
     *
     * @throws NoLeaseAgreementLinkedException    "prepare for signing" with no usable lease agreement
     * @throws LeaseCaptureIncompleteException    "prepare for signing" with required agreement details blank
     * @throws ValidationException                an overlap/activation refusal, a missing paper copy, …
     */
    public function capture(array $input, string $intent, User $user, ?Lease $previous = null): Lease
    {
        if (! in_array($intent, self::INTENTS, true)) {
            throw ValidationException::withMessages(['intent' => 'Choose what to do with this lease.']);
        }

        $property = $previous
            ? $previous->property
            : Property::query()->rentalVisibleTo($user)->findOrFail((int) ($input['property_id'] ?? 0));
        $agencyId = (int) ($previous?->agency_id ?? $property->agency_id);

        $agreement = $this->resolveAgreement($agencyId, $input['agreement_id'] ?? null, $intent);
        $agreementValues = (array) ($input['agreement'] ?? []);

        if ($intent === self::INTENT_LEASE_AND_SIGN) {
            if (! $agreement) {
                throw new NoLeaseAgreementLinkedException();
            }
            if (! $user->hasPermission('access_docuperfect') || ! $user->hasPermission('create_docuperfect_docs')) {
                throw ValidationException::withMessages(['intent' => 'You do not have access to prepare agreements.']);
            }
            // Belt and braces (§15.12.4 call site 3): the guard decides, not the picker.
            try {
                $this->guard->assertUsable($agreement->template, $agencyId, (array) ($agreement->field_map ?? []));
            } catch (ValidationException) {
                throw new NoLeaseAgreementLinkedException();
            }
            $missing = $this->missingForSigning($agreement, $agreementValues);
            if ($missing !== []) {
                throw LeaseCaptureIncompleteException::forMissing($missing);
            }
        }

        $file = $input['signed_document'] ?? null;
        if ($intent === self::INTENT_PAPER_COPY && ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['signed_document' => 'Attach the signed copy.']);
        }

        $captureKey = $this->storedKey($input['capture_key'] ?? null, $user);
        if ($captureKey && ($existing = Lease::withoutGlobalScopes()->where('capture_key', $captureKey)->first())) {
            return $existing;
        }

        $storedPath = null;

        try {
            return DB::transaction(function () use ($input, $intent, $user, $previous, $property, $agreement, $agreementValues, $captureKey, $file, &$storedPath) {
                $lease = $previous
                    ? $this->createRenewalTerm($previous, $input, $user)
                    : $this->createNewLease($property, $input, $user);

                $lease->update([
                    'capture_key' => $captureKey,
                    'agreement_template_id' => $intent === self::INTENT_LEASE_AND_SIGN && $agreement
                        ? (int) $agreement->docuperfect_template_id
                        : null,
                ]);

                if ($agreement && $agreementValues !== []) {
                    $this->writeTerms($lease, $agreement, $agreementValues);
                }

                $this->logEvent($lease, LeaseEvent::TYPE_LEASE_CREATED, $this->createdDescription($intent, $previous), $user, [
                    'intent' => $intent,
                    'previous_lease_id' => $previous?->id,
                    'agreement_template_id' => $lease->agreement_template_id,
                ]);

                if ($intent === self::INTENT_PAPER_COPY) {
                    $this->attachSignedCopy($lease, $file, $user, $previous !== null, $storedPath);
                    $lease = $this->activate($lease, $previous !== null, $user);
                    $lease->update([
                        'signing_status' => Lease::SIGNING_SIGNED_ON_PAPER,
                        'source' => Lease::SOURCE_UPLOADED_SIGNED_COPY,
                    ]);
                    $this->logEvent($lease, LeaseEvent::TYPE_LEASE_SIGNED_ON_PAPER, 'Signed paper copy attached — lease active', $user);
                } elseif ($intent === self::INTENT_LEASE_ONLY && ! empty($input['activate_immediately'])) {
                    $lease = $this->activate($lease, $previous !== null, $user);
                }

                return $lease->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two submits of the same screen raced past the lookup above — the other one won; return its lease.
            $this->discardStored($storedPath);
            if ($captureKey && ($existing = Lease::withoutGlobalScopes()->where('capture_key', $captureKey)->first())) {
                return $existing;
            }
            throw $e;
        } catch (\Throwable $e) {
            // The database rolled back; a file already written for the paper copy must not be left behind.
            $this->discardStored($storedPath);
            throw $e;
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────

    /** The agreement the lease is for: the one posted (must be the agency's own and ready) or the default. */
    private function resolveAgreement(int $agencyId, mixed $agreementId, string $intent): ?RentalLeaseTemplate
    {
        $ready = $this->guard->readyAgreementsFor($agencyId);

        if ($agreementId !== null && $agreementId !== '') {
            $chosen = $ready->firstWhere('id', (int) $agreementId);
            if (! $chosen && $intent === self::INTENT_LEASE_AND_SIGN) {
                // Same wording as a missing id (§15.12.4) — a forged id learns nothing.
                throw new NoLeaseAgreementLinkedException(LeaseAgreementTemplateGuard::NOT_AVAILABLE);
            }

            return $chosen ?? $ready->first();
        }

        return $ready->first();
    }

    private function createNewLease(Property $property, array $input, User $user): Lease
    {
        $lease = Lease::create([
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => $input['rental_amount'],
            'deposit_amount' => $input['deposit_amount'] ?? null,
            'start_date' => $input['start_date'],
            'end_date' => $input['end_date'] ?? null,
            'is_month_to_month' => (bool) ($input['is_month_to_month'] ?? false),
            'lease_type' => $input['lease_type'] ?? null,
            'source' => ! empty($input['rental_application_id']) ? 'rental_application' : 'manual',
            'rental_application_id' => $input['rental_application_id'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        foreach (array_values((array) ($input['tenant_contact_ids'] ?? [])) as $index => $contactId) {
            LeaseTenant::create([
                'lease_id' => $lease->id,
                'contact_id' => $contactId,
                'is_primary' => $index === 0,
            ]);
        }

        return $lease;
    }

    /**
     * A renewal is a NEW term chained to the one being renewed; the tenants are rebuilt from that term
     * and nothing posted can change them (R7). Deposit is written as typed — a cleared box means "no
     * deposit", not "carry the old one" (createRenewalTerm's own fallback).
     */
    private function createRenewalTerm(Lease $previous, array $input, User $user): Lease
    {
        $lease = app(LeaseRenewalService::class)->createRenewalTerm($previous, [
            'start_date' => $input['start_date'],
            'end_date' => $input['end_date'] ?? null,
            'rental_amount' => $input['rental_amount'],
            'deposit_amount' => $input['deposit_amount'] ?? null,
            'is_month_to_month' => (bool) ($input['is_month_to_month'] ?? false),
            'lease_type' => $input['lease_type'] ?? null,
        ], $user);

        $lease->update(['deposit_amount' => $input['deposit_amount'] ?? null]);

        return $lease;
    }

    private function activate(Lease $lease, bool $isRenewal, User $user): Lease
    {
        return $isRenewal
            ? app(LeaseRenewalService::class)->activateRenewalTerm($lease, $user)
            : app(LeaseActivationService::class)->activate($lease);
    }

    /** Typed columns for the registry's columns; everything else (the schedule, agency extras) in `extra`. */
    private function writeTerms(Lease $lease, RentalLeaseTemplate $agreement, array $values): void
    {
        $terms = LeaseAgreementTerms::forLease($lease);
        $extra = (array) ($terms->extra ?? []);

        foreach ($this->agreementFields($agreement) as $field) {
            $value = $this->normalise($values[$field['key']] ?? null);
            if ($field['column'] !== null) {
                $terms->{$field['column']} = $value;
            } else {
                $extra[$field['key']] = $value;
            }
        }

        $terms->extra = $extra === [] ? null : $extra;
        $terms->source = LeaseAgreementTerms::SOURCE_CAPTURED;
        $terms->save();
    }

    /** The value a terms row holds for a field (a typed column, or `extra`). */
    private function termValue(LeaseAgreementTerms $terms, array $field): mixed
    {
        return $field['column'] !== null
            ? $terms->{$field['column']}
            : (($terms->extra ?? [])[$field['key']] ?? null);
    }

    /** Files the signed copy exactly as the retired renewal upload did (§15.6.5, §15.11). */
    private function attachSignedCopy(Lease $lease, UploadedFile $file, User $user, bool $isRenewal, ?string &$storedPath): void
    {
        $ext = $file->getClientOriginalExtension();
        $storedPath = $file->storeAs(
            ($isRenewal ? 'lease-renewals/' : 'lease-signed-copies/') . $lease->id,
            Str::uuid() . ($ext ? ".{$ext}" : ''),
            'local'
        );

        $document = Document::create([
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $storedPath,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'document_type_id' => DocumentType::where('slug', 'lease_agreement')->value('id'),
            'source_type' => 'lease',
            'source_id' => $lease->id,
            'uploaded_by' => $user->id,
        ]);
        if ($lease->property_id) {
            $document->properties()->attach($lease->property_id);
        }

        event(new DocumentUploaded(document: $document, owner: $lease, actorUserId: $user->id));
    }

    private function discardStored(?string $path): void
    {
        if ($path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
                // Nothing more to do — the row it belonged to never committed.
            }
        }
    }

    private function createdDescription(string $intent, ?Lease $previous): string
    {
        $base = $previous ? "Lease created as a renewal of lease #{$previous->id}" : 'Lease created';

        return match ($intent) {
            self::INTENT_LEASE_AND_SIGN => $base . ' — signing document not prepared yet',
            self::INTENT_PAPER_COPY => $base . ' with a signed paper copy',
            default => $base,
        };
    }

    private function logEvent(Lease $lease, string $type, string $description, User $user, ?array $metadata = null): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => $type,
            'description' => $description,
            'actor_user_id' => $user->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** sha256(user|key) — exactly the 64 characters `leases.capture_key` holds, and never shared between users. */
    private function storedKey(mixed $key, User $user): ?string
    {
        $key = is_scalar($key) ? trim((string) $key) : '';

        return $key === '' ? null : hash('sha256', $user->id . '|' . $key);
    }

    /** Blank and whitespace-only become null; strings are trimmed. */
    public function normalise(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
