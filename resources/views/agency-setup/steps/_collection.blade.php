{{-- Reusable inline collection editor (list + add + remove), used inside an
     aux-partial. Expects: $collectionKey, $collectionLabel, $collectionPlaceholder,
     $items (each with ->id, ->name, and optional ->is_default). Posts to the
     wizard collection sub-routes which delegate to the canonical CRUD. --}}
<div>
    <h3 class="text-sm font-semibold mb-2" style="color:var(--text-primary);">{{ $collectionLabel }}</h3>

    <div class="flex flex-wrap gap-2 mb-3">
        @forelse ($items as $item)
            <span class="inline-flex items-center gap-1.5 rounded-full pl-3 pr-1.5 py-1 text-xs"
                  style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
                {{ $item->name }}
                @if (empty($item->is_default))
                    <form method="POST" action="{{ route('corex.agency-setup.collection.remove', ['collection' => $collectionKey, 'id' => $item->id]) }}" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" title="Remove" aria-label="Remove {{ $item->name }}"
                                class="inline-flex items-center justify-center w-4 h-4 rounded-full"
                                style="background:none;border:none;cursor:pointer;color:var(--text-muted,#94a3b8);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </form>
                @else
                    <span title="Built-in — disable on the Properties settings page rather than delete"
                          class="inline-flex items-center justify-center w-4 h-4 text-[10px]" style="color:var(--text-muted,#94a3b8);">•</span>
                @endif
            </span>
        @empty
            <span class="text-xs italic" style="color:var(--text-muted,#94a3b8);">None yet — add your first below.</span>
        @endforelse
    </div>

    <form method="POST" action="{{ route('corex.agency-setup.collection.add', ['collection' => $collectionKey]) }}" class="flex items-center gap-2">
        @csrf
        <input type="text" name="name" required maxlength="100" placeholder="{{ $collectionPlaceholder }}"
               class="flex-1 rounded-md px-3 py-2 text-sm" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">
        <button type="submit" class="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap"
                style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-secondary,#475569);">
            + Add
        </button>
    </form>
</div>
