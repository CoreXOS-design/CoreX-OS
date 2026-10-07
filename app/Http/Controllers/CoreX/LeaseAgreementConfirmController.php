<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\Rentals\LeaseAgreementConfirmationRefused;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Services\Rentals\LeaseAgreementCheck;
use App\Services\Rentals\LeaseAgreementConfirmService;
use App\Services\Rentals\LeaseSigningStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/leases.md §15.9 (Build L3c) — the lease screen in `mode=confirm`: what the agent sees when the lease
 * agreement was changed in e-sign. The same capture screen, opened on the lease's own record, showing for each
 * field the lease's value beside the agreement's.
 *
 * Three situations, one screen:
 *   approve   the agreement is waiting for the agent's final approval. The button posts to the e-sign engine's own
 *             approve route carrying the confirmation; the route's middleware (EnsureLeaseAgreementConfirmed) takes
 *             it, then lets the engine's unchanged approve action run.
 *   activate  the agreement was signed with a difference nobody confirmed (§15.5). The button posts here; the
 *             confirmation is taken and the lease goes active.
 *   preview   the agreement is still out for signing: the agent can see what has changed so far, nothing more.
 *
 * Leaving the screen changes nothing: the lease record is untouched until the button is pressed.
 */
class LeaseAgreementConfirmController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function show(Request $request, Lease $lease): View|RedirectResponse
    {
        $this->authorizeFor($request, $lease);

        // What the agent typed for a value the agreement does not show (kept across a refusal and a re-check).
        $entered = array_filter((array) old('lease_confirm.entered', []), fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        $verdict = app(LeaseAgreementCheck::class)->verdict($lease, $entered);

        if (! $verdict['applicable']) {
            return redirect()->route('corex.leases.show', $lease)
                ->with('error', 'This lease has no lease agreement to check its details against.');
        }

        $envelope = $lease->signature_template_id ? SignatureTemplate::withoutGlobalScopes()->find($lease->signature_template_id) : null;

        $stage = match (true) {
            $envelope !== null && $envelope->status === SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL
                && in_array($lease->signing_status, Lease::SIGNING_IN_FLIGHT, true) => 'approve',
            $lease->signing_status === Lease::SIGNING_SIGNED && $lease->status === Lease::STATUS_DRAFT => 'activate',
            default => 'preview',
        };

        return view('corex.leases.capture', [
            'mode' => 'confirm',
            'lease' => $lease->load(['property', 'tenants.contact']),
            'verdict' => $verdict,
            'stage' => $stage,
            'entered' => $entered,
            // Where a name, ID or address that disagrees with the agreement is corrected: the contact itself.
            'partyLinks' => [
                'tenant' => $lease->tenants()->with('contact')->orderByDesc('is_primary')->orderBy('id')->get()
                    ->map(fn ($t) => $t->contact)->filter()
                    ->map(fn ($c) => ['name' => (string) $c->full_name, 'url' => route('corex.contacts.show', $c)])->values()->all(),
                'landlord' => $lease->landlordContacts()
                    ->map(fn ($c) => ['name' => (string) $c->full_name, 'url' => route('corex.contacts.show', $c)])->values()->all(),
            ],
            'postUrl' => match ($stage) {
                'approve' => route('docuperfect.signatures.approveAndAdvance', $envelope->document_id),
                'activate' => route('corex.leases.agreement.confirm.store', $lease),
                default => null,
            },
            'agreementUrl' => $envelope?->document_id ? route('docuperfect.signatures.review', $envelope->document_id) : route('docuperfect.esign.myDocuments'),
        ]);
    }

    /** "Confirm and activate" — for a lease signed with a difference nobody had confirmed (§15.5, §15.9). */
    public function store(Request $request, Lease $lease): RedirectResponse
    {
        $this->authorizeFor($request, $lease);

        $data = $request->validate([
            'lease_confirm.fingerprint' => ['required', 'string', 'size:64'],
            'lease_confirm.entered' => ['nullable', 'array'],
            'lease_confirm.entered.*' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($lease->signing_status !== Lease::SIGNING_SIGNED || $lease->status !== Lease::STATUS_DRAFT) {
            return redirect()->route('corex.leases.show', $lease)->with('error', 'This lease is not waiting for its details to be confirmed.');
        }

        try {
            app(LeaseAgreementConfirmService::class)->confirm(
                $lease,
                $request->user(),
                (string) $data['lease_confirm']['fingerprint'],
                (array) ($data['lease_confirm']['entered'] ?? []),
            );
        } catch (LeaseAgreementConfirmationRefused $e) {
            return redirect()->route('corex.leases.agreement.confirm', $lease)
                ->withInput(['lease_confirm' => $data['lease_confirm']])
                ->with('error', $e->getMessage());
        }

        $result = app(LeaseSigningStateService::class)->activateConfirmed($lease->fresh(), $request->user());
        if ($result === null) {
            return redirect()->route('corex.leases.show', $lease)->with('error', 'This lease is not waiting for its details to be confirmed.');
        }

        return redirect()->route('corex.leases.show', $lease)->with(
            $result['activated'] ? 'success' : 'warning',
            $result['activated']
                ? 'Lease details confirmed — the lease is now active.'
                : 'Lease details confirmed. ' . ($result['note'] ?: 'The lease could not be made active yet.'),
        );
    }

    private function authorizeFor(Request $request, Lease $lease): void
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        abort_unless(app(LeaseAgreementConfirmService::class)->mayConfirm($request->user(), $lease), 403);
    }
}
