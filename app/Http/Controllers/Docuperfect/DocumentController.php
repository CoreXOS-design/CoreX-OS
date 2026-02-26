<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\PackAttachment;
use App\Models\Docuperfect\PackInstanceValue;
use App\Models\Docuperfect\Template;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $packInstance = $request->query('pack_instance');
        $filter = $request->query('filter', 'active');

        $query = Document::visibleTo($user)
            ->with(['template', 'owner', 'branch', 'signatureTemplate']);

        if ($filter === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        if ($packInstance) {
            $query->where('pack_instance_id', $packInstance);
        }

        $documents = $query->orderByDesc('updated_at')->get();

        $attachments = collect();
        if ($packInstance) {
            $attachments = PackAttachment::forInstance($packInstance)
                ->with('knowledgeDocument.category')
                ->get();
        }

        return view('docuperfect.documents.index', compact('documents', 'packInstance', 'attachments', 'user', 'filter'));
    }

    public function create(Request $request, $templateId)
    {
        $user = $request->user();
        $template = Template::active()->visibleTo($user)->findOrFail($templateId);

        $suggestedName = $template->name . ' — ' . $user->name . ' — ' . now()->format('Y-m-d');

        return view('docuperfect.documents.create', compact('template', 'suggestedName'));
    }

    public function store(Request $request, $templateId)
    {
        $user = $request->user();
        $template = Template::active()->visibleTo($user)->findOrFail($templateId);

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $document = Document::create([
            'name' => $request->input('name'),
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
        $namedFields = NamedField::orderBy('sort_order')->get();

        // Merge shared pack instance values into this document's fields
        if ($document->pack_instance_id) {
            $sharedValues = PackInstanceValue::where('pack_instance_id', $document->pack_instance_id)
                ->pluck('value', 'named_field_id');

            if ($sharedValues->isNotEmpty()) {
                $fieldsJson = $document->fields_json ?? [];
                $changed = false;

                foreach ($fieldsJson as &$field) {
                    if (!empty($field['named_field_id']) && $sharedValues->has($field['named_field_id'])) {
                        $sharedVal = $sharedValues[$field['named_field_id']];
                        if (($field['value'] ?? '') !== ($sharedVal ?? '')) {
                            $field['value'] = $sharedVal;
                            $changed = true;
                        }
                    }
                }
                unset($field);

                if ($changed) {
                    $document->update(['fields_json' => $fieldsJson]);
                }
            }
        }

        return view('docuperfect.documents.edit', compact('document', 'template', 'namedFields', 'user'));
    }

    public function saveFields(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        // Access check
        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $data = [];

        if ($request->has('fields')) {
            $data['fields_json'] = $request->input('fields');
        }
        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }

        if (!empty($data)) {
            $document->update($data);
        }

        // Sync named field values to pack instance siblings
        if ($document->pack_instance_id && $request->has('fields')) {
            $fieldsJson = $document->fields_json ?? [];

            // Extract named field values from saved fields
            $namedValues = [];
            foreach ($fieldsJson as $field) {
                if (!empty($field['named_field_id']) && array_key_exists('value', $field)) {
                    $namedValues[$field['named_field_id']] = $field['value'];
                }
            }

            if (!empty($namedValues)) {
                // Upsert shared pack instance values
                foreach ($namedValues as $namedFieldId => $value) {
                    PackInstanceValue::updateOrCreate(
                        [
                            'pack_instance_id' => $document->pack_instance_id,
                            'named_field_id' => $namedFieldId,
                        ],
                        ['value' => $value]
                    );
                }

                // Update sibling documents in the same pack
                $siblings = Document::where('pack_instance_id', $document->pack_instance_id)
                    ->where('id', '!=', $document->id)
                    ->get();

                foreach ($siblings as $sibling) {
                    $siblingFields = $sibling->fields_json ?? [];
                    $siblingChanged = false;

                    foreach ($siblingFields as &$sf) {
                        if (!empty($sf['named_field_id']) && array_key_exists($sf['named_field_id'], $namedValues)) {
                            if (($sf['value'] ?? '') !== ($namedValues[$sf['named_field_id']] ?? '')) {
                                $sf['value'] = $namedValues[$sf['named_field_id']];
                                $siblingChanged = true;
                            }
                        }
                    }
                    unset($sf);

                    if ($siblingChanged) {
                        $sibling->update(['fields_json' => $siblingFields]);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    public function combinedPdfData(Request $request, $instanceId)
    {
        $user = $request->user();

        $documents = Document::active()
            ->visibleTo($user)
            ->where('pack_instance_id', $instanceId)
            ->with('template')
            ->orderByDesc('updated_at')
            ->get();

        if ($documents->isEmpty()) {
            return response()->json(['error' => 'No documents found.'], 404);
        }

        $result = [];
        foreach ($documents as $doc) {
            $tpl = $doc->template;
            if (!$tpl) {
                continue;
            }

            $pageImages = [];
            for ($n = 0; $n < $tpl->page_count; $n++) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $tpl->id, 'page' => $n]);
            }

            $result[] = [
                'name' => $doc->name,
                'fields' => $doc->fields_json ?? [],
                'pageImages' => $pageImages,
            ];
        }

        return response()->json(['documents' => $result]);
    }

    public function rename(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $document->update(['name' => $request->input('name')]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'name' => $document->name]);
        }

        return redirect()->back()->with('status', 'Document renamed.');
    }

    public function archive(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        // Block archiving for documents in active signing workflows or active leases
        $sigTemplate = $document->signatureTemplate;
        if ($sigTemplate) {
            $blockedStatuses = [
                'awaiting_tenant',
                'awaiting_landlord',
                'signing',
                'pending_agent_approval',
                'completed',
                'sent',
            ];

            if (in_array($sigTemplate->status, $blockedStatuses)) {
                $statusLabels = [
                    'awaiting_tenant' => 'awaiting tenant signature',
                    'awaiting_landlord' => 'awaiting landlord signature',
                    'signing' => 'currently being signed',
                    'pending_agent_approval' => 'pending your approval',
                    'completed' => 'a completed active lease',
                    'sent' => 'sent for signing',
                ];
                $label = $statusLabels[$sigTemplate->status] ?? 'in an active workflow';

                return redirect()->back()
                    ->with('error', "Cannot archive \"{$document->name}\" — it is {$label}. Only draft and cancelled documents can be archived.");
            }
        }

        $document->update(['archived_at' => now()]);

        return redirect()->route('docuperfect.dashboard')
            ->with('status', "Document \"{$document->name}\" archived.");
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $document->update(['archived_at' => null]);

        return redirect()->back()
            ->with('status', "Document \"{$document->name}\" restored.");
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

        return redirect()->route('docuperfect.dashboard')
            ->with('status', "Document \"{$name}\" deleted.");
    }

    /**
     * Send a document to the Rental E-Signatures workflow.
     */
    public function sendToRentals(Request $request, $id)
    {
        $user = $request->user();
        $document = Document::findOrFail($id);

        if (!$user->isAdmin() && (int)$document->owner_id !== (int)$user->id) {
            abort(403);
        }

        $request->validate([
            'document_type' => 'required|string',
            'property_id' => 'required|exists:rental_properties,id',
        ]);

        $property = \App\Models\Rental\RentalProperty::findOrFail($request->property_id);

        $document->update([
            'document_type' => $request->document_type,
            'property_id' => $property->id,
            'property_address' => $property->full_address,
        ]);

        return redirect()->route('docuperfect.signatures.setup', $document->id)
            ->with('status', 'Document ready for signature setup.');
    }
}
