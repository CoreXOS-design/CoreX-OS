<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeDocument;
use App\Models\Scopes\AgencyScope;
use App\Services\AI\DocumentProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $categories = KnowledgeCategory::ordered()
            ->withCount(['documents' => fn ($q) => $q->visibleTo($user)])
            ->get();

        $recentDocuments = KnowledgeDocument::visibleTo($user)
            ->with(['category', 'uploader'])
            ->latest()
            ->limit(20)
            ->get();

        $stats = [
            'total_documents' => KnowledgeDocument::visibleTo($user)->count(),
            'total_chunks' => \App\Models\KnowledgeChunk::whereHas('document', fn ($q) => $q->visibleTo($user))->count(),
            'ellie_enabled' => KnowledgeDocument::visibleTo($user)->where('is_ellie_enabled', true)->count(),
            'by_status' => [
                'ready' => KnowledgeDocument::visibleTo($user)->where('status', 'ready')->count(),
                'processing' => KnowledgeDocument::visibleTo($user)->where('status', 'processing')->count(),
                'error' => KnowledgeDocument::visibleTo($user)->where('status', 'error')->count(),
            ],
        ];

        $canUploadGlobal = $user->isOwnerRole();

        return view('admin.knowledge.index', compact('categories', 'recentDocuments', 'stats', 'canUploadGlobal'));
    }

    public function show(Request $request, $categoryId)
    {
        $user = $request->user();
        $category = KnowledgeCategory::findOrFail($categoryId);

        $documents = KnowledgeDocument::visibleTo($user)
            ->where('category_id', $categoryId)
            ->with('uploader')
            ->latest()
            ->paginate(20);

        $allCategories = KnowledgeCategory::ordered()->get();
        $canUploadGlobal = $user->isOwnerRole();

        return view('admin.knowledge.category', compact('category', 'documents', 'allCategories', 'canUploadGlobal'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:knowledge_categories,id',
            'file' => 'required|file|mimes:pdf,docx,doc,txt,md|max:20480',
            'description' => 'nullable|string|max:2000',
            'version' => 'nullable|string|max:50',
            'ownership' => 'nullable|in:agency,global',
        ]);

        $user = $request->user();

        // Only a CoreX System Owner may flag a document as global. Enforced
        // here even though the UI already hides the option from everyone
        // else — a direct POST must not be able to force it.
        $wantsGlobal = $request->input('ownership') === 'global';
        if ($wantsGlobal && !$user->isOwnerRole()) {
            abort(403, 'Only a CoreX System Owner can upload a document for all agencies.');
        }

        $service = app(DocumentProcessingService::class);
        $document = $service->processUpload(
            $request->file('file'),
            $request->input('category_id'),
            $user->id,
            $request->input('title'),
            $request->input('description'),
            $request->input('version'),
            $wantsGlobal,
        );

        $statusMsg = $document->status === 'ready'
            ? "Document uploaded and processed successfully ({$document->chunk_count} chunks)."
            : "Document uploaded but processing encountered an issue: {$document->error_message}";

        return redirect()->back()->with('status', $statusMsg);
    }

    public function toggleActive($documentId)
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $document->is_active = !$document->is_active;
        $document->save();

        return redirect()->back()->with('status', "Document '{$document->title}' " . ($document->is_active ? 'activated' : 'deactivated') . '.');
    }

    public function toggleEllie($documentId)
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $document->is_ellie_enabled = !$document->is_ellie_enabled;
        $document->save();

        return redirect()->back()->with('status', "Ellie access for '{$document->title}' " . ($document->is_ellie_enabled ? 'enabled' : 'disabled') . '.');
    }

    public function reprocess($documentId)
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $service = app(DocumentProcessingService::class);
        $service->reprocess($document);

        $document->refresh();
        $statusMsg = $document->status === 'ready'
            ? "Document reprocessed successfully ({$document->chunk_count} chunks)."
            : "Reprocessing issue: {$document->error_message}";

        return redirect()->back()->with('status', $statusMsg);
    }

    public function destroy($documentId)
    {
        $document = KnowledgeDocument::findOrFail($documentId);
        $title = $document->title;

        // Delete the file from storage
        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        // Chunks are cascade-deleted by the DB
        $document->delete();

        return redirect()->back()->with('status', "Document '{$title}' archived.");
    }

    public function preview(Request $request, $documentId)
    {
        $document = KnowledgeDocument::visibleTo($request->user())
            ->with(['chunks' => function ($q) {
                $q->orderBy('chunk_index');
            }, 'category', 'uploader'])
            ->findOrFail($documentId);

        return view('admin.knowledge.preview', compact('document'));
    }

    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:100',
        ]);

        $maxOrder = KnowledgeCategory::max('sort_order') ?? 0;

        KnowledgeCategory::create([
            'name' => $request->input('name'),
            'slug' => Str::slug($request->input('name')),
            'description' => $request->input('description'),
            'icon' => $request->input('icon'),
            'sort_order' => $maxOrder + 1,
        ]);

        return redirect()->back()->with('status', 'Category created successfully.');
    }

    public function updateCategory(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:100',
        ]);

        $category = KnowledgeCategory::findOrFail($id);
        $category->update([
            'name' => $request->input('name'),
            'slug' => Str::slug($request->input('name')),
            'description' => $request->input('description'),
            'icon' => $request->input('icon'),
        ]);

        return redirect()->back()->with('status', "Category '{$category->name}' updated.");
    }

    public function deleteCategory($id)
    {
        $category = KnowledgeCategory::findOrFail($id);

        // Categories are shared taxonomy across every agency (like
        // document_types) — count documents WITHOUT the agency scope so one
        // agency can't delete a category another agency (or a global doc)
        // still depends on.
        $docsCount = KnowledgeDocument::withoutGlobalScope(AgencyScope::class)
            ->where('category_id', $id)
            ->count();

        if ($docsCount > 0) {
            return redirect()->back()->with('error', "Cannot delete — category '{$category->name}' has {$docsCount} document(s) across agencies. Move or delete them first.");
        }

        $name = $category->name;
        $category->delete();

        return redirect()->back()->with('status', "Category '{$name}' archived.");
    }

    public function reorderCategories(Request $request)
    {
        // Swap two categories' sort_order (from arrow buttons)
        if ($request->has('swap')) {
            $request->validate([
                'swap' => 'required|array|size:2',
                'swap.*' => 'required|integer|exists:knowledge_categories,id',
            ]);

            $ids = $request->input('swap');
            $a = KnowledgeCategory::findOrFail($ids[0]);
            $b = KnowledgeCategory::findOrFail($ids[1]);

            $tmpOrder = $a->sort_order;
            $a->update(['sort_order' => $b->sort_order]);
            $b->update(['sort_order' => $tmpOrder]);

            return response()->json(['success' => true]);
        }

        // Bulk reorder (from drag-drop or programmatic)
        $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|integer|exists:knowledge_categories,id',
            'order.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->input('order') as $item) {
            KnowledgeCategory::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }
}
