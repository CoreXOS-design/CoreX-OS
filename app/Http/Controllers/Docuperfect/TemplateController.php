<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $showArchived = $request->boolean('archived');

        $query = Template::visibleTo($user)->with(['owner', 'branches']);
        $templates = $showArchived
            ? $query->archived()->orderByDesc('archived_at')->get()
            : $query->active()->orderBy('name')->get();

        return view('docuperfect.templates.index', compact('templates', 'showArchived', 'user'));
    }

    public function upload(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:51200',
            'name' => 'nullable|string|max:255',
        ]);

        $file = $request->file('pdf');
        $name = $request->input('name') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $template = Template::create([
            'name' => $name,
            'template_type' => 'sales',
            'page_count' => 0,
            'fields_json' => [],
            'is_global' => false,
            'owner_id' => $user->id,
        ]);

        return redirect()->route('docuperfect.templates.edit', $template->id)
            ->with('status', 'Template created. Upload page images from the editor.');
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::with('branches')->findOrFail($id);
        $branches = \App\Models\Branch::orderBy('name')->get();

        return view('docuperfect.templates.edit', compact('template', 'branches', 'user'));
    }

    public function saveFields(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        $request->validate([
            'fields_json' => 'required|array',
        ]);

        $template->update([
            'fields_json' => $request->input('fields_json'),
        ]);

        return response()->json(['ok' => true]);
    }

    public function uploadPageImages(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        $request->validate([
            'pages' => 'required|array',
            'pages.*' => 'required|string',
        ]);

        $pages = $request->input('pages');
        $dir = "docuperfect/templates/{$template->id}";

        foreach ($pages as $i => $base64) {
            $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64));
            Storage::put("{$dir}/page-{$i}.png", $imageData);
        }

        $template->update(['page_count' => count($pages)]);

        return response()->json(['ok' => true, 'page_count' => count($pages)]);
    }

    public function archive(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $template->update(['archived_at' => now()]);

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$template->name}\" archived.");
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $template->update(['archived_at' => null]);

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$template->name}\" restored.");
    }

    public function copy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $original = Template::with('branches')->findOrFail($id);

        $copy = $original->replicate();
        $copy->name = $original->name . ' (Copy)';
        $copy->owner_id = $user->id;
        $copy->archived_at = null;
        $copy->save();

        // Copy branch associations
        $copy->branches()->sync($original->branches->pluck('id'));

        // Copy page images
        $srcDir = "docuperfect/templates/{$original->id}";
        $dstDir = "docuperfect/templates/{$copy->id}";
        for ($i = 0; $i < $original->page_count; $i++) {
            $srcPath = "{$srcDir}/page-{$i}.png";
            if (Storage::exists($srcPath)) {
                Storage::copy($srcPath, "{$dstDir}/page-{$i}.png");
            }
        }

        return redirect()->route('docuperfect.templates.edit', $copy->id)
            ->with('status', "Template duplicated as \"{$copy->name}\".");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $name = $template->name;

        // Delete page images
        $dir = "docuperfect/templates/{$template->id}";
        Storage::deleteDirectory($dir);

        $template->delete();

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$name}\" deleted.");
    }
}
