<?php

namespace App\Http\Controllers\PlatformEsign;

use App\Http\Controllers\Controller;
use App\Models\PlatformEsign\WordingAudit;
use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementBlocks;
use App\Services\PlatformEsign\Agreement\AgreementConflict;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use App\Services\PlatformEsign\Agreement\AgreementPdf;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementSettings;
use App\Services\PlatformEsign\Agreement\AgreementVersions;
use App\Services\PlatformEsign\Agreement\WordingInvalid;
use Illuminate\Http\Request;

/**
 * Owner-only wording editor for the Subscription Agreement (spec §11.13): versions list, drafts, clause-level section
 * editing, rates, preview, publish / discard / restore, "what changed", expiry + reminder settings, audit trail.
 * Owner-only by route middleware AND re-checked here; platform-owned data, no agency scoping applies.
 */
class AgreementWordingController extends Controller
{
    public function __construct(private AgreementVersions $versions, private AgreementRenderer $renderer, private AgreementLayout $layout, private AgreementPdf $pdf)
    {
    }

    private function owner(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole(), 403);

        return $u;
    }

    private function version(int $id): WordingVersion
    {
        $tid = $this->versions->template()->id;

        return WordingVersion::withTrashed()->where('template_id', $tid)->with(['publisher', 'creator', 'parent'])->findOrFail($id);
    }

    private function openDraft(int $id): WordingVersion
    {
        $v = $this->version($id);
        abort_if($v->is_published || $v->trashed(), 404);

        return $v;
    }

    // ── Versions list + settings ───────────────────────────────────────────

    public function index(Request $request)
    {
        $this->owner($request);
        $tid = $this->versions->template()->id;
        $f = $request->validate([
            'q' => 'nullable|string|max:100', 'status' => 'nullable|in:all,published,draft,discarded',
            'sort' => 'nullable|in:date,version,published', 'dir' => 'nullable|in:asc,desc',
            'from' => 'nullable|date', 'to' => 'nullable|date',
        ]);
        $status = $f['status'] ?? 'all';
        $sort = $f['sort'] ?? 'published';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $q = WordingVersion::withTrashed()->where('template_id', $tid)->withCount('documents')->with(['publisher', 'creator']);
        match ($status) {
            'published' => $q->where('is_published', true),
            'draft' => $q->where('is_published', false)->whereNull('deleted_at'),
            'discarded' => $q->where('is_published', false)->whereNotNull('deleted_at'),
            default => $q->where(fn ($w) => $w->where('is_published', true)->orWhereNull('deleted_at')),
        };
        if (!empty($f['q'])) {
            $term = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('version', 'like', $term)->orWhere('change_note', 'like', $term));
        }
        if (!empty($f['from'])) {
            $q->whereDate('version_date', '>=', $f['from']);
        }
        if (!empty($f['to'])) {
            $q->whereDate('version_date', '<=', $f['to']);
        }
        match ($sort) {
            'date' => $q->orderBy('version_date', $dir),
            'version' => $q->orderBy('version', $dir),
            default => $q->orderBy('is_published')->orderBy('published_at', $dir),
        };
        $q->orderByDesc('id');

        $current = $this->versions->current();

        return view('platform-esign.wording.index', [
            'rows' => $q->paginate(15)->withQueryString(), 'filters' => ['q' => $f['q'] ?? '', 'status' => $status, 'sort' => $sort, 'dir' => $dir, 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? ''],
            'current' => $current, 'draft' => $this->versions->draft(), 'settings' => AgreementSettings::all(), 'defaults' => AgreementSettings::defaults(),
            'audit' => WordingAudit::with(['user', 'version'])->where('template_id', $tid)->orderByDesc('id')->paginate(15, ['*'], 'audit_page')->withQueryString(),
            'sentTotal' => \App\Models\PlatformEsign\Document::where('source', 'webdoc')->count(),
        ]);
    }

    public function settings(Request $request)
    {
        $u = $this->owner($request);
        try {
            $changed = AgreementSettings::save($request->all());
        } catch (WordingInvalid $e) {
            return back()->withInput()->withErrors(['settings' => $e->errors]);
        }
        if ($changed) {
            $this->versions->audit($this->versions->template()->id, null, 'settings_changed', $u->id,
                implode('; ', array_map(fn ($k, $c) => AgreementSettings::FIELDS[$k][4] . ': ' . $c['from'] . ' → ' . $c['to'], array_keys($changed), $changed)));
        }

        return back()->with('success', $changed ? 'Expiry and reminder settings saved.' : 'Nothing changed.');
    }

    // ── Drafts ─────────────────────────────────────────────────────────────

    public function createDraft(Request $request)
    {
        $u = $this->owner($request);
        $from = $request->validate(['from' => 'nullable|integer'])['from'] ?? null;
        try {
            $draft = $this->versions->createDraft($u->id, $from ? (int) $from : null);
        } catch (\DomainException $e) {
            return redirect()->route('platform-esign.wording.index')->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.wording.show', $draft->id)->with('success', 'Draft created — a copy of version ' . $draft->parent?->version . '. Edit a section, then publish when you are happy with it.');
    }

    public function discard(Request $request, int $version)
    {
        $u = $this->owner($request);
        $v = $this->openDraft($version);
        try {
            $this->versions->discard($v, $u->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.wording.index')->with('success', 'Draft discarded. It is kept and can be restored from the list (Status → Discarded).');
    }

    public function restore(Request $request, int $version)
    {
        $u = $this->owner($request);
        try {
            $v = $this->versions->restore($version, $u->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['draft' => $e->getMessage()]);
        }

        return redirect()->route('platform-esign.wording.show', $v->id)->with('success', 'Draft restored.');
    }

    // ── Viewing ────────────────────────────────────────────────────────────

    public function show(Request $request, int $version)
    {
        $this->owner($request);
        $v = $this->version($version);
        $part = $request->query('part', 'part_b');
        $part = isset(AgreementContent::PARTS[$part]) || $part === 'rates' ? $part : 'part_b';
        $rates = array_merge(AgreementPricing::DEFAULT_RATES, (array) $v->rates_json);

        return view('platform-esign.wording.show', [
            'v' => $v, 'part' => $part, 'rates' => $rates, 'rateFields' => AgreementVersions::RATE_FIELDS,
            'clauses' => $part === 'rates' ? [] : $this->renderer->blocks($v, $part, 'edit'),
            'current' => $this->versions->current(), 'problems' => $v->is_published ? [] : $this->versions->problems($v),
            'suggested' => $this->versions->nextVersion(), 'sentCount' => $v->documents()->count(),
            'previous' => $this->previousOf($v), 'hasDraft' => (bool) $this->versions->draft(),
        ]);
    }

    private function previousOf(WordingVersion $v): ?WordingVersion
    {
        if (!$v->is_published) {
            return $this->versions->current();
        }

        return WordingVersion::published()->where('template_id', $v->template_id)->where(fn ($q) => $q->where('published_at', '<', $v->published_at)->orWhere(fn ($w) => $w->where('published_at', $v->published_at)->where('id', '<', $v->id)))
            ->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    // ── Editing a section ──────────────────────────────────────────────────

    public function edit(Request $request, int $version, string $part)
    {
        $this->owner($request);
        $v = $this->openDraft($version);
        abort_unless(isset(AgreementContent::PARTS[$part]), 404);
        $clauses = $this->versions->clauses($v, $part);
        $html = $this->renderer->blocks($v, $part, 'edit');
        if (count($html) !== count($clauses)) { // cannot happen for split/render of the same text — keep the editor honest if it ever does
            $html = array_map(fn ($c) => $this->renderClause($v, $c), $clauses);
        }

        return view('platform-esign.wording.edit', [
            'v' => $v, 'part' => $part, 'partLabel' => AgreementContent::PARTS[$part],
            'items' => array_map(fn ($md, $h) => ['md' => $md, 'html' => $h], $clauses, $html),
        ]);
    }

    private function renderClause(WordingVersion $v, string $md): string
    {
        $tmp = new WordingVersion(['content_json' => ['x' => $md], 'rates_json' => $v->rates_json]);

        return implode("\n", $this->renderer->blocks($tmp, 'x', 'edit'));
    }

    /** Live preview of ONE clause while it is being edited. */
    public function render(Request $request, int $version)
    {
        $this->owner($request);
        $v = $this->openDraft($version);
        $md = (string) $request->validate(['md' => 'present|nullable|string|max:20000'])['md'];

        return response()->json(['html' => trim($md) === '' ? '' : $this->renderClause($v, $md)])->header('Cache-Control', 'no-store');
    }

    public function saveSection(Request $request, int $version, string $part)
    {
        $u = $this->owner($request);
        $v = $this->openDraft($version);
        $data = $request->validate(['clauses' => 'required|array|max:600', 'clauses.*' => 'nullable|string|max:20000', 'rev' => 'required|integer|min:0']);
        try {
            $rev = $this->versions->saveSection($v, $part, $data['clauses'], (int) $data['rev'], $u->id);
        } catch (AgreementConflict $e) {
            return response()->json(['message' => 'This draft was saved in another window. Reload this page to see the latest wording before you change it.', 'rev' => $e->rev], 409);
        } catch (WordingInvalid $e) {
            return response()->json(['message' => 'Not saved — please fix the following.', 'errors' => $e->errors], 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'rev' => $rev, 'message' => 'Saved.']);
    }

    public function saveRates(Request $request, int $version)
    {
        $u = $this->owner($request);
        $v = $this->openDraft($version);
        $rev = (int) $request->validate(['rev' => 'required|integer|min:0'])['rev'];
        try {
            $this->versions->saveRates($v, (array) $request->input('rates', []), $rev, $u->id);
        } catch (AgreementConflict) {
            return back()->withInput()->withErrors(['rates' => ['This draft was saved in another window. Reload to see the latest before you change it.']]);
        } catch (WordingInvalid $e) {
            return back()->withInput()->withErrors(['rates' => $e->errors]);
        } catch (\DomainException $e) {
            return back()->withErrors(['rates' => [$e->getMessage()]]);
        }

        return redirect()->route('platform-esign.wording.show', [$v->id, 'part' => 'rates'])->with('success', 'Rates saved.');
    }

    // ── Preview ────────────────────────────────────────────────────────────

    public function preview(Request $request, int $version)
    {
        $this->owner($request);
        $v = $this->version($version);
        $view = $request->query('view') === 'print' ? 'print' : 'form';
        $layout = $this->layout->ensure($v);
        $rates = (array) $v->rates_json;
        if ($view === 'print') {
            $ctx = AgreementSample::ctx($v);
            $mode = 'pdf';
        } else {
            $ctx = ['values' => [], 'rr' => [], 'rates' => $rates, 'calc' => AgreementPricing::compute('', 0, 0, 0.0, $rates), 'ref' => 'CX000000', 'initials' => [], 'sigs' => [],
                'sign_date' => null, 'mask' => false, 'errors' => [], 'doc_id' => null, 'locked' => true];
            $mode = 'form';
        }

        return view('platform-esign.wording.preview', [
            'v' => $v, 'pages' => $this->pdf->pages($v, $layout, $mode, $ctx), 'total' => $layout['total'], 'viewMode' => $view,
        ]);
    }

    public function previewPdf(Request $request, int $version)
    {
        $this->owner($request);
        $v = $this->version($version);
        $layout = $this->layout->ensure($v);
        [$bin] = $this->pdf->renderWithTotal($v, $layout, 'pdf', AgreementSample::ctx($v));

        return response($bin, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="agreement-' . ($v->is_published ? $v->version : 'draft') . '-sample.pdf"', 'Cache-Control' => 'no-store']);
    }

    // ── Publish ────────────────────────────────────────────────────────────

    public function publish(Request $request, int $version)
    {
        $u = $this->owner($request);
        $v = $this->openDraft($version);
        $data = $request->validate(['version' => 'required|string|max:12', 'version_date' => 'required|date_format:Y-m-d', 'change_note' => 'required|string|max:500'],
            ['change_note.required' => 'Write a short change note — it is shown with the version.']);
        try {
            $pub = $this->versions->publish($v, $data['version'], $data['version_date'], $data['change_note'], $u->id);
        } catch (WordingInvalid $e) {
            return back()->withInput()->withErrors(['publish' => $e->errors]);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['publish' => [$e->getMessage()]]);
        }

        return redirect()->route('platform-esign.wording.show', $pub->id)->with('success', 'Version ' . $pub->version . ' is published. New agreements use it from now on; agreements already sent keep the version they were sent with.');
    }

    // ── What changed ───────────────────────────────────────────────────────

    public function compare(Request $request)
    {
        $this->owner($request);
        $tid = $this->versions->template()->id;
        $all = WordingVersion::withTrashed()->where('template_id', $tid)->where(fn ($q) => $q->where('is_published', true)->orWhereNull('deleted_at'))->orderByDesc('is_published')->orderByDesc('published_at')->orderByDesc('id')->get();
        $b = $all->firstWhere('id', (int) $request->query('b')) ?? $all->first();
        $a = $all->firstWhere('id', (int) $request->query('a')) ?? ($b ? ($this->previousOf($b) ?? $b) : null);
        $result = ($a && $b) ? $this->versions->compare($a, $b) : null;
        $only = $request->query('only') === 'changes';

        return view('platform-esign.wording.compare', ['all' => $all, 'a' => $a, 'b' => $b, 'result' => $result, 'onlyChanges' => $only]);
    }
}
