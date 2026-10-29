@php
    /** @var \App\Models\Property $property */
    $packFiles = $property->files()->orderBy('name')->get();
    $canPack = auth()->user()->hasPermission('auctions.lots.manage');
@endphp
<div class="rounded-md p-4 space-y-3" style="background:var(--surface-2);border:1px solid var(--border);" data-auction-pack>
    <div>
        <h3 class="text-xs font-bold uppercase tracking-wider" style="color:var(--text-muted);">Auction documents</h3>
        <p class="text-xs" style="color:var(--text-muted);">Choose which files of this property buyers can download from the public auction page. Upload the files below first, then set who can see each one. Files are hidden unless you change them here.</p>
    </div>
    @if($packFiles->isEmpty())
        <p class="text-xs" style="color:var(--text-muted);">No files uploaded yet.</p>
    @else
    <div class="rounded-md overflow-hidden" style="border:1px solid var(--border);">
        @foreach($packFiles as $f)
        <div class="flex items-center gap-3 px-3 py-2 text-xs" style="border-top:1px solid var(--border);" x-data="{ v: '{{ $f->auction_visibility ?? '' }}', saved: false }">
            <span class="flex-1 truncate" style="color:var(--text-primary);">{{ $f->name }}</span>
            <select class="text-xs rounded-md border px-2 py-1" style="border-color:var(--border);background:var(--surface);color:var(--text-primary);" x-model="v" @disabled(! $canPack)
                    @change="fetch('{{ route('corex.properties.files.auction-visibility', [$property, $f->id]) }}', {method:'PUT', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}, body: JSON.stringify({auction_visibility: v || null})}).then(r => { saved = r.ok; setTimeout(() => saved = false, 1500); })">
                <option value="">Hidden</option>
                <option value="registered">Registered bidders only</option>
                <option value="public">Everyone</option>
            </select>
            <span x-show="saved" x-cloak style="color:#10b981;">Saved</span>
        </div>
        @endforeach
    </div>
    @endif
</div>
