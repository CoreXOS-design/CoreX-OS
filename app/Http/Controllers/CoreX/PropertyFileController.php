<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Concerns\ValidatesDocumentUploads;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Rules\ExistsInScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyFileController extends Controller
{
    use AuthorizesPropertyAccess;
    use ValidatesDocumentUploads;

    public function store(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        // Two accepted shapes, normalised below into one (files[] + document_types[]):
        //  - Multi:  files[]          + document_types[]  (Drive tab multi-upload)
        //  - Single: file             + document_type_id  (compliance-checklist
        //            preset-type upload — type chosen automatically for the agent)
        $isSingle = $request->hasFile('file') && ! $request->hasFile('files');

        $request->validate($isSingle ? [
            'file'             => $this->documentUploadRule(51200),
            'document_type_id' => 'nullable|exists:document_types,id',
            'contact_id'       => ['nullable', new ExistsInScope(Contact::class)],
        ] : [
            // One or many files in a single submit. Each file carries its own
            // optional document type, keyed by the same index in document_types[].
            'files'            => 'required|array|min:1',
            'files.*'          => $this->documentUploadRule(51200),
            'document_types'   => 'nullable|array',
            'document_types.*' => 'nullable|exists:document_types,id',
            // ExistsInScope keeps a cross-agency / soft-deleted contact from being attached.
            'contact_id'       => ['nullable', new ExistsInScope(Contact::class)],
        ]);

        if ($isSingle) {
            $files = [$request->file('file')];
            $types = [$request->input('document_type_id') ?: null];
        } else {
            $files = $request->file('files');
            $types = $request->input('document_types', []);
        }
        $contactId = $request->filled('contact_id') ? $request->input('contact_id') : null;

        DB::transaction(function () use ($files, $types, $contactId, $property) {
            foreach ($files as $i => $uploaded) {
                // AT-267 / POPIA (audit 2026-07-21) — property Drive files were written to the PUBLIC
                // disk and served via direct /storage URLs (world-readable, and the download toggle
                // could never gate them). New files go to the LOCAL (private) disk and are served only
                // through the gated download() route below.
                $path = $uploaded->store("properties/{$property->id}/files", 'local');

                $doc = Document::create([
                    'original_name'    => $uploaded->getClientOriginalName(),
                    'storage_path'     => $path,
                    'disk'             => 'local',
                    'mime_type'        => $uploaded->getMimeType(),
                    'size'             => $uploaded->getSize(),
                    'document_type_id' => $types[$i] ?? null,
                    'source_type'      => 'upload',
                    'uploaded_by'      => auth()->id(),
                ]);

                // Attach to property
                $doc->properties()->attach($property->id);

                // Attach to contact if selected (applies to every uploaded file)
                if ($contactId) {
                    $doc->contacts()->attach($contactId);
                }
            }
        });

        $count   = count($files);
        $message = $count === 1 ? 'File uploaded.' : "{$count} files uploaded.";

        return back()->with('success', $message)->with('tab', 'drive');
    }

    /**
     * Gated download of a property Drive file. Streams from whatever disk the file is on (local for
     * new files, public for not-yet-backfilled legacy ones) so the UI never needs a direct /storage
     * URL. Guarded by the per-record property VIEW scope + the assistant download toggle (route
     * middleware), so it honours both data scope and the can_download_documents setting.
     */
    public function download(Property $property, Document $document)
    {
        $this->authorizeProperty($property, forEdit: false);
        abort_unless($document->properties()->where('properties.id', $property->id)->exists(), 404);

        $disk = $document->disk ?: 'local';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($document->storage_path), 404);

        return \Illuminate\Support\Facades\Storage::disk($disk)->download($document->storage_path, $document->original_name);
    }

    public function destroy(Property $property, Document $document)
    {
        abort_unless($document->properties()->where('properties.id', $property->id)->exists(), 404);
        abort_unless(
            auth()->id() === $document->uploaded_by || in_array(auth()->user()?->effectiveRole(), ['super_admin', 'admin']),
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
        $this->authorizeProperty($property);
        abort_unless($document->properties()->where('properties.id', $property->id)->exists(), 404);

        $request->validate([
            'document_type_id' => 'nullable|exists:document_types,id',
            'contact_id' => ['nullable', new ExistsInScope(Contact::class)],
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
