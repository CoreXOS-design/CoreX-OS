<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\AgencyPolicy;
use App\Models\Compliance\PolicyAcknowledgement;
use App\Models\Compliance\PolicySectionAcknowledgement;
use App\Models\Compliance\PolicyVersion;
use App\Services\Compliance\PolicyVariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Staff policy sign-off wizard (AT-29). Mirrors RmcpAcknowledgementController,
 * scoped by {policy} (agency_policies.policy_key) -> active PolicyVersion.
 */
class PolicyAcknowledgementController extends Controller
{
    /**
     * Start a new acknowledgement — creates in_progress record + section stubs.
     */
    public function start(string $policy)
    {
        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 403, 'No agency context.');

        $policyModel = $this->resolvePolicy($policy, $agencyId);
        $version = PolicyVersion::where('policy_id', $policyModel->id)->active()->firstOrFail();

        $existing = PolicyAcknowledgement::where('user_id', $user->id)
            ->where('policy_version_id', $version->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->first();

        if ($existing && $existing->status === 'in_progress') {
            $nextOrder = $this->nextIncompleteOrder($existing);
            if ($nextOrder === null) {
                return redirect()->route('policy.ack.sign', $policy);
            }
            return redirect()->route('policy.ack.step', [$policy, $nextOrder]);
        }

        if ($existing && $existing->isValid()) {
            return redirect()->route('policy.ack.receipt', [$policy, $existing])
                ->with('info', 'You have already acknowledged this policy version.');
        }

        $sections = $version->sections()
            ->where('requires_acknowledgement', true)
            ->orderBy('display_order')
            ->get();

        $ack = PolicyAcknowledgement::create([
            'agency_id'            => $agencyId,
            'policy_id'            => $policyModel->id,
            'policy_version_id'    => $version->id,
            'user_id'              => $user->id,
            'status'               => 'in_progress',
            'started_at'           => now(),
            'sections_total_count' => $sections->count(),
        ]);

        foreach ($sections as $section) {
            PolicySectionAcknowledgement::create([
                'agency_id'                 => $agencyId,
                'policy_acknowledgement_id' => $ack->id,
                'policy_section_id'         => $section->id,
                'acknowledged'             => false,
            ]);
        }

        return redirect()->route('policy.ack.step', [$policy, 1]);
    }

    /**
     * Show a single section for reading + acknowledgement.
     */
    public function step(string $policy, int $order)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user, $policy);
        abort_unless($ack, 404, 'No active acknowledgement session.');

        $nextIncomplete = $this->nextIncompleteOrder($ack);
        if ($nextIncomplete === null) {
            return redirect()->route('policy.ack.sign', $policy);
        }

        $sectionAcks = $ack->sectionAcknowledgements()
            ->with('section')
            ->get()
            ->sortBy('section.display_order')
            ->values();

        $total = $sectionAcks->count();
        $order = max(1, min($order, $total));

        // Enforce sequential — redirect to next incomplete if skipping ahead
        if ($order > $nextIncomplete) {
            return redirect()->route('policy.ack.step', [$policy, $nextIncomplete]);
        }

        $currentSectionAck = $sectionAcks[$order - 1] ?? null;
        abort_unless($currentSectionAck, 404);

        $section = $currentSectionAck->section;
        $agency = Agency::findOrFail($ack->agency_id);
        $resolver = app(PolicyVariableResolver::class);
        $variables = $resolver->resolve($agency, $ack->version, $user);

        $ackedCount = $sectionAcks->where('acknowledged', true)->count();

        return view('compliance.policy-ack.step', [
            'policyKey'   => $policy,
            'policyModel' => $ack->policy,
            'ack'         => $ack,
            'section'     => $section,
            'sectionAck'  => $currentSectionAck,
            'variables'   => $variables,
            'order'       => $order,
            'total'       => $total,
            'ackedCount'  => $ackedCount,
            'isLast'      => $order === $total,
            'isAcked'     => $currentSectionAck->acknowledged,
        ]);
    }

    /**
     * AJAX — mark a section as acknowledged.
     */
    public function confirmSection(Request $request, string $policy, int $order)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user, $policy);
        abort_unless($ack, 404);

        $sectionAcks = $ack->sectionAcknowledgements()
            ->with('section')
            ->get()
            ->sortBy('section.display_order')
            ->values();

        $sectionAck = $sectionAcks[$order - 1] ?? null;
        abort_unless($sectionAck, 404);

        if (!$sectionAck->acknowledged) {
            $sectionAck->update([
                'acknowledged'             => true,
                'acknowledged_at'          => now(),
                'acknowledgement_response' => 'yes',
                'ip_address'               => $request->ip(),
            ]);

            $ack->update([
                'sections_acknowledged_count' => $ack->sectionAcknowledgements()
                    ->where('acknowledged', true)->count(),
            ]);
        }

        $allDone = $ack->fresh()->sections_acknowledged_count >= $ack->sections_total_count;
        $nextUrl = $allDone
            ? route('policy.ack.sign', $policy)
            : route('policy.ack.step', [$policy, min($order + 1, $sectionAcks->count())]);

        return response()->json([
            'success'          => true,
            'next_url'         => $nextUrl,
            'progress_percent' => $ack->fresh()->progressPercent(),
            'all_done'         => $allDone,
        ]);
    }

    /**
     * Final signature page — only when all sections are acknowledged.
     */
    public function sign(string $policy)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user, $policy);
        abort_unless($ack, 404);

        if ($ack->sections_acknowledged_count < $ack->sections_total_count) {
            return redirect()->route('policy.ack.step', [$policy, $this->nextIncompleteOrder($ack)]);
        }

        $version = $ack->version;
        $agency = Agency::findOrFail($ack->agency_id);
        $resolver = app(PolicyVariableResolver::class);
        $variables = $resolver->resolve($agency, $version, $user);

        $declarationSection = $version->sections()
            ->where('section_type', 'acknowledgement')
            ->first();

        $declarationText = $declarationSection
            ? $declarationSection->renderedBody($variables)
            : 'I have read and understood this policy in full and acknowledge my obligations under it.';

        return view('compliance.policy-ack.sign', [
            'policyKey'       => $policy,
            'policyModel'     => $ack->policy,
            'ack'             => $ack,
            'version'         => $version,
            'agency'          => $agency,
            'user'            => $user,
            'declarationText' => $declarationText,
        ]);
    }

    /**
     * Submit final signature.
     */
    public function submit(Request $request, string $policy)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user, $policy);
        abort_unless($ack, 404);
        abort_unless($ack->sections_acknowledged_count >= $ack->sections_total_count, 403);

        $validated = $request->validate([
            'signature_type'           => 'required|in:drawn,typed',
            'signature_data'           => 'required_if:signature_type,drawn|nullable|string',
            'typed_name'               => 'required_if:signature_type,typed|nullable|string|max:200',
            'declaration_acknowledged' => 'accepted',
        ]);

        $agency = Agency::findOrFail($ack->agency_id);
        $version = $ack->version;
        $policyModel = $ack->policy;

        $idPart = $user->id_number ? " (ID: {$user->id_number})" : '';
        $declarationText = "I, {$user->name}{$idPart}, confirm that I have read and understood the {$policyModel->name} (v{$version->version_number}) of {$agency->name} in full, that I have acknowledged each section where required, and that I undertake to comply with it.";

        // Save signature
        $signaturePath = null;
        if ($validated['signature_type'] === 'drawn' && !empty($validated['signature_data'])) {
            $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $validated['signature_data']);
            $decoded = base64_decode($imageData);
            $filename = "{$user->id}-v{$version->version_number}-" . now()->format('Ymd-His') . '.png';
            $path = "policy/{$ack->agency_id}/{$policyModel->policy_key}/acknowledgements/{$filename}";
            Storage::disk('public')->put($path, $decoded);
            $signaturePath = $path;
        } elseif ($validated['signature_type'] === 'typed') {
            $signaturePath = 'typed:' . $validated['typed_name'];
        }

        $ack->update(['declaration_text' => $declarationText]);

        $ack->complete(
            $signaturePath,
            $validated['signature_type'],
            $request->ip(),
            $request->userAgent(),
            $validated['typed_name'] ?? null
        );

        return redirect()->route('policy.ack.receipt', [$policy, $ack])
            ->with('success', 'Policy acknowledgement complete. Valid until ' . $ack->fresh()->valid_until->format('d M Y') . '.');
    }

    /**
     * Completion receipt.
     */
    public function receipt(string $policy, PolicyAcknowledgement $ack)
    {
        abort_unless($ack->user_id === Auth::id() || Auth::user()->isOwnerRole(), 403);
        $ack->load(['version', 'policy', 'sectionAcknowledgements.section', 'user']);

        return view('compliance.policy-ack.receipt', ['policyKey' => $policy, 'ack' => $ack]);
    }

    /**
     * Download receipt as Puppeteer-generated PDF.
     */
    public function downloadReceipt(string $policy, PolicyAcknowledgement $ack)
    {
        $user = Auth::user();
        abort_unless(
            $ack->user_id === $user->id
            || $user->isOwnerRole()
            || $user->isComplianceOfficer()
            || $user->hasPermission('manage_compliance'),
            403
        );

        $ack->load(['version', 'policy', 'sectionAcknowledgements.section', 'user']);

        $html = view('compliance.policy-ack.receipt-print', ['ack' => $ack])->render();

        $pdfPath = $this->generateReceiptPdf($html, $ack->id);

        if (! $pdfPath || ! file_exists($pdfPath)) {
            Log::error('Policy receipt PDF generation failed', [
                'acknowledgement_id' => $ack->id,
                'user_id'            => $ack->user_id,
            ]);
            return back()->with('error', 'PDF generation failed. Please contact admin.');
        }

        $filename = sprintf(
            'policy-acknowledgement-%s-%s-%s.pdf',
            $ack->policy->policy_key,
            Str::slug($ack->user->name),
            ($ack->completed_at ?? $ack->created_at)->format('Ymd')
        );

        return response()->download($pdfPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * User's own acknowledgement history for this policy.
     */
    public function index(string $policy)
    {
        $user = Auth::user();
        $policyModel = $this->resolvePolicy($policy, $user->effectiveAgencyId());

        $acks = PolicyAcknowledgement::where('user_id', $user->id)
            ->where('policy_id', $policyModel->id)
            ->with('version')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('compliance.policy-ack.index', [
            'policyKey'   => $policy,
            'policyModel' => $policyModel,
            'acks'        => $acks,
        ]);
    }

    // ── Helpers ──

    private function resolvePolicy(string $policyKey, ?int $agencyId): AgencyPolicy
    {
        return AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $policyKey)
            ->firstOrFail();
    }

    private function currentAck($user, string $policyKey): ?PolicyAcknowledgement
    {
        $agencyId = $user->effectiveAgencyId();
        if (!$agencyId) return null;

        $policyModel = AgencyPolicy::where('agency_id', $agencyId)
            ->where('policy_key', $policyKey)
            ->first();
        if (!$policyModel) return null;

        $version = PolicyVersion::where('policy_id', $policyModel->id)->active()->first();
        if (!$version) return null;

        return PolicyAcknowledgement::where('user_id', $user->id)
            ->where('policy_version_id', $version->id)
            ->where('status', 'in_progress')
            ->latest()
            ->first();
    }

    private function nextIncompleteOrder(PolicyAcknowledgement $ack): ?int
    {
        $sectionAcks = $ack->sectionAcknowledgements()
            ->with('section')
            ->get()
            ->sortBy('section.display_order')
            ->values();

        foreach ($sectionAcks as $i => $sa) {
            if (!$sa->acknowledged) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Generate PDF from full HTML via Puppeteer (reuses scripts/html-to-pdf.mjs).
     * Mirrors RmcpAcknowledgementController::generateReceiptPdf.
     */
    private function generateReceiptPdf(string $fullHtml, int $ackId): ?string
    {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $timestamp = time();
        $htmlPath = $tempDir . '/policy_ack_' . $ackId . '_' . $timestamp . '.html';
        $pdfPath  = $tempDir . '/policy_ack_' . $ackId . '_' . $timestamp . '.pdf';

        file_put_contents($htmlPath, $fullHtml);

        $scriptPath  = base_path('scripts/html-to-pdf.mjs');
        $browserPath = config('services.pdf.puppeteer_browser_path', '');
        $isWindows   = DIRECTORY_SEPARATOR === '\\';

        $nodePath = 'node';
        if ($isWindows) {
            $candidates = [
                'C:\\Program Files\\nodejs\\node.exe',
                'C:\\Program Files (x86)\\nodejs\\node.exe',
                trim(shell_exec('where node 2>NUL') ?? ''),
            ];
            foreach ($candidates as $candidate) {
                $candidate = trim($candidate);
                if ($candidate && file_exists($candidate)) {
                    $nodePath = $candidate;
                    break;
                }
            }
        }

        $nodeArg   = escapeshellarg(str_replace('\\', '/', $nodePath));
        $scriptArg = escapeshellarg(str_replace('\\', '/', $scriptPath));
        $htmlArg   = escapeshellarg(str_replace('\\', '/', $htmlPath));
        $outArg    = escapeshellarg(str_replace('\\', '/', $pdfPath));

        $envPrefix = '';
        if (! $isWindows) {
            $envPrefix = 'HOME=/tmp';
            if ($browserPath) {
                $envPrefix .= sprintf(' PUPPETEER_BROWSER_PATH=%s', escapeshellarg($browserPath));
            }
            $envPrefix .= ' ';
        }

        $command = sprintf('%s%s %s %s %s', $envPrefix, $nodeArg, $scriptArg, $htmlArg, $outArg);
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'policy_pdf_' . $ackId . '.log';

        Log::info('Policy receipt PDF generation starting', ['ack_id' => $ackId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (! file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            @unlink($htmlPath);
            Log::error('Policy receipt PDF not generated', [
                'ack_id' => $ackId,
                'log'    => substr($logContent, 0, 500),
            ]);
            return null;
        }

        @unlink($htmlPath);

        Log::info('Policy receipt PDF complete', [
            'ack_id' => $ackId,
            'path'   => $normalizedOutput,
            'size'   => filesize($normalizedOutput),
        ]);

        return $normalizedOutput;
    }
}
