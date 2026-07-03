<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealDocumentService;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Http\Request;

class DealStepController extends Controller
{
    public function __construct(
        private DealPipelineService $pipelineService,
        private DealDocumentService $dealDocumentService,
    ) {
    }

    public function complete(Request $request, DealStepInstance $step)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('deals_v2.edit'), 403);

        // Scope gate (anti-gaming, own-deals discipline): the actor must be
        // entitled to THIS deal at their permitted scope — agent → own only,
        // BM → branch, admin → all — via visibleTo (the clampScope discipline;
        // no override = full permitted scope). An agent cannot complete a step
        // on someone else's deal.
        abort_unless(
            DealV2::query()->whereKey($step->deal_id)->visibleTo($user)->exists(),
            403,
            'You can only complete steps on deals within your scope.'
        );

        // Requirement inputs are now OPTIONAL — an unmet requirement is ALLOWED
        // WITH a structured reason (below), not hard-blocked. Format is still
        // validated when a value/file IS supplied.
        $rules = [
            'outcome'         => ['required', 'in:positive,negative'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'reason_category' => ['nullable', 'string', 'max:100'],
            'reason'          => ['nullable', 'string', 'max:1000'],
        ];
        switch ($step->completion_type) {
            case 'date_input':      $rules['value'] = ['nullable', 'date']; break;
            case 'amount_input':    $rules['value'] = ['nullable', 'numeric', 'min:0']; break;
            case 'text_input':      $rules['value'] = ['nullable', 'string', 'max:1000']; break;
            case 'document_upload': $rules['file']  = ['nullable', 'file', 'max:10240']; break;
        }

        $data = $request->validate($rules);
        $isNegative = $data['outcome'] === 'negative';
        $met = $this->requirementsMet($step, $request, $data);

        // A negative outcome (with a status trigger) already requires a reason;
        // an unmet POSITIVE completion now requires a structured reason too. This
        // is the anti-gaming escape valve: frictionless when requirements are met,
        // a stamped reason when they are not.
        $reasonRequired = ($isNegative && $step->negative_status_trigger) || (! $isNegative && ! $met);
        if ($reasonRequired && ! filled($data['reason'] ?? null)) {
            return back()->withInput()->withErrors([
                'reason' => $isNegative
                    ? 'A reason is required for a negative outcome.'
                    : "This step is being completed without its required {$this->requirementLabel($step)}. Choose a reason and add a short note.",
            ]);
        }

        $completionData = [
            'outcome' => $data['outcome'],
            'value' => $data['value'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($isNegative) {
            $completionData['reason'] = $data['reason'] ?? null;
        } elseif (! $met) {
            // Anti-gaming stamp: who/when comes from completeStep (completed_by_id
            // + completed_at); the category + note live here + the activity log.
            $completionData['completed_with_reason'] = true;
            $completionData['reason_category'] = $data['reason_category'] ?? 'other';
            $completionData['reason'] = $data['reason'];
        }

        // Handle file upload. WS3 (D4): file a unified Document anchored to the
        // deal (+ property + contacts) and back the step-file with its id, so
        // one upload is reachable from every angle — not orphaned on this step.
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $disk = config('filesystems.default', 'local');
            $path = $file->store("deals/{$step->deal_id}/steps/{$step->id}", $disk);

            $doc = $this->dealDocumentService->createDealDocument($step->deal, [
                'original_name'    => $file->getClientOriginalName(),
                'storage_path'     => $path,
                'disk'             => $disk,
                'mime_type'        => $file->getClientMimeType(),
                'size'             => $file->getSize(),
                'document_type_id' => data_get($step->completion_config, 'document_type_id'),
                'source_type'      => 'deal_step',
            ], auth()->user());

            $completionData['document_id'] = $doc->id;
            $completionData['file_path'] = $path;
            $completionData['file_name'] = $file->getClientOriginalName();
        }

        $this->pipelineService->completeStep($step, auth()->user(), $completionData);

        return redirect()->route('deals-v2.show', $step->deal_id)
            ->with('status', "Step \"{$step->name}\" completed.");
    }

    /** Does this completion satisfy the step's normal requirement (no reason needed)? */
    private function requirementsMet(DealStepInstance $step, Request $request, array $data): bool
    {
        return match ($step->completion_type) {
            'date_input', 'amount_input', 'text_input', 'multi_field' => filled($data['value'] ?? null),
            'document_upload', 'document_signed' => $request->hasFile('file')
                || filled($request->input('document_id'))
                || $step->documents()->exists(),
            default => true, // manual_tick / auto_from_linked_deal — nothing to attach
        };
    }

    /** Human label for the missing requirement, used in the "reason required" message. */
    private function requirementLabel(DealStepInstance $step): string
    {
        return match ($step->completion_type) {
            'document_upload', 'document_signed' => 'document',
            'date_input'   => 'date',
            'amount_input' => 'amount',
            'text_input'   => 'details',
            default        => 'information',
        };
    }

    public function approve(Request $request, DealStepInstance $step)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('deals_v2.manage_pipeline') || $user->is_admin, 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->pipelineService->approveStep($step, $user, $data['notes'] ?? null);

        return redirect()->route('deals-v2.show', $step->deal_id)
            ->with('status', "Step \"{$step->name}\" approved. Status change applied.");
    }

    public function reject(Request $request, DealStepInstance $step)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('deals_v2.manage_pipeline') || $user->is_admin, 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->pipelineService->rejectStep($step, $user, $data['reason']);

        return redirect()->route('deals-v2.show', $step->deal_id)
            ->with('status', "Step \"{$step->name}\" rejected and returned to agent.");
    }

    public function uploadDocument(Request $request, DealStepInstance $step)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        // WS3 (D4): back the standalone step upload with a unified Document
        // anchored to the deal (+ property + contacts), then record it on the
        // step for provenance. No orphaned raw file_path.
        $file = $request->file('file');
        $disk = config('filesystems.default', 'local');
        $path = $file->store("deals/{$step->deal_id}/steps/{$step->id}", $disk);

        $doc = $this->dealDocumentService->createDealDocument($step->deal, [
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => $disk,
            'mime_type'        => $file->getClientMimeType(),
            'size'             => $file->getSize(),
            'document_type_id' => data_get($step->completion_config, 'document_type_id'),
            'source_type'      => 'deal_step',
        ], auth()->user());

        $step->documents()->create([
            'document_id' => $doc->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'uploaded_by_id' => auth()->id(),
        ]);

        return redirect()->route('deals-v2.show', $step->deal_id)
            ->with('status', 'Document uploaded.');
    }

    public function overrideDueDate(Request $request, DealStepInstance $step)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.override_dates'), 403);

        $data = $request->validate([
            'due_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $oldDate = $step->due_date ? $step->due_date->format('d M Y') : 'none';
        $step->update([
            'due_date' => $data['due_date'],
            'current_rag' => $this->pipelineService->calculateRag($step, $data['due_date']),
        ]);

        $this->pipelineService->recalculateExpectedRegistration($step->deal);

        // Log via activity log directly
        \App\Models\DealV2\DealActivityLog::create([
            'deal_id' => $step->deal_id,
            'deal_step_instance_id' => $step->id,
            'user_id' => auth()->id(),
            'action' => 'date_overridden',
            'description' => "Due date for \"{$step->name}\" changed from {$oldDate} to " .
                \Carbon\Carbon::parse($data['due_date'])->format('d M Y') . ". Reason: {$data['reason']}",
            'created_at' => now(),
        ]);

        return redirect()->route('deals-v2.show', $step->deal_id)
            ->with('status', "Due date for \"{$step->name}\" updated.");
    }
}
