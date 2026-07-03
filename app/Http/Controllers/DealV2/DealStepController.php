<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealStepInstance;
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
        abort_unless(auth()->user()?->hasPermission('deals_v2.edit'), 403);

        $rules = [
            'outcome' => ['required', 'in:positive,negative'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        // Validate based on completion type
        switch ($step->completion_type) {
            case 'date_input':
                $rules['value'] = ['required', 'date'];
                break;
            case 'amount_input':
                $rules['value'] = ['required', 'numeric', 'min:0'];
                break;
            case 'text_input':
                $rules['value'] = ['required', 'string', 'max:1000'];
                break;
            case 'document_upload':
                $rules['file'] = ['required', 'file', 'max:10240'];
                break;
        }

        // Negative outcome requires reason
        if ($request->input('outcome') === 'negative' && $step->negative_status_trigger) {
            $rules['reason'] = ['required', 'string', 'max:1000'];
        }

        $data = $request->validate($rules);

        $completionData = [
            'outcome' => $data['outcome'],
            'value' => $data['value'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if ($data['outcome'] === 'negative') {
            $completionData['reason'] = $data['reason'] ?? null;
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
