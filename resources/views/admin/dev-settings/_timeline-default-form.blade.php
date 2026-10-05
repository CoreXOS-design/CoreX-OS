{{-- One add/edit form for a timeline default (AT-447). $kind block|milestone, $item null = add.
     Rendered inside a shaded Alpine panel by timeline-defaults.blade.php. --}}
<form method="POST" action="{{ $item ? route('admin.timeline-defaults.update', $item->id) : route('admin.timeline-defaults.store') }}" class="space-y-3 max-w-2xl">
    @csrf
    @if($item) @method('PUT') @else <input type="hidden" name="kind" value="{{ $kind }}"> @endif
    <div>
        <label class="ds-label block mb-1">{{ $kind === 'milestone' ? 'Step title' : 'Heading' }}</label>
        <input name="title" value="{{ $item?->title }}" required maxlength="255" class="ds-field w-full">
    </div>
    <div>
        <label class="ds-label block mb-1">{{ $kind === 'milestone' ? 'Description' : 'Text' }} @if($kind === 'milestone')<span style="color: var(--text-muted);">(optional)</span>@endif</label>
        <textarea name="body" rows="{{ $kind === 'milestone' ? 2 : 6 }}" maxlength="5000" class="ds-field w-full">{{ $item?->body }}</textarea>
    </div>
    @if($kind === 'milestone')
        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="ds-label block mb-1">Days after start</label>
                <input type="number" name="offset_days" min="0" max="365" required value="{{ $item?->offset_days }}" class="ds-field" style="width: 6rem;">
            </div>
            <div>
                <label class="ds-label block mb-1">Ticks itself when…</label>
                <select name="auto_complete_trigger" class="ds-field w-full">
                    <option value="">Never — I tick it manually</option>
                    @foreach($triggers as $k => $label)<option value="{{ $k }}" @selected($item?->auto_complete_trigger === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
        </div>
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="agency_can_complete" value="1" @checked($item?->agency_can_complete)> The agency can mark this step completed from their public link</label>
        <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_go_live" value="1" @checked($item?->is_go_live)> This is the go-live step (only one — choosing it here un-marks the current one)</label>
    @endif
    <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_public" value="1" @checked($item ? $item->is_public : true)> Show on the agency's public page</label>
    <button type="submit" class="corex-btn-primary text-xs">{{ $item ? 'Save' : 'Add' }}</button>
</form>
