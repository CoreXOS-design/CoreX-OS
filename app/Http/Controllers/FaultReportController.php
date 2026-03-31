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
