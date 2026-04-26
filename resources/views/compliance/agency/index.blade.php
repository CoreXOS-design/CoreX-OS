@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="agencyDocs()">
    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Agency Documents</h1>
                <p class="text-sm text-white/60">
                    Upload and manage your agency's compliance documents. Configure document types in
                    <a href="{{ route('compliance.document-types.index') }}" class="font-semibold underline" style="color: #fff;">Settings → Document Types</a>.
                </p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-crimson);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3l-7.07-12a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z"/></svg>
            <div class="flex-1">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        </div>
    @endif

    @if($matrix->isEmpty())
        <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                 style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No document types configured yet</h3>
            <p class="text-sm mb-4" style="color: var(--text-muted);">Set up the document types your agency needs to track before uploading anything.</p>
            @if($isAdmin)
                <a href="{{ route('compliance.document-types.index') }}" class="corex-btn-primary">Configure Document Types</a>
            @endif
        </div>
    @else
        @php
            $statusToken = [
                'teal'  => 'var(--ds-green)',
                'amber' => 'var(--ds-amber)',
                'red'   => 'var(--ds-amber)',
                'slate' => 'var(--text-muted)',
            ];
        @endphp

        @foreach($matrix as $row)
            @php
                $config = $row->type_config;
                $company = $row->company;
            @endphp
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                {{-- Type header --}}
                <div class="px-4 py-3 flex items-center justify-between flex-wrap gap-2" style="background: var(--surface-2); border-bottom: 1px solid var(--border);">
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">{{ $config->name }}</h3>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($config->required)
                            <span class="ds-badge ds-badge-success">Required</span>
                        @endif
                        @if($config->has_expiry)
                            <span class="ds-badge ds-badge-info">Expiry tracked</span>
                        @endif
                    </div>
                </div>

                {{-- Cards row --}}
                <div class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        {{-- Company card --}}
                        @php
                            $companyColour = $company ? $company->status_colour : ($config->required ? 'red' : 'slate');
                            $companyTone = $statusToken[$companyColour] ?? 'var(--text-muted)';
                        @endphp
                        <div class="rounded-md p-3" style="border: 1px solid var(--border);">
                            <div class="text-[0.6875rem] font-semibold uppercase mb-2 tracking-wider" style="color: var(--text-muted);">Company</div>
                            <div class="flex items-center gap-1.5 mb-2">
                                <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $companyTone }};"></span>
                                <span class="text-xs font-semibold" style="color: {{ $companyTone }};">
                                    {{ $company ? $company->status_label : ($config->required ? 'Required — not uploaded' : 'Not uploaded') }}
                                </span>
                            </div>
                            @if($company)
                                <div class="text-[0.6875rem] mb-2" style="color: var(--text-secondary);">
                                    {{ $company->document_original_name }}
                                    @if($company->policy_reference) · {{ $company->policy_reference }} @endif
                                </div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    @if($company->document_path)
                                        <a href="{{ asset('storage/' . $company->document_path) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand-icon);">Download</a>
                                    @endif
                                    @if($isAdmin)
                                        <a href="{{ route('compliance.agency-settings.edit', $company) }}" class="text-xs font-semibold" style="color: var(--text-secondary);">Edit</a>
                                        <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, null, 'Company')" class="text-xs font-semibold" style="color: var(--brand-icon); background: none; border: none; cursor: pointer; padding: 0;">Replace</button>
                                    @endif
                                </div>
                            @elseif($isAdmin)
                                <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, null, 'Company')" class="corex-btn-primary px-2.5 py-1 text-xs">Upload</button>
                            @endif
                        </div>

                        {{-- Branch cards --}}
                        @foreach($row->branches as $bRow)
                            @php
                                $br = $bRow->branch;
                                $bProv = $bRow->provision;
                                $canManageBranch = $isAdmin || ($isBranchManager && $userBranchId === $br->id);
                                $showCard = $isAdmin || ($isBranchManager && $userBranchId === $br->id);
                            @endphp
                            @if($showCard)
                                <div class="rounded-md p-3" style="border: 1px solid var(--border);">
                                    <div class="text-[0.6875rem] font-semibold uppercase mb-2 tracking-wider" style="color: var(--text-muted);">{{ $br->name }}</div>
                                    @if($bProv)
                                        @php
                                            $bColour = $bProv->status_colour;
                                            $bTone = $statusToken[$bColour] ?? 'var(--text-muted)';
                                        @endphp
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: {{ $bTone }};"></span>
                                            <span class="text-xs font-semibold" style="color: {{ $bTone }};">Branch version: {{ $bProv->status_label }}</span>
                                        </div>
                                        <div class="text-[0.6875rem] mb-2" style="color: var(--text-secondary);">{{ $bProv->document_original_name }}</div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            @if($bProv->document_path)
                                                <a href="{{ asset('storage/' . $bProv->document_path) }}" target="_blank" class="text-xs font-semibold" style="color: var(--brand-icon);">Download</a>
                                            @endif
                                            @if($canManageBranch)
                                                <a href="{{ route('compliance.agency-settings.edit', $bProv) }}" class="text-xs font-semibold" style="color: var(--text-secondary);">Edit</a>
                                                <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, {{ $br->id }}, '{{ addslashes($br->name) }}')" class="text-xs font-semibold" style="color: var(--brand-icon); background: none; border: none; cursor: pointer; padding: 0;">Replace</button>
                                            @endif
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: var(--text-muted);"></span>
                                            <span class="text-xs" style="color: var(--text-muted);">Using company fallback</span>
                                        </div>
                                        @if($canManageBranch)
                                            <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, {{ $br->id }}, '{{ addslashes($br->name) }}')" class="corex-btn-outline px-2.5 py-1 text-xs">Upload Override</button>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    {{-- Upload Modal --}}
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @keydown.escape.window="showModal = false">
        <div class="w-full max-w-lg mx-4 p-6 rounded-md" style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);" @click.outside="showModal = false">
            <h3 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">
                Upload <span x-text="tierLabel"></span> <span x-text="typeName"></span>
            </h3>
            <form method="POST" action="{{ route('compliance.agency-settings.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="document_type_config_id" :value="typeId">
                <input type="hidden" name="branch_id" :value="branchId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Document <span class="text-red-500">*</span></label>
                        <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm" style="color: var(--text-secondary);">
                        <p class="mt-1 text-xs" style="color: var(--text-muted);">PDF, JPG, PNG — max 10 MB</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Policy / Reference</label>
                        <input type="text" name="policy_reference" maxlength="200" class="w-full rounded-md px-3 py-2 text-sm"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Effective From <span class="text-red-500">*</span></label>
                            <input type="date" name="effective_from" required :value="today" class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div x-show="typeHasExpiry">
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Effective Until</label>
                            <input type="date" name="effective_until" class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Notes</label>
                        <textarea name="notes" rows="2" maxlength="2000" class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 mt-5">
                    <button type="button" @click="showModal = false" class="corex-btn-outline">Cancel</button>
                    <button type="submit" class="corex-btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function agencyDocs() {
    return {
        showModal: false,
        typeId: null,
        typeName: '',
        typeHasExpiry: true,
        branchId: '',
        tierLabel: '',
        today: new Date().toISOString().split('T')[0],
        openUpload(id, name, hasExpiry, branchId, tierLabel) {
            this.typeId = id;
            this.typeName = name;
            this.typeHasExpiry = hasExpiry;
            this.branchId = branchId || '';
            this.tierLabel = tierLabel;
            this.showModal = true;
        }
    };
}
</script>
@endsection
