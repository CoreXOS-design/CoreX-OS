@extends('layouts.corex-app')

@section('corex-content')
<div class="max-w-5xl mx-auto space-y-6">

    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Branch Assignments</h1>
                <p class="text-sm text-white/60">Manage branches and their per-branch settings.</p>
            </div>
        </div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Add / Delete Branches --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="ds-section-header">Add Branch</h3>

        <form method="POST" action="{{ route('admin.branches.store') }}" class="flex flex-wrap gap-3 items-end">
            @csrf
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Name</label>
                <input class="w-full rounded-md px-3 py-2 text-sm" name="name" required
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <div>
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Code</label>
                <input class="w-full rounded-md px-3 py-2 text-sm" name="code" required
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            </div>
            <button type="submit" class="corex-btn-primary text-sm">Add Branch</button>
        </form>

        <div class="pt-4 space-y-2">
            <h4 class="text-sm font-semibold" style="color: var(--text-primary);">Existing Branches</h4>

            @forelse($branches as $branch)
                <div class="flex items-center justify-between gap-4 pb-2" style="border-bottom: 1px solid var(--border);">
                    <div class="font-medium" style="color: var(--text-primary);">
                        {{ $branch->name }} <span style="color: var(--text-muted);">({{ $branch->code }})</span>
                    </div>

                    <form method="POST" action="{{ route('admin.branches.delete', $branch) }}"
                          onsubmit="return confirm('Delete this branch? This cannot be undone.');">
                        @csrf
                        <button class="text-xs font-semibold" style="color: var(--ds-crimson);">Delete</button>
                    </form>
                </div>
            @empty
                <div class="rounded-md py-8 px-6 text-center text-sm" style="color: var(--text-muted);">
                    No branches yet. Add the first one above.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Branch Contact Details --}}
    <div class="rounded-md p-4 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div>
            <h3 class="ds-section-header">Branch Contact Details</h3>
            <div class="text-sm mt-1" style="color: var(--text-muted);">
                Leave blank to inherit from Agency settings.
            </div>
        </div>

        <div class="space-y-4">
            @foreach($branches as $branch)
                <form method="POST" action="{{ route('admin.branch-settings.update', $branch) }}"
                      enctype="multipart/form-data"
                      class="rounded-md p-4 space-y-4"
                      style="background: var(--surface-2); border: 1px solid var(--border);"
                      x-data="{ removelogo: false }">
                    @csrf

                    <div class="flex items-center justify-between gap-4">
                        <div class="font-semibold" style="color: var(--text-primary);">
                            {{ $branch->name }} <span style="color: var(--text-muted);">({{ $branch->code }})</span>
                        </div>
                        <button type="submit" class="corex-btn-primary text-sm">Save</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Trading Name Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="trading_name" value="{{ old('trading_name', $branch->trading_name) }}"
                                   placeholder="e.g. Johan and Elize Properties T/A">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Tagline Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="tagline" value="{{ old('tagline', $branch->tagline) }}"
                                   placeholder="e.g. THE MANDATE COMPANY">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Address Override</label>
                        <textarea name="address" rows="2"
                                  class="w-full rounded-md px-3 py-2 text-sm"
                                  style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                  placeholder="Physical address">{{ old('address', $branch->address) }}</textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Phone Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="phone" value="{{ old('phone', $branch->phone) }}"
                                   placeholder="e.g. 071 351 0291">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Secondary Cell Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="phone_secondary" value="{{ old('phone_secondary', $branch->phone_secondary) }}"
                                   placeholder="e.g. 079 495 5994">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Fax</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="fax" value="{{ old('fax', $branch->fax) }}"
                                   placeholder="e.g. 086 233 2395">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Email</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="email" value="{{ old('email', $branch->email) }}"
                                   placeholder="e.g. info@hfcoastal.co.za">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Registration No Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="reg_no" value="{{ old('reg_no', $branch->reg_no) }}"
                                   placeholder="e.g. 2009/228978/23">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">VAT No Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="vat_no" value="{{ old('vat_no', $branch->vat_no) }}"
                                   placeholder="e.g. 4870264498">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FFC No Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="ffc_no" value="{{ old('ffc_no', $branch->ffc_no) }}"
                                   placeholder="e.g. FFC40/43916/5">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">FIC No Override</label>
                            <input class="w-full rounded-md px-3 py-2 text-sm"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                   name="fic_no" value="{{ old('fic_no', $branch->fic_no) }}"
                                   placeholder="e.g. 58538">
                        </div>
                    </div>

                    {{-- Property24 Agency ID Override --}}
                    @php $parentP24 = $branch->agency?->p24_agency_id; @endphp
                    <div class="pt-4" style="border-top: 1px solid var(--border);">
                        <div class="text-xs font-bold uppercase tracking-wider mb-2" style="color: var(--text-secondary);">Property24 Syndication</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">P24 Agency ID Override</label>
                                <input class="w-full rounded-md px-3 py-2 text-sm font-mono"
                                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                                       name="p24_agency_id" value="{{ old('p24_agency_id', $branch->p24_agency_id) }}"
                                       placeholder="{{ $parentP24 ? 'inherits ' . $parentP24 : 'e.g. 31358' }}">
                                <p class="mt-1 text-xs" style="color: var(--text-muted);">
                                    @if($parentP24)
                                        Leave blank to use agency default: <span class="font-mono">{{ $parentP24 }}</span>.
                                    @else
                                        Agency has no default set. Enter this branch's P24 agency ID.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>

                    {{-- Logo --}}
                    <div class="pt-4 space-y-3" style="border-top: 1px solid var(--border);">
                        <div class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-secondary);">Branch Logo</div>
                        <p class="text-xs" style="color: var(--text-muted);">JPG, PNG, or WebP — max 1 GB. Leave blank to inherit Agency logo.</p>

                        @if($branch->logo_path)
                            <div class="flex items-center gap-4">
                                <img src="{{ asset('storage/' . $branch->logo_path) }}" alt="Branch logo"
                                     class="h-14 rounded-md p-1"
                                     style="background: var(--surface); border: 1px solid var(--border);">
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" name="remove_logo" value="1" id="remove_logo_{{ $branch->id }}" x-model="removelogo"
                                           class="w-4 h-4 rounded cursor-pointer">
                                    <label for="remove_logo_{{ $branch->id }}" class="text-xs cursor-pointer" style="color: var(--text-secondary);">Remove current logo</label>
                                </div>
                            </div>
                        @endif

                        <div x-show="!removelogo">
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp"
                                   class="w-full text-sm file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:text-white file:cursor-pointer"
                                   style="color: var(--text-secondary);">
                        </div>
                    </div>
                </form>
            @endforeach
        </div>
    </div>

</div>
@endsection
