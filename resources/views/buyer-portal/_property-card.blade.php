<div class="card p-4">
    <div class="flex items-start justify-between mb-2">
        <div>
            <div class="text-sm font-semibold text-white">{{ $prop->title ?? 'Property' }}</div>
            <div class="text-xs text-slate-400">{{ $prop->suburb }} · R {{ number_format($prop->price ?? 0) }}</div>
        </div>
        <span class="text-[10px] px-1.5 py-0.5 rounded font-bold"
              style="background: {{ $match->tier === 'perfect' ? '#10b98120' : ($match->tier === 'strong' ? '#00d4aa20' : '#f59e0b20') }}; color: {{ $match->tier === 'perfect' ? '#10b981' : ($match->tier === 'strong' ? '#00d4aa' : '#f59e0b') }};">
            {{ $match->score }}%
        </span>
    </div>
    @php $missingFeats = json_decode($match->missing_features ?? '[]', true); @endphp
    @if(!empty($missingFeats))
        <div class="text-[10px] text-slate-500 mb-2">Missing: {{ implode(', ', $missingFeats) }}</div>
    @endif

    @if($resp)
        <div class="text-[10px] font-medium px-2 py-1 rounded inline-block"
             style="background: {{ $resp === 'interested' ? '#10b98120' : '#ef444420' }}; color: {{ $resp === 'interested' ? '#10b981' : '#ef4444' }};">
            {{ ucfirst(str_replace('_', ' ', $resp)) }}
        </div>
    @else
        <div class="flex items-center gap-2 mt-2">
            <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                @csrf
                <input type="hidden" name="property_id" value="{{ $prop->id }}">
                <input type="hidden" name="response" value="interested">
                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: #10b981; color: #fff;">Interested</button>
            </form>
            <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                @csrf
                <input type="hidden" name="property_id" value="{{ $prop->id }}">
                <input type="hidden" name="response" value="not_interested">
                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: #334155; color: #94a3b8;">Not Interested</button>
            </form>
            <form method="POST" action="{{ route('buyer-portal.respond', $token) }}">
                @csrf
                <input type="hidden" name="property_id" value="{{ $prop->id }}">
                <input type="hidden" name="response" value="viewing_requested">
                <button type="submit" class="text-[10px] font-medium px-2 py-1 rounded" style="background: #00d4aa; color: #0f172a;">Request Viewing</button>
            </form>
        </div>
    @endif
</div>
