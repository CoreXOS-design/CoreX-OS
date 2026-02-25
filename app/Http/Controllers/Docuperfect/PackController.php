<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Pack;
use App\Models\Docuperfect\PackAttachment;
use App\Models\Docuperfect\PackSlot;
use App\Models\Docuperfect\Template;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $packs = Pack::visibleTo($user)
            ->with(['templates', 'slots', 'branches', 'owner'])
            ->orderBy('name')
            ->get();

        $canManage = $user->isAdmin() || $user->isBranchManager();

        return view('docuperfect.packs.index', compact('packs', 'canManage', 'user'));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $templates = Template::active()->visibleTo($user)
            ->with('documentType')
            ->orderBy('name')
            ->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $branches = Branch::orderBy('name')->get();
        $knowledgeCategories = KnowledgeCategory::active()->ordered()->get();

        return view('docuperfect.packs.create', compact('templates', 'documentTypes', 'branches', 'knowledgeCategories', 'user'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_global' => 'boolean',
            'creation_mode' => 'required|in:individual,linked',
            'slots_json' => 'required|string',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $slots = json_decode($request->input('slots_json'), true);
        if (!is_array($slots) || count($slots) === 0) {
            return redirect()->back()->withInput()->withErrors(['slots_json' => 'At least one slot is required.']);
        }

        $pack = Pack::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'is_global' => $request->boolean('is_global'),
            'creation_mode' => $request->input('creation_mode'),
            'owner_id' => $user->id,
        ]);

        $this->saveSlots($pack, $slots);

        // Attach branches
        if (!$request->boolean('is_global') && $request->has('branch_ids')) {
            $pack->branches()->sync($request->input('branch_ids'));
        }

        return redirect()->route('docuperfect.packs.index')
            ->with('status', "Pack \"{$pack->name}\" created.");
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $pack = Pack::with(['templates', 'slots.template', 'slots.documentType', 'slots.knowledgeCategory', 'branches'])->findOrFail($id);

        $templates = Template::active()->visibleTo($user)
            ->with('documentType')
            ->orderBy('name')
            ->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $branches = Branch::orderBy('name')->get();
        $knowledgeCategories = KnowledgeCategory::active()->ordered()->get();

        // Build existing slots for Alpine.js
        $existingSlots = $pack->slots->map(function ($s) {
            return [
                'label' => $s->label,
                'slot_type' => $s->slot_type,
                'template_id' => $s->template_id ? (string) $s->template_id : '',
                'document_type_id' => $s->document_type_id ? (string) $s->document_type_id : '',
                'knowledge_category_id' => $s->knowledge_category_id ? (string) $s->knowledge_category_id : '',
                'allow_multiple' => $s->allow_multiple,
                'is_optional' => $s->is_optional,
            ];
        })->toArray();

        return view('docuperfect.packs.create', compact('pack', 'templates', 'documentTypes', 'branches', 'knowledgeCategories', 'user', 'existingSlots'));
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $pack = Pack::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_global' => 'boolean',
            'creation_mode' => 'required|in:individual,linked',
            'slots_json' => 'required|string',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $slots = json_decode($request->input('slots_json'), true);
        if (!is_array($slots) || count($slots) === 0) {
            return redirect()->back()->withInput()->withErrors(['slots_json' => 'At least one slot is required.']);
        }

        $pack->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'is_global' => $request->boolean('is_global'),
            'creation_mode' => $request->input('creation_mode'),
        ]);

        // Delete old slots and recreate
        $pack->slots()->delete();
        $this->saveSlots($pack, $slots);

        // Sync branches
        if ($request->boolean('is_global')) {
            $pack->branches()->detach();
        } elseif ($request->has('branch_ids')) {
            $pack->branches()->sync($request->input('branch_ids'));
        }

        return redirect()->route('docuperfect.packs.index')
            ->with('status', "Pack \"{$pack->name}\" updated.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $pack = Pack::findOrFail($id);
        $name = $pack->name;
        $pack->delete();

        return redirect()->route('docuperfect.packs.index')
            ->with('status', "Pack \"{$name}\" deleted.");
    }

    public function showLaunch(Request $request, $id)
    {
        $user = $request->user();
        $pack = Pack::visibleTo($user)
            ->with(['slots.template', 'slots.documentType', 'slots.knowledgeCategory', 'templates'])
            ->findOrFail($id);

        // Legacy packs (no slots) — execute old launch directly
        if (!$pack->usesSlots()) {
            return $this->legacyLaunch($request, $pack);
        }

        // Gather selectable templates grouped by document_type_id
        $selectableTypeIds = $pack->slots()
            ->where('slot_type', 'selectable')
            ->whereNotNull('document_type_id')
            ->pluck('document_type_id')
            ->unique();

        $selectableTemplates = Template::active()
            ->visibleTo($user)
            ->whereIn('document_type_id', $selectableTypeIds)
            ->with('documentType')
            ->orderBy('name')
            ->get()
            ->groupBy('document_type_id');

        // Gather KB documents grouped by category_id
        $kbCategoryIds = $pack->slots()
            ->where('slot_type', 'attachment')
            ->whereNotNull('knowledge_category_id')
            ->pluck('knowledge_category_id')
            ->unique();

        $knowledgeDocuments = collect();
        if ($kbCategoryIds->isNotEmpty()) {
            $knowledgeDocuments = KnowledgeDocument::where('is_active', true)
                ->whereIn('category_id', $kbCategoryIds)
                ->with('category')
                ->orderBy('title')
                ->get()
                ->groupBy('category_id');
        }

        return view('docuperfect.packs.launch', compact('pack', 'selectableTemplates', 'knowledgeDocuments', 'user'));
    }

    public function executeLaunch(Request $request, $id)
    {
        $user = $request->user();
        $pack = Pack::visibleTo($user)->with('slots')->findOrFail($id);

        // Legacy packs — use old launch logic
        if (!$pack->usesSlots()) {
            return $this->legacyLaunch($request, $pack);
        }

        $selectedTemplateIds = $request->input('selected_templates', []);
        $selectedKbDocIds = $request->input('selected_kb_docs', []);

        $packInstanceId = ($pack->creation_mode === 'linked')
            ? (int) (microtime(true) * 1000)
            : null;

        $docCount = 0;

        foreach ($pack->slots()->ordered()->get() as $slot) {
            if ($slot->slot_type === 'required' && $slot->template_id) {
                $this->createDocumentFromTemplate($slot->template_id, $pack, $user, $packInstanceId);
                $docCount++;
            } elseif ($slot->slot_type === 'selectable') {
                $slotTemplateIds = $this->resolveSelectableTemplates($slot, $selectedTemplateIds);
                foreach ($slotTemplateIds as $tid) {
                    $this->createDocumentFromTemplate($tid, $pack, $user, $packInstanceId);
                    $docCount++;
                }
            } elseif ($slot->slot_type === 'attachment' && $packInstanceId) {
                foreach ($selectedKbDocIds as $kbId) {
                    $kbDoc = KnowledgeDocument::find($kbId);
                    if ($kbDoc && (int) $kbDoc->category_id === (int) $slot->knowledge_category_id) {
                        PackAttachment::create([
                            'pack_instance_id' => $packInstanceId,
                            'knowledge_document_id' => $kbId,
                            'slot_label' => $slot->label,
                        ]);
                    }
                }
            }
        }

        $redirectParams = $packInstanceId ? ['pack_instance' => $packInstanceId] : [];
        $msg = "Created {$docCount} document" . ($docCount !== 1 ? 's' : '') . " from \"{$pack->name}\".";

        return redirect()->route('docuperfect.documents.index', $redirectParams)
            ->with('status', $msg);
    }

    public function downloadAttachment(Request $request, $id)
    {
        $attachment = PackAttachment::with('knowledgeDocument')->findOrFail($id);
        $kbDoc = $attachment->knowledgeDocument;

        if (!$kbDoc || !$kbDoc->file_path || !Storage::disk('local')->exists($kbDoc->file_path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('local')->download($kbDoc->file_path, $kbDoc->title);
    }

    // ── Private helpers ──────────────────────────────────────────────

    private function legacyLaunch(Request $request, Pack $pack)
    {
        $user = $request->user();
        $pack->loadMissing('templates');

        $packInstanceId = (int) (microtime(true) * 1000);
        $count = 0;

        foreach ($pack->templates as $template) {
            $this->createDocumentFromTemplate($template->id, $pack, $user, $packInstanceId);
            $count++;
        }

        return redirect()->route('docuperfect.documents.index', ['pack_instance' => $packInstanceId])
            ->with('status', "Created {$count} document" . ($count !== 1 ? 's' : '') . " from \"{$pack->name}\".");
    }

    private function createDocumentFromTemplate($templateId, Pack $pack, $user, $packInstanceId)
    {
        $template = Template::find($templateId);
        if (!$template) {
            return;
        }

        $fields = $template->fields_json ?? [];
        $clearedFields = array_map(function ($field) {
            $f = $field;
            if (isset($f['value'])) {
                $f['value'] = '';
            }
            if (isset($f['selectedValue'])) {
                $f['selectedValue'] = '';
            }
            if (isset($f['active'])) {
                $f['active'] = false;
            }
            if (isset($f['text'])) {
                $f['text'] = '';
            }
            return $f;
        }, $fields);

        Document::create([
            'name' => $pack->name . ' — ' . $template->name,
            'template_id' => $template->id,
            'fields_json' => $clearedFields,
            'owner_id' => $user->id,
            'branch_id' => $user->effectiveBranchId(),
            'pack_instance_id' => $packInstanceId,
        ]);
    }

    private function resolveSelectableTemplates(PackSlot $slot, array $selectedIds): array
    {
        if ($slot->template_id) {
            return in_array($slot->template_id, $selectedIds) ? [$slot->template_id] : [];
        }
        if ($slot->document_type_id) {
            return Template::whereIn('id', $selectedIds)
                ->where('document_type_id', $slot->document_type_id)
                ->pluck('id')
                ->toArray();
        }
        return [];
    }

    private function saveSlots(Pack $pack, array $slots): void
    {
        foreach ($slots as $i => $slotData) {
            PackSlot::create([
                'pack_id' => $pack->id,
                'sort_order' => $i * 10,
                'label' => $slotData['label'] ?? 'Untitled Slot',
                'slot_type' => $slotData['slot_type'] ?? 'required',
                'template_id' => !empty($slotData['template_id']) ? $slotData['template_id'] : null,
                'document_type_id' => !empty($slotData['document_type_id']) ? $slotData['document_type_id'] : null,
                'knowledge_category_id' => !empty($slotData['knowledge_category_id']) ? $slotData['knowledge_category_id'] : null,
                'allow_multiple' => !empty($slotData['allow_multiple']),
                'is_optional' => !empty($slotData['is_optional']),
            ]);
        }
    }
}
