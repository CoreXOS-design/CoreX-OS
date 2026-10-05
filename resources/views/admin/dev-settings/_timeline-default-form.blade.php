{{-- One add/edit form for a timeline default (AT-447). $kind block|milestone, $item null = add. --}}
<form method="POST" action="{{ $item ? route('admin.timeline-defaults.update', $item->id) : route('admin.timeline-defaults.store') }}"
      class="{{ $floating ? 'absolute right-0 z-10 mt-2 w-96 rounded-md p-3' : 'mt-2 max-w-xl' }} space-y-2"
      @if($floating) style="background: var(--surface); border:1px solid var(--border); box-shadow:0 8px 24px rgba(0,0,0,.15);" @endif>
    @csrf
    @if($item) @method('PUT') @else <input type="hidden" name="kind" value="{{ $kind }}"> @endif
    <input name="title" value="{{ $item?->title }}" required maxlength="255" placeholder="{{ $kind === 'milestone' ? 'Step title' : 'Heading' }}" class="ds-field w-full">
    <textarea name="body" rows="{{ $kind === 'milestone' ? 2 : 6 }}" maxlength="5000" placeholder="{{ $kind === 'milestone' ? 'Optional description' : 'Text' }}" class="ds-field w-full">{{ $item?->body }}</textarea>
    @if($kind === 'milestone')
        <div class="flex items-center gap-2 text-sm">
            <label class="ds-label">Days after start</label>
            <input type="number" name="offset_days" min="0" max="365" required value="{{ $item?->offset_days }}" class="ds-field" style="width:6rem;">
        </div>
        <div>
            <label class="ds-label block mb-1">Ticks itself when…</label>
            <select name="auto_complete_trigger" class="ds-field w-full">
                <option value="">Never — I tick it manually</option>
                @foreach($triggers as $k => $label)<option value="{{ $k }}" @selected($item?->auto_complete_trigger === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_go_live" value="1" @checked($item?->is_go_live)> This is the go-live step (only one — choosing it here un-marks the current one)</label>
    @endif
    <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_public" value="1" @checked($item ? $item->is_public : true)> Show on the agency's public page</label>
    <button class="corex-btn-primary text-xs">{{ $item ? 'Save' : 'Add' }}</button>
</form>
