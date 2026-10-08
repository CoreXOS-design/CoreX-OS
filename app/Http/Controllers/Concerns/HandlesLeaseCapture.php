<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\RentalApplication;
use App\Models\User;
use App\Models\Docuperfect\Flow;
use App\Services\Rentals\LeaseAgentService;
use App\Services\Rentals\LeaseCaptureService;
use App\Services\Rentals\LeaseNoticeTermsService;
use App\Services\Rentals\LeaseSigningLauncher;
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
    protected function leaseCaptureScreen(User $user, ?Lease $previous, ?RentalApplication $application = null, ?Property $property = null): array
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
            // The letting commission % starts at the property's own (leases.md §15.12.5 #22) — a renewal's previous
            // term wins when it holds one; the agent can change it.
            $propertyCommission = ($previous?->property ?? $property)?->commission_percent;
            if (($defaults['commission_percent'] ?? null) === null && $propertyCommission !== null) {
                $defaults['commission_percent'] = $propertyCommission;
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

        // leases.md §17 — the owner's and the tenant's agent on the screen. A renewal starts from the term being renewed;
        // a new lease from the default rules (the owner's agent follows the property the agent picks — see the screen).
        $leaseAgents = app(LeaseAgentService::class);
        if ($previous) {
            $carried = $leaseAgents->effectiveIds($previous);
            $agentDefaults = ['owner' => $carried['owner'], 'tenant' => $carried['tenant']];
        } else {
            $defaults = $leaseAgents->defaultsFor($agencyId, $property?->agent_id, $application, $user->id);
            $agentDefaults = ['owner' => $defaults['owner']['id'], 'tenant' => $defaults['tenant']['id']];
        }

        // leases.md §18 — the notice / early-cancellation block: the term being renewed (dates moved with the new start) or the
        // agency's defaults, overlaid with whatever the agent just typed. Shown on every capture, linked agreement or not.
        $notice = app(LeaseNoticeTermsService::class);
        $noticeStart = $previous && ! empty($base['start_date']) ? \Illuminate\Support\Carbon::parse($base['start_date']) : null;
        [$noticeBase, $noticeSource] = $previous
            ? $notice->forRenewal($previous, $noticeStart ?? now()->startOfDay())
            : [$notice->defaultsFor($agencyId, null), LeaseNoticeTermsService::SOURCE_AGENCY_DEFAULT];
        $noticeValues = [];
        foreach (LeaseNoticeTermsService::EDIT_KEYS as $key) {
            $noticeValues[$key] = old('notice.' . $key, $noticeBase[$key] ?? null);
        }
        $agreementKeys = collect($agreements)->flatMap(fn ($a) => collect($a['fields'])->pluck('key'))->unique()->values()->all();

        return [
            'noticeValues' => $noticeValues,
            'noticeSource' => $noticeSource,
            'noticeDefaultsNote' => $previous ? 'From the term being renewed' : 'From your agency defaults',
            'noticeAgreementKeys' => $agreementKeys,
            'earliestNoticeMonths' => LeaseSetting::earliestNoticeMonthsFor($agencyId),
            'agentOptions' => $leaseAgents->selectableAgents($agencyId, $previous?->branch_id ?? $property?->branch_id ?? $user->effectiveBranchId())->all(),
            'agentDefaults' => $agentDefaults,
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
            // The list (with a link to fix each gap) rides along so the screen can show it beside the errors.
            return back()->withInput()->withErrors($e->errors())->with('capture_gaps', $e->missing);
        } catch (NoLeaseAgreementLinkedException $e) {
            return back()->withInput()->withErrors(['intent' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        // "Prepare for signing": the agent lands on the finished agreement (Fill & review) to check it and sign
        // first — nothing has been sent to anyone (§15.4).
        if ($intent === LeaseCaptureService::INTENT_LEASE_AND_SIGN && $lease->signing_flow_id && ($flow = Flow::find($lease->signing_flow_id))) {
            return redirect(app(LeaseSigningLauncher::class)->landingUrl($flow))->with('success', $this->leaseCaptureMessage($intent, $previous !== null));
        }

        return redirect()->route('corex.leases.show', $lease)->with('success', $this->leaseCaptureMessage($intent, $previous !== null));
    }

    private function leaseCaptureMessage(string $intent, bool $isRenewal): string
    {
        return match ($intent) {
            LeaseCaptureService::INTENT_LEASE_AND_SIGN => ($isRenewal ? 'Renewal created.' : 'Lease created.')
                . ' The lease agreement is ready — check it, then sign. Nothing has been sent to anyone yet.',
            LeaseCaptureService::INTENT_PAPER_COPY => $isRenewal ? 'Renewal recorded and activated.' : 'Lease created and activated with the signed copy.',
            default => $isRenewal ? 'Renewal created.' : 'Lease created.',
        };
    }
}
