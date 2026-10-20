{{-- One default wave: add (item null) or edit. AT-478, spec agency-feature-rollout.md §5.1. --}}
@php $chosen = old('feature_keys', $item?->feature_keys ?? []); @endphp
<form method="POST" action="{{ $item ? route('admin.feature-rollout-defaults.update', $item->id) : route('admin.feature-rollout-defaults.store') }}" class="space-y-4">
    @csrf
    @if($item) @method('PUT') @endif
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <label class="block md:col-span-2">
            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Wave name</span>
            <input type="text" name="title" required maxlength="255" value="{{ old('title', $item?->title) }}"
                   placeholder="e.g. Deals and documents"
                   class="w-full mt-1 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
        </label>
        <label class="block">
            <span class="text-xs font-semibold" style="color:var(--text-secondary);">Track</span>
            <select name="track" class="w-full mt-1 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                @foreach($tracks as $value => $label)
                    <option value="{{ $value }}" @selected(old('track', $item?->track ?? 'general') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="text-[11px] mt-1 block" style="color:var(--text-muted);">Rentals waves go first for rentals agencies, and run alongside the others when an agency chooses "side by side". "Admin decides" waves switch on after every other wave, for the agency's admins only — the admin then decides who else gets them, and nobody else is told about them until then.</span>
        </label>
    </div>
    <label class="block">
        <span class="text-xs font-semibold" style="color:var(--text-secondary);">What to tell people when it switches on</span>
        <textarea name="intro_text" rows="2" maxlength="2000"
                  placeholder="e.g. You can now record every sale and rental, and keep its documents together."
                  class="w-full mt-1 rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('intro_text', $item?->intro_text) }}</textarea>
    </label>
    <div>
        <span class="text-xs font-semibold" style="color:var(--text-secondary);">Features this wave switches on</span>
        <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-4 max-h-80 overflow-y-auto p-3 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            @foreach($grouped as $category => $options)
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--text-muted);">{{ $category }}</div>
                    @foreach($options as $key => $label)
                        <label class="flex items-center gap-2 text-sm py-0.5" style="color:var(--text-primary);">
                            <input type="checkbox" name="feature_keys[]" value="{{ $key }}" @checked(in_array($key, (array) $chosen, true))
                                   class="w-4 h-4 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                            <span>{{ $label }}</span>
                            @if(empty($tourCoverage[$key]))<span class="ds-badge ds-badge-muted" title="No guided tour yet">no tour yet</span>@endif
                        </label>
                    @endforeach
                </div>
            @endforeach
        </div>
        @error('feature_keys')<p class="text-xs mt-1" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
    </div>
    <div class="flex justify-end">
        <button type="submit" class="corex-btn-primary">{{ $item ? 'Save wave' : 'Add wave' }}</button>
    </div>
</form>
