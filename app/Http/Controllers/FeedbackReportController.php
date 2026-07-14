<?php

namespace App\Http\Controllers;

use App\Services\FeedbackDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FeedbackReportController extends Controller
{
    private function deriveModuleTag(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        return match (true) {
            str_contains($path, '/calendar/invitations') => 'invitations',
            str_contains($path, '/calendar') => 'calendar',
            str_contains($path, '/buyers/pipeline') => 'buyer_pipeline',
            str_contains($path, '/buyers/') => 'buyers',
            str_contains($path, '/properties/') || str_contains($path, '/property/') => 'properties',
            str_contains($path, '/contacts/') => 'contacts',
            str_contains($path, '/lost-deals') => 'lost_deals',
            str_contains($path, '/reporting/') || str_contains($path, '/dashboard') => 'reporting',
            str_contains($path, '/settings/') => 'settings',
            str_contains($path, '/property/live/') => 'seller_link',
            str_contains($path, '/buyer/portal/') => 'buyer_portal',
            default => 'other',
        };
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:bug,enhancement,question,compliment,other',
            'severity' => 'nullable|in:critical,major,minor',
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'steps_to_reproduce' => 'nullable|string|max:3000',
            'expected_behaviour' => 'nullable|string|max:2000',
            'actual_behaviour' => 'nullable|string|max:2000',
            'page_url' => 'nullable|string|max:500',
            'page_title' => 'nullable|string|max:200',
            'browser' => 'nullable|string|max:100',
            'os' => 'nullable|string|max:50',
            'viewport_width' => 'nullable|integer',
            'viewport_height' => 'nullable|integer',
            'screenshot_base64' => 'nullable|string|max:5000000',
        ]);

        $screenshotBase64 = $data['screenshot_base64'] ?? null;
        unset($data['screenshot_base64']);

        // AT-253 (STANDARDS Rule 17) — feedback_reports.agency_id is NOT NULL and there is no
        // domain object to derive a tenant from, so an actor with no agency gets a clear
        // refusal. The old `?? 1` silently filed a global super-admin's feedback into agency
        // 1's queue, where the wrong agency's staff would read and act on it.
        $agencyId = auth()->user()?->effectiveAgencyId();
        if (! $agencyId) {
            throw new \App\Exceptions\MissingAgencyContextException('a feedback report');
        }

        $now = now();
        $reportId = DB::table('feedback_reports')->insertGetId(array_merge($data, [
            'agency_id' => $agencyId,
            'user_id' => auth()->id(),
            'module_tag' => $this->deriveModuleTag($data['page_url'] ?? ''),
            'submitted_at' => $now,
            'server_log_window_start' => $now->copy()->subMinutes(5),
            'server_log_window_end' => $now->copy()->addMinute(),
            'status' => 'new',
            'created_at' => $now, 'updated_at' => $now,
        ]));

        // Handle screenshot from base64 (html2canvas capture)
        if ($screenshotBase64) {
            $this->saveScreenshotFromBase64($reportId, $screenshotBase64, $now);
        }

        // Handle file upload screenshot (fallback)
        if ($request->hasFile('screenshot')) {
            $file = $request->file('screenshot');
            $path = $file->store("feedback/{$reportId}", 'local');
            DB::table('feedback_attachments')->insert([
                'feedback_report_id' => $reportId,
                'filename' => basename($path),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'storage_path' => $path,
                'created_at' => $now,
            ]);
        }

        // Deliver notification (log on local, email on staging/production)
        try {
            app(FeedbackDeliveryService::class)->deliver($reportId);
        } catch (\Throwable $e) {
            // Don't fail the submission if delivery fails
            \Log::warning('Feedback delivery failed', ['report_id' => $reportId, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'report_id' => $reportId]);
    }

    private function saveScreenshotFromBase64(int $reportId, string $base64, $now): void
    {
        // Strip data URI prefix if present (e.g. "data:image/png;base64,")
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $decoded = base64_decode($base64, true);
        if (!$decoded || strlen($decoded) < 100) return;

        $filename = "screenshot_{$reportId}_" . time() . '.png';
        $path = "feedback/{$reportId}/{$filename}";
        Storage::disk('local')->put($path, $decoded);

        DB::table('feedback_attachments')->insert([
            'feedback_report_id' => $reportId,
            'filename' => $filename,
            'original_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($decoded),
            'storage_path' => $path,
            'created_at' => $now,
        ]);
    }

    public function index(Request $request)
    {
        $query = DB::table('feedback_reports')
            ->where('agency_id', (int) (auth()->user()?->effectiveAgencyId() ?: 0))   // AT-253 Rule 17
            ->whereNull('deleted_at');

        if ($status = $request->get('status')) $query->where('status', $status);
        if ($type = $request->get('type')) $query->where('type', $type);
        if ($module = $request->get('module')) $query->where('module_tag', $module);
        if ($search = $request->get('q')) $query->where(fn($q) => $q->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));

        $reports = $query->orderByDesc('submitted_at')->paginate(25)->withQueryString();

        return view('command-center.feedback.index', ['reports' => $reports]);
    }

    public function show(int $id)
    {
        $report = DB::table('feedback_reports')->where('id', $id)->first();
        if (!$report) abort(404);
        $attachments = DB::table('feedback_attachments')->where('feedback_report_id', $id)->get();
        $submitter = \App\Models\User::withoutGlobalScopes()->find($report->user_id);

        return view('command-center.feedback.show', ['report' => $report, 'attachments' => $attachments, 'submitter' => $submitter]);
    }

    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:new,reviewing,in_progress,fixed,wont_fix,duplicate,deferred',
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        DB::table('feedback_reports')->where('id', $id)->update(array_merge($data, [
            'reviewed_at' => now(), 'reviewed_by_user_id' => auth()->id(), 'updated_at' => now(),
        ]));

        return back()->with('success', 'Status updated.');
    }

    public function export(Request $request)
    {
        $format = $request->get('format', 'json');
        $query = DB::table('feedback_reports')
            ->where('agency_id', (int) (auth()->user()?->effectiveAgencyId() ?: 0))   // AT-253 Rule 17
            ->whereNull('deleted_at')
            ->orderByDesc('submitted_at');

        if ($status = $request->get('status')) $query->where('status', $status);
        $reports = $query->get();

        if ($format === 'csv') {
            $csv = "ID,Submitted,User,Type,Severity,Title,Module,Status,URL\n";
            foreach ($reports as $r) {
                $user = \App\Models\User::withoutGlobalScopes()->find($r->user_id);
                $csv .= "{$r->id},{$r->submitted_at},{$user?->name},{$r->type},{$r->severity},{$r->title},{$r->module_tag},{$r->status},{$r->page_url}\n";
            }
            return response($csv)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="feedback-export.csv"');
        }

        if ($format === 'markdown') {
            $md = "# Feedback Export\n\nExported: " . now()->format('Y-m-d H:i') . " | Reports: " . $reports->count() . "\n\n---\n\n";
            foreach ($reports as $r) {
                $user = \App\Models\User::withoutGlobalScopes()->find($r->user_id);
                $sev = $r->severity ? "[{$r->severity}] " : '';
                $md .= "## {$sev}{$r->title}\n\n";
                $md .= "- **Type:** {$r->type} | **Status:** {$r->status} | **Module:** {$r->module_tag}\n";
                $md .= "- **Submitted:** {$r->submitted_at} by {$user?->name}\n";
                $md .= "- **Page:** {$r->page_url}\n";
                $md .= "- **Browser:** {$r->browser} | **Viewport:** {$r->viewport_width}x{$r->viewport_height}\n\n";
                $md .= "**Description:**\n{$r->description}\n\n";
                if ($r->steps_to_reproduce) $md .= "**Steps to Reproduce:**\n{$r->steps_to_reproduce}\n\n";
                if ($r->expected_behaviour) $md .= "**Expected:**\n{$r->expected_behaviour}\n\n";
                if ($r->actual_behaviour) $md .= "**Actual:**\n{$r->actual_behaviour}\n\n";
                $md .= "---\n\n";
            }
            return response($md)->header('Content-Type', 'text/markdown')->header('Content-Disposition', 'attachment; filename="feedback-export.md"');
        }

        // JSON default
        $data = [
            'export_metadata' => [
                'exported_at' => now()->toIso8601String(),
                'exported_by' => auth()->user()->name,
                'report_count' => $reports->count(),
            ],
            'reports' => $reports->map(fn($r) => (array) $r),
        ];
        return response()->json($data)->header('Content-Disposition', 'attachment; filename="feedback-export.json"');
    }
}
