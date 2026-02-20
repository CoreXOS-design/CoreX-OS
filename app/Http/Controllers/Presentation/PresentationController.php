<?php

namespace App\Http\Controllers\Presentation;

use App\Domain\Presentation\UploadProcessor;
use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationUpload;
use Illuminate\Http\Request;

class PresentationController extends Controller
{
    public function index()
    {
        $uploads = PresentationUpload::latest()->get();

        return view('presentations.index', compact('uploads'));
    }

    public function create()
    {
        // Stub — to be implemented in a future task
        return view('presentations.index', ['uploads' => []]);
    }

    public function show(int $presentation)
    {
        // Stub — to be implemented in a future task
        return view('presentations.index', ['uploads' => []]);
    }

    public function store(Request $request)
    {
        // Stub — to be implemented in a future task
        return redirect()->route('presentations.index');
    }

    /**
     * Handle a document upload for a presentation.
     * Stores file, extracts text, and detects structured fields.
     * Never touches finance logic.
     */
    public function upload(Request $request, Presentation $presentation)
    {
        $request->validate([
            'document' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $processor = new UploadProcessor(new \App\Domain\Presentation\TextExtractionService());
        $processor->process($request->file('document'), $presentation, auth()->id());

        return redirect()->route('presentations.index')
            ->with('success', 'File uploaded and processed.');
    }
}
