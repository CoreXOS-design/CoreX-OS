<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\DocumentLibraryItem;
use App\Models\DocumentLibraryType;
use App\Models\Presentation;
use App\Models\PresentationDocumentLibraryItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentLibraryController extends Controller
{
    public function index(Request $request)
    {
        if (!config('features.document_library_v1')) {
            abort(404);
        }

        $query = DocumentLibraryItem::where('is_enabled', true);

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($w) use ($q) {
                $w->where('original_name', 'like', "%{$q}%")
                  ->orWhere('title', 'like', "%{$q}%");
            });
        }

        if ($request->filled('doc_type')) {
            $query->where('doc_type', $request->input('doc_type'));
        }

        if ($request->filled('user_id')) {
            $query->where('uploaded_by_user_id', $request->input('user_id'));
        }

        $items = $query->with('uploader')->orderByDesc('created_at')->paginate(30)->withQueryString();

        $presentationId = $request->input('presentation_id');
        $returnUrl = $request->input('return');
        $presentation = $presentationId ? Presentation::find($presentationId) : null;

        $uploaders = User::whereIn('id', DocumentLibraryItem::select('uploaded_by_user_id')->distinct())
            ->orderBy('name')->get(['id', 'name']);

        $docTypes = DocumentLibraryItem::select('doc_type')->distinct()->orderBy('doc_type')->pluck('doc_type');

        $documentTypes = DocumentLibraryType::orderBy('sort_order')->orderBy('label')->get();

        // Already-attached IDs for this presentation (to show checkmarks)
        $attachedIds = [];
        if ($presentation) {
            $attachedIds = $presentation->documentLibraryItems()->pluck('document_library_items.id')->toArray();
        }

        return view('documents.library.index', compact(
            'items', 'presentation', 'presentationId', 'returnUrl',
            'uploaders', 'docTypes', 'attachedIds', 'documentTypes'
        ));
    }

    public function upload(Request $request)
    {
        if (!config('features.document_library_v1')) {
            abort(404);
        }

        $request->validate([
            'file'     => 'required|file|max:20480',
            'doc_type' => 'required|string|max:50',
            'title'    => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $now = now();
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $rand = Str::random(8);
        $ext = $file->getClientOriginalExtension();
        $folder = 'document_library/' . $now->format('Y/m');
        $filename = "{$slug}-{$rand}.{$ext}";

        $storedPath = $file->storeAs($folder, $filename, 'local');

        DocumentLibraryItem::create([
            'uploaded_by_user_id' => auth()->id(),
            'original_name'       => $file->getClientOriginalName(),
            'stored_path'         => $storedPath,
            'mime_type'           => $file->getClientMimeType(),
            'bytes'               => $file->getSize(),
            'doc_type'            => $request->input('doc_type'),
            'title'               => $request->input('title'),
        ]);

        return redirect()->back()->with('success', 'Document uploaded to library.');
    }

    public function download(DocumentLibraryItem $item)
    {
        if (!config('features.document_library_v1')) {
            abort(404);
        }

        $fullPath = storage_path('app/private/' . $item->stored_path);

        if (!file_exists($fullPath)) {
            abort(404, 'File not found.');
        }

        return response()->download($fullPath, $item->original_name);
    }

    public function attach(Request $request)
    {
        if (!config('features.document_library_v1')) {
            abort(404);
        }

        $request->validate([
            'presentation_id' => 'required|exists:presentations,id',
            'item_ids'         => 'required|array|min:1',
            'item_ids.*'       => 'exists:document_library_items,id',
            'return'           => 'nullable|string',
        ]);

        $presentationId = $request->input('presentation_id');
        $itemIds = $request->input('item_ids');

        foreach ($itemIds as $itemId) {
            PresentationDocumentLibraryItem::firstOrCreate([
                'presentation_id'          => $presentationId,
                'document_library_item_id' => $itemId,
            ], [
                'attached_by_user_id' => auth()->id(),
            ]);
        }

        $returnUrl = $request->input('return', route('presentations.show', $presentationId) . '#documents');

        return redirect($returnUrl)->with('success', count($itemIds) . ' document(s) attached from library.');
    }

    public function storeType(Request $request)
    {
        $request->validate([
            'label' => 'required|string|max:100',
        ]);

        $key = Str::slug($request->input('label'), '_');

        if (DocumentLibraryType::where('key', $key)->exists()) {
            return redirect()->back()->with('error', 'A document type with that name already exists.');
        }

        $maxSort = DocumentLibraryType::max('sort_order') ?? 0;

        DocumentLibraryType::create([
            'key'        => $key,
            'label'      => trim($request->input('label')),
            'sort_order' => $maxSort + 1,
        ]);

        return redirect()->back()->with('success', 'Document type added.');
    }

    public function updateType(Request $request, DocumentLibraryType $documentType)
    {
        $request->validate([
            'label' => 'required|string|max:100',
        ]);

        $documentType->update([
            'label' => trim($request->input('label')),
        ]);

        return redirect()->back()->with('success', 'Document type updated.');
    }

    public function destroyType(DocumentLibraryType $documentType)
    {
        $inUse = DocumentLibraryItem::where('doc_type', $documentType->key)->exists();

        if ($inUse) {
            return redirect()->back()->with('error', 'Cannot delete — documents are using this type. Reassign them first.');
        }

        $documentType->delete();

        return redirect()->back()->with('success', 'Document type archived.');
    }
}
