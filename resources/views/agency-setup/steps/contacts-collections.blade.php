{{-- Contacts step aux-partial: contact types (system-managed, read-only) +
     inline-manageable contact sources. $contactTypes, $contactSources. --}}
<div class="px-6 py-5 space-y-6">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your contact lists</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted);">How you categorise the people behind every deal.</p>
    </div>

    {{-- Contact types are system-managed (fixed roles), shown for context. --}}
    <div>
        <h3 class="text-sm font-semibold mb-2" style="color:var(--text-primary);">Contact types
            <span class="text-[11px] font-normal" style="color:var(--text-muted);">— built in</span>
        </h3>
        <div class="flex flex-wrap gap-2">
            @forelse ($contactTypes as $t)
                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs"
                      style="background:var(--surface-2,#f1f5f9); border:1px solid var(--border,#e5e7eb); color:var(--text-primary,#0f172a);">{{ $t->name }}</span>
            @empty
                <span class="text-xs italic" style="color:var(--text-muted,#94a3b8);">No contact types configured.</span>
            @endforelse
        </div>
    </div>

    {{-- Contact sources — freely manageable inline. --}}
    @include('agency-setup.steps._collection', [
        'collectionKey' => 'contact_source', 'collectionLabel' => 'Lead sources',
        'collectionPlaceholder' => 'e.g. Walk-in, Referral, Property24', 'items' => $contactSources,
    ])
</div>
