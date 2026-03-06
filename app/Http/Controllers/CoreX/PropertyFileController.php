<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyFileController extends Controller
{
    public function store(Request $request, Property $property)
    {
        $request->validate([
            'file'  => 'required|file|max:51200', // 50 MB
        ]);

        $uploaded = $request->file('file');
        $path     = $uploaded->store("properties/{$property->id}/files", 'public');

        $property->files()->create([
            'user_id'   => auth()->id(),
            'name'      => $uploaded->getClientOriginalName(),
            'path'      => $path,
            'size'      => $uploaded->getSize(),
            'mime_type' => $uploaded->getMimeType(),
        ]);

        return back()->with('success', 'File uploaded.')->with('tab', 'drive');
    }

    public function destroy(Property $property, PropertyFile $file)
    {
        abort_unless((int) $file->property_id === $property->id, 404);
        abort_unless(
            auth()->id() === $file->user_id || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']),
            403
        );

        Storage::disk('public')->delete($file->path);
        $file->delete();

        return back()->with('success', 'File deleted.')->with('tab', 'drive');
    }
}
