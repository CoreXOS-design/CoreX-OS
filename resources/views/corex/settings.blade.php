@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ activeTab: '{{ $activeTab }}' }">

    {{-- Page header --}}
    <div style="background:#0b2a4a; border-radius:16px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Settings</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">System configuration and preferences.</div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 px-4 py-3 text-sm font-medium">
            {{ session('success') }}
        </div>
    @endif
    @if(session('status'))
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 px-4 py-3 text-sm font-medium">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 text-red-300 px-4 py-3 text-sm font-medium">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-500/30 bg-red-500/10 text-red-300 px-4 py-3 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- Tab container --}}
    <div style="background:#0d1f35; border:1px solid rgba(255,255,255,0.07); border-radius:16px; overflow:hidden;">

        {{-- Tab bar --}}
        <div class="flex overflow-x-auto" style="border-bottom:1px solid rgba(255,255,255,0.08);">
            @foreach([
                ['key'=>'agency',  'label'=>'Agency Settings'],
                ['key'=>'user',    'label'=>'User Settings'],
                ['key'=>'feature', 'label'=>'Feature Settings'],
                ['key'=>'system',  'label'=>'System Settings'],
            ] as $tab)
            <button type="button"
                    @click="activeTab = '{{ $tab['key'] }}'"
                    :class="activeTab === '{{ $tab['key'] }}' ? 'text-[#00b4d8] border-b-2 border-[#00b4d8] bg-[#00b4d8]/5' : 'text-white/50 border-b-2 border-transparent hover:text-white/80'"
                    class="px-6 py-4 text-sm font-semibold whitespace-nowrap flex-shrink-0 transition-colors duration-150 outline-none focus:outline-none"
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
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Structure</h3>
                <a href="{{ route('admin.branch-assignments') }}"
                   class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline group"
                   style="border:1px solid rgba(255,255,255,0.07);"
                   onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(14,165,233,0.15);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0ea5e9" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" /></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold text-white">Branch Assignments</div>
                        <div class="text-xs" style="color:rgba(255,255,255,0.4);">Manage branches and user assignments</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                </a>
            </div>

            {{-- Company Settings (inline form) --}}
            @if(isset($companyName))
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Company Settings</h3>
                <form method="POST" action="{{ route('admin.performance-settings.update') }}" enctype="multipart/form-data"
                      class="space-y-4 p-4 rounded-xl" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">Company Name</label>
                            <input type="text" name="company_name" value="{{ old('company_name', $companyName) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">FFC</label>
                            <input type="text" name="company_ffc" value="{{ old('company_ffc', $companyFfc) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">Address</label>
                            <input type="text" name="company_address" value="{{ old('company_address', $companyAddress) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">Telephone</label>
                            <input type="text" name="company_tel" value="{{ old('company_tel', $companyTel) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">VAT Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="vat_rate" value="{{ old('vat_rate', $vatRate) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">Listings per Sale</label>
                            <input type="number" step="0.01" min="0.01" name="listings_per_sale" value="{{ old('listings_per_sale', $listingsPerSale) }}"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            <p class="text-xs mt-1" style="color:rgba(255,255,255,0.3);">Used to calculate how many listings are needed for the target sales.</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.45);">Company Logo</label>
                            @if(!empty($companyLogoUrl))
                                <div class="mb-2 flex items-center gap-3">
                                    <img src="{{ $companyLogoUrl }}" alt="Company Logo" class="h-10 w-auto rounded border bg-white">
                                    <label class="inline-flex items-center gap-2 text-sm" style="color:rgba(255,255,255,0.6);">
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
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); color:rgba(255,255,255,0.7);">
                            <p class="text-xs mt-1" style="color:rgba(255,255,255,0.3);">Max 2MB. Upload replaces the current logo.</p>
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="submit" class="corex-btn-primary text-sm">Save Company Settings</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Agency Management (super admin only) --}}
            @if(auth()->user()?->isSuperAdmin())
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Super Admin</h3>
                <a href="{{ route('agencies.index') }}"
                   class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline"
                   style="border:1px solid rgba(255,255,255,0.07);"
                   onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(99,102,241,0.15);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#818cf8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    </div>
                    <div class="flex-1">
                        <div class="text-sm font-semibold text-white">Agency Management</div>
                        <div class="text-xs" style="color:rgba(255,255,255,0.4);">Create and manage agencies on the platform</div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
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
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Management</h3>
                <div class="space-y-2">
                    <a href="{{ route('admin.users') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline"
                       style="border:1px solid rgba(255,255,255,0.07);"
                       onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(34,197,94,0.15);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#22c55e" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-white">User Management</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.4);">Activate, deactivate, or remove users</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                    <a href="{{ route('corex.role-manager') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline"
                       style="border:1px solid rgba(255,255,255,0.07);"
                       onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.15);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-white">Role &amp; Permissions Manager</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.4);">Manage role-based access and user roles</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>
            </div>

            {{-- Designations (inline) --}}
            @can('manage_designations')
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Designations</h3>

                {{-- Add designation --}}
                <div class="p-4 rounded-xl mb-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                    <div class="text-xs font-semibold mb-3 text-white/60">Add Designation</div>
                    <form method="POST" action="{{ url('/admin/designations') }}"
                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        <div class="md:col-span-6">
                            <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Name</label>
                            <input name="name" required placeholder="e.g. Property Practitioner"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div class="md:col-span-3">
                            <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Sort order</label>
                            <input name="sort_order" type="number" step="1" min="0" placeholder="0"
                                   class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                   style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                        </div>
                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox" name="is_enabled" value="1" checked class="rounded">
                            <span class="text-sm" style="color:rgba(255,255,255,0.7);">Enabled</span>
                        </div>
                        <div class="md:col-span-1">
                            <button class="w-full corex-btn-primary text-sm">Add</button>
                        </div>
                    </form>
                </div>

                {{-- Designations list --}}
                <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(255,255,255,0.07);">
                    <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid rgba(255,255,255,0.07); background:rgba(255,255,255,0.02);">
                        <div class="text-sm font-semibold text-white">Current Designations</div>
                        <div class="text-xs" style="color:rgba(255,255,255,0.35);">{{ count($designations) }} total</div>
                    </div>
                    <div class="divide-y" style="--tw-divide-opacity:1; border-color:rgba(255,255,255,0.05);">
                        @forelse($designations as $d)
                        <div class="p-4" style="border-bottom:1px solid rgba(255,255,255,0.05);">
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf
                                <div class="md:col-span-6">
                                    <input name="name" value="{{ $d->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-3">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$d->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-2 flex items-center gap-2">
                                    <input type="hidden" name="is_enabled" value="0">
                                    <input type="checkbox" name="is_enabled" value="1" {{ $d->is_enabled ? 'checked' : '' }} class="rounded">
                                    <span class="text-sm" style="color:rgba(255,255,255,0.7);">Enabled</span>
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ url('/admin/designations/'.$d->id.'/delete') }}"
                                  onsubmit="return confirm('Delete this designation?');" class="mt-2">
                                @csrf
                                <button class="text-xs font-semibold text-red-400 hover:text-red-300">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:rgba(255,255,255,0.4);">No designations yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
            @endcan

        </div>

        {{-- ============================================================
             FEATURE SETTINGS TAB
             Contains: Documents (Docuperfect), Rentals
             ============================================================ --}}
        <div x-show="activeTab === 'feature'" x-cloak class="p-6 space-y-8"
             x-data="{ featureSection: 'documents' }">

            {{-- Feature sub-nav --}}
            <div class="flex gap-2 flex-wrap" style="border-bottom:1px solid rgba(255,255,255,0.07); padding-bottom:16px;">
                <button type="button"
                        @click="featureSection = 'documents'"
                        :class="featureSection === 'documents' ? 'bg-[#00b4d8]/15 text-[#00b4d8] border-[#00b4d8]/40' : 'text-white/50 border-white/10 hover:text-white/80'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Documents
                </button>
                <button type="button"
                        @click="featureSection = 'rentals'"
                        :class="featureSection === 'rentals' ? 'bg-[#00b4d8]/15 text-[#00b4d8] border-[#00b4d8]/40' : 'text-white/50 border-white/10 hover:text-white/80'"
                        class="px-4 py-2 rounded-lg text-sm font-semibold border transition-colors duration-150 outline-none"
                        style="background:transparent;">
                    Rentals
                </button>
            </div>

            {{-- DOCUMENTS section --}}
            <div x-show="featureSection === 'documents'" class="space-y-6">

                {{-- Document Types --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Document Types</h3>
                    <div class="p-4 rounded-xl mb-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                        <div class="text-xs font-semibold mb-3 text-white/60">Add Document Type</div>
                        <form method="POST" action="{{ route('docuperfect.settings.types.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-7">
                                <input name="name" required placeholder="e.g. Mandates"
                                       class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            </div>
                            <div class="md:col-span-3">
                                <input name="sort_order" type="number" step="1" min="0" placeholder="Sort order"
                                       class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            </div>
                            <div class="md:col-span-2">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(255,255,255,0.07);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid rgba(255,255,255,0.07); background:rgba(255,255,255,0.02);">
                            <div class="text-sm font-semibold text-white">Current Types</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.35);">{{ count($docTypes) }} total</div>
                        </div>
                        @forelse($docTypes as $type)
                        <div class="p-4" style="border-bottom:1px solid rgba(255,255,255,0.05);">
                            <form method="POST" action="{{ route('docuperfect.settings.types.update', $type->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf @method('PUT')
                                <div class="md:col-span-7">
                                    <input name="name" value="{{ $type->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-3">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$type->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-2">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <div class="flex items-center gap-4 mt-2">
                                <span class="text-xs" style="color:rgba(255,255,255,0.35);">{{ $type->templates()->count() }} template{{ $type->templates()->count() !== 1 ? 's' : '' }}</span>
                                <form method="POST" action="{{ route('docuperfect.settings.types.destroy', $type->id) }}"
                                      onsubmit="return confirm('Delete this document type?');">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-semibold text-red-400 hover:text-red-300"
                                            {{ $type->templates()->count() > 0 ? 'disabled title=Cannot delete — templates assigned' : '' }}>
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:rgba(255,255,255,0.4);">No document types yet.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Named Fields --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Named Fields</h3>
                    <div class="p-4 rounded-xl mb-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                        <div class="text-xs font-semibold mb-3 text-white/60">Add Named Field</div>
                        <form method="POST" action="{{ route('docuperfect.settings.namedFields.store') }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf
                            <div class="md:col-span-4">
                                <input name="name" required placeholder="e.g. Seller Name"
                                       class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            </div>
                            <div class="md:col-span-2">
                                <select name="field_type"
                                        class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                        style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                    <option value="text">Text</option>
                                    <option value="date">Date</option>
                                    <option value="selection">Selection</option>
                                </select>
                            </div>
                            <div class="md:col-span-3">
                                <input name="default_options" placeholder="Options (comma-separated)"
                                       class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            </div>
                            <div class="md:col-span-2">
                                <input name="sort_order" type="number" step="1" min="0" placeholder="Sort order"
                                       class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                       style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                            </div>
                            <div class="md:col-span-1">
                                <button class="w-full corex-btn-primary text-sm">Add</button>
                            </div>
                        </form>
                    </div>
                    <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(255,255,255,0.07);">
                        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom:1px solid rgba(255,255,255,0.07); background:rgba(255,255,255,0.02);">
                            <div class="text-sm font-semibold text-white">Current Named Fields</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.35);">{{ count($namedFields) }} total</div>
                        </div>
                        @forelse($namedFields as $field)
                        <div class="p-4" style="border-bottom:1px solid rgba(255,255,255,0.05);">
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.update', $field->id) }}"
                                  class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                                @csrf @method('PUT')
                                <div class="md:col-span-4">
                                    <input name="name" value="{{ $field->name }}" required
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-2">
                                    <select name="field_type"
                                            class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                        <option value="text" {{ $field->field_type === 'text' ? 'selected' : '' }}>Text</option>
                                        <option value="date" {{ $field->field_type === 'date' ? 'selected' : '' }}>Date</option>
                                        <option value="selection" {{ $field->field_type === 'selection' ? 'selected' : '' }}>Selection</option>
                                    </select>
                                </div>
                                <div class="md:col-span-3">
                                    <input name="default_options"
                                           value="{{ is_array($field->default_options) ? implode(', ', $field->default_options) : '' }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-2">
                                    <input name="sort_order" type="number" step="1" min="0" value="{{ (int)$field->sort_order }}"
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div class="md:col-span-1">
                                    <button class="w-full corex-btn-primary text-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.settings.namedFields.destroy', $field->id) }}"
                                  onsubmit="return confirm('Delete this named field?');" class="mt-2">
                                @csrf @method('DELETE')
                                <button class="text-xs font-semibold text-red-400 hover:text-red-300">Delete</button>
                            </form>
                        </div>
                        @empty
                        <div class="p-5 text-sm" style="color:rgba(255,255,255,0.4);">No named fields yet.</div>
                        @endforelse
                    </div>
                </div>

            </div>{{-- /documents --}}

            {{-- RENTALS section --}}
            <div x-show="featureSection === 'rentals'" x-cloak class="space-y-6">

                {{-- Rental Properties link (has sub-pages) --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Properties</h3>
                    <a href="{{ route('rental.settings.properties.index') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline"
                       style="border:1px solid rgba(255,255,255,0.07);"
                       onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(34,197,94,0.15);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#22c55e" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-white">Rental Properties</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.4);">Add and manage rental property listings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

                {{-- Rental Document Types (inline) --}}
                <div>
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Rental Document Types</h3>
                    <div class="space-y-2 mb-3" x-data="{ showAdd: false, editId: null }">
                        @foreach($rentalDocTypes as $rType)
                        <div class="flex items-center justify-between p-3 rounded-lg {{ !$rType->is_active ? 'opacity-50' : '' }}"
                             style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07);">
                            <div class="flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $rType->color }}"></span>
                                <span class="text-sm font-medium text-white">{{ $rType->name }}</span>
                                @if($rType->is_system)<span class="text-xs ml-1" style="color:rgba(255,255,255,0.3);">(system)</span>@endif
                                @if($rType->is_lease)<span class="text-xs bg-green-500/20 text-green-400 px-2 py-0.5 rounded ml-2">Lease</span>@endif
                                @if(!$rType->is_active)<span class="text-xs bg-white/10 text-white/40 px-2 py-0.5 rounded ml-2">Inactive</span>@endif
                            </div>
                            <div class="flex items-center gap-3">
                                <button @click="editId = editId === {{ $rType->id }} ? null : {{ $rType->id }}"
                                        class="text-xs text-blue-400 hover:text-blue-300">Edit</button>
                                @if(!$rType->is_system)
                                <form method="POST" action="{{ route('rental.settings.document-types.toggle', $rType) }}">
                                    @csrf
                                    <button type="submit" class="text-xs {{ $rType->is_active ? 'text-orange-400' : 'text-green-400' }}">
                                        {{ $rType->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                @endif
                            </div>
                        </div>
                        {{-- Inline edit --}}
                        <div x-show="editId === {{ $rType->id }}" x-cloak class="rounded-lg p-3"
                             style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2);">
                            <form method="POST" action="{{ route('rental.settings.document-types.update', $rType) }}"
                                  class="flex flex-wrap items-end gap-3">
                                @csrf @method('PUT')
                                <div class="flex-1 min-w-[180px]">
                                    <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Name</label>
                                    <input type="text" name="name" value="{{ $rType->name }}" required
                                           class="w-full rounded px-3 py-1.5 text-sm text-white"
                                           style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15);">
                                </div>
                                <div class="w-20">
                                    <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Color</label>
                                    <input type="color" name="color" value="{{ $rType->color }}"
                                           class="w-full h-8 rounded cursor-pointer border"
                                           style="border-color:rgba(255,255,255,0.15);">
                                </div>
                                <label class="flex items-center gap-1.5 text-sm text-white/70">
                                    <input type="checkbox" name="is_lease" value="1" {{ $rType->is_lease ? 'checked' : '' }} class="rounded">
                                    Lease
                                </label>
                                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                                <button type="button" @click="editId = null" class="px-3 py-1.5 text-sm text-white/50">Cancel</button>
                            </form>
                        </div>
                        @endforeach

                        {{-- Add new --}}
                        <div class="mt-2">
                            <button @click="showAdd = !showAdd"
                                    class="text-sm text-blue-400 hover:text-blue-300 font-medium">+ Add Document Type</button>
                            <div x-show="showAdd" x-cloak class="rounded-lg p-3 mt-2"
                                 style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2);">
                                <form method="POST" action="{{ route('rental.settings.document-types.store') }}"
                                      class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    <div class="flex-1 min-w-[180px]">
                                        <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Name *</label>
                                        <input type="text" name="name" required placeholder="e.g. Deposit Receipt"
                                               class="w-full rounded px-3 py-1.5 text-sm text-white"
                                               style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15);">
                                    </div>
                                    <div class="w-20">
                                        <label class="block text-xs mb-1" style="color:rgba(255,255,255,0.4);">Color</label>
                                        <input type="color" name="color" value="#6B7280" class="w-full h-8 rounded cursor-pointer border"
                                               style="border-color:rgba(255,255,255,0.15);">
                                    </div>
                                    <label class="flex items-center gap-1.5 text-sm text-white/70">
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
                    <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35);">Email Reminders</h3>
                    <form method="POST" action="{{ route('rental.settings.reminders.update') }}"
                          x-data="{
                              mode: '{{ old('mode', $rentalReminderSettings->mode) }}',
                              enabled: {{ old('enabled', $rentalReminderSettings->enabled) ? 'true' : 'false' }}
                          }"
                          class="space-y-4">
                        @csrf @method('PUT')

                        <div class="p-4 rounded-xl" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-white">Automatic Reminders</div>
                                    <div class="text-xs mt-0.5" style="color:rgba(255,255,255,0.4);">Send automatic email reminders for unsigned documents</div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="enabled" value="0">
                                    <input type="checkbox" name="enabled" value="1" x-model="enabled" class="sr-only peer" {{ $rentalReminderSettings->enabled ? 'checked' : '' }}>
                                    <div class="w-10 h-5 bg-white/20 peer-focus:ring-2 peer-focus:ring-blue-500/50 rounded-full peer peer-checked:bg-blue-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-5"></div>
                                </label>
                            </div>
                        </div>

                        <div x-show="enabled" x-cloak class="space-y-4">
                            <div class="p-4 rounded-xl" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                                <div class="text-sm font-semibold text-white mb-3">Reminder Mode</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <label :class="mode === 'escalating' ? 'border-blue-500 bg-blue-500/10' : 'border-white/10 hover:border-white/20'"
                                           class="border rounded-lg p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="escalating" x-model="mode" class="sr-only">
                                        <div class="font-medium text-white text-sm">Escalating</div>
                                        <div class="text-xs mt-1" style="color:rgba(255,255,255,0.4);">Gentle → Firm → Team Alert → Final</div>
                                    </label>
                                    <label :class="mode === 'simple' ? 'border-blue-500 bg-blue-500/10' : 'border-white/10 hover:border-white/20'"
                                           class="border rounded-lg p-3 cursor-pointer transition">
                                        <input type="radio" name="mode" value="simple" x-model="mode" class="sr-only">
                                        <div class="font-medium text-white text-sm">Simple Interval</div>
                                        <div class="text-xs mt-1" style="color:rgba(255,255,255,0.4);">Same reminder every N days</div>
                                    </label>
                                </div>
                            </div>

                            <div x-show="mode === 'escalating'" x-cloak class="p-4 rounded-xl space-y-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                                <div class="text-sm font-semibold text-white">Escalation Schedule</div>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach([
                                        ['key'=>'gentle_after_days','label'=>'Gentle reminder after (days)'],
                                        ['key'=>'firm_after_days','label'=>'Firm reminder after (days)'],
                                        ['key'=>'team_alert_after_days','label'=>'Team alert after (days)'],
                                        ['key'=>'final_after_days','label'=>'Final reminder after (days)'],
                                    ] as $rf)
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">{{ $rf['label'] }}</label>
                                        <input type="number" name="{{ $rf['key'] }}"
                                               value="{{ old($rf['key'], $rentalReminderSettings->{$rf['key']}) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                               style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                    </div>
                                    @endforeach
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">Max reminders per signer</label>
                                        <input type="number" name="max_escalating_reminders"
                                               value="{{ old('max_escalating_reminders', $rentalReminderSettings->max_escalating_reminders) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                               style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                    </div>
                                </div>
                            </div>

                            <div x-show="mode === 'simple'" x-cloak class="p-4 rounded-xl space-y-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                                <div class="text-sm font-semibold text-white">Simple Interval</div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">Send every (days)</label>
                                        <input type="number" name="interval_days"
                                               value="{{ old('interval_days', $rentalReminderSettings->interval_days) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                               style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">Max reminders per signer</label>
                                        <input type="number" name="max_simple_reminders"
                                               value="{{ old('max_simple_reminders', $rentalReminderSettings->max_simple_reminders) }}"
                                               class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                               style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 rounded-xl space-y-3" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                                <div class="text-sm font-semibold text-white">Custom Email Template</div>
                                <div class="flex flex-wrap gap-1 text-xs">
                                    @foreach(['{signer_name}','{document_name}','{agent_name}','{signing_link}','{days_waiting}'] as $ph)
                                    <code class="px-1.5 py-0.5 rounded font-mono" style="background:rgba(255,255,255,0.08); color:rgba(255,255,255,0.6);">{{ $ph }}</code>
                                    @endforeach
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">Subject</label>
                                    <input type="text" name="email_subject"
                                           value="{{ old('email_subject', $rentalReminderSettings->email_subject) }}"
                                           placeholder="e.g. Reminder: Please sign {document_name}"
                                           class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                           style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium mb-1" style="color:rgba(255,255,255,0.4);">Body</label>
                                    <textarea name="email_body" rows="5"
                                              class="w-full rounded-lg px-3 py-2 text-sm text-white"
                                              style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12);">{{ old('email_body', $rentalReminderSettings->email_body) }}</textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <button type="submit" class="corex-btn-primary text-sm">Save Reminder Settings</button>
                            @if($rentalReminderSettings->updatedByUser ?? null)
                            <span class="text-xs" style="color:rgba(255,255,255,0.35);">
                                Last updated by {{ $rentalReminderSettings->updatedByUser->name }}
                                on {{ $rentalReminderSettings->updated_at->format('d M Y H:i') }}
                            </span>
                            @endif
                        </div>
                    </form>
                </div>

            </div>{{-- /rentals --}}

        </div>

        {{-- ============================================================
             SYSTEM SETTINGS TAB
             Contains: General, P24 Suburbs, System Info
             ============================================================ --}}
        <div x-show="activeTab === 'system'" x-cloak class="p-6 space-y-6">

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- General --}}
                <div class="p-4 rounded-xl space-y-4" style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);">
                    <h3 class="text-xs font-bold uppercase tracking-widest" style="color:rgba(255,255,255,0.35); border-left:3px solid #00b4d8; padding-left:10px;">General</h3>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.4);">Application Name</label>
                        <input type="text" value="{{ config('app.name') }}" disabled
                               class="w-full rounded-lg px-3 py-2 text-sm cursor-not-allowed"
                               style="background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); color:rgba(255,255,255,0.4);">
                        <p class="text-xs mt-1" style="color:rgba(255,255,255,0.25);">Configured in environment settings.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.4);">Environment</label>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ config('app.env') === 'production' ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800' }}">
                            {{ config('app.env') }}
                        </span>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:rgba(255,255,255,0.4);">Debug Mode</label>
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold {{ config('app.debug') ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                            {{ config('app.debug') ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                </div>

                {{-- P24 Suburbs + quick links --}}
                <div class="space-y-2">
                    <a href="{{ route('admin.p24-suburbs.index') }}"
                       class="flex items-center gap-3 p-3 rounded-xl transition-colors duration-150 no-underline"
                       style="border:1px solid rgba(255,255,255,0.07);"
                       onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='transparent'">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(0,180,216,0.15);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00b4d8" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm font-semibold text-white">P24 Suburbs</div>
                            <div class="text-xs" style="color:rgba(255,255,255,0.4);">Manage Property24 suburb mappings</div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="rgba(255,255,255,0.2)" class="w-4 h-4 flex-shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                    </a>
                </div>

            </div>

            {{-- System Information --}}
            <div>
                <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color:rgba(255,255,255,0.35); border-left:3px solid #00b4d8; padding-left:10px;">System Information</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @foreach([
                        ['label'=>'Laravel','value'=>app()->version()],
                        ['label'=>'PHP','value'=>PHP_VERSION],
                        ['label'=>'Database','value'=>config('database.default')],
                        ['label'=>'Users','value'=>\App\Models\User::count()],
                    ] as $stat)
                    <div class="p-4 rounded-xl" style="background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.07);">
                        <div class="text-xs font-bold uppercase tracking-widest mb-2" style="color:rgba(255,255,255,0.3);">{{ $stat['label'] }}</div>
                        <div class="text-xl font-bold text-white">{{ $stat['value'] }}</div>
                    </div>
                    @endforeach
                </div>
            </div>

        </div>

    </div>{{-- /tab container --}}

</div>
@endsection
