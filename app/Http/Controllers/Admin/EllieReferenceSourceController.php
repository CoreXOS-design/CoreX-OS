<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AI\EllieReferenceSource;
use App\Services\AI\EllieReferenceSourceFetchService;
use Illuminate\Http\Request;

/**
 * Admin CRUD for Ellie's external reference-source allowlist. Gated by
 * `manage_reference_sources` — super_admin only (global, not per-agency).
 *
 * Spec: .ai/specs/ellie-reference-sources.md §8.
 */
class EllieReferenceSourceController extends Controller
{
    public function index()
    {
        $sources = EllieReferenceSource::withCount('chunks')
            ->with('addedBy')
            ->latest()
            ->get();

        return view('admin.ellie.reference-sources.index', compact('sources'));
    }

    public function store(Request $request, EllieReferenceSourceFetchService $fetcher)
    {
        $request->validate([
            'url' => 'required|string|max:2048|url',
            'title' => 'nullable|string|max:255',
        ]);

        $url = trim($request->input('url'));

        // Server-side SSRF guard check BEFORE creating a row — no point creating
        // a source that can never fetch.
        $error = $fetcher->validateAddable($url);
        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', "Could not add that URL: {$error}");
        }

        if (EllieReferenceSource::where('url', $url)->exists()) {
            return redirect()->back()->withInput()->with('error', 'That URL is already an approved reference source.');
        }

        $source = EllieReferenceSource::create([
            'url' => $url,
            'title' => trim((string) $request->input('title')) ?: null,
            'added_by_user_id' => auth()->id(),
            'is_active' => true,
        ]);

        $fetcher->refresh($source);
        $source->refresh();

        $statusMsg = $source->last_fetch_status === EllieReferenceSource::STATUS_OK
            ? "Reference source added and indexed ({$source->chunks()->count()} chunk(s))."
            : "Reference source added, but the first fetch failed: {$source->fetch_error}";

        return redirect()->back()->with('status', $statusMsg);
    }

    public function refresh(EllieReferenceSource $referenceSource, EllieReferenceSourceFetchService $fetcher)
    {
        $fetcher->refresh($referenceSource);
        $referenceSource->refresh();

        $statusMsg = $referenceSource->last_fetch_status === EllieReferenceSource::STATUS_OK
            ? "Refreshed '{$referenceSource->title}'."
            : "Refresh failed for '{$referenceSource->title}': {$referenceSource->fetch_error}";

        return redirect()->back()->with($referenceSource->last_fetch_status === EllieReferenceSource::STATUS_OK ? 'status' : 'error', $statusMsg);
    }

    public function toggleActive(EllieReferenceSource $referenceSource)
    {
        $referenceSource->is_active = ! $referenceSource->is_active;
        $referenceSource->save();

        return redirect()->back()->with('status', "'{$referenceSource->title}' " . ($referenceSource->is_active ? 'enabled' : 'disabled') . '.');
    }

    public function destroy(EllieReferenceSource $referenceSource)
    {
        $title = $referenceSource->title ?: $referenceSource->url;
        $referenceSource->delete();

        return redirect()->back()->with('status', "'{$title}' removed.");
    }
}
