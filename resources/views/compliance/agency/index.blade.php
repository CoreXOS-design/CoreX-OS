@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Agency Compliance Provisions" :back-route="route('corex.settings')" :flush="true">
        <x-slot:actions>
            <button type="button" onclick="document.getElementById('add-provision-form').classList.toggle('hidden')"
                    class="px-4 py-2 rounded text-sm font-semibold text-white transition-colors"
                    style="background:#00d4aa; border-radius:3px;"
                    onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                Add Provision
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6 space-y-5">

        @if(session('success'))
        <div class="rounded px-4 py-3 text-sm font-medium" style="background:rgba(0,212,170,0.1); border:1px solid rgba(0,212,170,0.3); color:#00d4aa; border-radius:3px;">
            {{ session('success') }}
        </div>
        @endif

        @if($errors->any())
        <div class="rounded px-4 py-3 text-sm" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#ef4444; border-radius:3px;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        {{-- Add Provision Form (hidden by default) --}}
        <div id="add-provision-form" class="hidden rounded p-6" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
            <h3 class="text-sm font-bold uppercase tracking-wider mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">New Agency Provision</h3>
            <form method="POST" action="{{ route('compliance.agency-settings.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Provision Type <span class="text-red-500">*</span></label>
                        <select name="provision_type" required class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                            <option value="">Select...</option>
                            @foreach($types as $t)
                            <option value="{{ $t }}" {{ old('provision_type') === $t ? 'selected' : '' }}>{{ $typeLabels[$t] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Policy Reference</label>
                        <input type="text" name="policy_reference" value="{{ old('policy_reference') }}" placeholder="e.g. Santam Policy #12345"
                               class="w-full rounded px-3 py-2.5 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Document (optional)</label>
                        <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm rounded px-3 py-2"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary); border-radius:3px;">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Effective From <span class="text-red-500">*</span></label>
                        <input type="date" name="effective_from" value="{{ old('effective_from', now()->toDateString()) }}" required
                               class="w-full rounded px-3 py-2.5 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Effective Until</label>
                        <input type="date" name="effective_until" value="{{ old('effective_until') }}"
                               class="w-full rounded px-3 py-2.5 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Applies to Roles</label>
                        <select name="applies_to_roles[]" multiple class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px; min-height:80px;">
                            @foreach($roles as $role)
                            <option value="{{ $role->name }}">{{ $role->label }}</option>
                            @endforeach
                        </select>
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">Leave empty = all roles</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Applies to Branches</label>
                        <select name="applies_to_branches[]" multiple class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px; min-height:80px;">
                            @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">Leave empty = all branches</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Notes</label>
                        <textarea name="notes" rows="2" class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">{{ old('notes') }}</textarea>
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-4">
                    <button type="submit" class="px-5 py-2.5 rounded text-sm font-semibold text-white"
                            style="background:#00d4aa; border-radius:3px;">Save Provision</button>
                    <button type="button" onclick="document.getElementById('add-provision-form').classList.add('hidden')"
                            class="px-4 py-2 rounded text-sm" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Cancel</button>
                </div>
            </form>
        </div>

        {{-- Provisions by Type --}}
        <div class="rounded overflow-hidden" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">
                    Current Provisions
                    <span class="text-xs font-normal ml-2" style="color:var(--text-muted);">{{ $activeByType->count() }} of {{ count($types) }} items covered</span>
                </h3>
            </div>

            <div class="divide-y" style="border-color:var(--border);">
                @foreach($types as $type)
                @php $provision = $activeByType->get($type); @endphp
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $provision ? '#00d4aa' : '#64748b' }};"></span>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $typeLabels[$type] }}</div>
                            @if($provision)
                            <div class="text-[10px]" style="color:var(--text-muted);">
                                {{ $provision->policy_reference ?: ($provision->document_original_name ?: 'Agency provides') }}
                                @if($provision->effective_until)
                                    &middot; Valid until {{ $provision->effective_until->format('d M Y') }}
                                @endif
                                @if($provision->applies_to_roles && count($provision->applies_to_roles))
                                    &middot; Roles: {{ implode(', ', $provision->applies_to_roles) }}
                                @endif
                            </div>
                            @else
                            <div class="text-[10px]" style="color:var(--text-muted);">Not provided by agency — individual upload required</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($provision)
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Active</span>
                        @if($provision->document_path)
                        <a href="{{ asset('storage/' . $provision->document_path) }}" target="_blank"
                           class="text-[10px] font-medium px-2 py-0.5 rounded" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">View Doc</a>
                        @endif
                        <a href="{{ route('compliance.agency-settings.edit', $provision) }}"
                           class="text-[10px] font-medium px-2 py-0.5 rounded" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Edit</a>
                        <form method="POST" action="{{ route('compliance.agency-settings.destroy', $provision) }}" class="inline"
                              onsubmit="return confirm('End this provision? Staff will need individual uploads.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-[10px] font-medium px-2 py-0.5 rounded" style="color:#ef4444; border:1px solid rgba(239,68,68,0.3); border-radius:3px;">End</button>
                        </form>
                        @else
                        <button type="button" onclick="document.getElementById('add-provision-form').classList.remove('hidden'); document.querySelector('[name=provision_type]').value='{{ $type }}'; window.scrollTo({top:0,behavior:'smooth'});"
                                class="text-[10px] font-semibold px-2 py-0.5 rounded" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Add Provision</button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- History: superseded/expired provisions --}}
        @php $historicalProvisions = $provisions->where('status', '!=', 'active'); @endphp
        @if($historicalProvisions->count())
        <div class="rounded overflow-hidden" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">History</h3>
            </div>
            <div class="divide-y" style="border-color:var(--border);">
                @foreach($historicalProvisions as $hist)
                <div class="flex items-center justify-between px-5 py-2.5">
                    <div>
                        <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $typeLabels[$hist->provision_type] ?? $hist->provision_type }}</span>
                        <span class="text-[10px] ml-2" style="color:var(--text-muted);">{{ $hist->policy_reference }} &middot; {{ ucfirst($hist->status) }} &middot; {{ $hist->created_at->format('d M Y') }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>
</div>
@endsection
