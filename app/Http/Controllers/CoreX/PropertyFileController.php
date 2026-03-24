<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Property;
use Illuminate\Http\Request;

class PropertyFileController extends Controller
{
    public function store(Request $request, Property $property)
    {
        $request->validate([
            'file'  => 'required|file|max:51200',
            'document_type_id' => 'nullable|exists:document_types,id',
            'contact_id' => 'nullable|exists:contacts,id',
        ]);

        $uploaded = $request->file('file');
        $path = $uploaded->store("properties/{$property->id}/files", 'public');

        $doc = Document::create([
            'original_name'    => $uploaded->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => 'public',
            'mime_type'        => $uploaded->getMimeType(),
            'size'             => $uploaded->getSize(),
            'document_type_id' => $request->input('document_type_id') ?: null,
            'source_type'      => 'upload',
            'uploaded_by'      => auth()->id(),
        ]);

        // Attach to property
        $doc->properties()->attach($property->id);

        // Attach to contact if selected
        if ($request->filled('contact_id')) {
            $doc->contacts()->attach($request->input('contact_id'));
        }

        return back()->with('success', 'File uploaded.')->with('tab', 'drive');
    }

    public function destroy(Property $property, Document $document)
    {
        abort_unless($document->properties()->where('properties.id', $property->id)->exists(), 404);
        abort_unless(
            auth()->id() === $document->uploaded_by || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']),
            403
        );

        // Detach from this property
        $document->properties()->detach($property->id);

        // If no links remain, soft-delete
        if ($document->contacts()->count() === 0 && $document->properties()->count() === 0) {
            $document->delete();
        }

        return back()->with('success', 'File removed.')->with('tab', 'drive');
    }

    public function updateTag(Request $request, Property $property, Document $document)
    {
        abort_unless($document->properties()->where('properties.id', $property->id)->exists(), 404);

        $request->validate([
            'document_type_id' => 'nullable|exists:document_types,id',
            'contact_id' => 'nullable|exists:contacts,id',
        ]);

        $document->update([
            'document_type_id' => $request->input('document_type_id') ?: null,
        ]);

        // Manage contact pivot
        $newContactId = $request->input('contact_id') ?: null;
        $currentContacts = $document->contacts()->pluck('contacts.id')->toArray();
        if ($newContactId && !in_array($newContactId, $currentContacts)) {
            $document->contacts()->attach($newContactId);
        } elseif (!$newContactId) {
            // Remove contact links that came from property drive tagging (keep e-sign links)
            $document->contacts()->detach();
        }

        return back()->with('success', 'Document tagged.')->with('tab', 'drive');
    }
}
