@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §8 — the move-out disposition vocabulary,
    agency-configurable with a sensible default, never hardcoded. Own
    settings model/screen — see RentalInventorySetting's own docblock for
    why this is not folded into the rental-inspections settings page.
--}}

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Inventory Settings</h1>
            <p class="text-sm text-white/60">The options an agent picks from when recording what was found at move-out.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-inventory.update') }}" class="space-y-4"
          x-data="{ presets: {{ Js::from($dispositionPresets) }} }">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Move-out disposition options</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    When an agent records what an inventory item looks like at move-out, they pick one of
                    these. "Requires a note" means the agent must explain why — useful for damaged or
                    missing, not usually needed for present or short (the quantity already says that).
                </p>
                <template x-for="(preset, i) in presets" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="preset.label" :name="`disposition_presets[${i}][label]`"
                               maxlength="191" required placeholder="Label"
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`disposition_presets[${i}][key]`" :value="preset.key">
                        <label class="flex items-center gap-1.5 text-xs whitespace-nowrap" style="color: var(--text-secondary);">
                            <input type="checkbox" x-model="preset.requires_notes" :name="`disposition_presets[${i}][requires_notes]`" value="1">
                            Requires a note
                        </label>
                        <button type="button" @click="presets.splice(i, 1)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button"
                        @click="presets.push({ key: 'custom_' + Date.now(), label: '', requires_notes: false })"
                        class="corex-btn-outline text-xs">+ Add an option</button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
