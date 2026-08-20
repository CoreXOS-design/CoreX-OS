{{-- CX-110 (Johan, 2026-08-20) — the UNIFIED contact History tab. Merges
     contact_audit_log, buyer_activity_log, calendar_event_feedback, calendar_events, and
     contact_access_log (ContactHistoryService) into one chronological list. Previously
     read ONLY contact_audit_log — real history (viewings, feedback, activity) was sitting
     correctly written in the other four tables the whole time; this was a read-path gap,
     not data loss. Each row is a plain array (date/actor/summary/category/source/is_system),
     not an Eloquent model — the service already resolved actor names and built the human
     summary, so this partial only renders. --}}
@if(isset($fullAuditLog))
    @php
        $catColors = [
            'contact'  => '#0ea5e9',
            'activity' => '#22c55e',
            'viewing'  => '#f59e0b',
            'access'   => '#94a3b8',
            'system'   => '#64748b',
        ];
    @endphp
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Contact History</h3>
        <div class="flex items-center gap-3">
            {{-- CX-110 — now spans every source: contact_audit_log rows with a non-'user'
                 actor_type, buyer_activity_log's contact_access mirror rows, and
                 contact_access_log itself. Default OFF = real human activity only. --}}
            <label class="flex items-center gap-1 text-[10px] cursor-pointer" style="color:var(--text-muted);">
                <input type="checkbox" @checked($includeSystem ?? false)
                    onchange="window.location='{{ route('corex.contacts.show', $contact->id) }}?tab=history' + (this.checked ? '&include_system=1' : '')">
                Include system trail
            </label>
            <a href="{{ route('corex.contacts.show', $contact->id) }}?tab=history&export=csv{{ ($includeSystem ?? false) ? '&include_system=1' : '' }}"
               class="text-[10px] font-medium px-2 py-1 rounded no-underline"
               style="background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);">Export CSV</a>
        </div>
    </div>
    @forelse($fullAuditLog as $entry)
        <div class="flex items-start gap-3 px-4 py-2.5 rounded" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="background:{{ $catColors[$entry['category']] ?? '#94a3b8' }};"></div>
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $entry['summary'] }}</span>
                        <span class="text-[10px] ml-1 px-1.5 py-0.5 rounded" style="background:{{ $catColors[$entry['category']] ?? '#94a3b8' }}20; color:{{ $catColors[$entry['category']] ?? '#94a3b8' }};">{{ ucfirst($entry['category']) }}</span>
                    </div>
                    <div class="text-[10px] flex-shrink-0" style="color:var(--text-muted);">{{ $entry['date']->format('j M Y, H:i') }}</div>
                </div>
                <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">
                    {{ $entry['actor'] }}
                    <span class="ml-1 px-1 py-0.5 rounded" style="background:var(--surface); border:1px solid var(--border);">{{ $entry['source'] }}</span>
                </div>
            </div>
        </div>
    @empty
        <div class="py-8 text-center">
            <p class="text-sm" style="color:var(--text-muted);">No history recorded yet.</p>
        </div>
    @endforelse

    {{-- CX-110 — the FULL merged trail is paginated (no cap). --}}
    @if(method_exists($fullAuditLog, 'hasPages') && $fullAuditLog->hasPages())
        <div class="pt-2">{{ $fullAuditLog->links() }}</div>
    @endif
@endif
