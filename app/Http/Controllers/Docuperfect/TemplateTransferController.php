<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateTransferLog;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackage;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageExporter;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferSettings;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferStaging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Export / import of e-sign template packages. Owner-level only (routes carry
 * owner_only + permission:templates.transfer). Spec: .ai/specs/esign-template-transfer.md.
 */
class TemplateTransferController extends Controller
{
    public function __construct(
        private readonly TemplatePackageExporter $exporter,
        private readonly TemplatePackageImporter $importer,
        private readonly TemplateTransferStaging $staging,
    ) {
    }

    private function owner(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->isOwnerRole() && $user->hasPermission('templates.transfer'), 403);

        return $user;
    }

    // ── Export ─────────────────────────────────────────────────────────────

    public function export(Request $request, int $id)
    {
        $user = $this->owner($request);
        $template = Template::findOrFail($id);
        $template->assertAccessibleBy($user);

        try {
            $pkg = $this->exporter->export($template, $user);
        } catch (TemplateTransferException $e) {
            return redirect()->route('docuperfect.templates.index')->withErrors(['export' => $e->getMessage()]);
        }

        return response($pkg['bytes'], 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $pkg['filename'] . '"',
            'Content-Length'      => (string) strlen($pkg['bytes']),
        ]);
    }

    public function exportSelected(Request $request)
    {
        $user = $this->owner($request);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        $max = TemplateTransferSettings::int('max_bundle_templates');

        if (! $ids) {
            return redirect()->route('docuperfect.templates.index')->withErrors(['export' => 'Tick at least one template to export.']);
        }
        if (count($ids) > $max) {
            return redirect()->route('docuperfect.templates.index')->withErrors(['export' => "You can export at most {$max} templates at once."]);
        }

        // Re-resolved through the visibility scope: a posted id outside it is ignored, never trusted.
        $templates = Template::visibleTo($user)->whereIn('id', $ids)->orderBy('name')->get();
        if ($templates->isEmpty()) {
            return redirect()->route('docuperfect.templates.index')->withErrors(['export' => 'None of the ticked templates could be found.']);
        }

        if ($templates->count() === 1) {
            return $this->export($request, (int) $templates->first()->id);
        }

        $packages = [];
        try {
            foreach ($templates as $n => $template) {
                $pkg = $this->exporter->export($template, $user);
                $packages[sprintf('%02d-%s', $n + 1, $pkg['filename'])] = $pkg['bytes'];
            }
        } catch (TemplateTransferException $e) {
            return redirect()->route('docuperfect.templates.index')->withErrors(['export' => '"' . $template->name . '": ' . $e->getMessage()]);
        }

        $bytes = TemplatePackage::buildBundle($packages);

        return response($bytes, 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="templates-' . now()->format('Ymd-Hi') . '.zip"',
            'Content-Length'      => (string) strlen($bytes),
        ]);
    }

    // ── Import: page, upload, preview, confirm, cancel ─────────────────────

    public function index(Request $request)
    {
        $user = $this->owner($request);

        $query = TemplateTransferLog::query();
        if ($q = trim((string) $request->input('search'))) {
            $query->where(fn ($w) => $w->where('template_name', 'like', "%{$q}%")
                ->orWhere('actor_name', 'like', "%{$q}%")
                ->orWhere('package_checksum', 'like', "%{$q}%"));
        }
        if (in_array($request->input('direction_filter'), ['export', 'import'], true)) {
            $query->where('direction', $request->input('direction_filter'));
        }
        if (in_array($request->input('outcome'), ['success', 'rejected', 'failed'], true)) {
            $query->where('outcome', $request->input('outcome'));
        }
        if ($from = $this->date($request->input('from'))) {
            $query->where('created_at', '>=', $from->startOfDay());
        }
        if ($to = $this->date($request->input('to'))) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        $sort = in_array($request->input('sort'), ['created_at', 'template_name', 'direction', 'outcome'], true) ? $request->input('sort') : 'created_at';
        // Default: newest first. An explicit ?sort= takes ?direction= (asc unless desc).
        $dir = $request->input('sort') ? ($request->input('direction') === 'desc' ? 'desc' : 'asc') : 'desc';
        $logs = $query->orderBy($sort, $dir)->orderByDesc('id')->paginate(20)->withQueryString();

        return view('docuperfect.template-transfer.index', [
            'logs'     => $logs,
            'settings' => TemplateTransferSettings::all(),
            'fields'   => TemplateTransferSettings::FIELDS,
            'filtered' => $request->filled('search') || $request->filled('direction_filter') || $request->filled('outcome') || $request->filled('from') || $request->filled('to'),
        ]);
    }

    public function upload(Request $request)
    {
        $user = $this->owner($request);

        $file = $request->file('package');
        if (! $file) {
            return back()->withErrors(['package' => 'Choose a template package file (.cxpkg) to upload.']);
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['cxpkg', 'zip'], true)) {
            return back()->withErrors(['package' => 'That is not a template package. Choose a .cxpkg file (or the .zip bundle made by "Export selected").']);
        }

        try {
            $token = $this->staging->stage($file, $user);
            $staged = $this->staging->resolve($token, $user);
        } catch (TemplateTransferException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        try {
            $this->importer->load($staged['path']);
        } catch (TemplateTransferException $e) {
            $this->staging->discard($token);
            $this->importer->logRejected($user, implode(' ', array_merge([$e->getMessage()], $e->details)), @hash_file('sha256', $staged['path']) ?: null, $staged['original_name']);

            return back()->withErrors(['package' => $e->getMessage()])->with('error_details', $e->details);
        } catch (\Throwable $e) {
            $this->staging->discard($token);
            Log::warning('Template package upload could not be read', ['error' => $e->getMessage()]);
            $this->importer->logRejected($user, 'Unreadable: ' . $e->getMessage(), null, $staged['original_name']);

            return back()->withErrors(['package' => 'This file could not be read as a CoreX template package. Nothing was imported.']);
        }

        return redirect()->route('docuperfect.template-transfer.preview', ['token' => $token]);
    }

    public function preview(Request $request, string $token)
    {
        $user = $this->owner($request);

        try {
            $staged = $this->staging->resolve($token, $user);
            $loaded = $this->importer->load($staged['path']);
        } catch (TemplateTransferException $e) {
            return redirect()->route('docuperfect.template-transfer.index')->withErrors(['package' => $e->getMessage()]);
        }

        $agencies = Agency::query()->orderBy('name')->get(['id', 'name']);
        $target = $agencies->firstWhere('id', (int) $request->input('agency_id'));

        return view('docuperfect.template-transfer.preview', [
            'token'    => $token,
            'previews' => $this->importer->preview($loaded, $target),
            'agencies' => $agencies,
            'target'   => $target,
            'original' => $staged['original_name'],
            'settings' => TemplateTransferSettings::all(),
        ]);
    }

    public function confirm(Request $request, string $token)
    {
        $user = $this->owner($request);

        try {
            $staged = $this->staging->resolve($token, $user);
        } catch (TemplateTransferException $e) {
            return redirect()->route('docuperfect.template-transfer.index')->withErrors(['package' => $e->getMessage()]);
        }

        $target = Agency::query()->find((int) $request->input('agency_id'));
        if (! $target) {
            return back()->withErrors(['agency_id' => 'Choose the agency this template should be created in.'])->withInput();
        }
        if (! $request->boolean('confirmed')) {
            return back()->withErrors(['confirmed' => 'Tick the box to confirm you have read the preview.'])->withInput();
        }

        // One confirm at a time per upload: a double click cannot create two templates.
        $lock = Cache::lock('template-transfer-confirm:' . $token, 120);
        if (! $lock->get()) {
            return back()->withErrors(['package' => 'This import is already running. Wait a moment and check the Template list.']);
        }

        try {
            $loaded = $this->importer->load($staged['path']);
            $choices = array_map('strval', (array) $request->input('choice', []));
            $created = $this->importer->import($loaded, $target, $user, $choices, $request->boolean('allow_missing_document_type'));
            $this->staging->discard($token);
        } catch (TemplateTransferException $e) {
            return back()->withErrors(['package' => $e->getMessage()])->with('error_details', $e->details)->withInput();
        } finally {
            $lock->release();
        }

        $names = collect($created)->map(fn ($t) => '"' . $t->name . '"')->join(', ');

        return redirect()->route('docuperfect.template-transfer.index')
            ->with('status', 'Imported into ' . $target->name . ': ' . $names . '. Nothing existing was changed.');
    }

    public function cancel(Request $request, string $token)
    {
        $this->owner($request);
        $this->staging->discard($token);

        return redirect()->route('docuperfect.template-transfer.index')->with('status', 'Upload cancelled. Nothing was imported.');
    }

    public function saveSettings(Request $request)
    {
        $this->owner($request);

        try {
            TemplateTransferSettings::save($request->all());
        } catch (TemplateTransferException $e) {
            return back()->withErrors(['settings' => $e->getMessage()])->with('error_details', $e->details)->withInput();
        }

        return back()->with('status', 'Template package settings saved.');
    }

    private function date(?string $value): ?\Illuminate\Support\Carbon
    {
        try {
            return $value ? \Illuminate\Support\Carbon::parse($value) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
