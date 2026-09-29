@extends('layouts.corex')

{{--
    .ai/specs/rental-property-tab.md §2/§8, Part 1 — agency-defined fields
    on the property Rental Details tab. Johan: "under settings rentals
    agencies can add anything to this screen they desire." The rental price
    type list (Part 3) and the lease type list (Part 4) join this same page
    as they're built.
--}}

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Details Settings</h1>
            <p class="text-sm text-white/60">Fields your agency wants on the Rental tab, beyond what CoreX ships.</p>
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

    @php
        $activeCustomFields = $customFields->where('deleted_at', null)->values();
        $retiredCustomFields = $customFields->whereNotNull('deleted_at')->values();
    @endphp

    {{--
        Retiring keeps an already-captured value intact on whatever
        property has one; it just stops offering the field to new ones.
    --}}
    <div id="custom-fields" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Custom Fields</h2>
        <p class="text-xs mb-3" style="color: var(--text-muted);">Fields of your own, beyond what CoreX ships — e.g. a "Lets Assist" yes/no toggle. Each can also be included in the auto-generated advert block.</p>

        @php $activeCustomFieldIds = $activeCustomFields->pluck('id')->all(); @endphp

        <div class="space-y-3 mb-4">
            @forelse($activeCustomFields as $i => $customField)
                <div class="rounded-md p-2" style="border: 1px solid var(--border);">
                    <form method="POST" action="{{ route('corex.settings.rental-details.custom-fields.update', $customField) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        @method('PUT')
                        <span class="flex flex-col" style="line-height: 1;">
                            <button type="submit" form="cf-reorder-up-{{ $customField->id }}" @disabled($i === 0) title="Move up" class="text-xs" style="opacity: {{ $i === 0 ? '0.3' : '1' }};">&#9650;</button>
                            <button type="submit" form="cf-reorder-down-{{ $customField->id }}" @disabled($i === count($activeCustomFieldIds) - 1) title="Move down" class="text-xs" style="opacity: {{ $i === count($activeCustomFieldIds) - 1 ? '0.3' : '1' }};">&#9660;</button>
                        </span>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Label</label>
                            <input type="text" name="label" value="{{ old('label', $customField->label) }}" maxlength="150" required class="corex-input text-xs" style="width: 160px;">
                        </div>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Type</label>
                            <select name="field_type" class="corex-input text-xs" style="width: 110px;">
                                @foreach($fieldTypes as $type)
                                    <option value="{{ $type }}" @selected(old('field_type', $customField->field_type) === $type)>{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Help text</label>
                            <input type="text" name="help_text" value="{{ old('help_text', $customField->help_text) }}" maxlength="1000" class="corex-input text-xs" style="width: 200px;">
                        </div>
                        <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" name="required" value="1" @checked(old('required', $customField->required))>
                            Compulsory
                        </label>
                        <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                            <input type="checkbox" name="shown" value="1" @checked(old('shown', $customField->shown))>
                            Shown
                        </label>
                        <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);" title="Eligible to appear in the auto-generated advert block, if a property also has that switched on">
                            <input type="checkbox" name="advertise" value="1" @checked(old('advertise', $customField->advertise))>
                            Advertise
                        </label>
                        <button type="submit" class="text-xs" style="color: var(--ds-blue, #2563eb);">Save</button>
                    </form>
                    @if($i > 0)
                        @php $cfSwappedUp = $activeCustomFieldIds; [$cfSwappedUp[$i - 1], $cfSwappedUp[$i]] = [$cfSwappedUp[$i], $cfSwappedUp[$i - 1]]; @endphp
                        <form id="cf-reorder-up-{{ $customField->id }}" method="POST" action="{{ route('corex.settings.rental-details.custom-fields.reorder') }}" style="display:none;">
                            @csrf
                            @foreach($cfSwappedUp as $orderedId)
                                <input type="hidden" name="order[]" value="{{ $orderedId }}">
                            @endforeach
                        </form>
                    @endif
                    @if($i < count($activeCustomFieldIds) - 1)
                        @php $cfSwappedDown = $activeCustomFieldIds; [$cfSwappedDown[$i], $cfSwappedDown[$i + 1]] = [$cfSwappedDown[$i + 1], $cfSwappedDown[$i]]; @endphp
                        <form id="cf-reorder-down-{{ $customField->id }}" method="POST" action="{{ route('corex.settings.rental-details.custom-fields.reorder') }}" style="display:none;">
                            @csrf
                            @foreach($cfSwappedDown as $orderedId)
                                <input type="hidden" name="order[]" value="{{ $orderedId }}">
                            @endforeach
                        </form>
                    @endif
                    <div class="text-[11px] mt-1" style="color: var(--text-muted);">
                        {{ $customField->key }} &middot; {{ $customField->creator ? 'Added by ' . $customField->creator->name : 'Creator not recorded' }}
                        <form method="POST" action="{{ route('corex.settings.rental-details.custom-fields.archive', $customField) }}" style="display:inline;" onsubmit="return confirm('Retire this field? Properties that already have a value keep it; it just won\'t be on new ones.');">
                            @csrf
                            <button type="submit" style="color: var(--text-muted); margin-left: 6px;">Retire</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No custom fields yet.</p>
            @endforelse
        </div>

        <div class="pt-2 mb-4" style="border-top: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Add a field</h3>
            <form method="POST" action="{{ route('corex.settings.rental-details.custom-fields.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Label</label>
                    <input type="text" name="label" value="{{ old('label') }}" maxlength="150" placeholder="e.g. Lets Assist" required class="corex-input text-xs" style="width: 160px;">
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Type</label>
                    <select name="field_type" class="corex-input text-xs" style="width: 110px;">
                        @foreach($fieldTypes as $type)
                            <option value="{{ $type }}">{{ str_replace('_', ' ', ucfirst($type)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] mb-1" style="color: var(--text-muted);">Help text</label>
                    <input type="text" name="help_text" value="{{ old('help_text') }}" maxlength="1000" class="corex-input text-xs" style="width: 200px;">
                </div>
                <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);">
                    <input type="checkbox" name="required" value="1" @checked(old('required'))>
                    Compulsory
                </label>
                <label class="flex items-center gap-1 text-xs" style="color: var(--text-secondary);" title="Eligible to appear in the auto-generated advert block, if a property also has that switched on">
                    <input type="checkbox" name="advertise" value="1" @checked(old('advertise'))>
                    Advertise
                </label>
                <button type="submit" class="corex-btn-primary text-xs">Add</button>
            </form>
        </div>

        <div class="pt-2" style="border-top: 1px solid var(--border);">
            <h3 class="text-xs font-semibold mb-2" style="color: var(--text-secondary);">Retired</h3>
            @if($retiredCustomFields->isEmpty())
                <p class="text-xs" style="color: var(--text-muted);">Nothing retired.</p>
            @else
                <div class="space-y-1">
                    @foreach($retiredCustomFields as $customField)
                        <div class="flex items-center gap-2 text-xs" style="opacity: 0.7;">
                            <span style="color: var(--text-secondary);">{{ $customField->label }}</span>
                            <span style="color: var(--text-muted);">({{ str_replace('_', ' ', ucfirst($customField->field_type)) }})</span>
                            <span style="color: var(--text-muted);">&middot; {{ $customField->creator ? 'Added by ' . $customField->creator->name : 'Creator not recorded' }}</span>
                            <form method="POST" action="{{ route('corex.settings.rental-details.custom-fields.restore', $customField->id) }}">
                                @csrf
                                <button type="submit" style="color: var(--ds-blue, #2563eb);">Restore</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
