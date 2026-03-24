<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactDocumentController extends Controller
{
    public function store(Request $request, Contact $contact)
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'document_type_id' => 'nullable|exists:document_types,id',
            'property_id' => 'nullable|exists:properties,id',
        ]);

        $file = $request->file('file');
        $ext  = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'contact-documents/' . $contact->id,
            Str::uuid() . ($ext ? ".{$ext}" : ''),
            'local'
        );

        $doc = Document::create([
            'original_name'    => $file->getClientOriginalName(),
            'storage_path'     => $path,
            'disk'             => 'local',
            'mime_type'        => $file->getMimeType(),
            'size'             => $file->getSize(),
            'document_type_id' => $request->input('document_type_id') ?: null,
            'source_type'      => 'upload',
            'uploaded_by'      => auth()->id(),
        ]);

        // Attach to contact
        $doc->contacts()->attach($contact->id);

        // Attach to property if selected
        if ($request->filled('property_id')) {
            $doc->properties()->attach($request->input('property_id'));
        }

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'File uploaded.')
            ->withFragment('tab-drive');
    }

    public function download(Contact $contact, Document $document)
    {
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404);

        return $document->downloadResponse();
    }

    public function destroy(Contact $contact, Document $document)
    {
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404);

        // Detach from this contact
        $document->contacts()->detach($contact->id);

        // If no contacts or properties remain linked, soft-delete the document
        if ($document->contacts()->count() === 0 && $document->properties()->count() === 0) {
            $document->delete();
        }

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'File removed.')
            ->withFragment('tab-drive');
    }

    public function updateTag(Request $request, Contact $contact, Document $document)
    {
        abort_unless($document->contacts()->where('contacts.id', $contact->id)->exists(), 404);

        $request->validate([
            'document_type_id' => 'nullable|exists:document_types,id',
            'property_id' => 'nullable|exists:properties,id',
        ]);

        $document->update([
            'document_type_id' => $request->input('document_type_id') ?: null,
        ]);

        // Manage property pivot
        $newPropertyId = $request->input('property_id') ?: null;
        $document->properties()->sync($newPropertyId ? [$newPropertyId] : []);

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Document tagged.')
            ->withFragment('tab-drive');
    }
}
