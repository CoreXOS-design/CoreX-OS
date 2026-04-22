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

        @if($typeCards->isEmpty())
            <div class="py-12 text-center">
                <p class="text-sm mb-3" style="color:var(--text-secondary, #6b7280);">No document types configured yet.</p>
                <a href="{{ route('compliance.document-types.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;">Configure Document Types</a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($typeCards as $card)
                @php
                    $config = $card->config;
                    $prov = $card->provision;
                    $colour = $prov ? $prov->status_colour : ($config->required ? 'red' : 'slate');
                    $colourMap = ['teal' => '#00d4aa', 'amber' => '#f59e0b', 'red' => '#ef4444', 'slate' => '#94a3b8'];
                    $bgMap = ['teal' => 'rgba(0,212,170,0.08)', 'amber' => 'rgba(245,158,11,0.08)', 'red' => 'rgba(239,68,68,0.08)', 'slate' => 'rgba(148,163,184,0.08)'];
                @endphp
                <div style="border:1px solid var(--border, #e5e7eb); border-radius:3px; overflow:hidden;">
                    <div class="px-4 py-3 flex items-start justify-between" style="border-bottom:1px solid var(--border, #e5e7eb);">
                        <div>
                            <h4 class="text-sm font-bold" style="color:var(--text-primary, #0f172a); font-family:'Plus Jakarta Sans',sans-serif;">{{ $config->name }}</h4>
                            @if($config->description)
                                <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">{{ $config->description }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            @if($config->required)
                                <span class="text-[10px] font-semibold px-1.5 py-0.5" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Required</span>
                            @endif
                        </div>
                    </div>

                    <div class="px-4 py-3">
                        {{-- Status --}}
                        <div class="flex items-center gap-2 mb-3">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $colourMap[$colour] }};"></span>
                            <span class="text-xs font-semibold" style="color:{{ $colourMap[$colour] }};">
                                {{ $prov ? $prov->status_label : 'Not uploaded' }}
                            </span>
                        </div>

                        @if($prov)
                            {{-- Current provision details --}}
                            <div class="text-[10px] space-y-0.5 mb-3" style="color:var(--text-secondary, #6b7280);">
                                @if($prov->document_original_name)
                                    <div>File: {{ $prov->document_original_name }}</div>
                                @endif
                                @if($prov->policy_reference)
                                    <div>Ref: {{ $prov->policy_reference }}</div>
                                @endif
                                <div>Uploaded {{ $prov->created_at->format('d M Y') }} by {{ $prov->creator?->name ?? 'System' }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if($prov->document_path)
                                    <a href="{{ asset('storage/' . $prov->document_path) }}" target="_blank" class="text-[10px] font-semibold px-2 py-1" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">View</a>
                                @endif
                                <a href="{{ route('compliance.agency-settings.edit', $prov) }}" class="text-[10px] font-semibold px-2 py-1" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">Edit</a>
                                <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }})"
                                        class="text-[10px] font-semibold px-2 py-1" style="color:#00d4aa; border:1px solid rgba(0,212,170,0.3); border-radius:3px;">Replace</button>
                                <form method="POST" action="{{ route('compliance.agency-settings.destroy', $prov) }}" class="inline" onsubmit="return confirm('Remove this document?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-[10px] font-semibold px-2 py-1" style="color:#ef4444; border:1px solid rgba(239,68,68,0.3); border-radius:3px;">Remove</button>
                                </form>
                            </div>
                        @else
                            <button type="button" @click="openUpload({{ $config->id }}, '{{ addslashes($config->name) }}', {{ $config->has_expiry ? 'true' : 'false' }})"
                                    class="text-xs font-semibold px-3 py-1.5 text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                                Upload
                            </button>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Upload Modal --}}
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);" @keydown.escape.window="showModal = false">
        <div class="w-full max-w-lg mx-4 p-6" style="background:var(--surface, #fff); border-radius:3px; box-shadow:0 25px 50px rgba(0,0,0,0.25);" @click.outside="showModal = false">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary, #0f172a); font-family:'Plus Jakarta Sans',sans-serif;">
                Upload: <span x-text="typeName"></span>
            </h3>
            <form method="POST" action="{{ route('compliance.agency-settings.store') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="document_type_config_id" :value="typeId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Document <span class="text-red-500">*</span></label>
                        <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png"
                               class="w-full text-sm" style="color:var(--text-secondary, #6b7280);">
                        <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">PDF, JPG, PNG — max 10 MB</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Policy / Reference Number</label>
                        <input type="text" name="policy_reference" maxlength="200" placeholder="e.g. Santam Policy #12345"
                               class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective From <span class="text-red-500">*</span></label>
                            <input type="date" name="effective_from" required :value="today"
                                   class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                        <div x-show="typeHasExpiry">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Effective Until</label>
                            <input type="date" name="effective_until"
                                   class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes</label>
                        <textarea name="notes" rows="2" maxlength="2000"
                                  class="w-full px-3 py-2 text-sm" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;" placeholder="Optional notes"></textarea>
                    </div>
                </div>
                <div class="flex items-center gap-3 mt-5">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">Upload</button>
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
        today: new Date().toISOString().split('T')[0],
        openUpload(id, name, hasExpiry) {
            this.typeId = id;
            this.typeName = name;
            this.typeHasExpiry = hasExpiry;
            this.showModal = true;
        }
    };
}
</script>
@endsection
