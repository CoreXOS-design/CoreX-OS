@extends('layouts.corex')

@section('corex-content')
<div class="max-w-xl mx-auto mt-12">
    <div class="rounded-xl shadow-sm border p-8" style="background:var(--surface);border-color:var(--border)">
        <div class="flex items-center gap-3 mb-6">
            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold bg-red-600 text-white tracking-wide">TEST</span>
            <h1 class="text-xl font-bold" style="color:var(--text-primary)">Document Parser Test</h1>
        </div>
        <p class="text-sm mb-6" style="color:var(--text-muted)">Upload a .docx file to parse it into CoreX Document Structure (CDS) JSON and preview the rendered output.</p>

        <form action="{{ route('docuperfect.parser-test.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="mb-6">
                <label for="document" class="block text-sm font-medium mb-2" style="color:var(--text-secondary)">Select .docx file</label>
                <input type="file" name="document" id="document" accept=".docx" required
                    class="block w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-[color:var(--surface-2)] file:text-[color:var(--brand-icon)] hover:file:bg-[color:var(--surface-2)]" style="color:var(--text-muted)">
                @error('document')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="w-full py-2.5 px-4 text-white text-sm font-semibold rounded-lg transition" style="background:var(--brand-button)">
                Parse Document
            </button>
        </form>
    </div>
</div>
@endsection
