{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold 23.25 step 7 (Presentation results), the Result form as a screen of the pitch popup: the agency's own result list, posted to the ONE result route (23.9, ResultService). --}}
@extends('layouts.corex-app')
@section('title', 'Presentation results')

@section('corex-content')
@php $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);'; @endphp
<div class="mx-auto max-w-xl py-8 px-4" data-qa="pitch-result" x-data="{ key: '', date: '', opts: @js($options),
        get needsDate() { var o = this.opts.find(x => x.key === this.key); return !!o && o.needs_date; },
        pick() { var o = this.opts.find(x => x.key === this.key); if (o && o.days) { var d = new Date(); d.setDate(d.getDate() + o.days); this.date = d.toISOString().slice(0, 10); } } }">
    <h1 class="text-lg font-bold" style="color: var(--text-primary);" data-qa="step-heading">Presentation results</h1>
    <p class="text-sm mt-1" style="color: var(--text-muted);">{{ $address }} - how did it go?</p>
    @if(session('status'))<div class="mt-3 rounded-md px-4 py-3 text-sm" data-qa="result-saved" style="background: color-mix(in srgb, var(--ds-green, #10b981) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #10b981) 35%, transparent); color: var(--text-primary);" role="status">{{ session('status') }}</div>@endif
    @if(session('rts_error') || $errors->any())<div class="mt-3 rounded-md px-4 py-3 text-sm" data-qa="result-problem" style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 35%, transparent); color: var(--text-primary);" role="alert">{{ session('rts_error') ?: $errors->first() }}</div>@endif
    @unless(session('status') && ! session('rts_error'))
    <form method="POST" action="{{ route('road-to-sold.prospects.result', $prospect->id) }}" class="mt-4 space-y-3 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @if($prospect->track !== 'sales')<input type="hidden" name="track" value="{{ $prospect->track }}">@endif
        <div class="space-y-1.5">
            <template x-for="o in opts" :key="o.key">
                <label class="flex items-start gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                    <input type="radio" name="result_key" :value="o.key" x-model="key" required class="mt-1" @change="pick()">
                    <span class="font-semibold" x-text="o.label"></span>
                </label>
            </template>
        </div>
        <div x-show="key && needsDate" x-cloak><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="pr-on">Bring it back on</label>
            <input id="pr-on" type="date" name="revisit_on" x-model="date" :disabled="!(key && needsDate)" class="rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="pr-note">Note</label>
            <input id="pr-note" name="note" maxlength="255" class="w-full rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}"></div>
        <div class="flex justify-end"><button type="submit" class="corex-btn-primary" :disabled="!key" data-qa="result-save">Save result</button></div>
    </form>
    @endunless
</div>
@endsection
