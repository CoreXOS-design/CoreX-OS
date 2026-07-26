<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Http\Request;

class LeaseController extends Controller
{
    protected SignatureService $signatureService;

    public function __construct(SignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
    }

    /**
     * Per-record authorization for a lease action.
     *
     * SECURITY (audit 2026-07-21): lease_records has NO agency_id and NO global scope, so
     * route-model binding resolves ANY lease by id across ALL agencies. renew/terminate/history
     * previously ran with no check at all — any authenticated DocuPerfect user could terminate any
     * lease in any agency. This gates every lease action through the SAME scope the list uses
     * (LeaseRecord::visibleTo), so a user only ever touches leases within their rentals data scope,
     * and (see scopeVisibleTo) never outside their own agency.
     */
    private function authorizeLease(LeaseRecord $lease, \App\Models\User $user): void
    {
        abort_unless(
            LeaseRecord::visibleTo($user)->whereKey($lease->getKey())->exists(),
            403,
            'You do not have access to this lease.'
        );
    }

    /**
     * Renew a lease — clone the document and create a linked lease record.
     */
    public function renewLease(Request $request, LeaseRecord $lease)
    {
        $user = $request->user();
        $this->authorizeLease($lease, $user);

        // Ensure the lease can be renewed
        if (!in_array($lease->status, [LeaseRecord::STATUS_ACTIVE, LeaseRecord::STATUS_EXPIRING_SOON, LeaseRecord::STATUS_EXPIRED])) {
            return redirect()->route('docuperfect.rental')
                ->with('error', 'This lease cannot be renewed (status: ' . $lease->status . ').');
        }

        $lease->loadMissing('document');
        $originalDocument = $lease->document;

        if (!$originalDocument) {
            return redirect()->route('docuperfect.rental')
                ->with('error', 'Original lease document not found.');
        }

        // Clone the document
        $newDocument = $originalDocument->replicate();
        $newDocument->name = preg_replace(
            '/(\(Renewal(?: \d+)?\))?$/',
            '',
            trim($originalDocument->name)
        );
        $newDocument->name = trim($newDocument->name) . ' (Renewal)';
        $newDocument->archived_at = null;
        $newDocument->save();

        // Mark old lease as renewed
        $lease->update(['status' => LeaseRecord::STATUS_RENEWED]);

        // Create new lease record linked to old
        $newLeaseRecord = LeaseRecord::create([
            'document_id' => $newDocument->id,
            'property_address' => $lease->property_address,
            'property_id' => $lease->property_id,
            'tenant_name' => $lease->tenant_name,
            'tenant_email' => $lease->tenant_email,
            'landlord_name' => $lease->landlord_name,
            'landlord_email' => $lease->landlord_email,
            'rental_amount' => $lease->rental_amount,
            'lease_start_date' => $lease->lease_end_date ?? now(),
            'lease_end_date' => ($lease->lease_end_date ?? now())->copy()->addYear(),
            'status' => LeaseRecord::STATUS_ACTIVE,
            'previous_lease_id' => $lease->id,
        ]);

        // Link old to new
        $lease->update(['renewed_lease_id' => $newLeaseRecord->id]);

        // Audit log if signature template exists
        if ($lease->signatureTemplate) {
            SignatureAuditLog::log(
                $lease->signatureTemplate,
                'lease_renewed',
                SignatureAuditLog::ACTOR_USER,
                $user->name,
                $user->email,
                $user->id,
                metadata: [
                    'old_lease_id' => $lease->id,
                    'new_lease_id' => $newLeaseRecord->id,
                    'new_document_id' => $newDocument->id,
                ],
            );
        }

        return redirect()->route('docuperfect.documents.edit', $newDocument->id)
            ->with('success', 'Lease renewed. Please update the rental amount and dates, then proceed to signatures.');
    }

    /**
     * Terminate a lease.
     */
    public function terminateLease(Request $request, LeaseRecord $lease)
    {
        $this->authorizeLease($lease, $request->user());

        $request->validate([
            'termination_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        $lease->update([
            'status' => LeaseRecord::STATUS_TERMINATED,
        ]);

        // Audit log
        if ($lease->signatureTemplate) {
            SignatureAuditLog::log(
                $lease->signatureTemplate,
                'lease_terminated',
                SignatureAuditLog::ACTOR_USER,
                $request->user()->name,
                $request->user()->email,
                $request->user()->id,
                metadata: [
                    'termination_date' => $request->termination_date,
                    'reason' => $request->reason,
                    'terminated_by' => $request->user()->name,
                ],
            );
        }

        return redirect()->route('docuperfect.rental')
            ->with('success', "Lease for {$lease->property_address} marked as terminated.");
    }

    /**
     * Show lease version history.
     */
    public function leaseHistory(Request $request, LeaseRecord $lease)
    {
        $this->authorizeLease($lease, $request->user());

        $versions = $lease->allVersions();

        // Determine current version number
        $currentIndex = $versions->search(fn($v) => $v->id === $lease->id);

        return view('docuperfect.leases.history', [
            'currentLease' => $lease,
            'versions' => $versions,
            'currentVersionNumber' => $currentIndex !== false ? $currentIndex + 1 : 1,
            'totalVersions' => $versions->count(),
            'user' => $request->user(),
        ]);
    }
}
