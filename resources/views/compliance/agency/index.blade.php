@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="agencyDocs()">
    <x-page-header title="Agency Documents" :flush="true" />

    <div class="p-4 lg:p-6 space-y-5">
        <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Upload and manage your agency's compliance documents. Configure document types in <a href="{{ route('compliance.document-types.index') }}" style="color:#00d4aa; font-weight:600;">Settings &rarr; Document Types</a>.</p>

        @if(session('success'))
        <div class="px-4 py-3 text-sm font-medium" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); color:#00d4aa; border-radius:3px;">{{ session('success') }}</div>
        @endif

        @if($errors->any())
        <div class="px-4 py-3 text-sm" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); color:#ef4444; border-radius:3px;">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
        @endif

        @if($matrix->isEmpty())
            <div class="py-12 text-center">
                <p class="text-sm mb-3" style="color:var(--text-secondary, #6b7280);">No document types configured yet.</p>
                @if($isAdmin)
                <a href="{{ route('compliance.document-types.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;">Configure Document Types</a>
                @endif
            </div>
        @else
            @foreach($matrix as $row)
            @php
                $config = $row->type_config;
                $company = $row->company;
                $colourMap = ['teal' => '#00d4aa', 'amber' => '#f59e0b', 'red' => '#ef4444', 'slate' => '#94a3b8'];
            @endphp
            <div style="border:1px solid var(--border, #e5e7eb); border-radius:3px; overflow:hidden;">
                {{-- Type header --}}
                <div class="px-4 py-2.5 flex items-center justify-between" style="background:var(--surface-2, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                    <h4 class="text-sm font-bold" style="color:var(--text-primary, #0f172a); font-family:'Plus Jakarta Sans',sans-serif;">{{ $config->name }}</h4>
                    <div class="flex items-center gap-1.5">
                        @if($config->required)
                            <span class="text-[10px] font-semibold px-1.5 py-0.5" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Required</span>
                        @endif
                        @if($config->has_expiry)
                            <span class="text-[10px] px-1.5 py-0.5" style="background:rgba(148,163,184,0.1); color:#94a3b8; border-radius:3px;">Expiry tracked</span>
                        @endif
                    </div>
                </div>

                {{-- Cards row --}}
                <div class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        {{-- Company card --}}
                        @php
                            $companyColour = $company ? $company->status_colour : ($config->required ? 'red' : 'slate');
                        @endphp
                        <div class="p-3" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                            <div class="text-[10px] font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Company</div>
                            <div class="flex items-center gap-1.5 mb-2">
                                <span class="w-2 h-2 rounded-full" style="background:{{ $colourMap[$companyColour] }};"></span>
                                <span class="text-xs font-semibold" style="color:{{ $colourMap[$companyColour] }};">
                                    {{ $company ? $company->status_label : ($config->required ? 'Required — not uploaded' : 'Not uploaded') }}
                                </span>
                            </div>
                            @if($company)
                                <div class="text-[10px] mb-2" style="color:var(--text-secondary, #6b7280);">
                                    {{ $company->document_original_name }}
                                    @if($company->policy_reference) &middot; {{ $company->policy_reference }} @endif
                                </div>
                                <div class="flex items-center gap-1.5">
                                    @if($company->document_path)
                                        <a href="{{ asset('storage/' . $company->document_path) }}" target="_blank" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Download</a>
                                    @endif
                                    @if($isAdmin)
                                        <a href="{{ route('compliance.agency-settings.edit', $company) }}" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Edit</a>
                                        <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, null, 'Company')" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:#00d4aa; border:1px solid rgba(0,212,170,0.3); border-radius:3px;">Replace</button>
                                    @endif
                                </div>
                            @elseif($isAdmin)
                                <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, null, 'Company')" class="text-[10px] font-semibold px-2 py-1 text-white" style="background:#00d4aa; border-radius:3px;">Upload</button>
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
                        <div class="p-3" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                            <div class="text-[10px] font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">{{ $br->name }}</div>
                            @if($bProv)
                                @php $bColour = $bProv->status_colour; @endphp
                                <div class="flex items-center gap-1.5 mb-2">
                                    <span class="w-2 h-2 rounded-full" style="background:{{ $colourMap[$bColour] }};"></span>
                                    <span class="text-xs font-semibold" style="color:{{ $colourMap[$bColour] }};">Branch version: {{ $bProv->status_label }}</span>
                                </div>
                                <div class="text-[10px] mb-2" style="color:var(--text-secondary, #6b7280);">{{ $bProv->document_original_name }}</div>
                                <div class="flex items-center gap-1.5">
                                    @if($bProv->document_path)
                                        <a href="{{ asset('storage/' . $bProv->document_path) }}" target="_blank" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Download</a>
                                    @endif
                                    @if($canManageBranch)
                                        <a href="{{ route('compliance.agency-settings.edit', $bProv) }}" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:var(--text-secondary); border:1px solid var(--border); border-radius:3px;">Edit</a>
                                        <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, {{ $br->id }}, '{{ addslashes($br->name) }}')" class="text-[10px] font-semibold px-1.5 py-0.5" style="color:#00d4aa; border:1px solid rgba(0,212,170,0.3); border-radius:3px;">Replace</button>
                                    @endif
                                </div>
                            @else
                                <div class="flex items-center gap-1.5 mb-2">
                                    <span class="w-2 h-2 rounded-full" style="background:#94a3b8;"></span>
                                    <span class="text-xs" style="color:#94a3b8;">Using company fallback</span>
                                </div>
                                @if($canManageBranch)
                                    <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }}, {{ $br->id }}, '{{ addslashes($br->name) }}')" class="text-[10px] font-semibold px-2 py-1" style="color:#00d4aa; border:1px solid rgba(0,212,170,0.3); border-radius:3px;">Upload Override</button>
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
    </div>

    {{-- Upload Modal --}}
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);" @keydown.escape.window="showModal = false">
        <div class="w-full max-w-lg mx-4 p-6" style="background:var(--surface, #fff); border-radius:3px; box-shadow:0 25px 50px rgba(0,0,0,0.25);" @click.outside="showModal = false">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary, #0f172a); font-family:'Plus Jakarta Sans',sans-serif;">
                Upload <span x-text="tierLabel"></span> <span x-text="typeName"></span>
            </h3>
            <form method="POST" action="{{ route('compliance.agency-settings.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="document_type_config_id" :value="typeId">
                <input type="hidden" name="branch_id" :value="branchId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Document <span class="text-red-500">*</span></label>
                        <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png" class="w-full text-sm" style="color:var(--text-secondary, #6b7280);">
                        <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">PDF, JPG, PNG — max 10 MB</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Policy / Reference</label>
                        <input type="text" name="policy_reference" maxlength="200" class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective From <span class="text-red-500">*</span></label>
                            <input type="date" name="effective_from" required :value="today" class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                        <div x-show="typeHasExpiry">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective Until</label>
                            <input type="date" name="effective_until" class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes</label>
                        <textarea name="notes" rows="2" maxlength="2000" class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"></textarea>
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-5">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;">Upload</button>
                    <button type="button" @click="showModal = false" class="px-4 py-2 text-sm" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">Cancel</button>
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
