{{-- AT-366-E — period-scoped buyer-activity summary tiles (company / branch level).
     Expects $buyer = ['metrics' => BuyerActivityService::METRICS, 'aggregate' => vector|null]. --}}
<div>
    <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Buyer activity <span class="normal-case font-normal">· {{ $report['period']['label'] ?? '' }}</span></h2>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach($buyer['metrics'] as $m)
            @php $val = $buyer['aggregate'][$m['key']] ?? 0; @endphp
            <div class="rounded p-4" style="background:var(--surface-2); border:1px solid var(--border);">
                <div class="text-2xl font-bold" style="color:var(--text-primary);">{{ $m['currency'] ? 'R ' . number_format((float) $val) : number_format((int) $val) }}</div>
                <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
            </div>
        @endforeach
    </div>
    <p class="text-[10px] mt-1" style="color:var(--text-muted);">
        Captured-and-matched floor: comms count only where a buyer contact was matched and a WhatsApp/email channel is provisioned; shared-mailbox email is not agent-attributable.
    </p>
</div>
