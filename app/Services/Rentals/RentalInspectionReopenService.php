<?php

namespace App\Services\Rentals;

use App\Exceptions\RentalInspectionNotRecordableException;
use App\Exceptions\RentalInspectionSigningLinkException as LinkException;
use App\Models\Contact;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionReopen;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-inspections.md §47 — "Edit report" on a signed inspection (Johan's ruling, 7 Oct 2026).
 *
 * A signed report is locked. The one way to change it is this action: it voids EVERY signature (each tenant, the
 * landlord, the agent — however they signed: link, QR, the agent's device, the agent's own screen, the PIN), puts the
 * report back to the not-signed state, and everyone signs the changed report again. One report, not a second copy.
 *
 * Nothing is deleted. A voided signature stays as history (who signed, when, how, the report as they saw it, who reopened
 * it, when and why) and is marked superseded, so it never counts as a signature and never prints as one. Outstanding
 * signing links are revoked; fresh ones are issued when the inspection is marked ready to sign again, and the agent
 * resends them — nobody is emailed automatically.
 *
 * Refused for a DISTRIBUTED report (completed / copies sent) — for everyone, in every role: see
 * RentalInspection::isDistributed() and RentalInspection::startReplacement().
 */
class RentalInspectionReopenService
{
    /**
     * @throws \LogicException  RentalInspectionNotRecordableException when distributed / completed / cancelled / archived
     */
    public function reopen(RentalInspection $inspection, User $by, string $reason): RentalInspectionReopen
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new \LogicException('Please say why the report is being changed — a reason is required.');
        }

        return DB::transaction(function () use ($inspection, $by, $reason) {
            // Lock the row so two presses (or a signature arriving at the same moment) cannot interleave.
            $locked = RentalInspection::withoutGlobalScopes()->withTrashed()->lockForUpdate()->findOrFail($inspection->id);

            if ($locked->isDistributed()) {
                throw new RentalInspectionNotRecordableException(
                    'This report has been sent to the parties, so it can never be edited or reopened — by anyone. To correct it, start a new inspection that replaces it.'
                );
            }
            $locked->assertRecordable();
            if (! $locked->isSignedLocked()) {
                throw new \LogicException('This report has no signatures yet, so it is not locked — you can edit it freely.');
            }

            $live = RentalInspectionSignature::withoutGlobalScopes()
                ->where('rental_inspection_id', $locked->id)->whereNull('superseded_at')->orderBy('id')->get();

            $voided = $live->map(fn (RentalInspectionSignature $s) => [
                'signature_id' => $s->id,
                'party_role' => $s->party_role,
                'name' => $this->nameOf($s, $locked),
                'disposition' => $s->disposition,
                'signed_at' => $s->disposition_recorded_at?->toIso8601String(),
                'signed_via' => $s->signed_via,
                'signed_report_fingerprint' => $s->signed_report_fingerprint,
            ])->values()->all();

            $links = RentalInspectionSigningLink::withoutGlobalScopes()
                ->where('rental_inspection_id', $locked->id)->whereNull('revoked_at')->get();

            $reopen = RentalInspectionReopen::create([
                'agency_id' => $locked->agency_id,
                'rental_inspection_id' => $locked->id,
                'reopened_by_user_id' => $by->id,
                'reopened_at' => now(),
                'reason' => mb_substr($reason, 0, 1000),
                'previous_status' => (string) $locked->status,
                // The report is locked from the first signature until this moment, so its state now IS what they signed.
                'report_fingerprint' => $locked->reportFingerprint(),
                'report_snapshot' => $locked->reportSnapshot(),
                'voided_signatures' => $voided,
                'revoked_link_ids' => $links->pluck('id')->all(),
            ]);

            RentalInspectionSignature::withoutGlobalScopes()->whereIn('id', $live->pluck('id'))
                ->update(['superseded_at' => now(), 'voided_by_reopen_id' => $reopen->id]);

            foreach ($links as $link) {
                $link->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $by->id, 'revoked_reason' => 'reopened'])->save();
            }

            // Back to the normal recording state: the agent edits, then marks it ready to sign again (the usual checks run).
            $locked->forceFill(['status' => RentalInspection::STATUS_DRAFT, 'signing_deadline_at' => null])->save();

            RentalInspectionAuditLog::record(
                $locked,
                RentalInspectionAuditLog::EVENT_REOPENED,
                'Report reopened for editing by ' . $by->name . ': ' . mb_substr($reason, 0, 200) . ' — ' . count($voided) . ' signature(s) voided, '
                    . $links->count() . ' signing link(s) revoked. Everyone must sign again.',
                ['voided_signatures' => collect($voided)->map(fn ($v) => $v['name'] . ' (' . $v['party_role'] . ', ' . ($v['disposition'] === 'signed' ? 'signed' : str_replace('_', ' ', $v['disposition']))
                    . ($v['signed_at'] ? ' ' . \Illuminate\Support\Carbon::parse($v['signed_at'])->format('d M H:i') : '') . ($v['signed_via'] ? ', ' . str_replace('_', ' ', $v['signed_via']) : '') . ')')->all()],
                ['reopen_id' => $reopen->id, 'status' => RentalInspection::STATUS_DRAFT],
                $by,
            );

            return $reopen;
        });
    }

    /** The newest reopen of this inspection, if it was ever reopened. */
    public function latestFor(RentalInspection $inspection): ?RentalInspectionReopen
    {
        return RentalInspectionReopen::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->latest('id')->first();
    }

    /**
     * After "Ready to sign" again: a fresh link for each party whose link the reopen revoked (and who has not already
     * signed again). Issued, NOT sent — the agent resends, and a party who had signed hears about it only then.
     *
     * @return int how many fresh links were issued
     */
    public function reissueLinksAfterReopen(RentalInspection $inspection, User $by): int
    {
        $reopen = $this->latestFor($inspection);
        if (! $reopen || empty($reopen->revoked_link_ids)) {
            return 0;
        }
        $links = app(RentalInspectionSigningLinkService::class);
        $issued = 0;
        $seen = [];
        foreach (RentalInspectionSigningLink::withoutGlobalScopes()->whereIn('id', $reopen->revoked_link_ids)->get() as $old) {
            $key = $old->party_role . ':' . $old->party_contact_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            try {
                $before = RentalInspectionSigningLink::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->count();
                $links->issue($inspection, $old->party_role, $old->party_contact_id, $by);
                $issued += RentalInspectionSigningLink::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->count() > $before ? 1 : 0;
            } catch (LinkException $e) {
                // The setting is off, or the party already has an outcome again — nothing to issue.
            }
        }

        return $issued;
    }

    private function nameOf(RentalInspectionSignature $s, RentalInspection $inspection): string
    {
        if ($s->party_role === RentalInspectionSignature::PARTY_AGENT) {
            $agent = $s->recorded_by_user_id ? User::withoutGlobalScopes()->find($s->recorded_by_user_id) : null;

            return $agent?->name ?? ($inspection->inspector ?? $inspection->createdBy)?->name ?? 'Agent';
        }
        $contact = $s->party_contact_id ? Contact::withoutGlobalScopes()->find($s->party_contact_id) : null;

        return $s->signed_typed_name ?: ($contact?->full_name ?? ucfirst($s->party_role));
    }
}
