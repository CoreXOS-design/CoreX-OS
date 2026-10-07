<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\PropertySettingItem;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Rentals\LeaseCaptureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.2 (Build L2) — what the two controllers that serve the single capture screen
 * (LeaseController for a new lease, LeaseRenewalController for a renewal) share: building the screen's
 * agreement/term data and running a capture, so both go through the same code.
 */
trait HandlesLeaseCapture
{
    /**
     * The data the capture view needs beyond the mode-specific bits (property, tenants).
     *
     * @return array<string, mixed>
     */
    protected function leaseCaptureScreen(User $user, ?Lease $previous, ?RentalApplication $application = null): array
    {
        $service = app(LeaseCaptureService::class);
        $agencyId = (int) ($previous?->agency_id ?? $user->effectiveAgencyId());
        $state = $service->agreementStateFor($agencyId);

        $agreements = [];
        $values = [];
        foreach ($state['agreements'] as $row) {
            $fields = $service->agreementFields($row);
            $renewal = $previous ? $service->renewalDefaults($previous, $fields) : null;
            $defaults = $renewal['agreement'] ?? [];
            if (! $previous && $application && $application->adults !== null) {
                // A linked rental application already says how many adults will live there.
                $defaults['adults'] = $application->adults;
            }

            $agreements[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'fields' => $fields,
                'on_record' => $renewal['on_record'] ?? [],
            ];
            foreach ($fields as $field) {
                $values[$field['key']] ??= old('agreement.' . $field['key'], $defaults[$field['key']] ?? null);
            }
        }

        $base = $previous ? $service->renewalDefaults($previous, []) : null;

        return [
            'agreementState' => $state['state'],
            'agreementReason' => $state['reason'],
            'agreements' => $agreements,
            'agreementValues' => $values,
            'selectedAgreementId' => (int) old('agreement_id', $agreements[0]['id'] ?? 0) ?: ($agreements[0]['id'] ?? null),
            'canPrepare' => $user->hasPermission('access_docuperfect') && $user->hasPermission('create_docuperfect_docs'),
            'canManageAgreements' => $user->hasPermission('rental_lease_templates.manage_settings'),
            // Johan, 2026-09-22 — agency-configurable, hidden by default (same setting as the lease edit panel).
            'showLeaseType' => LeaseSetting::showLeaseTypeFieldFor($agencyId),
            // .ai/specs/rental-property-tab.md §5, Part 4 — the agency-editable list.
            'leaseTypes' => PropertySettingItem::group('lease_type')->where('active', true)->get(),
            'depositMonths' => LeaseSetting::defaultDepositMonthsFor($agencyId),
            'renewalBase' => $base,
            'captureKey' => (string) old('capture_key', (string) Str::uuid()),
        ];
    }

    /**
     * Run a capture and answer the way the screen needs: a created lease goes to its Lease Hub; anything
     * the agent can fix goes back to the same screen with every field kept and the reason shown.
     */
    protected function runLeaseCapture(LeaseCaptureRequest $request, ?Lease $previous): RedirectResponse
    {
        $input = $request->validated();
        $input['signed_document'] = $request->file('signed_document');
        $intent = (string) $input['intent'];

        try {
            $lease = app(LeaseCaptureService::class)->capture($input, $intent, $request->user(), $previous);
        } catch (LeaseCaptureIncompleteException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (NoLeaseAgreementLinkedException $e) {
            return back()->withInput()->withErrors(['intent' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', $this->leaseCaptureMessage($intent, $previous !== null));
    }

    private function leaseCaptureMessage(string $intent, bool $isRenewal): string
    {
        return match ($intent) {
            LeaseCaptureService::INTENT_LEASE_AND_SIGN => ($isRenewal ? 'Renewal created.' : 'Lease created.')
                . ' Preparing the signing document is not available yet, so no document was made.',
            LeaseCaptureService::INTENT_PAPER_COPY => $isRenewal ? 'Renewal recorded and activated.' : 'Lease created and activated with the signed copy.',
            default => $isRenewal ? 'Renewal created.' : 'Lease created.',
        };
    }
}
