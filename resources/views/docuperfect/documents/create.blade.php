@extends('layouts.corex')

@section('content')
<div class="max-w-xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Create Document</h2>
                <div class="text-sm text-white/60 mt-1">Template: {{ $template->name }}</div>
            </div>
            <a href="{{ route('docuperfect.create') }}" class="text-sm text-white/70 hover:text-white">Back</a>
        </div>
    </div>

    <form method="POST" action="{{ route('docuperfect.documents.store', $template->id) }}" class="ds-status-card p-6 space-y-4">
        @csrf

        <div>
            <label for="doc_name" class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">Document Name</label>
            <input type="text" name="name" id="doc_name"
                   value="{{ old('name', $suggestedName) }}"
                   class="w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-[color:var(--brand-icon)] focus:border-[color:var(--brand-icon)]"
                   style="border-color:var(--border);background:var(--surface);color:var(--text-primary)"
                   required maxlength="255" autofocus>
            @error('name')
                <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
            @enderror
            <div class="text-xs mt-1" style="color:var(--text-faint)">You can customise the document name before creating it.</div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="corex-btn-primary text-sm px-5 py-2" style="background:#10b981;">
                <i class="fas fa-plus mr-1"></i> Create Document
            </button>
            <a href="{{ route('docuperfect.create') }}" class="text-sm hover:text-[color:var(--text-secondary)]" style="color:var(--text-muted)">Cancel</a>
        </div>
    </form>

</div>
@endsection
