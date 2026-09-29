{{--
    PPRA Inspection Pack Phase F — verification harness only, not a spec
    screen. .ai/specs/ppra-inspection-pack.md §6.8a is "pure infrastructure
    ... no standalone checklist row" — this page exists purely so the
    picker can be exercised end to end in a real browser before Phases
    G/H/I wire the real k/l/m checklist rows to it. Superseded (and this
    page removed) once that wiring lands.
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Sample Picker — Phase F preview</h1>
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            {{ $agency->trading_name ?? $agency->name }} — internal QA harness for §6.8a, not part of the shipped checklist.
            Each button opens the shared picker component in a different mode; "Confirm selection" persists to this agency's
            current draft <code>ppra_inspection_packs</code> row.
        </p>
    </div>

    <div class="rounded-md p-5 flex flex-wrap gap-3" style="background:var(--surface); border:1px solid var(--border);">
        <button type="button" class="corex-btn-primary text-xs" x-data x-on:click="$dispatch('open-modal', 'preview-deal-picker')">
            Choose sales sample (deal mode)
        </button>
        <button type="button" class="corex-btn-primary text-xs" x-data x-on:click="$dispatch('open-modal', 'preview-rental-picker')">
            Choose rental sample (rental mode)
        </button>
        <button type="button" class="corex-btn-primary text-xs" x-data x-on:click="$dispatch('open-modal', 'preview-listing-picker')">
            Choose mandate sample (listing mode)
        </button>
    </div>

    <div class="rounded-md p-5 text-xs" style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);"
        x-data="{ log: [] }" x-on:ppra-sample-picker-saved.window="log.unshift(JSON.stringify($event.detail))">
        <p class="font-semibold mb-2" style="color:var(--text-primary);">Last save (this page load):</p>
        <template x-if="log.length === 0"><p style="color:var(--text-muted);">Nothing confirmed yet.</p></template>
        <template x-for="entry in log"><p x-text="entry"></p></template>
    </div>
</div>

<x-ppra-sample-picker mode="deal" name="preview-deal-picker" title="Choose sales sample" />
<x-ppra-sample-picker mode="rental" name="preview-rental-picker" title="Choose rental sample" />
<x-ppra-sample-picker mode="listing" name="preview-listing-picker" title="Choose mandate sample" />
@endsection
