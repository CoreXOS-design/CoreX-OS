{{-- History tab body — extracted as its own partial (pre-existing bug fix,
     found while verifying Phase 4 in a real browser — NOT a Phase 4 change):
     same class of Blade-compiler defect as the other _*.blade.php partials
     split out of show.blade.php today. No logic changed here. --}}
@if(isset($fullAuditLog))
    @php
        $catColors = [
            'contact' => '#0ea5e9', 'compliance' => '#10b981', 'consent' => '#22c55e',
            'document' => '#8b5cf6', 'marketing' => '#ec4899', 'system' => '#64748b',
        ];
    @endphp
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Contact Audit Trail</h3>
        <a href="{{ route('corex.contacts.show', $contact->id) }}?tab=history&export=csv"
           class="text-[10px] font-medium px-2 py-1 rounded no-underline"
           style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export CSV</a>
    </div>
    @forelse($fullAuditLog as $entry)
        <div class="flex items-start gap-3 px-4 py-2.5 rounded" style="background:var(--surface-2); border:1px solid var(--border);" x-data="{ showDetail: false }">
            <div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="background:{{ $catColors[$entry->event_category] ?? '#94a3b8' }};"></div>
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $entry->human_summary ?? ucfirst(str_replace('_', ' ', $entry->event_type)) }}</span>
                        <span class="text-[10px] ml-1 px-1.5 py-0.5 rounded" style="background:{{ $catColors[$entry->event_category] ?? '#94a3b8' }}20; color:{{ $catColors[$entry->event_category] ?? '#94a3b8' }};">{{ ucfirst($entry->event_category) }}</span>
                    </div>
                    <div class="text-[10px] flex-shrink-0" style="color:var(--text-muted);">{{ $entry->created_at->format('j M Y, H:i') }}</div>
                </div>
                <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">
                    {{-- AT-321-C — always attributable: user name, else the source/actor label, never a bare blank "System". --}}
                    @if($entry->user)
                        {{ $entry->user->name }}
                    @else
                        {{ $entry->actor_label ?? 'System' }}
                    @endif
                    @if($entry->source)
                        <span class="ml-1 px-1 py-0.5 rounded" style="background:var(--surface); border:1px solid var(--border);">{{ $entry->source }}</span>
                    @endif
                </div>
                @if($entry->old_values || $entry->new_values || $entry->metadata)
                    <button type="button" @click="showDetail = !showDetail" class="text-[10px] mt-1 underline" style="color:var(--text-muted);" x-text="showDetail ? 'Hide details' : 'Show details'"></button>
                    <div x-show="showDetail" x-cloak class="mt-1 text-[10px] rounded p-2" style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                        @if($entry->old_values)<div><span class="font-medium">Before:</span> {{ json_encode($entry->old_values) }}</div>@endif
                        @if($entry->new_values)<div><span class="font-medium">After:</span> {{ json_encode($entry->new_values) }}</div>@endif
                        @if($entry->metadata)<div><span class="font-medium">Details:</span> {{ json_encode($entry->metadata) }}</div>@endif
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="py-8 text-center">
            <p class="text-sm" style="color:var(--text-muted);">No audit history recorded yet.</p>
        </div>
    @endforelse

    {{-- AT-321-C — the FULL trail is paginated (no cap). --}}
    @if(method_exists($fullAuditLog, 'hasPages') && $fullAuditLog->hasPages())
        <div class="pt-2">{{ $fullAuditLog->links() }}</div>
    @endif
@endif
