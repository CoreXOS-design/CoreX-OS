<section id="rts-history" style="padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
    <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Road to Sold calls</h3>

    @if($history->isEmpty())
        <div class="text-xs" style="color: var(--text-muted);">No Road to Sold calls on this property yet.</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="color: var(--text-muted);">
                        <th class="py-2 pr-3 text-left font-semibold">When</th>
                        <th class="py-2 pr-3 text-left font-semibold">Who</th>
                        <th class="py-2 pr-3 text-left font-semibold">Outcome</th>
                        <th class="py-2 pr-3 text-left font-semibold">Note</th>
                        <th class="py-2 text-left font-semibold">Next action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($history as $o)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-primary);">
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $o->occurred_at?->format('j M Y H:i') }}</td>
                        <td class="py-2 pr-3">{{ $o->agent?->name ?: '-' }}</td>
                        <td class="py-2 pr-3 font-semibold">{{ $o->outcome_label }}</td>
                        <td class="py-2 pr-3" style="white-space: pre-line;">{{ $o->note ?: '-' }}</td>
                        <td class="py-2 whitespace-nowrap">{{ $o->next_action_at?->format('j M Y') ?: '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if($history->hasPages())
            <div class="flex items-center justify-end gap-2 mt-3">
                <span class="text-xs" style="color: var(--text-muted);">Page {{ $history->currentPage() }} of {{ $history->lastPage() }}</span>
                @if($history->onFirstPage())
                    <span class="corex-btn-outline corex-btn-xs" style="opacity: .5;">Previous</span>
                @else
                    <a href="{{ $history->previousPageUrl() }}" class="corex-btn-outline corex-btn-xs">Previous</a>
                @endif
                @if($history->hasMorePages())
                    <a href="{{ $history->nextPageUrl() }}" class="corex-btn-outline corex-btn-xs">Next</a>
                @else
                    <span class="corex-btn-outline corex-btn-xs" style="opacity: .5;">Next</span>
                @endif
            </div>
        @endif
    @endif
</section>
