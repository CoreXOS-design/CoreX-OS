{{-- AT-454 - Road to Sold settings tab strip. Tabs are added as each screen lands (areas, targets). --}}
@if(count($tabs) > 1)
    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach($tabs as $tab)
            <a href="{{ $tab['href'] }}"
               class="px-4 py-2 rounded-md text-sm font-semibold"
               style="{{ $tab['active'] ? 'background:var(--brand-icon, #0ea5e9); color:#fff;' : 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
@endif
