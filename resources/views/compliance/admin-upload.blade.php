@extends('layouts.corex')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Upload Document for {{ $user->name }}" :back-route="route('admin.users.edit', $user)" :flush="true">
        <x-slot:actions>
            <button type="submit" form="admin-upload-form" class="px-4 py-2 rounded text-sm font-semibold text-white"
                    style="background:#00d4aa; border-radius:3px;">Upload & Mark Verified</button>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">

        @if($errors->any())
        <div class="rounded px-4 py-3 text-sm mb-5" style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); color:#ef4444; border-radius:3px;">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Upload form --}}
            <div class="lg:col-span-2">
                <form id="admin-upload-form" method="POST" action="{{ route('admin.user.documents.store', $user) }}" enctype="multipart/form-data"
                      class="rounded p-6 space-y-4" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
                    @csrf

                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Document Type <span class="text-red-500">*</span></label>
                        <select name="document_type" required class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                            <option value="">Select...</option>
                            @foreach($documentTypes as $key => $label)
                            <option value="{{ $key }}" {{ old('document_type', request('type')) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">File <span class="text-red-500">*</span></label>
                        <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png"
                               class="block w-full text-sm rounded px-3 py-2"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary); border-radius:3px;">
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">PDF, JPG, or PNG. Max 10MB.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Reason <span class="text-red-500">*</span></label>
                        <textarea name="reason" required minlength="10" rows="3" placeholder="e.g. Agent dropped physical certificate at office, uploaded on her behalf"
                                  class="w-full rounded px-3 py-2.5 text-sm outline-none"
                                  style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">{{ old('reason') }}</textarea>
                        <p class="text-[10px] mt-1" style="color:var(--text-muted);">Minimum 10 characters. This is recorded as an audit trail.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium mb-1.5" style="color:var(--text-secondary);">Expiry Date</label>
                        <input type="date" name="expiry_date" value="{{ old('expiry_date') }}"
                               class="w-full rounded px-3 py-2.5 text-sm outline-none"
                               style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); border-radius:3px;">
                    </div>

                    <input type="hidden" name="redirect_to" value="user">
                </form>
            </div>

            {{-- Current documents sidebar --}}
            <div>
                <div class="rounded p-5" style="background:var(--surface); border:1px solid var(--border); border-radius:3px;">
                    <h3 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Current Documents</h3>
                    <div class="space-y-2">
                        @foreach($documentTypes as $key => $label)
                        @php $doc = $existingDocs->get($key); @endphp
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $doc ? ($doc->status === 'verified' ? '#00d4aa' : ($doc->status === 'pending' ? '#eab308' : '#ef4444')) : '#64748b' }};"></span>
                            <span class="text-xs" style="color:var(--text-secondary);">{{ $label }}</span>
                            <span class="text-[10px] ml-auto" style="color:var(--text-muted);">
                                @if($doc) {{ ucfirst($doc->status) }} @else Missing @endif
                            </span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
