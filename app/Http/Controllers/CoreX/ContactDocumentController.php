<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContactDocumentController extends Controller
{
    public function store(Request $request, Contact $contact)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // 20MB max
        ]);

        $file = $request->file('file');
        $ext  = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            'contact-documents/' . $contact->id,
            Str::uuid() . ($ext ? ".{$ext}" : ''),
            'local'
        );

        $contact->documents()->create([
            'uploaded_by_user_id' => auth()->id(),
            'original_name'       => $file->getClientOriginalName(),
            'storage_path'        => $path,
            'mime_type'           => $file->getMimeType(),
            'size'                => $file->getSize(),
        ]);

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'File uploaded.')
            ->withFragment('tab-drive');
    }

    public function download(Contact $contact, ContactDocument $document)
    {
        abort_unless($document->contact_id === $contact->id, 404);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->download($document->storage_path, $document->original_name);
    }

    public function destroy(Contact $contact, ContactDocument $document)
    {
        abort_unless($document->contact_id === $contact->id, 404);

        Storage::disk('local')->delete($document->storage_path);
        $document->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'File deleted.')
            ->withFragment('tab-drive');
    }
}
