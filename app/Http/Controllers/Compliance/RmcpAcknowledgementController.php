<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpSectionAcknowledgement;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\RmcpVariableResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RmcpAcknowledgementController extends Controller
{
    /**
     * Start a new acknowledgement — creates in_progress record + section stubs.
     */
    public function start()
    {
        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 403, 'No agency context.');

        $version = RmcpVersion::where('agency_id', $agencyId)->active()->firstOrFail();

        // Check if user already has an in_progress or completed ack for this version
        $existing = RmcpAcknowledgement::where('user_id', $user->id)
            ->where('rmcp_version_id', $version->id)
            ->whereIn('status', ['in_progress', 'completed'])
            ->first();

        if ($existing && $existing->status === 'in_progress') {
            $nextOrder = $this->nextIncompleteOrder($existing);
            if ($nextOrder === null) {
                return redirect()->route('rmcp.ack.sign');
            }
            return redirect()->route('rmcp.ack.step', $nextOrder);
        }

        if ($existing && $existing->isValid()) {
            return redirect()->route('rmcp.ack.receipt', $existing)
                ->with('info', 'You have already acknowledged this RMCP version.');
        }

        // Get sections requiring acknowledgement
        $sections = $version->sections()
            ->where('requires_acknowledgement', true)
            ->orderBy('display_order')
            ->get();

        $ack = RmcpAcknowledgement::create([
            'agency_id'        => $agencyId,
            'rmcp_version_id'  => $version->id,
            'user_id'          => $user->id,
            'status'           => 'in_progress',
            'started_at'       => now(),
            'sections_total_count' => $sections->count(),
        ]);

        foreach ($sections as $section) {
            RmcpSectionAcknowledgement::create([
                'rmcp_acknowledgement_id' => $ack->id,
                'rmcp_section_id'         => $section->id,
                'acknowledged'            => false,
            ]);
        }

        return redirect()->route('rmcp.ack.step', 1);
    }

    /**
     * Show a single section for reading + acknowledgement.
     */
    public function step(int $order)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user);
        abort_unless($ack, 404, 'No active acknowledgement session.');

        // If all sections are acknowledged, go straight to sign
        $nextIncomplete = $this->nextIncompleteOrder($ack);
        if ($nextIncomplete === null) {
            return redirect()->route('rmcp.ack.sign');
        }

        $sectionAcks = $ack->sectionAcknowledgements()
            ->with('section')
            ->get()
            ->sortBy('section.display_order')
            ->values();

        $total = $sectionAcks->count();
        $order = max(1, min($order, $total));

        // Enforce sequential — redirect to next incomplete if skipping
        if ($order > $nextIncomplete) {
            return redirect()->route('rmcp.ack.step', $nextIncomplete);
        }

        $currentSectionAck = $sectionAcks[$order - 1] ?? null;
        abort_unless($currentSectionAck, 404);

        $section = $currentSectionAck->section;
        $agency = Agency::findOrFail($ack->agency_id);
        $resolver = app(RmcpVariableResolver::class);
        $variables = $resolver->resolve($agency, $ack->version);

        $ackedCount = $sectionAcks->where('acknowledged', true)->count();

        return view('compliance.rmcp-ack.step', [
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
    public function confirmSection(Request $request, int $order)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user);
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
                'acknowledged'              => true,
                'acknowledged_at'           => now(),
                'acknowledgement_response'  => 'yes',
                'ip_address'                => $request->ip(),
            ]);

            $ack->update([
                'sections_acknowledged_count' => $ack->sectionAcknowledgements()
                    ->where('acknowledged', true)->count(),
            ]);
        }

        $allDone = $ack->fresh()->sections_acknowledged_count >= $ack->sections_total_count;
        $nextUrl = $allDone
            ? route('rmcp.ack.sign')
            : route('rmcp.ack.step', min($order + 1, $sectionAcks->count()));

        return response()->json([
            'success'          => true,
            'next_url'         => $nextUrl,
            'progress_percent' => $ack->fresh()->progressPercent(),
            'all_done'         => $allDone,
        ]);
    }

    /**
     * Final signature page — only accessible when all sections are acknowledged.
     */
    public function sign()
    {
        $user = Auth::user();
        $ack = $this->currentAck($user);
        abort_unless($ack, 404);

        if ($ack->sections_acknowledged_count < $ack->sections_total_count) {
            return redirect()->route('rmcp.ack.step', $this->nextIncompleteOrder($ack));
        }

        $version = $ack->version;

        // Get declaration text from the acknowledgement section
        $declarationSection = $version->sections()
            ->where('section_type', 'acknowledgement')
            ->first();

        $agency = Agency::findOrFail($ack->agency_id);
        $resolver = app(RmcpVariableResolver::class);
        $variables = $resolver->resolve($agency, $version);

        $declarationText = $declarationSection
            ? $declarationSection->renderedBody($variables)
            : 'I have read and understood the RMCP and acknowledge my obligations.';

        return view('compliance.rmcp-ack.sign', [
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
    public function submit(Request $request)
    {
        $user = Auth::user();
        $ack = $this->currentAck($user);
        abort_unless($ack, 404);
        abort_unless($ack->sections_acknowledged_count >= $ack->sections_total_count, 403);

        $validated = $request->validate([
            'signature_type'          => 'required|in:drawn,typed',
            'signature_data'          => 'required_if:signature_type,drawn|nullable|string',
            'typed_name'              => 'required_if:signature_type,typed|nullable|string|max:200',
            'declaration_acknowledged' => 'accepted',
        ]);

        // Store declaration text snapshot (electronic signing version with ID number)
        $agency = Agency::findOrFail($ack->agency_id);
        $version = $ack->version;

        $idPart = $user->id_number ? " (ID: {$user->id_number})" : '';
        $declarationText = "I, {$user->name}{$idPart}, confirm that I have read and understood the Risk Management and Compliance Programme (RMCP v{$version->version_number}) of {$agency->name} in full, that I have acknowledged each section where required, and that I undertake to observe strictly and diligently all my duties imposed by FICA and this RMCP.";

        // Save signature
        $signaturePath = null;
        if ($validated['signature_type'] === 'drawn' && !empty($validated['signature_data'])) {
            $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $validated['signature_data']);
            $decoded = base64_decode($imageData);
            $filename = "{$user->id}-v{$version->version_number}-" . now()->format('Ymd-His') . '.png';
            $path = "rmcp/{$ack->agency_id}/acknowledgements/{$filename}";
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

        return redirect()->route('rmcp.ack.receipt', $ack)
            ->with('success', 'RMCP acknowledgement complete. Valid until ' . $ack->fresh()->valid_until->format('d M Y') . '.');
    }

    /**
     * Completion receipt.
     */
    public function receipt(RmcpAcknowledgement $ack)
    {
        abort_unless($ack->user_id === Auth::id() || Auth::user()->isOwnerRole(), 403);
        $ack->load(['version', 'sectionAcknowledgements.section', 'user']);

        return view('compliance.rmcp-ack.receipt', compact('ack'));
    }

    /**
     * Download receipt as Puppeteer-generated PDF.
     */
    public function downloadReceipt(RmcpAcknowledgement $ack)
    {
        $user = Auth::user();
        abort_unless(
            $ack->user_id === $user->id
            || $user->isOwnerRole()
            || $user->isComplianceOfficer()
            || $user->hasPermission('manage_compliance'),
            403
        );

        $ack->load(['version', 'sectionAcknowledgements.section', 'user']);

        $html = view('compliance.rmcp-ack.receipt-print', compact('ack'))->render();

        $pdfPath = $this->generateReceiptPdf($html, $ack->id);

        if (! $pdfPath || ! file_exists($pdfPath)) {
            Log::error('RMCP receipt PDF generation failed', [
                'acknowledgement_id' => $ack->id,
                'user_id'            => $ack->user_id,
            ]);
            return back()->with('error', 'PDF generation failed. Please contact admin.');
        }

        $filename = sprintf(
            'rmcp-acknowledgement-%s-%s.pdf',
            Str::slug($ack->user->name),
            ($ack->completed_at ?? $ack->created_at)->format('Ymd')
        );

        return response()->download($pdfPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Generate PDF from full HTML via Puppeteer (reuses scripts/html-to-pdf.mjs).
     */
    private function generateReceiptPdf(string $fullHtml, int $ackId): ?string
    {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $timestamp = time();
        $htmlPath = $tempDir . '/rmcp_ack_' . $ackId . '_' . $timestamp . '.html';
        $pdfPath  = $tempDir . '/rmcp_ack_' . $ackId . '_' . $timestamp . '.pdf';

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
        $logPath = $tempDir . DIRECTORY_SEPARATOR . 'rmcp_pdf_' . $ackId . '.log';

        Log::info('RMCP receipt PDF generation starting', ['ack_id' => $ackId, 'command' => $command]);

        $fullCommand = $command . ' > ' . escapeshellarg(str_replace('/', DIRECTORY_SEPARATOR, $logPath)) . ' 2>&1';
        shell_exec($fullCommand);

        $logContent = file_exists($logPath) ? file_get_contents($logPath) : '';
        @unlink($logPath);

        clearstatcache();
        $normalizedOutput = str_replace('/', DIRECTORY_SEPARATOR, $pdfPath);

        if (! file_exists($normalizedOutput) || filesize($normalizedOutput) === 0) {
            @unlink($htmlPath);
            Log::error('RMCP receipt PDF not generated', [
                'ack_id' => $ackId,
                'log'    => substr($logContent, 0, 500),
            ]);
            return null;
        }

        @unlink($htmlPath);

        Log::info('RMCP receipt PDF complete', [
            'ack_id' => $ackId,
            'path'   => $normalizedOutput,
            'size'   => filesize($normalizedOutput),
        ]);

        return $normalizedOutput;
    }

    /**
     * User's own acknowledgement history.
     */
    public function index()
    {
        $acks = RmcpAcknowledgement::where('user_id', Auth::id())
            ->with('version')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('compliance.rmcp-ack.index', compact('acks'));
    }

    // ── Helpers ──

    private function currentAck($user): ?RmcpAcknowledgement
    {
        $agencyId = $user->effectiveAgencyId();
        if (!$agencyId) return null;

        $version = RmcpVersion::where('agency_id', $agencyId)->active()->first();
        if (!$version) return null;

        return RmcpAcknowledgement::where('user_id', $user->id)
            ->where('rmcp_version_id', $version->id)
            ->where('status', 'in_progress')
            ->latest()
            ->first();
    }

    private function nextIncompleteOrder(RmcpAcknowledgement $ack): ?int
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

        // All sections acknowledged — caller should redirect to sign
        return null;
    }
}
