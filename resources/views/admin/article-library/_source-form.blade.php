{{-- Add / edit one Article Library source. $source = null for "add". --}}
@php
    $formKey = $source ? 'edit-' . $source->id : 'add';
    $mine    = old('_form') === $formKey;
    $input   = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
@endphp
<form method="POST" action="{{ $source ? route('admin.article-library.sources.update', $source->id) : route('admin.article-library.sources.store') }}"
      class="grid gap-3 md:grid-cols-6 items-end">
    @csrf
    @if($source) @method('PUT') @endif
    <input type="hidden" name="_form" value="{{ $formKey }}">

    <label class="md:col-span-2 block">
        <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Name (shown as the credit)</span>
        <input type="text" name="name" required maxlength="120" value="{{ $mine ? old('name') : ($source?->name) }}"
               placeholder="e.g. Business Day Property" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
    </label>
    <label class="md:col-span-3 block">
        <span class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-secondary);">Feed link (RSS)</span>
        <input type="url" name="url" required maxlength="500" value="{{ $mine ? old('url') : ($source?->url) }}"
               placeholder="https://example.co.za/property/feed/" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $input }}">
    </label>
    <div class="md:col-span-1 flex flex-col gap-2">
        <label class="inline-flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked($mine ? old('is_active', '1') === '1' : ($source ? $source->is_active : true))>
            Fetch this source
        </label>
        <button type="submit" class="corex-btn-primary text-sm px-4 py-2">{{ $source ? 'Save' : 'Add source' }}</button>
    </div>
</form>
