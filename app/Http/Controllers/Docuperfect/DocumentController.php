<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $documents = Document::active()
            ->visibleTo($user)
            ->with(['template', 'owner', 'branch'])
            ->orderByDesc('updated_at')
            ->get();

        return view('docuperfect.documents.index', compact('documents', 'user'));
    }

    public function create(Request $request, $templateId)
    {
        $user = $request->user();
        $template = Template::active()->visibleTo($user)->findOrFail($templateId);

        $document = Document::create([
            'name' => $template->name . ' — ' . $user->name . ' — ' . now()->format('Y-m-d'),
            'template_id' => $template->id,
            'fields_json' => $template->fields_json ?? [],
            'owner_id' => $user->id,
            'branch_id' => $user->effectiveBranchId(),
        ]);

        return redirect()->route('docuperfect.documents.edit', $document->id);
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::with(['template', 'template.branches'])->findOrFail($id);

        // Access check
        if (!$user->isAdmin()) {
            if ($user->isBranchManager()) {
                if ($document->branch_id !== $user->effectiveBranchId()) {
                    abort(403);
                }
            } else {
                if ((int)$document->owner_id !== (int)$user->id) {
                    abort(403);
                }
            }
        }

        $template = $document->template;

        return view('docuperfect.documents.edit', compact('document', 'template', 'user'));
    }

    public function saveFields(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        // Access check
        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $request->validate([
            'fields_json' => 'required|array',
        ]);

        $document->update([
            'fields_json' => $request->input('fields_json'),
        ]);

        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $document->update(['archived_at' => now()]);

        return redirect()->route('docuperfect.documents.index')
            ->with('status', "Document \"{$document->name}\" archived.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $name = $document->name;
        $document->delete();

        return redirect()->route('docuperfect.documents.index')
            ->with('status', "Document \"{$name}\" deleted.");
    }
}
