{{-- MIC ↔ Deeds ↔ Contact loop (Part B) — persistent dead-end marker. Shows whenever this
     contact was captured with no contactable details, so any agent viewing it immediately sees
     it's been chased and there is nothing to reach. --}}
@if($contact->deadEndFlag)
    @php($de = $contact->deadEndFlag)
    <div class="rounded-md px-4 py-3 mb-4"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 45%, var(--border));">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-semibold" style="color: var(--text-primary);">⚠ No contact details available — dead end</span>
            <span class="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded"
                  style="background: var(--surface-2); color: var(--text-muted);">{{ \App\Models\ContactDeadEndFlag::reasonLabel($de->reason) }}</span>
        </div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            This owner has been chased and there is nothing contactable (no phone or email).
            @if($de->created_at) Recorded {{ $de->created_at->diffForHumans() }}. @endif
        </div>
    </div>
@endif
