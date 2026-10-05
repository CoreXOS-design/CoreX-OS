{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — create/edit a contract template (AT-447).
     Merge fields are written @{{ like_this }} so Blade does not evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
@php $isNew = !$template->exists; @endphp
<div class="w-full max-w-4xl space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <a href="{{ route('admin.agency-contracts.templates') }}" class="text-xs underline" style="color:var(--text-muted);">← Templates</a>
        <h1 class="text-base font-bold leading-tight mt-1" style="color: var(--text-primary);">{{ $isNew ? 'New contract template' : 'Edit template — ' . $template->name . ' (v' . $template->version . ')' }}</h1>
    </div>
    @include('admin.partials.platform-flash')

    <form method="POST" action="{{ $isNew ? route('admin.agency-contracts.templates.store') : route('admin.agency-contracts.templates.update', $template->id) }}" class="rounded-md p-5 space-y-4" style="background: var(--surface); border:1px solid var(--border);">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="ds-label block mb-1">Name</label><input name="name" required maxlength="255" value="{{ old('name', $template->name) }}" class="ds-field w-full"></div>
            <div><label class="ds-label block mb-1">Type</label><select name="kind" class="ds-field w-full">@foreach(\App\Models\Platform\PlatformContractTemplate::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $template->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div>
            <label class="ds-label block mb-1">Contract wording</label>
            <textarea name="body" required rows="24" class="ds-field w-full font-mono text-xs" style="line-height:1.5;">{{ old('body', $template->body) }}</textarea>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Type plain text. A blank line starts a new paragraph. <code># Heading</code> makes a heading, <code>## Sub-heading</code> a smaller one, lines starting <code>- </code> make a bullet list, <code>**bold**</code> makes bold.</p>
        </div>
        <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color:var(--text-secondary);">
            <div class="font-semibold mb-1" style="color:var(--text-primary);">Agency details you can drop into the wording</div>
            @foreach($fields as $f)<code class="inline-block mr-2 mb-1">{!! '{' . '{ ' . e($f) . ' }' . '}' !!}</code>@endforeach
            <p class="mt-1" style="color:var(--text-muted);">They are filled in when you send. If an agency is missing a value (e.g. no VAT number, or no timeline for the go-live date), sending is refused and tells you which one.</p>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))> Active (can be sent)</label>
        <div class="flex items-center gap-4">
            <button class="corex-btn-primary text-sm">{{ $isNew ? 'Create template' : 'Save' }}</button>
            @unless($isNew)<a href="{{ route('admin.agency-contracts.templates.preview', $template->id) }}" class="text-xs underline" style="color:var(--brand-icon);">Preview with sample data</a>@endunless
        </div>
    </form>

    @unless($isNew)
        @if(!$template->deleted_at)
        <form method="POST" action="{{ route('admin.agency-contracts.templates.destroy', $template->id) }}" onsubmit="return confirm('Archive this template? Contracts already sent are not affected. You can restore it.');">@csrf @method('DELETE')
            <button class="text-xs underline" style="color:var(--ds-crimson);">Archive this template</button></form>
        @else
        <form method="POST" action="{{ route('admin.agency-contracts.templates.restore', $template->id) }}">@csrf<button class="corex-btn-outline text-xs">Restore this template</button></form>
        @endif
    @endunless
</div>
@endsection
