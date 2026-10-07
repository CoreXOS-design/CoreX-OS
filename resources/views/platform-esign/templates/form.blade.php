{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — create / edit a Platform E-Sign template (AT-447, spec §3A).
     Merge fields are written @{{ like_this }} so Blade does not evaluate them. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $isNew = !$template->exists;
    $isPdf = $template->source === 'pdf';
    $roles = old('roles', collect($template->roles())->map(fn ($r) => ['key' => $r['key'], 'label' => $r['label']])->all() ?: [['key' => '', 'label' => 'Agency Principal'], ['key' => '', 'label' => 'CoreX']]);
@endphp
<div class="w-full space-y-5" x-data="{ roles: {{ \Illuminate\Support\Js::from(array_values($roles)) }} }">
    @include('platform-esign._header', [
        'title' => $isNew ? ($isPdf ? 'New template from a PDF' : 'New wording template') : 'Edit — ' . $template->name,
        'sub' => $isNew ? 'Signers are named by you — no property roles.' : 'Version ' . $template->version . '. Saving creates the next version; contracts already sent keep the wording they were sent with.',
        'tab' => 'templates',
        'actions' => '<a href="' . route('platform-esign.templates.index') . '" class="corex-btn-outline">← Templates</a>',
    ])

    <form method="POST" enctype="multipart/form-data" action="{{ $isNew ? route('platform-esign.templates.store') : route('platform-esign.templates.update', $template->id) }}"
          class="rounded-md p-6 space-y-5 max-w-4xl" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf @unless($isNew) @method('PUT') @endunless
        @if($isNew)<input type="hidden" name="source" value="{{ $template->source }}">@endif

        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="ds-label block mb-1">Name</label><input name="name" required maxlength="255" value="{{ old('name', $template->name) }}" class="ds-field w-full"></div>
            <div><label class="ds-label block mb-1">Type</label>
                <select name="kind" class="ds-field w-full">@foreach(\App\Models\PlatformEsign\Template::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $template->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
        </div>

        <div>
            <label class="ds-label block mb-1">Who signs, in order</label>
            <div class="space-y-2">
                <template x-for="(r, i) in roles" :key="i">
                    <div class="flex items-center gap-2">
                        <span class="text-xs w-6 text-center tabular-nums" style="color: var(--text-muted);" x-text="i + 1"></span>
                        {{-- The key is the signer's stable identity: fields placed on a PDF point at it, so it is carried through every save. --}}
                        <input type="hidden" :name="'roles[' + i + '][key]'" :value="r.key || ''">
                        <input :name="'roles[' + i + '][label]'" x-model="r.label" maxlength="80" placeholder="e.g. Agency Principal" class="ds-field flex-1">
                        <button type="button" class="corex-btn-outline corex-btn-xs" @click="roles.splice(i, 1)" x-show="roles.length > 1">Remove</button>
                    </div>
                </template>
            </div>
            <button type="button" class="corex-btn-outline corex-btn-xs mt-2" @click="roles.length < 6 && roles.push({key: '', label: ''})">+ Add signer</button>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Signers sign in this order when a contract is sent in sequence. The names and email addresses are entered when you send.</p>
        </div>

        @if($isPdf)
            <div>
                <label class="ds-label block mb-1">PDF document {{ $isNew ? '' : '(leave empty to keep the current file)' }}</label>
                <input type="file" name="pdf" accept="application/pdf" {{ $isNew ? 'required' : '' }} class="ds-field w-full">
                <p class="text-xs mt-1" style="color: var(--text-muted);">Up to 20 MB and 60 pages. {{ $isNew ? 'After saving you place the signature spots, initials, dates and text fields on the pages.' : ($template->page_count . ' pages on file. Uploading a new file clears the placed fields.') }}</p>
            </div>
        @else
            <div>
                <label class="ds-label block mb-1">Contract wording</label>
                <textarea name="body" required rows="22" class="ds-field w-full font-mono text-xs" style="line-height:1.5;">{{ old('body', $template->body) }}</textarea>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Plain text. A blank line starts a new paragraph. <code># Heading</code> / <code>## Sub-heading</code>, lines starting <code>- </code> make a list, <code>**bold**</code> makes bold. A signature block for each signer is added at the end automatically.</p>
            </div>
            <div class="rounded-md p-3 text-xs" style="background: var(--surface-2); color: var(--text-secondary);">
                <div class="font-semibold mb-1" style="color: var(--text-primary);">Agency details you can drop into the wording</div>
                @foreach($fields as $f)<code class="inline-block mr-2 mb-1">{!! '{' . '{ ' . e($f) . ' }' . '}' !!}</code>@endforeach
                <p class="mt-1" style="color: var(--text-muted);">They are filled in from the agency you pick when sending. If the agency is missing a value (e.g. no VAT number, or no timeline for the go-live date), sending is refused and tells you which one.</p>
            </div>
        @endif

        @unless($isNew)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))> Available to send</label>@endunless

        <div class="pt-4 flex items-center gap-2" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">{{ $isNew ? ($isPdf ? 'Create and place fields' : 'Create template') : 'Save changes' }}</button>
            <a href="{{ route('platform-esign.templates.index') }}" class="corex-btn-outline">Cancel</a>
        </div>
    </form>
    @include('platform-esign._end')
</div>
@endsection
