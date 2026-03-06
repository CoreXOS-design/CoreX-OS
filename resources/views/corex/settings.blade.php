@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ activeTab: '{{ $activeTab }}' }">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Settings</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">System configuration and preferences.</div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('status'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border px-4 py-3 text-sm" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Tab container --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:16px; overflow:hidden;">

        {{-- Tab bar --}}
        <div class="flex overflow-x-auto" style="border-bottom:1px solid var(--border);">
            @foreach([
                ['key'=>'agency',  'label'=>'Agency Settings'],
                ['key'=>'user',    'label'=>'User Settings'],
                ['key'=>'feature', 'label'=>'Feature Settings'],
                ['key'=>'system',  'label'=>'System Settings'],
            ] as $tab)
            <button type="button"
                    @click="activeTab = '{{ $tab['key'] }}'"
                    :class="activeTab === '{{ $tab['key'] }}' ? 'text-[#00b4d8] border-b-2 border-[#00b4d8] bg-[#00b4d8]/5' : 'border-b-2 border-transparent'"
                    :style="activeTab !== '{{ $tab['key'] }}' ? 'color:var(--text-secondary);' : ''"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 transition-colors duration-150 outline-none focus:outline-none hover:opacity-80"
                    style="background:transparent;">
                {{ $tab['label'] }}
            </button>
            @endforeach
        </div>

        {{-- ============================================================
             AGENCY SETTINGS TAB
             Contains: Branch Assignments, Company Settings, Agency Mgmt
             ============================================================ --}}
        <div x-show="activeTab === 'agency'" class="p-6 space-y-6">

            {{-- Branch Assignments link --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Structure</h3>
                <a href="{{ route('admin.branch-assignments') }}"
                   class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline group hover:bg-black/[0.03]"
                   style="border:1px solid var(--border);">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(14,165,233,0.12);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0ea5e9" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" /></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Branch Assignments</div>
                        <div class="text-xs" style="color:var(--text-secondary);">Manage branches and user assignments</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            {{-- Company Settings (inline form) --}}
            @if(isset($companyName))
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Company Settings</h3>
                <form method="POST" action="{{ route('admin.performance-settings.update') }}" enctype="multipart/form-data"
                      class="space-y-4 p-4 rounded-xl" style="background:var(--surface-2); border:1px solid var(--border);">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Company Name</label>
                            <input type="text" name="company_name" value="{{ old('company_name', $companyName) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC</label>
                            <input type="text" name="company_ffc" value="{{ old('company_ffc', $companyFfc) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Address</label>
                            <input type="text" name="company_address" value="{{ old('company_address', $companyAddress) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Telephone</label>
                            <input type="text" name="company_tel" value="{{ old('company_tel', $companyTel) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">VAT Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="vat_rate" value="{{ old('vat_rate', $vatRate) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Listings per Sale</label>
                            <input type="number" step="0.01" min="0.01" name="listings_per_sale" value="{{ old('listings_per_sale', $listingsPerSale) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">Used to calculate how many listings are needed for the target sales.</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Company Logo</label>
                            @if(!empty($companyLogoUrl))
                                <div class="mb-2 flex items-center gap-3">
                                    <img src="{{ $companyLogoUrl }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white">
                                    <label class="inline-flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                        <input type="hidden" name="clear_company_logo" value="0">
                                        <input type="checkbox" name="clear_company_logo" value="1" class="rounded">
                                        Clear logo
                                    </label>
                                </div>
                            @else
                                <input type="hidden" name="clear_company_logo" value="0">
                            @endif
                            <input type="file" name="company_logo" accept="image/*"
                                   class="block w-full text-sm rounded-lg px-3 py-2"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-secondary);">
                            <p class="text-xs mt-1" style="color:var(--text-muted);">Max 2MB. Upload replaces the current logo.</p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="submit" class="corex-btn-primary text-sm">Save Company Settings</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Agency Management (owner role only) --}}
            @if(auth()->user()?->isOwnerRole())
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Super Admin</h3>
                <a href="{{ route('agencies.index') }}"
                   class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline hover:bg-black/[0.03]"
                   style="border:1px solid var(--border);">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(99,102,241,0.12);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#818cf8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Agency Management</div>
                        <div class="text-xs" style="color:var(--text-secondary);">Create and manage agencies on the platform</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>
            @endif

        </div>

        {{-- ============================================================
             USER SETTINGS TAB
             Contains: User Management, Roles & Permissions, Designations
             ============================================================ --}}
        <div x-show="activeTab === 'user'" x-cloak class="p-6 space-y-6">

            {{-- Links: User Mgmt + Roles --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Management</h3>
                <div class="space-y-2">
                    <a href="{{ route('admin.users') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline hover:bg-black/[0.03]"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(34,197,94,0.12);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#22c55e" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">User Management</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Activate, deactivate, or remove users</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                    <a href="{{ route('corex.role-manager') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline hover:bg-black/[0.03]"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.12);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Role &amp; Permissions Manager</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Manage role-based access and user roles</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
            </div>

            {{-- Designations (inline) --}}
            @permission('manage_designations')
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Designations</h3>

                {{-- Add designation --}}
                <div class="p-4 rounded-xl mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Designation</div>
                    <form method="POST" action="{{ url('/admin/designations') }}"
                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        <div class="md:col-span-6">
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                            <input name="name" required placeholder="e.g. Property Practitioner"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" checked class="rounded">
                            <span class="text-sm" style="color:var(--text-secondary);">Enabled</span>
                        </div>
                        <div class="md:col-span-1">
                            <button class="w-full corex-btn-primary text-sm">Add</button>
                        </div>
                    </form>
                </div>

                {{-- Designations list --}}
                <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Designations</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ count($designations) }} total</div>
                    </div>
                    <div>
                        @forelse($designations as $d)
                        <div class="p-4" style="border-bottom:1px solid var(--border);">
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf
                                <div class="md:col-span-6">
                                    <input name="name" value="{{ $d->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-3">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$d->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2 flex items-center gap-2">
                                    <input type="hidden" name="is_enabled" value="0">
                                    <input type="checkbox" name="is_enabled" value="1" {{ $d->is_enabled ? 'checked' : '' }} class="rounded">
                                    <span class="text-sm" style="color:var(--text-secondary);">Enabled</span>
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id.'/delete') }}"
                                  onsubmit="return confirm('Delete this designation?');" class="mt-2">
                                @csrf
                                <button class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:var(--text-muted);">No designations yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
            @endpermission

        </div>

        {{-- ============================================================
             FEATURE SETTINGS TAB
             Contains: Documents (Docuperfect), Rentals
             ============================================================ --}}
        <div x-show="activeTab === 'feature'" x-cloak class="p-6 space-y-8"
             x-data="{ featureSection: '{{ $featureSection }}' }">

            {{-- Feature sub-nav --}}
            <div class="flex gap-2 flex-wrap" style="border-bottom:1px solid var(--border); padding-bottom:16px;">
                <button type="button"
                        @click="featureSection = 'documents'"
                        :class="featureSection === 'documents' ? 'bg-[#00b4d8]/10 text-[#00b4d8] border-[#00b4d8]/40' : 'text-gray-400 border-gray-200 hover:text-gray-600 hover:border-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Documents
                </button>
                <button type="button"
                        @click="featureSection = 'rentals'"
                        :class="featureSection === 'rentals' ? 'bg-[#00b4d8]/10 text-[#00b4d8] border-[#00b4d8]/40' : 'text-gray-400 border-gray-200 hover:text-gray-600 hover:border-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Rentals
                </button>
                <button type="button"
                        @click="featureSection = 'contacts'"
                        :class="featureSection === 'contacts' ? 'bg-[#00b4d8]/10 text-[#00b4d8] border-[#00b4d8]/40' : 'text-gray-400 border-gray-200 hover:text-gray-600 hover:border-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Contacts
                </button>
                <button type="button"
                        @click="featureSection = 'properties'"
                        :class="featureSection === 'properties' ? 'bg-[#00b4d8]/10 text-[#00b4d8] border-[#00b4d8]/40' : 'text-gray-400 border-gray-200 hover:text-gray-600 hover:border-gray-300'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Properties
                </button>
            </div>

            {{-- DOCUMENTS section --}}
            <div x-show="featureSection === 'documents'" class="space-y-6">

                {{-- Document Types --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Document Types</h3>
                    <div class="p-4 rounded-xl mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Document Type</div>
                        <form method="POST" action="{{ route('docuperfect.settings.types.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-7">
                                <input name="name" required placeholder="e.g. Mandates"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-3">
                                <input name="sort_order" type="number" step="1" min="0" placeholder="Sort order"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Types</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ count($docTypes) }} total</div>
                        </div>
                        @forelse($docTypes as $type)
                        <div class="p-4" style="border-bottom:1px solid var(--border);">
                            <form method="POST" action="{{ route('docuperfect.settings.types.update', $type->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf @method('PUT')
                                <div class="md:col-span-7">
                                    <input name="name" value="{{ $type->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-3">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$type->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <div class="flex items-center gap-4 mt-2">
                                <span class="text-xs" style="color:var(--text-muted);">{{ $type->templates()->count() }} template{{ $type->templates()->count() !== 1 ? 's' : '' }}</span>
                                <form method="POST" action="{{ route('docuperfect.settings.types.destroy', $type->id) }}"
                                      onsubmit="return confirm('Delete this document type?');">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-red-600 hover:text-red-700"
                                            {{ $type->templates()->count() > 0 ? 'disabled title=Cannot delete — templates assigned' : '' }}>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:var(--text-muted);">No document types yet.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Named Fields --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Named Fields</h3>
                    <div class="p-4 rounded-xl mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Named Field</div>
                        <form method="POST" action="{{ route('docuperfect.settings.namedFields.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-4">
                                <input name="name" required placeholder="e.g. Seller Name"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <select name="field_type"
                                        class="w-full rounded-lg px-3 py-2 text-sm"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="text">Text</option>
                                    <option value="date">Date</option>
                                    <option value="selection">Selection</option>
                                </select>
                            </div>
                            <div class="md:col-span-3">
                                <input name="default_options" placeholder="Options (comma-separated)"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <input name="sort_order" type="number" step="1" min="0" placeholder="Sort order"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-1">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Named Fields</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ count($namedFields) }} total</div>
                        </div>
                        @forelse($namedFields as $field)
                        <div class="p-4" style="border-bottom:1px solid var(--border);">
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.update', $field->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf @method('PUT')
                                <div class="md:col-span-4">
                                    <input name="name" value="{{ $field->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2">
                                    <select name="field_type"
                                            class="w-full rounded-lg px-3 py-2 text-sm"
                                            style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        <option value="text" {{ $field->field_type === 'text' ? 'selected' : '' }}>Text</option>
                                        <option value="date" {{ $field->field_type === 'date' ? 'selected' : '' }}>Date</option>
                                        <option value="selection" {{ $field->field_type === 'selection' ? 'selected' : '' }}>Selection</option>
                                    </select>
                                </div>
                                <div class="md:col-span-3">
                                    <input name="default_options"
                                           value="{{ is_array($field->default_options) ? implode(', ', $field->default_options) : '' }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-2">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$field->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.destroy', $field->id) }}"
                                  onsubmit="return confirm('Delete this named field?');" class="mt-2">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:var(--text-muted);">No named fields yet.</div>
                        @endforelse
                    </div>
                </div>

            </div>{{-- /documents --}}

            {{-- RENTALS section --}}
            <div x-show="featureSection === 'rentals'" x-cloak class="space-y-6">

                {{-- Rental Properties link (has sub-pages) --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Properties</h3>
                    <a href="{{ route('rental.settings.properties.index') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline hover:bg-black/[0.03]"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(34,197,94,0.12);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#22c55e" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Rental Properties</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Add and manage rental property listings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

                {{-- Rental Document Types (inline) --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Rental Document Types</h3>
                    <div class="space-y-2 mb-3" x-data="{ showAdd: false, editId: null }">
                        @foreach($rentalDocTypes as $rType)
                        <div class="flex items-center justify-between p-3 rounded-lg {{ !$rType->is_active ? 'opacity-50' : '' }}"
                             style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $rType->color }}"></span>
                                <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $rType->name }}</span>
                                @if($rType->is_system)<span class="text-xs ml-1" style="color:var(--text-muted);">(system)</span>@endif
                                @if($rType->is_lease)<span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded ml-2">Lease</span>@endif
                                @if(!$rType->is_active)<span class="text-xs bg-gray-100 text-gray-400 px-2 py-0.5 rounded ml-2">Inactive</span>@endif
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="editId = editId === {{ $rType->id }} ? null : {{ $rType->id }}"
                                        class="text-xs text-blue-600 hover:text-blue-700">Edit</button>
                                @if(!$rType->is_system)
                                <form method="POST" action="{{ route('rental.settings.document-types.toggle', $rType) }}">
                                    @csrf
                                    <button type="submit" class="text-xs {{ $rType->is_active ? 'text-orange-600' : 'text-green-600' }}">
                                        {{ $rType->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        {{-- Inline edit --}}
                        <div x-show="editId === {{ $rType->id }}" x-cloak class="rounded-lg p-3"
                             style="background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2);">
                            <form method="POST" action="{{ route('rental.settings.document-types.update', $rType) }}"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf @method('PUT')
                                <div class="flex-1 min-w-[180px]">
                                    <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                    <input type="text" name="name" value="{{ $rType->name }}" required
                                           class="w-full rounded px-3 py-1.5 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div class="w-20">
                                    <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                    <input type="color" name="color" value="{{ $rType->color }}"
                                           class="w-full h-8 rounded cursor-pointer border"
                                           style="border-color:var(--border);">
                                </div>
                                <label class="flex items-center gap-1.5 text-sm" style="color:var(--text-secondary);">
                                    <input type="checkbox" name="is_lease" value="1" {{ $rType->is_lease ? 'checked' : '' }} class="rounded">
                                    Lease
                                </label>
                                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                <button type="button" @click="editId = null" class="px-3 py-1.5 text-sm" style="color:var(--text-muted);">Cancel</button>
                            </form>
                        </div>
                        @endforeach

                        {{-- Add new --}}
                        <div class="mt-2">
                            <button @click="showAdd = !showAdd"
                                    class="text-sm text-blue-600 hover:text-blue-700 font-medium">+ Add Document Type</button>
                            <div x-show="showAdd" x-cloak class="rounded-lg p-3 mt-2"
                                 style="background:rgba(34,197,94,0.06); border:1px solid rgba(34,197,94,0.2);">
                                <form method="POST" action="{{ route('rental.settings.document-types.store') }}"
                                      class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="flex-1 min-w-[180px]">
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Name *</label>
                                        <input type="text" name="name" required placeholder="e.g. Deposit Receipt"
                                               class="w-full rounded px-3 py-1.5 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                        <input type="color" name="color" value="#6B7280" class="w-full h-8 rounded cursor-pointer border"
                                               style="border-color:var(--border);">
                                    </div>
                                    <label class="flex items-center gap-1.5 text-sm" style="color:var(--text-secondary);">
                                        <input type="checkbox" name="is_lease" value="1" class="rounded">
                                        Lease
                                    </label>
                                    <button type="submit" class="px-3 py-1.5 bg-green-600 text-white text-sm rounded hover:bg-green-700">Add</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Rental Reminders (inline) --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Email Reminders</h3>
                    <form method="POST" action="{{ route('rental.settings.reminders.update') }}"
                          x-data="{
                              mode: '{{ old('mode', $rentalReminderSettings->mode) }}',
                              enabled: {{ old('enabled', $rentalReminderSettings->enabled) ? 'true' : 'false' }}
                          }"
                          class="space-y-4">
                        @csrf @method('PUT')

                        <div class="p-4 rounded-xl" style="background:var(--surface-2); border:1px solid var(--border);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Automatic Reminders</div>
                                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">Send automatic email reminders for unsigned documents</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only peer" {{ $rentalReminderSettings->enabled ? 'checked' : '' }}>
                                    <div class="w-10 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-blue-500/50 rounded-full peer peer-checked:bg-blue-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
                                </label>
                            </div>
                        </div>

                        <div x-show="enabled" x-cloak class="space-y-4">
                            <div class="p-4 rounded-xl" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold mb-3" style="color:var(--text-primary);">Reminder Mode</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <label :class="mode === 'escalating' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
                                           class="border rounded-lg p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="escalating" x-model="mode" class="sr-only">
                                        <div class="font-medium text-sm" style="color:var(--text-primary);">Escalating</div>
                                        <div class="text-xs mt-1" style="color:var(--text-secondary);">Gentle → Firm → Team Alert → Final</div>
                                    </label>
                                    <label :class="mode === 'simple' ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300'"
                                           class="border rounded-lg p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="simple" x-model="mode" class="sr-only">
                                        <div class="font-medium text-sm" style="color:var(--text-primary);">Simple Interval</div>
                                        <div class="text-xs mt-1" style="color:var(--text-secondary);">Same reminder every N days</div>
                                    </label>
                                </div>
                            </div>

                            <div x-show="mode === 'escalating'" x-cloak class="p-4 rounded-xl space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Escalation Schedule</div>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach([
                                        ['key'=>'gentle_after_days','label'=>'Gentle reminder after (days)'],
                                        ['key'=>'firm_after_days','label'=>'Firm reminder after (days)'],
                                        ['key'=>'team_alert_after_days','label'=>'Team alert after (days)'],
                                        ['key'=>'final_after_days','label'=>'Final reminder after (days)'],
                                    ] as $rf)
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">{{ $rf['label'] }}</label>
                                        <input type="number" name="{{ $rf['key'] }}"
                                               value="{{ old($rf['key'], $rentalReminderSettings->{$rf['key']}) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    @endforeach
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Max reminders per signer</label>
                                        <input type="number" name="max_escalating_reminders"
                                               value="{{ old('max_escalating_reminders', $rentalReminderSettings->max_escalating_reminders) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                </div>
                            </div>

                            <div x-show="mode === 'simple'" x-cloak class="p-4 rounded-xl space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Simple Interval</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Send every (days)</label>
                                        <input type="number" name="interval_days"
                                               value="{{ old('interval_days', $rentalReminderSettings->interval_days) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Max reminders per signer</label>
                                        <input type="number" name="max_simple_reminders"
                                               value="{{ old('max_simple_reminders', $rentalReminderSettings->max_simple_reminders) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl space-y-3" style="background:var(--surface-2); border:1px solid var(--border);">
                                <div class="text-sm font-semibold" style="color:var(--text-primary);">Custom Email Template</div>
                                <div class="flex flex-wrap gap-1 text-xs">
                                    @foreach(['{signer_name}','{document_name}','{agent_name}','{signing_link}','{days_waiting}'] as $ph)
                                    <code class="px-1.5 py-0.5 rounded font-mono" style="background:rgba(0,0,0,0.05); color:var(--text-secondary);">{{ $ph }}</code>
                                    @endforeach
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Subject</label>
                                    <input type="text" name="email_subject"
                                           value="{{ old('email_subject', $rentalReminderSettings->email_subject) }}"
                                           placeholder="e.g. Reminder: Please sign {document_name}"
                                           class="w-full rounded-lg px-3 py-2 text-sm"
                                           style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Body</label>
                                    <textarea name="email_body" rows="5"
                                              class="w-full rounded-lg px-3 py-2 text-sm"
                                              style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('email_body', $rentalReminderSettings->email_body) }}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <button type="submit" class="corex-btn-primary text-sm">Save Reminder Settings</button>
                            @if($rentalReminderSettings->updatedByUser ?? null)
                            <span class="text-xs" style="color:var(--text-muted);">
                                Last updated by {{ $rentalReminderSettings->updatedByUser->name }}
                                on {{ $rentalReminderSettings->updated_at->format('d M Y H:i') }}
                            </span>
                            @endif
                        </div>
                    </form>
                </div>

            </div>{{-- /rentals --}}

            {{-- CONTACTS section --}}
            <div x-show="featureSection === 'contacts'" x-cloak class="space-y-6">

                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted);">Contact Types</h3>
                    <p class="text-xs mb-4" style="color:var(--text-muted);">Types appear in the contact form when creating or editing a contact.</p>

                    {{-- Add Contact Type --}}
                    <div class="p-4 rounded-xl mb-3" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-semibold mb-3" style="color:var(--text-secondary);">Add Contact Type</div>
                        <form method="POST" action="{{ route('corex.settings.contact-types.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-6">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                <input name="name" required placeholder="e.g. Buyer, Seller, Tenant"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                <input type="color" name="color" value="#6366f1"
                                       class="w-full h-9 rounded-lg cursor-pointer border"
                                       style="border-color:var(--border); background:var(--surface);">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                                <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                       class="w-full rounded-lg px-3 py-2 text-sm"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="md:col-span-2">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>

                    {{-- Contact Types list --}}
                    <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border); background:var(--surface-2);">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">Current Types</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ count($contactTypes) }} total</div>
                        </div>
                        <div x-data="{ editCTId: null }">
                            @forelse($contactTypes as $cType)
                            <div style="border-bottom:1px solid var(--border);">
                                {{-- View row --}}
                                <div x-show="editCTId !== {{ $cType->id }}"
                                     class="p-4 flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-4 h-4 rounded-full flex-shrink-0"
                                              style="background-color: {{ $cType->color }}"></span>
                                        <span class="text-sm font-medium" style="color:var(--text-primary);">{{ $cType->name }}</span>
                                        <span class="text-xs" style="color:var(--text-muted);">{{ $cType->contacts()->count() }} contact{{ $cType->contacts()->count() !== 1 ? 's' : '' }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button @click="editCTId = {{ $cType->id }}"
                                                class="text-xs font-semibold text-[#00b4d8] hover:text-[#0091ae]">Edit</button>
                                        <form method="POST" action="{{ route('corex.settings.contact-types.destroy', $cType) }}"
                                              onsubmit="return confirm('Delete this contact type?');">
                                            @csrf @method('DELETE')
                                            <button class="text-xs font-semibold text-red-600 hover:text-red-700"
                                                    {{ $cType->contacts()->count() > 0 ? 'disabled title=Cannot delete — contacts assigned' : '' }}>
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                {{-- Edit row --}}
                                <div x-show="editCTId === {{ $cType->id }}" x-cloak
                                     class="p-4" style="background:rgba(0,180,216,0.05); border-top:1px solid rgba(0,180,216,0.15);">
                                    <form method="POST" action="{{ route('corex.settings.contact-types.update', $cType) }}"
                                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                        @csrf @method('PUT')
                                        <div class="md:col-span-6">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Name</label>
                                            <input name="name" value="{{ $cType->name }}" required
                                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Color</label>
                                            <input type="color" name="color" value="{{ $cType->color }}"
                                                   class="w-full h-9 rounded-lg cursor-pointer border"
                                                   style="border-color:var(--border); background:var(--surface);">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="block text-xs mb-1" style="color:var(--text-muted);">Sort order</label>
                                            <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$cType->sort_order }}"
                                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2 flex gap-2">
                                            <button type="submit" class="flex-1 corex-btn-primary text-sm">Save</button>
                                            <button type="button" @click="editCTId = null"
                                                    class="flex-1 text-sm rounded-lg"
                                                    style="border:1px solid var(--border); color:var(--text-secondary);">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @empty
                            <div class="p-5 text-sm" style="color:var(--text-muted);">No contact types yet. Add one above.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

            </div>{{-- /contacts --}}

            {{-- PROPERTIES section --}}
            <div x-show="featureSection === 'properties'" x-cloak class="space-y-3">

                @php
                $propGroups = [
                    ['key' => 'category',        'label' => 'Categories',        'items' => $propCategories,   'placeholder' => 'e.g. Residential, Commercial'],
                    ['key' => 'property_type',   'label' => 'Property Types',    'items' => $propTypes,        'placeholder' => 'e.g. House, Flat, Townhouse'],
                    ['key' => 'property_status', 'label' => 'Property Statuses', 'items' => $propStatuses,     'placeholder' => 'e.g. Active, Draft, Sold'],
                    ['key' => 'mandate_type',    'label' => 'Mandate Types',     'items' => $propMandateTypes, 'placeholder' => 'e.g. Sole, Joint, Open'],
                ];
                $reorderUrl  = route('corex.settings.property-items.reorder');
                $itemBaseUrl = url('corex/settings/property-items');
                $csrfToken   = csrf_token();
                @endphp

                @foreach($propGroups as $pg)
                @php
                    $defaultItems    = $pg['items']->where('is_default', true)->values();
                    $customItems     = $pg['items']->where('is_default', false)->values();
                    $hasDefaults     = $defaultItems->isNotEmpty();
                    $totalCount      = $pg['items']->count();
                    $batchToggleUrl  = route('corex.settings.property-items.batch-toggle', $pg['key']);

                    $defsJson = $defaultItems->map(fn($i) => [
                        'id' => $i->id, 'name' => $i->name,
                        'sort_order' => (int)$i->sort_order, 'active' => (bool)$i->active,
                    ])->toJson();
                    $custsJson = $customItems->map(fn($i) => [
                        'id' => $i->id, 'name' => $i->name,
                        'sort_order' => (int)$i->sort_order,
                    ])->toJson();
                @endphp

                <div x-data="{
                    open: false, addOpen: false,
                    editId: null, editName: '', editSort: 0,
                    defs:  {{ $defsJson }},
                    custs: {{ $custsJson }},
                    dragFrom: null, dragTarget: null,
                    reorderUrl:     '{{ $reorderUrl }}',
                    batchToggleUrl: '{{ $batchToggleUrl }}',
                    itemBaseUrl:    '{{ $itemBaseUrl }}',
                    csrf:           '{{ $csrfToken }}',
                    startDrag(idx, list) { this.dragFrom = { idx, list }; },
                    onOver(idx, list)    { if (this.dragFrom?.list === list) this.dragTarget = { idx, list }; },
                    drop(toIdx, list) {
                        if (!this.dragFrom || this.dragFrom.list !== list) { this.resetDrag(); return; }
                        const arr = list === 'd' ? this.defs : this.custs;
                        const fromIdx = this.dragFrom.idx;
                        if (fromIdx === toIdx) { this.resetDrag(); return; }
                        const a = [...arr];
                        const [m] = a.splice(fromIdx, 1);
                        a.splice(toIdx, 0, m);
                        list === 'd' ? (this.defs = a) : (this.custs = a);
                        this.resetDrag();
                        fetch(this.reorderUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            body: JSON.stringify({ items: a.map((it, i) => ({ id: it.id, sort_order: i })) })
                        });
                    },
                    resetDrag() { this.dragFrom = null; this.dragTarget = null; },
                    startEdit(item) { this.editId = item.id; this.editName = item.name; this.editSort = item.sort_order; },
                    saveDefaults() {
                        const f = document.createElement('form');
                        f.method = 'POST'; f.action = this.batchToggleUrl;
                        const t = document.createElement('input'); t.type='hidden'; t.name='_token'; t.value=this.csrf; f.appendChild(t);
                        this.defs.filter(d => d.active).forEach(d => {
                            const i = document.createElement('input'); i.type='hidden'; i.name='enabled_ids[]'; i.value=d.id; f.appendChild(i);
                        });
                        document.body.appendChild(f); f.submit();
                    },
                    isDragTarget(idx, list) {
                        return this.dragTarget?.idx === idx && this.dragTarget?.list === list && this.dragFrom?.list === list && this.dragFrom?.idx !== idx;
                    }
                }" class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">

                    {{-- Accordion header --}}
                    <button type="button" @click="open = !open"
                            class="w-full flex items-center justify-between px-4 py-3 transition-colors"
                            style="background:var(--surface-2);"
                            onmouseover="this.style.background='rgba(0,180,216,0.04)'"
                            onmouseout="this.style.background='var(--surface-2)'">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-semibold" style="color:var(--text-primary);">{{ $pg['label'] }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" style="background:rgba(0,180,216,0.12); color:#00b4d8;">{{ $totalCount }}</span>
                        </div>
                        <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--text-muted);"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>

                    {{-- Panel --}}
                    <div x-show="open" x-cloak style="border-top:1px solid var(--border);">

                        {{-- Add New --}}
                        <div style="border-bottom:1px solid var(--border);">
                            <button type="button" @click="addOpen = !addOpen"
                                    class="w-full flex items-center gap-2 px-4 py-2.5 text-sm font-medium transition-colors"
                                    style="color:#00b4d8; background:var(--surface);"
                                    onmouseover="this.style.background='rgba(0,180,216,0.04)'"
                                    onmouseout="this.style.background='var(--surface)'">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                                Add New
                                <svg class="w-3.5 h-3.5 ml-auto transition-transform" :class="addOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <div x-show="addOpen" x-cloak class="px-4 pb-4 pt-3" style="background:rgba(0,180,216,0.03); border-top:1px solid var(--border);">
                                <form method="POST" action="{{ route('corex.settings.property-items.store') }}"
                                      class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                    @csrf
                                    <input type="hidden" name="group" value="{{ $pg['key'] }}">
                                    <div class="md:col-span-7">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Name</label>
                                        <input name="name" required placeholder="{{ $pg['placeholder'] }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div class="md:col-span-3">
                                        <label class="block text-xs font-medium mb-1" style="color:var(--text-muted);">Sort Order</label>
                                        <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                               class="w-full rounded-lg px-3 py-2 text-sm"
                                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    </div>
                                    <div class="md:col-span-2">
                                        <button class="w-full corex-btn-primary text-sm">Add</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- Defaults list --}}
                        @if($hasDefaults)
                        <template x-for="(item, idx) in defs" :key="item.id">
                            <div :style="isDragTarget(idx,'d') ? 'border-top:2px solid #00b4d8; background:rgba(0,180,216,0.04);' : 'border-bottom:1px solid var(--border); background:var(--surface);'"
                                 @dragover.prevent="onOver(idx,'d')"
                                 @drop.prevent="drop(idx,'d')"
                                 @dragleave="dragTarget=null">
                                <div class="flex items-center gap-3 px-4 py-2.5 transition-opacity"
                                     :class="dragFrom?.idx===idx && dragFrom?.list==='d' ? 'opacity-30' : ''"
                                     draggable="true"
                                     @dragstart="startDrag(idx,'d')"
                                     @dragend="resetDrag()">
                                    {{-- Drag handle --}}
                                    <span class="cursor-grab flex-shrink-0" style="color:var(--text-muted);">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="7" cy="4" r="1.5"/><circle cx="13" cy="4" r="1.5"/><circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/><circle cx="7" cy="16" r="1.5"/><circle cx="13" cy="16" r="1.5"/></svg>
                                    </span>
                                    {{-- Toggle switch --}}
                                    <label class="relative flex-shrink-0 cursor-pointer" style="width:36px; height:20px; display:block;">
                                        <input type="checkbox" :checked="item.active" @change="item.active = !item.active" class="sr-only">
                                        <span class="block w-full h-full rounded-full transition-colors duration-200"
                                              :style="item.active ? 'background:#00b4d8' : 'background:var(--border-hover)'"></span>
                                        <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-all duration-200"
                                              :style="item.active ? 'transform:translateX(16px)' : 'transform:translateX(0)'"></span>
                                    </label>
                                    <span class="flex-1 text-sm font-medium" x-text="item.name" style="color:var(--text-primary);"></span>
                                    <span class="text-[10px] uppercase tracking-wide font-semibold" style="color:var(--text-muted);">Default</span>
                                </div>
                            </div>
                        </template>
                        {{-- Defaults save button --}}
                        <div class="flex items-center justify-between px-4 py-3" style="background:var(--surface-2); border-top:1px solid var(--border);">
                            <span class="text-xs" style="color:var(--text-muted);">Toggle on/off then save. Drag to reorder.</span>
                            <button type="button" @click="saveDefaults()" class="corex-btn-primary text-sm px-5">Save Changes</button>
                        </div>
                        @endif

                        {{-- Custom items (all groups) --}}
                        <template x-for="(item, idx) in custs" :key="item.id">
                            <div :style="isDragTarget(idx,'c') ? 'border-top:2px solid #00b4d8; background:rgba(0,180,216,0.04);' : 'border-bottom:1px solid var(--border);'"
                                 @dragover.prevent="onOver(idx,'c')"
                                 @drop.prevent="drop(idx,'c')"
                                 @dragleave="dragTarget=null">
                                {{-- View row --}}
                                <div x-show="editId !== item.id"
                                     class="flex items-center gap-3 px-4 py-2.5 transition-opacity"
                                     :class="dragFrom?.idx===idx && dragFrom?.list==='c' ? 'opacity-30' : ''"
                                     style="background:var(--surface);"
                                     draggable="true"
                                     @dragstart="startDrag(idx,'c')"
                                     @dragend="resetDrag()">
                                    <span class="cursor-grab flex-shrink-0" style="color:var(--text-muted);">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><circle cx="7" cy="4" r="1.5"/><circle cx="13" cy="4" r="1.5"/><circle cx="7" cy="10" r="1.5"/><circle cx="13" cy="10" r="1.5"/><circle cx="7" cy="16" r="1.5"/><circle cx="13" cy="16" r="1.5"/></svg>
                                    </span>
                                    <span class="flex-1 text-sm font-medium" x-text="item.name" style="color:var(--text-primary);"></span>
                                    <span class="text-xs tabular-nums" x-text="'#' + (idx+1)" style="color:var(--text-muted);"></span>
                                    <button type="button" @click="startEdit(item)"
                                            class="text-xs font-semibold" style="color:#00b4d8;"
                                            onmouseover="this.style.color='#0091ae'" onmouseout="this.style.color='#00b4d8'">Edit</button>
                                    <form :action="itemBaseUrl + '/' + item.id" method="POST"
                                          @submit.prevent="if(confirm('Delete \'' + item.name + '\'?')) $el.submit()">
                                        <input type="hidden" name="_token" :value="csrf">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="text-xs font-semibold" style="color:var(--text-muted);"
                                                onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='var(--text-muted)'">Delete</button>
                                    </form>
                                </div>
                                {{-- Edit row --}}
                                <div x-show="editId === item.id" x-cloak
                                     class="px-4 py-3" style="background:rgba(0,180,216,0.04); border-top:1px solid rgba(0,180,216,0.15);">
                                    <form :action="itemBaseUrl + '/' + item.id" method="POST"
                                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                        <input type="hidden" name="_token" :value="csrf">
                                        <input type="hidden" name="_method" value="PUT">
                                        <div class="md:col-span-7">
                                            <input name="name" :value="editName" required
                                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-3">
                                            <input name="sort_order" type="number" step="1" min="0" :value="editSort"
                                                   class="w-full rounded-lg px-3 py-2 text-sm"
                                                   style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                        </div>
                                        <div class="md:col-span-2 flex gap-2">
                                            <button type="submit" class="flex-1 corex-btn-primary text-sm">Save</button>
                                            <button type="button" @click="editId = null"
                                                    class="flex-1 text-sm rounded-lg"
                                                    style="border:1px solid var(--border); color:var(--text-secondary);">✕</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </template>

                        {{-- Empty state --}}
                        <div x-show="custs.length === 0" class="px-4 py-5 text-sm" style="color:var(--text-muted);">
                            @if($hasDefaults)
                            No custom {{ strtolower($pg['label']) }} yet — add one above.
                            @else
                            No {{ strtolower($pg['label']) }} yet — add one above.
                            @endif
                        </div>

                    </div>{{-- /panel --}}
                </div>
                @endforeach

            </div>{{-- /properties --}}

        </div>

        {{-- ============================================================
             SYSTEM SETTINGS TAB
             Contains: General, P24 Suburbs, System Info
             ============================================================ --}}
        <div x-show="activeTab === 'system'" x-cloak class="p-6 space-y-6">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- General --}}
                <div class="p-4 rounded-xl space-y-4" style="background:var(--surface-2); border:1px solid var(--border);">
                    <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted); border-left:3px solid #00b4d8; padding-left:10px;">General</h3>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Application Name</label>
                        <input type="text" value="{{ config('app.name') }}" disabled
                               class="w-full rounded-lg px-3 py-2 text-sm cursor-not-allowed"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-muted);">
                        <p class="text-xs mt-1" style="color:var(--text-muted);">Configured in environment settings.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Environment</label>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ config('app.env') === 'production' ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800' }}">
                            {{ config('app.env') }}
                        </span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Debug Mode</label>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ config('app.debug') ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                            {{ config('app.debug') ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                </div>

                {{-- P24 Suburbs + quick links --}}
                <div class="space-y-2">
                    <a href="{{ route('admin.p24-suburbs.index') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline hover:bg-black/[0.03]"
                       style="border:1px solid var(--border);">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.12);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">P24 Suburbs</div>
                            <div class="text-xs" style="color:var(--text-secondary);">Manage Property24 suburb mappings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" class="w-4 h-4 flex-shrink-0" style="color:var(--border-hover);"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

            </div>

            {{-- System Information --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:var(--text-muted); border-left:3px solid #00b4d8; padding-left:10px;">System Information</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach([
                        ['label'=>'Laravel','value'=>app()->version()],
                        ['label'=>'PHP','value'=>PHP_VERSION],
                        ['label'=>'Database','value'=>config('database.default')],
                        ['label'=>'Users','value'=>\App\Models\User::count()],
                    ] as $stat)
                    <div class="p-4 rounded-xl" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">{{ $stat['label'] }}</div>
                        <div class="text-xl font-bold" style="color:var(--text-primary);">{{ $stat['value'] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>

    </div>{{-- /tab container --}}

</div>
@endsection
