<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;

/**
 * .ai/specs/rental-work-orders.md §3a/§11/§13 — where a fault report is
 * actually RECORDED. Thin controllers call into this, per the same
 * mobile-foundation discipline rental-inspections.md §14 established: the
 * logic here is the exact call a future mobile/tenant-app endpoint makes
 * too (§13), with `captured_by_user_id` simply omitted for a self-reported
 * row instead of a second implementation existing anywhere.
 */
class RentalFaultReportService
{
    /**
     * §3.2a/§0c — the agent captures a fault report on the reporter's
     * behalf today; `captured_by_user_id` is nullable specifically so a
     * future self-service caller (Andre's tenant app) can omit it without
     * this method, or anything downstream of it, changing.
     */
    public function report(Property $property, array $attributes): RentalFaultReport
    {
        $faultReport = RentalFaultReport::create(array_merge($attributes, [
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => $attributes['reported_at'] ?? now(),
        ]));

        $this->notifyCreated($faultReport);

        return $faultReport;
    }

    /**
     * AT-452 (§19.14) - the OWNER instructs the agency to arrange work ("Ask your agent to arrange work"). It reaches the agent flagged "Requested by
     * the owner", and the owner's instruction IS their authority to go to a quote: the fault is born approved (the agency appoints), recorded as the
     * owner's own decision from their portal, so the owner is never asked to approve their own request. The limits still apply afterwards: a quote
     * above the property's no-approval limit goes to the owner to approve. No mail goes to the tenant - they did not report this.
     */
    public function reportOwnerRequest(Property $property, \App\Models\Contact $owner, array $attributes, ?string $comment = null): RentalFaultReport
    {
        $who = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $text = trim((string) $comment);

        $fault = \Illuminate\Support\Facades\DB::transaction(function () use ($property, $owner, $attributes, $who, $text) {
            $fault = RentalFaultReport::create(array_merge($attributes, [
                'agency_id' => $property->agency_id,
                'branch_id' => $property->branch_id,
                'property_id' => $property->id,
                'reported_by_type' => RentalFaultReport::REPORTED_BY_LANDLORD,
                'reported_by_contact_id' => $owner->id,
                'reported_channel' => RentalFaultReport::CHANNEL_APP,
                'status' => RentalFaultReport::STATUS_APPROVED,
                'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED,
                'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
                'reported_at' => now(),
            ]));
            $approval = $fault->approvals()->create([
                'agency_id' => $fault->agency_id, 'decision' => \App\Models\RentalApproval::DECISION_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
                'contractor_source' => \App\Models\RentalApproval::CONTRACTOR_AGENCY, 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL,
                'evidence_text' => 'Requested by the owner: ' . $fault->title, 'decided_at' => now(), 'recorded_by_contact_id' => $owner->id,
            ]);
            // Section 22: the owner's own instruction IS their Decision 1 authority - it leaves the same authorisation record as a press on their link
            app(RentalAuthorisationService::class)->mirrorApproval($fault, $approval, $owner);
            $fault->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OWNER_REPAIR_REQUEST, null,
                $who . ' (owner) asked the agency to arrange this work. Their instruction is their authority to go to a quote - they are not asked to approve it again. A quote above this property\'s limit still goes to them.'
                . ($text !== '' ? ' They wrote: ' . $text : ''));
            if ($text !== '') {
                $fault->forceFill(['owner_comment' => $text, 'owner_comment_by_contact_id' => $owner->id, 'owner_commented_at' => now()])->save();
            }

            return $fault;
        });

        $this->notifyCreated($fault);

        return $fault->fresh();
    }

    /**
     * AT-452 round 2 (F) - the tenant read the first-aid steps and pressed "That fixed it". The fault is LOGGED (the agent can see it and
     * that the tenant fixed it themselves) but it is born closed: no "new fault" alert asking anyone to act, nothing for the review queue,
     * no owner step. The lease's tenancy log carries it as an informational line (LeaseTimelineService).
     */
    public function reportSelfResolved(Property $property, array $attributes): RentalFaultReport
    {
        $now = $attributes['reported_at'] ?? now();
        $faultReport = RentalFaultReport::create(array_merge($attributes, [
            'agency_id' => $property->agency_id,
            'branch_id' => $property->branch_id,
            'property_id' => $property->id,
            'status' => RentalFaultReport::STATUS_RESOLVED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => $now,
            'resolved_at' => now(),
            'repaired_at' => now()->toDateString(),
            'outcome' => RentalFaultReport::OUTCOME_RESOLVED_BY_FIRST_AID,
            'outcome_note' => RentalFaultReport::SELF_RESOLVED_LABEL . '.',
            'outcome_set_automatically' => false,
        ]));
        $faultReport->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OUTCOME_SET, null, RentalFaultReport::SELF_RESOLVED_LABEL . '.');

        return $faultReport;
    }

    /**
     * AT-452 round 2 (F-OWNER) - "Have this repaired anyway" from the agent's side: a NEW fault report on the same property / lease / type, linked to
     * the one the tenant resolved themselves, that then goes through the normal flow (review, owner version, send, decision, work order). The
     * resolved report is never changed. One live follow-up at a time: pressing it again returns the one already made.
     */
    public function raiseFollowUp(RentalFaultReport $resolved, \App\Models\User $by): RentalFaultReport
    {
        if (! $resolved->isTenantSelfResolved()) {
            throw new \LogicException('Only a fault the tenant resolved themselves can be followed up this way.');
        }
        $existing = $resolved->followUps()->where('status', '!=', RentalFaultReport::STATUS_CANCELLED)->first();
        if ($existing) {
            return $existing;
        }
        $property = $resolved->property()->withoutGlobalScopes()->firstOrFail();
        $note = 'Follows the report of ' . ($resolved->reported_at ?? $resolved->created_at)->format('j M Y') . ' that the tenant resolved themselves. Repair requested by ' . $by->name . '.';

        return $this->report($property, [
            'lease_id' => $resolved->lease_id,
            'rental_fault_type_id' => $resolved->rental_fault_type_id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $by->id,
            'reported_channel' => RentalFaultReport::CHANNEL_OTHER,
            'captured_by_user_id' => $by->id,
            'created_by_user_id' => $by->id,
            'title' => $resolved->title,
            'description' => trim($note . ' ' . (string) $resolved->description),
            'follows_fault_report_id' => $resolved->id,
        ]);
    }

    /**
     * AT-452 (§19.12) - the OWNER presses "Ask my agent to have this repaired" on a fault the tenant resolved themselves, with an optional comment of
     * their own. A NEW request is raised (the resolved report is never changed - its text, date and photos stay exactly as the tenant left them),
     * linked to it, carrying the owner's comment with their name and the time. Both lease agents are told like for any new fault. One live request
     * at a time: asking again is refused with a plain sentence.
     */
    public function raiseOwnerRepairRequest(RentalFaultReport $resolved, \App\Models\Contact $owner, ?string $comment): RentalFaultReport
    {
        if (! $resolved->isTenantSelfResolved()) {
            throw new \LogicException('Only a fault the tenant resolved themselves can be followed up this way.');
        }
        if ($resolved->followUps()->where('status', '!=', RentalFaultReport::STATUS_CANCELLED)->exists()) {
            throw new \LogicException('A repair has already been asked for on this one.');
        }
        $property = $resolved->property()->withoutGlobalScopes()->firstOrFail();
        $note = 'Follows the report of ' . ($resolved->reported_at ?? $resolved->created_at)->format('j M Y') . ' that the tenant resolved themselves.';

        $new = $this->reportOwnerRequest($property, $owner, [
            'lease_id' => $resolved->lease_id,
            'rental_fault_type_id' => $resolved->rental_fault_type_id,
            'title' => $resolved->title,
            'description' => trim($note . ' ' . (string) $resolved->description),
            'follows_fault_report_id' => $resolved->id,
        ], $comment);
        $who = trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''));
        $text = trim((string) $comment);
        $resolved->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OWNER_REPAIR_REQUEST, null, $who . ' (owner) asked for a repair - request #' . $new->id . ($text !== '' ? ' - they wrote: ' . $text : '.'));

        return $new;
    }

    /**
     * §3a.3 — a fault report's photo, ONE implementation for the office screen and the mobile app: same image pipeline as everywhere else in this
     * feature family (PropertyImageStorer), de-duplicated on `client_idempotency_key` so a retried upload returns the row already stored.
     * AT-508: `$capturedAt` is when the phone took the picture (a queued upload arrives late); it becomes the photo's created_at.
     *
     * @return array{0: \App\Models\RentalFaultReportPhoto, 1: bool} [photo, true when it was already stored]
     */
    public function storePhoto(RentalFaultReport $fault, UploadedFile $file, \App\Models\User $by, ?string $clientKey = null, ?\DateTimeInterface $capturedAt = null): array
    {
        if ($clientKey) {
            $existing = \App\Models\RentalFaultReportPhoto::where('client_idempotency_key', $clientKey)
                ->where('rental_fault_report_id', $fault->id)->first();
            if ($existing) {
                return [$existing, true];
            }
        }

        $url = app(PropertyImageStorer::class)->store($file, $fault->property_id);

        $photo = \App\Models\RentalFaultReportPhoto::create(array_filter([
            'agency_id' => $fault->agency_id,
            'rental_fault_report_id' => $fault->id,
            'storage_path' => $url,
            'uploaded_by_user_id' => $by->id,
            'client_idempotency_key' => $clientKey,
            'file_size_bytes' => $file->getSize(),
            'created_at' => $capturedAt,
        ], fn ($v) => $v !== null));

        return [$photo, false];
    }

    /**
     * §3.4a — an approval's evidence screenshot, reusing PropertyImageStorer
     * exactly like every other image in this feature family. No second
     * pipeline for evidence specifically.
     */
    public function storeApprovalScreenshot(UploadedFile $file, int $propertyId): string
    {
        return app(PropertyImageStorer::class)->store($file, $propertyId);
    }

    /**
     * §4 — fires to the property's assigned agent, independent of whether a
     * work order ever follows. Skipped, not attempted, if the property has
     * no assigned agent — same "logged as a no-op, not a failure" treatment
     * §4 already gives the owner-notification path for un-owned stock.
     */
    private function notifyCreated(RentalFaultReport $faultReport): void
    {
        $property = $faultReport->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }

        // Fault flow (8 Oct 2026): the lease's tenant-side AND owner-side agents (once each), else the property's agent, else the
        // branch manager / office admin / agency admin - a fault is never reported to nobody. Same event, same per-agent settings.
        $recipients = app(LeaseAgentService::class)->recipientsForEvent(
            $faultReport->lease, $property, (int) $faultReport->agency_id,
            $faultReport->captured_by_user_id ? \App\Models\User::withoutGlobalScopes()->find($faultReport->captured_by_user_id) : null
        );
        foreach ($recipients as $user) {
            app(NotificationDispatcher::class)->fire(
                $user,
                'rental_fault_report.created',
                $faultReport,
                [
                    'title' => \App\Support\RentalAlertTitle::withProperty($faultReport->isOwnerRequested() ? 'Work requested by the owner' : ($faultReport->isEmergency() ? 'EMERGENCY fault reported' : 'Fault reported'), $property),
                    'body' => $faultReport->isOwnerRequested() ? $faultReport->title . ' — arrange a contractor and a quote.' : $faultReport->title,
                    'action_url' => $faultReport->isOwnerRequested()
                        ? route('corex.rental-fault-reports.show', ['rentalFaultReport' => $faultReport->id, 'create_work_order' => 1])
                        : route('corex.rental-fault-reports.show', $faultReport->id),
                    // section 6.5 (C-C2): an emergency-type fault goes out at the elevated severity the dispatcher already carries ('warning'), whichever route it takes afterwards
                    'severity' => $faultReport->isEmergency() ? 'warning' : 'info',
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }

    /**
     * Audit fixes (C-A5 / D20): the tenant adds a note and/or photos to their own OPEN fault. Both land on the agent's fault page (a history line, the photos with no
     * uploader = the tenant); the owner sees nothing new unless the agent shares it (owner-version rules are untouched). The agents hear through their own alert type.
     *
     * @param array<int, \Illuminate\Http\UploadedFile> $photos
     * @return array{notes: int, photos: int}
     */
    public function tenantAddsToFault(RentalFaultReport $fault, \App\Models\Contact $contact, ?string $note, array $photos): array
    {
        $note = trim(strip_tags((string) $note));
        if ($note === '' && $photos === []) {
            throw new \InvalidArgumentException('Write a note or add a photo.');
        }
        if (in_array($fault->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED, RentalFaultReport::STATUS_DECLINED], true) || $fault->isTenantSelfResolved()) {
            throw new \LogicException('This fault is closed. If the problem is back, please report it again.');
        }

        // files first, rows after: a photo that fails to store leaves nothing behind
        // D14 (audit 2026-10-20 H1): a tenant's picture of their home is PRIVATE exactly like the first upload - a `private:` reference, never a /storage address
        $images = app(\App\Services\Images\PrivateImageService::class);
        $paths = [];
        foreach ($photos as $photo) {
            $paths[] = ['path' => $images->storeUpload($photo, (int) $fault->property_id), 'size' => $photo->getSize()];
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($fault, $contact, $note, $paths) {
            foreach ($paths as $p) {
                $fault->photos()->create([
                    'agency_id' => $fault->agency_id, 'storage_path' => $p['path'], 'file_size_bytes' => $p['size'],
                    'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                ]);
            }
            $who = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'The tenant';
            $bits = array_filter([$note !== '' ? 'a note: "' . \Illuminate\Support\Str::limit($note, 500) . '"' : null, $paths ? count($paths) . ($paths ? (count($paths) === 1 ? ' photo' : ' photos') : '') : null]);
            $fault->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_NOTE, null, $who . ' (tenant) added ' . implode(' and ', $bits) . '.');
        });
        $this->notifyTenantUpdate($fault, 'Tenant added to a fault', ($note !== '' ? \Illuminate\Support\Str::limit($note, 140) : count($paths) . ' new photo(s)') . ' - ' . $fault->title);

        return ['notes' => $note !== '' ? 1 : 0, 'photos' => count($paths)];
    }

    /** Audit fixes (C-A5 / D20): the tenant withdraws their own fault (see RentalFaultReport::cancelByTenant for when). A reason is required. */
    public function tenantWithdraws(RentalFaultReport $fault, \App\Models\Contact $contact, string $reason): void
    {
        $reason = trim(strip_tags($reason));
        if (mb_strlen($reason) < 3) {
            throw new \InvalidArgumentException('Tell us why you are withdrawing it.');
        }
        $fault->cancelByTenant($contact, \Illuminate\Support\Str::limit($reason, 500, ''));
        $this->notifyTenantUpdate($fault, 'Tenant withdrew a fault', $fault->title . ': ' . \Illuminate\Support\Str::limit($reason, 140));
    }

    /** The lease's agents (same recipients as a new fault), through the tenant-update alert type; skip-not-fail like notifyCreated(). */
    private function notifyTenantUpdate(RentalFaultReport $fault, string $title, string $body): void
    {
        $property = $fault->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }
        foreach (app(LeaseAgentService::class)->recipientsForEvent($fault->lease, $property, (int) $fault->agency_id, null) as $user) {
            app(NotificationDispatcher::class)->fire($user, 'rental_fault_report.tenant_update', $fault, [
                'title' => \App\Support\RentalAlertTitle::withProperty($title, $property), 'body' => $body,
                'action_url' => route('corex.rental-fault-reports.show', $fault->id), 'severity' => 'info', 'threshold_hit_at' => now(),
            ]);
        }
    }

    /**
     * §4 — fires to the assigned agent when the outcome is set, whatever
     * that outcome is. Same skip-not-fail treatment as notifyCreated().
     */
    public function notifyResolved(RentalFaultReport $faultReport, ?\App\Models\User $actor = null): void
    {
        $property = $faultReport->property()->withoutGlobalScopes()->first();
        if (! $property) {
            return;
        }

        // AT-451 round 2: the same people who heard about the fault hear how it ended (not only the property's agent).
        $recipients = app(\App\Services\Rentals\LeaseAgentService::class)
            ->recipientsForEvent($faultReport->lease, $property, (int) $faultReport->agency_id, $actor);

        foreach ($recipients as $user) {
            app(NotificationDispatcher::class)->fire(
                $user,
                'rental_fault_report.resolved',
                $faultReport,
                [
                    'title' => \App\Support\RentalAlertTitle::withProperty('Fault report resolved', $property),
                    'body' => $faultReport->title . ': ' . str_replace('_', ' ', ucfirst($faultReport->outcome)),
                    'action_url' => route('corex.rental-fault-reports.show', $faultReport->id),
                    'severity' => 'info',
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }
}
