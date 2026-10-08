<?php

namespace App\Services\Rentals;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.4 / §15.21 (Builds L1 + L3a). The ONE place a lease's e-sign flow is built: new
 * lease and renewal both use it. It inserts a `flows` row in exactly the shape ESignWizardController::store()
 * / saveStep() already write, so the existing wizard reads it unchanged and no e-sign controller is
 * re-implemented.
 *
 *   missing()  — the gate: everything still needed before the agreement can be prepared (a landlord on the
 *                property; every signer with an email and an ID/passport number; the agreement details the
 *                agency's own lease marks required; the commission % when the lease carries a service fee).
 *   launch()   — the template guard (§15.12.4 call site 4), then the flow with the signers in the one fixed
 *                order agent → tenant(s) → landlord(s) (R4 — no setting, no switch), the agreement's values
 *                seeded into Fill & review through the agency's field map.
 *
 * Recipient order (R4): the e-sign engine already sorts signers agent → acquiring party → owner party when it
 * sends (ESignWizardController::sortRecipientsBySigningOrder); the flow is written in that same order so the
 * wizard shows it from the first screen.
 */
class LeaseSigningLauncher
{
    /** Fill & review — the step the agent lands on (§15.4 step 5). */
    public const LANDING_STEP = 5;

    /**
     * The step_data fragment (property + recipients + details) used to resolve a template's fields
     * BEFORE a draft term exists — $terms is the proposed rent/dates, not yet persisted. Shared with
     * RenewalDraftService::missingRequiredFields().
     *
     * @param array{start_date?:string,end_date?:?string,rental_amount?:float,deposit_amount?:?float} $terms
     */
    public function buildStepData(Lease $lease, array $terms, User $user): array
    {
        $property = $lease->property;

        return [
            'property' => [
                'property_id' => $property?->id,
                '_property_source' => 'properties',
                'title' => $property?->title,
                'suburb' => $property?->suburb,
            ],
            'recipients' => ['recipients' => $this->recipientRows($lease, $user)],
            'details' => [
                'lease_start' => $terms['start_date'] ?? optional($lease->start_date)->toDateString(),
                'lease_end' => $terms['end_date'] ?? optional($lease->end_date)->toDateString(),
                'monthly_rental' => (string) ($terms['rental_amount'] ?? $lease->rental_amount),
                'deposit' => (string) ($terms['deposit_amount'] ?? $lease->deposit_amount),
                'lease_type' => $lease->lease_type,
            ],
        ];
    }

    /**
     * Insert the e-sign `flows` row for an already-created lease term. `$fieldValueSeeds` (field name → value)
     * is written into Fill & review under each field's own id so the agent opens a document that is already
     * filled in (§15.4 step 4); `$landingStep` is where the wizard opens (the renewal copy-forward path keeps
     * its old step 2, the prepared lease agreement opens on Fill & review).
     *
     * @param array<string,string> $fieldValueSeeds
     */
    public function buildFlow(Lease $newTerm, int $templateId, User $user, array $fieldValueSeeds = [], int $landingStep = 2): Flow
    {
        $property = $newTerm->property;
        $tenants = $newTerm->tenants()->with('contact')->get();

        $template = Template::findOrFail($templateId);
        $fields = $this->wizardFieldsFor($template);

        $stepData = [
            'template' => ['template_id' => $templateId],
            'fields' => $fields,
            'property' => [
                'property_id' => $property?->id,
                '_property_source' => 'properties',
                'title' => $property?->title,
                'suburb' => $property?->suburb,
            ],
            'recipients' => ['recipients' => $this->recipientRows($newTerm, $user)],
            'details' => [
                'lease_start' => optional($newTerm->start_date)->toDateString(),
                'lease_end' => optional($newTerm->end_date)->toDateString(),
                'monthly_rental' => (string) $newTerm->rental_amount,
                'deposit' => (string) $newTerm->deposit_amount,
                'lease_type' => $newTerm->lease_type,
            ],
        ];

        $fieldValues = $this->seedFieldValues($fields, $fieldValueSeeds);
        if ($fieldValues !== []) {
            $stepData['fill_review'] = ['fieldValues' => $fieldValues];
        }

        return Flow::create([
            'type' => 'esign',
            'template_id' => $templateId,
            'user_id' => $user->id,
            'property_id' => $property?->id,
            'contact_id' => $tenants->first()?->contact_id,
            'current_step' => $landingStep,
            'step_data' => $stepData,
            'status' => 'active',
            'lease_id' => $newTerm->id,
        ]);
    }

    // ── The gate ──────────────────────────────────────────────────────────────────────────────

    /**
     * §15.4 step 1 — everything still missing before the agreement can be prepared. Nothing is created when
     * this is not empty.
     *
     * @param array<string,mixed> $terms agreement details typed on the capture screen and not yet saved
     *                                   (key => value); they win over the lease's saved terms row
     * @return array<int, array{key: string, label: string, fix_url: ?string}>
     */
    public function missing(Lease $lease, RentalLeaseTemplate $agreement, array $terms, User $user): array
    {
        $lease->loadMissing(['property', 'tenants.contact']);

        $gaps = $this->partyGaps($lease->property, app(LeaseAgreementDocumentValues::class)->signersFor($lease)->where('role', 'tenant')->pluck('contact'), $agreement);

        // Agreement details the agency's own lease marks "required for signing".
        $capture = app(LeaseCaptureService::class);
        $saved = $this->savedTermValues($lease);
        foreach ($capture->agreementFields($agreement) as $field) {
            if (! $field['required']) {
                continue;
            }
            $value = array_key_exists($field['key'], $terms) ? $terms[$field['key']] : ($saved[$field['key']] ?? null);
            if ($capture->normalise($value) === null) {
                $gaps[] = ['key' => $field['key'], 'label' => $field['label'], 'fix_url' => null];
            }
        }

        // Rentals front-half decision D8 (agency setting, default on): the same end-date-or-month-to-month rule the capture screen
        // applies, for a lease that already exists (prepare again, the API).
        if (\App\Models\LeaseSetting::requireEndOrMonthToMonthForSigningFor((int) $lease->agency_id) && ! $lease->end_date && ! $lease->is_month_to_month) {
            $gaps[] = ['key' => 'end_date', 'label' => 'An end date, or month-to-month ticked', 'fix_url' => route('corex.leases.show', $lease)];
        }

        // A lease with a service fee needs the letting commission % to work it out.
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? []));
        if ((isset($map['agent_service_fee']) || isset($map['net_to_owner']))
            && app(LeaseAgreementDocumentValues::class)->commissionPercent($lease, $lease->agreementTerms()->first(), $terms) === null) {
            $gaps[] = [
                'key' => 'commission_percent',
                'label' => 'Letting commission %',
                'fix_url' => $lease->property_id ? route('corex.properties.show', ['property' => $lease->property_id]) : null,
            ];
        }

        return $gaps;
    }

    /**
     * The contact side of the gate (§15.4 step 1): a landlord on the property, and every party who must sign
     * has an email address and an ID or passport number. Works before the lease exists — it needs only the
     * property and the tenants — so "Create lease & prepare for signing" can refuse before anything is written.
     * A company (entity) signs through its representatives, which the e-sign engine checks itself when it sends.
     *
     * @param Collection<int, Contact> $tenants in primary-first order
     * @return array<int, array{key: string, label: string, fix_url: ?string}>
     */
    public function partyGaps(?Property $property, Collection $tenants, RentalLeaseTemplate $agreement): array
    {
        $gaps = [];
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? []));

        $landlords = $this->landlordsOf($property);

        if ($landlords->isEmpty()) {
            $gaps[] = [
                'key' => 'landlord',
                'label' => 'A landlord linked to the property',
                'fix_url' => $property ? route('corex.properties.show', ['property' => $property->id, 'tab' => 'contacts']) : null,
            ];
        }

        $parties = $tenants->map(fn (Contact $c) => ['role' => 'Tenant', 'prefix' => 'tenant', 'contact' => $c])
            ->concat($landlords->map(fn (Contact $c) => ['role' => 'Landlord', 'prefix' => 'landlord', 'contact' => $c]));

        foreach ($parties as $party) {
            /** @var Contact $contact */
            $contact = $party['contact'];
            $who = trim($contact->full_name ?: ('Contact #' . $contact->id)) . ' (' . strtolower($party['role']) . ')';
            $url = route('corex.contacts.show', $contact);

            foreach ($this->contactNeeds($contact, $party['prefix'], $map) as $kind => $what) {
                $gaps[] = ['key' => "{$party['prefix']}_{$contact->id}_{$kind}", 'label' => "{$who} — {$what}", 'fix_url' => $url];
            }
        }

        return $gaps;
    }

    /**
     * What one signing party is still missing, in plain words, keyed email / id / address. A company (entity)
     * signs through its representatives — the e-sign engine checks those itself when it sends — so it is never
     * asked for a personal email or ID here.
     *
     * @param array<string,array{field:string,required:bool,label:?string}> $map the agreement's normalised field map
     * @return array<string,string>
     */
    public function contactNeeds(Contact $contact, string $prefix, array $map): array
    {
        if ($contact->isEntity()) {
            return [];
        }

        $needs = [];
        if (trim((string) $contact->email) === '') {
            $needs['email'] = 'email address';
        }
        if (trim((string) $contact->id_number) === '' && trim((string) $contact->passport_number) === '') {
            $needs['id'] = 'ID or passport number';
        }
        if (isset($map["{$prefix}_address"]) && trim((string) $contact->address) === '') {
            $needs['address'] = 'address';
        }

        return $needs;
    }

    /**
     * What the Lease Hub's "Agreement" card shows (§15.13): the state, which lease agreement it is, the signers in
     * signing order, and the one or two things the agent can do next. Null for a lease with no agreement at all.
     *
     * @return array<string,mixed>|null
     */
    public function cardFor(Lease $lease, User $user): ?array
    {
        $status = (string) ($lease->signing_status ?? Lease::SIGNING_NOT_SENT);
        if ($status === Lease::SIGNING_NOT_SENT) {
            return null;
        }

        $flow = $lease->signing_flow_id ? Flow::query()->find($lease->signing_flow_id) : null;
        $owner = $flow ? User::query()->find($flow->user_id) : null;
        $mine = $flow && (int) $flow->user_id === (int) $user->id;
        $signedDoc = in_array($status, [Lease::SIGNING_SIGNED, Lease::SIGNING_SIGNED_ON_PAPER], true) ? $lease->signedDocument() : null;
        $esignDocumentId = $lease->agreement_document_id ?: $lease->source_document_id;

        // leases.md §15.16 (Build L3b) — everyone has signed and the agent has approved, but the signed copy is
        // still being filed: say so, rather than "Needs my approval" for something already approved.
        $filing = $status === Lease::SIGNING_AWAITING_AGENT_REVIEW && $lease->signature_template_id
            && SignatureTemplate::query()->whereKey($lease->signature_template_id)->where('status', SignatureTemplate::STATUS_COMPLETED)->exists();

        return [
            'status' => $status,
            'filing' => $filing,
            'label' => $filing ? 'Signed — filing the document' : (Lease::SIGNING_LABELS[$status] ?? ucfirst($status)),
            'agreement_name' => $lease->agreementTemplate?->name,
            'signers' => in_array($status, [Lease::SIGNING_SIGNED_ON_PAPER], true) ? [] : $this->signersSummary($lease),
            'owner_name' => $owner?->name,
            'continue_url' => $status === Lease::SIGNING_PREPARED && $mine ? $this->landingUrl($flow) : null,
            'open_url' => in_array($status, [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW], true) ? route('docuperfect.esign.myDocuments') : null,
            'approve_url' => $status === Lease::SIGNING_AWAITING_AGENT_REVIEW && ! $filing && $esignDocumentId ? route('docuperfect.signatures.review', $esignDocumentId) : null,
            'signed_copy_url' => $signedDoc && $esignDocumentId && $status === Lease::SIGNING_SIGNED ? route('docuperfect.signatures.download', $esignDocumentId) : null,
            'certificate_url' => $signedDoc && $esignDocumentId && $status === Lease::SIGNING_SIGNED ? route('docuperfect.signatures.certificate', $esignDocumentId) : null,
            'can_prepare_again' => $lease->status === Lease::STATUS_DRAFT
                && in_array($status, [Lease::SIGNING_DECLINED, Lease::SIGNING_VOIDED, Lease::SIGNING_EXPIRED], true)
                && ($user->hasPermission('leases.create') || $user->hasPermission('leases.renew'))
                && $user->hasPermission('access_docuperfect') && $user->hasPermission('create_docuperfect_docs'),
            'failure_note' => $lease->signing_failure_note,
        ] + $this->changeLinksFor($lease, $user, $status);
    }

    /**
     * leases.md §15.13 / §15.8.4 (Build L3c) — "Review changes" while the agreement is out or waiting for approval and
     * it has been changed in e-sign; "Confirm the lease details" when it was signed with a difference nobody confirmed.
     * Only offered to someone who may confirm. A read of the document; a fault reads as "nothing to review".
     *
     * @return array{review_url: ?string, confirm_url: ?string}
     */
    private function changeLinksFor(Lease $lease, User $user, string $status): array
    {
        $links = ['review_url' => null, 'confirm_url' => null];

        try {
            if (! app(LeaseAgreementConfirmService::class)->mayConfirm($user, $lease)) {
                return $links;
            }

            if (in_array($status, [Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW], true)
                && app(LeaseAgreementCheck::class)->verdict($lease)['has_differences']) {
                $links['review_url'] = route('corex.leases.agreement.confirm', $lease);
            }

            if ($status === Lease::SIGNING_SIGNED && $lease->status === Lease::STATUS_DRAFT && app(LeaseHubService::class)->awaitingConfirmation($lease)) {
                $links['confirm_url'] = route('corex.leases.agreement.confirm', $lease);
            }
        } catch (\Throwable $e) {
            // A card must always draw.
        }

        return $links;
    }

    /**
     * The agreement's signers for the Lease Hub card and the API: from the envelope once it exists (in the order
     * it signs, with each one's status), else the planned order. Agent first, then tenant(s), then landlord(s).
     *
     * @return array<int, array{role: string, name: string, status: string, email: ?string, last_reminder: ?string}>
     */
    public function signersSummary(Lease $lease): array
    {
        $envelope = $lease->signature_template_id ? SignatureTemplate::query()->with('requests')->find($lease->signature_template_id) : null;

        if ($envelope && $envelope->requests->isNotEmpty()) {
            $labels = [
                'waiting' => 'Waiting its turn', 'pending' => 'Asked to sign', 'viewed' => 'Opened it',
                'partially_signed' => 'Part signed', 'completed' => 'Signed', 'cancelled' => 'Cancelled', 'declined' => 'Declined',
            ];

            return $envelope->requests->sortBy('signing_order')->map(fn ($r) => [
                'role' => (string) $r->party_role,
                'name' => (string) ($r->signer_name ?: 'Signer'),
                'status' => $labels[$r->status] ?? ucfirst(str_replace('_', ' ', (string) $r->status)),
                'email' => $r->signer_email,
                'last_reminder' => $r->reminder_sent_at?->format('d M Y H:i'),
            ])->values()->all();
        }

        $user = $lease->signingFlow?->user_id ? User::query()->find($lease->signingFlow->user_id) : null;
        $rows = [['role' => 'agent', 'name' => $user?->name ?? 'The agent', 'status' => 'Signs first', 'email' => $user?->email, 'last_reminder' => null]];
        foreach (app(LeaseAgreementDocumentValues::class)->signersFor($lease) as $signer) {
            $rows[] = [
                'role' => $signer['role'], 'name' => (string) $signer['contact']->full_name,
                'status' => 'Not sent yet', 'email' => $signer['contact']->email, 'last_reminder' => null,
            ];
        }

        return $rows;
    }

    /**
     * The landlord(s) a property gives a lease — the very same rule Lease::landlordContacts() applies, so the
     * capture screen's panel, the gate and the document can never name different people.
     *
     * @return Collection<int, Contact>
     */
    public function landlordsOf(?Property $property): Collection
    {
        if (! $property) {
            return collect();
        }

        $probe = new Lease();
        $probe->setRelation('property', $property);

        return $probe->landlordContacts();
    }

    // ── The launch ────────────────────────────────────────────────────────────────────────────

    /**
     * §15.4 steps 2–5 — guard, gate, then the flow with the signers in the fixed order and the agreement's
     * values seeded. Idempotent: a lease that already has a prepared flow gets that same flow back (a
     * double-click, or back-and-resubmit, never makes a second document).
     *
     * @param array<string,mixed> $terms agreement details typed on the screen and not yet saved
     *
     * @throws NoLeaseAgreementLinkedException    the agreement fails the guard (not the agency's own, archived, …)
     * @throws LeaseCaptureIncompleteException    something is still missing — nothing was created
     */
    public function launch(Lease $lease, RentalLeaseTemplate $agreement, User $user, array $terms = []): Flow
    {
        $lease->loadMissing(['property', 'tenants.contact']);

        if ($lease->signing_status === Lease::SIGNING_PREPARED && $lease->signing_flow_id
            && ($existing = Flow::query()->find($lease->signing_flow_id))) {
            return $existing;
        }

        // §15.12.4 call site 4 — server-side, immediately before the flow row exists, even for the API.
        try {
            app(LeaseAgreementTemplateGuard::class)->assertUsable($agreement->template, (int) $lease->agency_id, (array) ($agreement->field_map ?? []));
        } catch (ValidationException) {
            throw new NoLeaseAgreementLinkedException();
        }

        $missing = $this->missing($lease, $agreement, $terms, $user);
        if ($missing !== []) {
            throw LeaseCaptureIncompleteException::forMissing($missing);
        }

        $seeds = $this->seedsFor($lease, $agreement, $terms);

        return DB::transaction(function () use ($lease, $agreement, $user, $seeds) {
            $flow = $this->buildFlow($lease, (int) $agreement->docuperfect_template_id, $user, $seeds, self::LANDING_STEP);

            $lease->update([
                'signing_flow_id' => $flow->id,
                // A renewal also keeps the older pointer its draft screens read (§15.10 M2 "kept and mirrored").
                'renewal_draft_flow_id' => $lease->previous_lease_id ? $flow->id : $lease->renewal_draft_flow_id,
                'signing_status' => Lease::SIGNING_PREPARED,
                'agreement_template_id' => (int) $agreement->docuperfect_template_id,
                'signature_template_id' => null,
                'agreement_document_id' => null,
                'signing_failure_note' => null,
            ]);

            LeaseEvent::create([
                'lease_id' => $lease->id,
                'event_type' => LeaseEvent::TYPE_AGREEMENT_PREPARED,
                'description' => 'Lease agreement prepared for signing',
                'actor_user_id' => $user->id,
                'metadata' => ['flow_id' => $flow->id, 'agreement_template_id' => (int) $agreement->docuperfect_template_id],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return $flow;
        });
    }

    /** The URL the agent lands on: Fill & review of the prepared agreement. */
    public function landingUrl(Flow $flow): string
    {
        return route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => self::LANDING_STEP]);
    }

    /**
     * The one hook the e-sign wizard calls when the agent prepares signing (ESignWizardController::prepareSigning,
     * where `step_data.signature_template_id` is written): the lease receives the envelope and the document and
     * is out for signing. A flow that is no longer the lease's current one (an older, abandoned attempt) is
     * ignored. Never throws — a lease-side fault must not break the agent's signing.
     */
    public function linkEnvelope(Flow $flow, SignatureTemplate $envelope, ?Document $document): void
    {
        try {
            if (! $flow->lease_id) {
                return;
            }
            $lease = Lease::withoutGlobalScopes()->find($flow->lease_id);
            if (! $lease || (int) $lease->signing_flow_id !== (int) $flow->id
                || ! in_array($lease->signing_status, [Lease::SIGNING_PREPARED, Lease::SIGNING_OUT_FOR_SIGNING], true)) {
                return;
            }

            $lease->update([
                'signature_template_id' => $envelope->id,
                'agreement_document_id' => $document?->id,
                'signing_status' => Lease::SIGNING_OUT_FOR_SIGNING,
            ]);

            LeaseEvent::create([
                'lease_id' => $lease->id,
                'event_type' => LeaseEvent::TYPE_AGREEMENT_OUT_FOR_SIGNING,
                'description' => 'Lease agreement out for signing',
                'actor_user_id' => $flow->user_id,
                'metadata' => ['signature_template_id' => $envelope->id, 'document_id' => $document?->id, 'flow_id' => $flow->id],
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            SignatureAuditLog::log($envelope, 'lease_linked', SignatureAuditLog::ACTOR_SYSTEM, 'CoreX', null, null, null, null, null, ['lease_id' => $lease->id]);
        } catch (\Throwable $e) {
            Log::warning('Lease signing: could not link the envelope to its lease', ['flow_id' => $flow->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * When a lease is cancelled (or a renewal draft is cancelled) while its agreement is still open, the open
     * agreement goes with it: a flow nobody has sent yet is abandoned; an envelope already out is cancelled
     * exactly as e-sign's own "cancel document" does (waiting parties' links stop working and they are told),
     * and the lease's agreement shows as voided. Nothing happens for a lease with no open agreement.
     *
     * The envelope half is SignatureService::cancelEnvelope() — e-sign's own "cancel document". It announces the
     * cancel, so the lease is normally already marked voided (with its event) by the time it returns; the lease is
     * marked here only when there was no envelope to announce anything (an agreement prepared but never sent).
     */
    public function closeOpenAgreement(Lease $lease, User $user, string $reason): void
    {
        if (! in_array($lease->signing_status, [Lease::SIGNING_PREPARED, Lease::SIGNING_OUT_FOR_SIGNING, Lease::SIGNING_AWAITING_AGENT_REVIEW], true)) {
            return;
        }

        $envelope = $lease->signature_template_id ? SignatureTemplate::query()->find($lease->signature_template_id) : null;
        if ($envelope && ! in_array($envelope->status, [SignatureTemplate::STATUS_COMPLETED, SignatureTemplate::STATUS_CANCELLED], true)) {
            app(SignatureService::class)->cancelEnvelope($envelope, $user, $reason, null, null, ['via' => 'lease']);
        }

        if ($lease->signing_flow_id && ($flow = Flow::query()->find($lease->signing_flow_id))) {
            $flow->delete(); // soft delete — the wizard can no longer open it
        }

        $lease->refresh();
        if ($lease->signing_status === Lease::SIGNING_VOIDED) {
            return; // the cancel announcement already recorded it
        }

        $lease->update(['signing_status' => Lease::SIGNING_VOIDED, 'signing_failure_note' => mb_substr($reason, 0, 500)]);

        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => LeaseEvent::TYPE_AGREEMENT_VOIDED,
            'description' => 'Lease agreement closed — ' . $reason,
            'actor_user_id' => $user->id,
            'metadata' => ['reason' => $reason, 'signature_template_id' => $envelope?->id],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────

    /**
     * The signers, in the one fixed order (R4): the agent, the tenant(s) with the primary first, then the
     * landlord(s). Each row is shaped exactly as the wizard's own recipients step saves it, with the contact's
     * own email, ID/passport and address, so nothing has to be typed again on the way to sending.
     *
     * @return array<int, array<string,mixed>>
     */
    public function recipientRows(Lease $lease, User $user): array
    {
        $rows = [[
            'order' => 1, 'role' => 'agent', 'name' => $user->name, 'id_number' => '',
            'email' => $user->email ?? '', 'cell' => '', 'address' => '', 'readonly' => true,
        ]];

        foreach (app(LeaseAgreementDocumentValues::class)->signersFor($lease) as $signer) {
            $rows[] = $this->recipientRow($signer['role'], $signer['contact'], count($rows) + 1);
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function recipientRow(string $role, Contact $contact, int $order): array
    {
        $name = $contact->full_name ?: trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        return [
            'order' => $order,
            'role' => $role,
            'name' => $name,
            'first_name' => $contact->first_name ?? '',
            'last_name' => $contact->last_name ?? '',
            'id_number' => $contact->id_number ?? '',
            'passport_number' => $contact->passport_number ?? '',
            'email' => $contact->email ?? '',
            'cell' => $contact->phone ?? '',
            'address' => $contact->address ?? '',
            '_contact_id' => $contact->id,
            '_is_entity' => $contact->isEntity(),
        ];
    }

    /**
     * The template's fill-in fields exactly as the e-sign wizard will list them (same rule as
     * ESignWizardController::showStep), so a value seeded under a field's id is the value that field shows.
     *
     * @return array<int|string, array<string,mixed>>
     */
    private function wizardFieldsFor(Template $template): array
    {
        $fields = $template->fields_json ?? [];
        $skeletal = ! empty($fields) && empty($fields[0]['id'] ?? null) && empty($fields[0]['field_name'] ?? null);

        if (($template->render_type ?? 'pdf') === 'web' && ! empty($template->field_mappings)
            && (($template->template_type ?? '') === 'cds' || empty($fields) || $skeletal)) {
            $fields = app(ESignWizardController::class)->buildFieldsFromMappings($template->field_mappings);
        }

        return $fields;
    }

    /**
     * Turn "template field name → value" into Fill & review's "field id → value". A value for a field the
     * template does not actually have is dropped (the agency's map and its document can drift apart — the
     * set-up page's check reports that; seeding must never invent a field).
     *
     * @param array<int|string, array<string,mixed>> $fields
     * @param array<string,string> $seeds
     * @return array<string,string>
     */
    private function seedFieldValues(array $fields, array $seeds): array
    {
        if ($seeds === []) {
            return [];
        }

        $byName = [];
        foreach ($fields as $field) {
            $name = (string) ($field['field_name'] ?? '');
            $id = $field['id'] ?? null;
            if ($name === '' || $id === null || in_array($field['tag_type'] ?? $field['type'] ?? '', ['signature', 'initial'], true)) {
                continue;
            }
            $byName[$name] ??= (string) $id;
            $byName[preg_replace('/[^a-zA-Z0-9_]/', '_', $name)] ??= (string) $id;
        }

        $values = [];
        foreach ($seeds as $fieldName => $value) {
            $id = $byName[$fieldName] ?? $byName[preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName)] ?? null;
            if ($id !== null && $value !== '') {
                $values[$id] = $value;
            }
        }

        return $values;
    }

    /**
     * Field name in the agency's own document → the value to put there (§15.4 step 4).
     *
     * @param array<string,mixed> $terms
     * @return array<string,string>
     */
    private function seedsFor(Lease $lease, RentalLeaseTemplate $agreement, array $terms): array
    {
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap((array) ($agreement->field_map ?? []));
        $values = app(LeaseAgreementDocumentValues::class)->forLease($lease, $agreement, $terms === [] ? null : $terms);

        $seeds = [];
        foreach ($values as $key => $value) {
            if (isset($map[$key])) {
                $seeds[$map[$key]['field']] = $value;
            }
        }

        return $seeds;
    }

    /** @return array<string,mixed> the lease's saved agreement terms, key => value (typed columns and `extra`) */
    private function savedTermValues(Lease $lease): array
    {
        $terms = $lease->agreementTerms()->first();
        if (! $terms) {
            return [];
        }

        $out = (array) ($terms->extra ?? []);
        foreach ((array) config('lease-agreement-fields.fields', []) as $key => $def) {
            if (($def['column'] ?? null) !== null && ($def['side'] ?? '') === 'terms') {
                $out[$key] = $terms->{$def['column']};
            }
        }

        return $out;
    }
}
