{{-- THE contractor form (Supplier Directory "Add a provider"), one file: the directory page includes it in place, and the fault / work order pages open it in a
     popup over the screen (AT-452 round 2, item H) - the same fields, the same validation (SupplierDirectoryController::store), never a second form.
     Expects: $serviceTypes, $specialties; optional $preTick (codes), $idp (id prefix), $formAttrs, $cancelAttrs, $saveLabel, $faultId, $workOrderId,
     $popup (true = the plain agent wording used inside the fault / work order popups; the directory page keeps its own). --}}
@php
    $preTick = $preTick ?? [];
    $idp = $idp ?? 'sp';
    $formAttrs = $formAttrs ?? '';
    $cancelAttrs = $cancelAttrs ?? '@click="showAdd = false"';
    $saveLabel = $saveLabel ?? 'Save to directory';
    $popup = $popup ?? false;
@endphp
        <form method="POST" action="{{ route('deals-v2.suppliers.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4" id="{{ $idp }}-form" {!! $formAttrs !!}>
            @csrf
            @if(! empty($faultId))<input type="hidden" name="fault_id" value="{{ $faultId }}">@endif
            @if(! empty($workOrderId))<input type="hidden" name="work_order_id" value="{{ $workOrderId }}">@endif
            <div class="md:col-span-3 text-xs hidden" data-form-errors role="alert" style="color: var(--ds-crimson, #b3261e);"></div>
            <div>
                <label for="{{ $idp }}-name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name <span class="text-red-500">*</span></label>
                <input id="{{ $idp }}-name" name="name" required maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="e.g. Sparky Electrical">
            </div>
            <div>
                <label for="{{ $idp }}-specialty" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $popup ? 'Trade' : 'Specialty' }} <span class="text-red-500">*</span></label>
                <select id="{{ $idp }}-specialty" name="specialty" required
                        class="w-full rounded-md px-3 py-2 text-sm"
                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($specialties as $s)
                        <option value="{{ $s }}">{{ ucwords(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="{{ $idp }}-company" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Company</label>
                <input id="{{ $idp }}-company" name="company" maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                {{-- Johan, 2026-08-26 — split from the old combined
                     "Registration / ID Number" field. This one is the
                     COMPANY's own registration number only — the
                     representative's own ID number is a separate field,
                     captured per-person on the "Add contact" form under
                     each provider below (a supplier is one company + one
                     or more representatives; the person's ID belongs on
                     THEIR record, not the firm's). Never required here —
                     required only where it actually matters: putting this
                     supplier on a document as a representative (both this
                     and the representative's own ID number, together). --}}
                <label for="{{ $idp }}-registration-number" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Company Registration Number</label>
                <input id="{{ $idp }}-registration-number" name="registration_number" maxlength="100"
                       placeholder="e.g. 2020/778899/23"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="{{ $idp }}-email" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                <input id="{{ $idp }}-email" name="email" type="email" maxlength="191"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label for="{{ $idp }}-phone" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone</label>
                <input id="{{ $idp }}-phone" name="phone" maxlength="50"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="md:col-span-3">
                <label for="{{ $idp }}-address" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $popup ? 'Business address' : 'Address' }} @unless($popup)<span class="text-[11px]" style="color: var(--text-muted);">(the firm's business address — used on documents this supplier signs, e.g. as executor)</span>@endunless</label>
                <input id="{{ $idp }}-address" name="address" maxlength="500"
                       placeholder="e.g. 21 Dee Road, Uvongo"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm pb-2" style="color: var(--text-secondary);">
                    <input type="checkbox" name="is_preferred" value="1" class="rounded" style="accent-color: var(--brand-button);">
                    {{ $popup ? 'Our preferred contractor for this trade' : 'Preferred for this specialty' }}
                </label>
            </div>
            {{-- AT-319 — a supplier can handle MORE THAN ONE service type. Multi-select of the
                 agency's configurable COC/service list (Settings → COC / Service Types). The
                 work-order panel filters its supplier picker by these. --}}
            <div class="md:col-span-3">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">{{ $popup ? 'Certificates and services they can do' : 'Service types' }} @unless($popup)<span class="text-[11px]" style="color: var(--text-muted);">(the COCs / services this supplier handles — filters the work-order picker)</span>@endunless</label>
                @forelse($serviceTypes as $t)
                    <label class="inline-flex items-center gap-1.5 text-sm mr-4 mb-1" style="color: var(--text-secondary);">
                        <input type="checkbox" name="service_types[]" value="{{ $t->code }}" @checked(in_array($t->code, $preTick, true)) data-trade-code="{{ $t->code }}" style="accent-color: var(--brand-button, #0ea5e9);">
                        {{ $t->label }}
                    </label>
                @empty
                    <div class="text-xs" style="color: var(--text-muted);">{{ $popup ? 'No certificates or services have been set up yet. Ask someone who manages the trade list to add them.' : 'No service types configured yet — add them under Settings → COC / Service Types.' }}</div>
                @endforelse
            </div>
            <div class="md:col-span-3 flex items-center gap-2">
                <button type="submit" class="corex-btn-primary text-sm" data-add-contractor-save>{{ $saveLabel }}</button>
                <button type="button" {!! $cancelAttrs !!} class="corex-btn-outline text-sm">Cancel</button>
            </div>
        </form>
