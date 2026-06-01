<?php

namespace App\Http\Controllers;

use App\Models\FaultReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FaultReportController extends Controller
{
    /**
     * List fault reports (admin view).
     */
    public function index(Request $request)
    {
        $query = FaultReport::with('user')->recent();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->byType($request->type);
        }

        $reports = $query->paginate(50);

        return view('admin.fault-reports.index', compact('reports'));
    }

    /**
     * Capture a fault report from frontend or manual submission.
     * Always returns 200 — fault capture must never break the caller.
     */
    public function capture(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:500',
                'type' => 'required|in:backend,frontend,manual',
                'severity' => 'sometimes|in:error,warning,info',
                'message' => 'nullable|string|max:5000',
                'exception_class' => 'nullable|string|max:255',
                'file' => 'nullable|string|max:500',
                'line' => 'nullable|integer',
                'trace' => 'nullable|string|max:5000',
                'url' => 'nullable|string|max:1000',
                'method' => 'nullable|string|max:10',
                'screenshot_path' => 'nullable|string|max:500',
            ]);

            $now = now();

            // Deduplication: same exception_class + file + line in last 24h
            $existing = null;
            if (!empty($validated['exception_class']) && !empty($validated['file']) && !empty($validated['line'])) {
                $existing = FaultReport::where('exception_class', $validated['exception_class'])
                    ->where('file', $validated['file'])
                    ->where('line', $validated['line'])
                    ->where('last_seen_at', '>=', Carbon::now()->subDay())
                    ->first();
            }

            if ($existing) {
                $existing->incrementOccurrence();
            } else {
                FaultReport::create(array_merge($validated, [
                    'severity' => $validated['severity'] ?? 'error',
                    'user_id' => $request->user()?->id,
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip(),
                    'request_data' => $this->sanitiseRequestData($request->except([
                        'title', 'type', 'severity', 'message', 'exception_class',
                        'file', 'line', 'trace', 'url', 'method', 'screenshot_path',
                    ])),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]));
            }

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            // Fault capture must NEVER break the app
            return response()->json(['status' => 'ok']);
        }
    }

    /**
     * Show a single fault report detail.
     */
    public function show(int $id)
    {
        $report = FaultReport::with(['user', 'resolvedBy'])->findOrFail($id);

        return view('admin.fault-reports.show', compact('report'));
    }

    /**
     * Update fault report status and/or notes.
     */
    public function updateStatus(Request $request, int $id)
    {
        $report = FaultReport::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:new,investigating,fixed,ignored',
            'notes' => 'nullable|string|max:5000',
        ]);

        if (isset($validated['status'])) {
            $report->status = $validated['status'];
            if ($validated['status'] === 'fixed' || $validated['status'] === 'ignored') {
                $report->resolved_by = auth()->id();
                $report->resolved_at = now();
            }
        }
        if (array_key_exists('notes', $validated)) {
            $report->notes = $validated['notes'];
        }

        $report->save();

        return back()->with('success', 'Fault report updated.');
    }

    /**
     * Bulk update status on multiple fault reports.
     */
    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:fixed,ignored,investigating',
            'ids'    => 'required|array|max:50',
            'ids.*'  => 'integer|exists:fault_reports,id',
            'notes'  => 'nullable|string|max:5000',
        ]);

        $updates = ['status' => $validated['action']];

        if (in_array($validated['action'], ['fixed', 'ignored'])) {
            $updates['resolved_by'] = auth()->id();
            $updates['resolved_at'] = now();
        }

        if (!empty($validated['notes'])) {
            $updates['notes'] = $validated['notes'];
        }

        $count = FaultReport::whereIn('id', $validated['ids'])->update($updates);

        return back()->with('success', "{$count} fault report(s) updated to {$validated['action']}.");
    }

    /**
     * Create a manual fault report from the "Report Issue" modal.
     */
    public function manualReport(Request $request)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:2000',
            'url' => 'nullable|string|max:1000',
        ]);

        FaultReport::create([
            'type' => 'manual',
            'severity' => 'info',
            'title' => mb_substr($validated['description'], 0, 500),
            'message' => $validated['description'],
            'url' => $validated['url'],
            'user_id' => auth()->id(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return back()->with('success', 'Issue reported. Thank you!');
    }

    /**
     * Clear (soft-delete) all fault reports.
     */
    public function clearAll(Request $request)
    {
        $count = FaultReport::query()->delete();

        return back()->with('success', "Cleared {$count} fault report(s).");
    }

    /**
     * Scan the Laravel log file for recent errors and create fault reports
     * for any not already captured. Returns how many new reports were added.
     */
    public function scan(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');
        if (!is_file($logPath) || !is_readable($logPath)) {
            return back()->with('error', 'Log file not found or not readable.');
        }

        // Read the tail of the file (~512KB) to avoid loading huge logs.
        $maxBytes = 512 * 1024;
        $size = filesize($logPath);
        $fh = fopen($logPath, 'rb');
        if ($size > $maxBytes) {
            fseek($fh, $size - $maxBytes);
            // Skip the partial line at the seek point.
            fgets($fh);
        }
        $tail = stream_get_contents($fh);
        fclose($fh);

        // Each entry begins with [YYYY-MM-DD HH:MM:SS] ... laravel.ERROR: ...
        $entries = preg_split('/(?=^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/m', (string) $tail) ?: [];
        $added = 0;
        $scanned = 0;
        $skippedOld = 0;
        $cutoff = Carbon::now()->subDay();

        // High-water mark: only ingest log entries newer than the previous scan
        // so we don't keep re-importing the same historical errors every click.
        // Stored in cache (never expires until the next scan overwrites it).
        $lastScanTs = \Illuminate\Support\Facades\Cache::get('fault_reports.last_scan_log_ts');
        $lastScanCarbon = $lastScanTs ? Carbon::parse($lastScanTs) : null;
        $newestSeen = $lastScanCarbon;

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            // Only ERROR / CRITICAL / ALERT / EMERGENCY rows.
            if (!preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $entry, $level)) {
                continue;
            }

            // Parse the log line timestamp and skip anything we've scanned before.
            $entryCarbon = null;
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $entry, $tm)) {
                try {
                    $entryCarbon = Carbon::parse($tm[1]);
                } catch (\Throwable $e) {
                    $entryCarbon = null;
                }
            }
            if ($entryCarbon && $lastScanCarbon && $entryCarbon->lte($lastScanCarbon)) {
                $skippedOld++;
                continue;
            }
            if ($entryCarbon && (!$newestSeen || $entryCarbon->gt($newestSeen))) {
                $newestSeen = $entryCarbon;
            }
            $scanned++;

            $exceptionClass = null;
            $file = null;
            $line = null;
            $title = '';

            // Try the standard "ClassName: message at /path/file.php:NNN" pattern.
            if (preg_match('/([A-Z][A-Za-z0-9_\\\\]+(?:Exception|Error)): (.+?)(?: in (\/[^\s:]+):(\d+))?$/m', $entry, $m)) {
                $exceptionClass = $m[1];
                $title = mb_substr(trim($m[2]), 0, 500);
                $file = $m[3] ?? null;
                $line = isset($m[4]) ? (int) $m[4] : null;
            } else {
                // Fallback: first line after the level marker.
                $firstLine = strtok($entry, "\n");
                $title = mb_substr(trim(preg_replace('/^\[[^\]]+\]\s+\w+\.\w+:\s*/', '', $firstLine) ?? ''), 0, 500);
            }

            if ($title === '') {
                continue;
            }

            // Dedupe: same class+file+line in last 24h, or same title.
            $existing = null;
            if ($exceptionClass && $file && $line) {
                $existing = FaultReport::where('exception_class', $exceptionClass)
                    ->where('file', $file)
                    ->where('line', $line)
                    ->where('last_seen_at', '>=', $cutoff)
                    ->first();
            }
            if (!$existing) {
                $existing = FaultReport::where('title', $title)
                    ->where('type', 'backend')
                    ->where('last_seen_at', '>=', $cutoff)
                    ->first();
            }

            if ($existing) {
                $existing->incrementOccurrence();
                continue;
            }

            FaultReport::create([
                'type' => 'backend',
                'severity' => 'error',
                'title' => $title,
                'message' => mb_substr($entry, 0, 5000),
                'exception_class' => $exceptionClass,
                'file' => $file,
                'line' => $line,
                'trace' => mb_substr($entry, 0, 5000),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
            $added++;
        }

        // Advance the high-water mark to the newest log timestamp we saw
        // (or to now() if the log was empty / unparseable) so the next scan
        // starts strictly after this one.
        $newWatermark = ($newestSeen ?? Carbon::now())->format('Y-m-d H:i:s');
        \Illuminate\Support\Facades\Cache::forever('fault_reports.last_scan_log_ts', $newWatermark);

        $msg = "Scan complete. {$scanned} new log entries scanned, {$added} fault report(s) added.";
        if ($skippedOld > 0) {
            $msg .= " Skipped {$skippedOld} already-scanned entries.";
        }
        return back()->with('success', $msg);
    }

    /**
     * Strip sensitive fields from request data before storing.
     */
    private function sanitiseRequestData(?array $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        $sensitivePatterns = ['password', 'token', 'secret', 'card', 'cvv'];

        return collect($data)->filter(function ($value, $key) use ($sensitivePatterns) {
            foreach ($sensitivePatterns as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    return false;
                }
            }
            return true;
        })->toArray() ?: null;
    }
}
