@extends('layouts.corex')

@php
    $photoUrl = $user->profilePhotoUrl();
    $overallColors = ['green' => '#00d4aa', 'amber' => '#f59e0b', 'red' => '#ef4444'];
    $overallColor = $overallColors[$complianceStatus['overall']] ?? '#64748b';
@endphp

@section('corex-content')
<div class="-m-4 lg:-m-6"
     x-data="{
        tab: (window.location.hash || '#overview').replace('#', ''),
        setTab(t) { this.tab = t; history.replaceState(null, '', '#' + t); }
     }"
     x-init="window.addEventListener('hashchange', () => tab = (window.location.hash || '#overview').replace('#', ''))">

    {{-- Sticky page header (Rule 5) --}}
    <x-page-header title="My Portal" :flush="true">
        <x-slot:actions>
            <span style="width:8px; height:8px; border-radius:50%; background:{{ $overallColor }}; display:inline-block;"></span>
            <span style="font-size:0.75rem; font-weight:600; color:{{ $overallColor }};">
                @if($complianceStatus['overall'] === 'green') Compliant
                @elseif($complianceStatus['overall'] === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                @else Action required @endif
            </span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <div class="max-w-5xl mx-auto space-y-4">

    {{-- Flash messages --}}
    @if(session('success'))
        <div style="border-radius:3px; border:1px solid #bbf7d0; background:rgba(0,212,170,0.08); color:#00d4aa; padding:10px 16px; font-size:0.8rem; font-weight:500;">{{ session('success') }}</div>
    @endif
    @if(session('status') === 'profile-updated')
        <div style="border-radius:3px; border:1px solid #bbf7d0; background:rgba(0,212,170,0.08); color:#00d4aa; padding:10px 16px; font-size:0.8rem; font-weight:500;">Profile updated successfully.</div>
    @endif
    @if(session('status') === 'password-updated')
        <div style="border-radius:3px; border:1px solid #bbf7d0; background:rgba(0,212,170,0.08); color:#00d4aa; padding:10px 16px; font-size:0.8rem; font-weight:500;">Password updated successfully.</div>
    @endif

    {{-- Tab navigation (fixed spacing) --}}
    <div style="border-bottom:1px solid var(--border);">
        <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
            @foreach([
                'overview' => 'Overview',
                'profile' => 'Profile',
                'documents' => 'Documents',
                'compliance' => 'Compliance',
                'training' => 'Training',
                'password' => 'Password',
            ] as $key => $label)
            <button type="button"
                    @click="setTab('{{ $key }}')"
                    :class="tab === '{{ $key }}'
                        ? 'border-[#00d4aa] text-[#00d4aa]'
                        : 'border-transparent hover:border-slate-300'"
                    class="whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 transition-colors"
                    :style="tab === '{{ $key }}'
                        ? 'color:#00d4aa; font-weight:700; font-family:Plus Jakarta Sans,sans-serif;'
                        : 'color:var(--text-muted); font-family:Plus Jakarta Sans,sans-serif;'">
                {{ $label }}
            </button>
            @endforeach
        </nav>
    </div>

    {{-- Agent subtitle strip --}}
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center gap-3 text-sm" style="color:var(--text-muted);">
            @if($photoUrl)
            <img src="{{ $photoUrl }}" alt="" style="width:24px; height:24px; object-fit:cover; border-radius:50%; border:1px solid var(--border);">
            @else
            <div style="width:24px; height:24px; border-radius:50%; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:0.55rem; font-weight:700; color:var(--text-muted); font-family:'Plus Jakarta Sans',sans-serif;">{{ $user->initials() }}</div>
            @endif
            <span style="color:var(--text-primary); font-weight:600;">{{ $user->name }}</span>
            <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
            <span>{{ $user->designation ?? 'No designation' }}</span>
            <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
            <span>{{ $user->branch?->name ?? 'No branch' }}</span>
        </div>
        @if($profilePercent < 100)
        <button type="button" @click="setTab('compliance')" class="flex items-center gap-2" style="background:none; border:none; cursor:pointer; padding:0;">
            <div style="width:80px; height:6px; border-radius:3px; background:var(--border); overflow:hidden;">
                <div style="height:100%; width:{{ $profilePercent }}%; background:#00d4aa; border-radius:3px;"></div>
            </div>
            <span style="font-size:0.7rem; font-weight:600; color:var(--text-muted);">{{ $profilePercent }}% complete</span>
        </button>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: OVERVIEW
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'overview'" x-cloak>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Earnings snapshot --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px;">
                <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">My Earnings</h3>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="p-3 rounded" style="background:var(--surface-2); border:1px solid var(--border); border-radius:3px;">
                        <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Month</div>
                        <div class="text-lg font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisMonthEarnings, 2) }}</div>
                    </div>
                    <div class="p-3 rounded" style="background:var(--surface-2); border:1px solid var(--border); border-radius:3px;">
                        <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Year</div>
                        <div class="text-lg font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisYearEarnings, 2) }}</div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs" style="color:var(--text-muted);">Cap Progress</span>
                        <span class="text-xs font-bold" style="color:{{ $isCapped ? '#f59e0b' : 'var(--text-primary)' }};">{{ $isCapped ? 'CAPPED' : $capPercent . '%' }}</span>
                    </div>
                    <div class="h-2 overflow-hidden" style="background:var(--border); border-radius:3px;">
                        <div class="h-full" style="width:{{ $capPercent }}%; background:{{ $isCapped ? '#f59e0b' : '#00d4aa' }}; border-radius:3px;"></div>
                    </div>
                </div>
                <a href="{{ route('commission.dashboard') }}" class="text-xs font-medium no-underline" style="color:#00d4aa;">View Full Earnings &rarr;</a>
            </div>

            {{-- Quick compliance card --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px;">
                <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Compliance Overview</h3>
                @php $dotColors = ['green' => '#00d4aa', 'amber' => '#f59e0b', 'red' => '#ef4444', 'grey' => '#64748b', 'missing' => '#64748b']; @endphp
                <div class="space-y-2">
                    @foreach([
                        'ffc_number' => 'FFC Number',
                        'ffc_certificate' => 'FFC Certificate',
                        'ffc_expiry' => 'FFC Expiry',
                        'id_copy' => 'ID Copy',
                        'pi_insurance' => 'PI Insurance',
                        'tax_clearance' => 'Tax Clearance',
                    ] as $key => $label)
                    @php $item = $complianceStatus[$key]; @endphp
                    <div class="flex items-center justify-between py-1.5 px-3" style="border:1px solid var(--border); border-radius:3px;">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] ?? '#64748b' }};"></span>
                            <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $label }}</span>
                        </div>
                        <span class="text-[10px]" style="color:{{ $dotColors[$item['status']] ?? '#64748b' }};">{{ $item['label'] }}</span>
                    </div>
                    @endforeach
                </div>
                <button @click="setTab('compliance')" class="mt-3 text-xs font-medium no-underline" style="color:#00d4aa; background:none; border:none; cursor:pointer; padding:0;">View full compliance &rarr;</button>
            </div>
        </div>

        {{-- Recent activity --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden; margin-top:16px;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Recent Activity</h3>
            </div>
            @if($recentActivity->isEmpty())
                <div class="p-6 text-center text-xs" style="color:var(--text-muted);">No commission entries yet.</div>
            @else
            <div class="divide-y" style="border-color:var(--border);">
                @foreach($recentActivity as $tx)
                <div class="flex items-center justify-between px-5 py-2.5">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="text-xs whitespace-nowrap" style="color:var(--text-muted);">{{ $tx->deal_date ? $tx->deal_date->format('d M') : $tx->created_at->format('d M') }}</span>
                        <span class="text-sm truncate" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($tx->description, 40) }}</span>
                    </div>
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($tx->net_agent_amount, 2) }}</span>
                        @php
                            $sBadge = match($tx->status) {
                                'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b'],
                                'confirmed' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6'],
                                'paid' => ['bg' => 'rgba(0,212,170,0.12)', 'color' => '#00d4aa'],
                                default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8'],
                            };
                        @endphp
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:{{ $sBadge['bg'] }}; color:{{ $sBadge['color'] }}; border-radius:3px;">{{ ucfirst($tx->status) }}</span>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: PROFILE
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'profile'" x-cloak>

        {{-- Profile photo upload --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-bottom:20px;">
            <div class="flex items-center gap-6">
                <div style="position:relative;">
                    @if($photoUrl)
                    <img src="{{ $photoUrl }}" alt="Profile photo"
                         style="width:80px; height:80px; object-fit:cover; border-radius:50%; border:2px solid var(--border);">
                    @else
                    <div style="width:80px; height:80px; border-radius:50%; background:var(--surface-2); border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.5rem; font-weight:700; color:var(--text-muted); font-family:'Plus Jakarta Sans',sans-serif;">
                        {{ $user->initials() }}
                    </div>
                    @endif
                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data" style="position:absolute; bottom:-4px; right:-4px;">
                        @csrf
                        <input type="hidden" name="document_type" value="photo">
                        <label style="width:28px; height:28px; border-radius:50%; background:#00d4aa; display:flex; align-items:center; justify-content:center; cursor:pointer; border:2px solid var(--surface);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#0f172a" style="width:14px; height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                            <input type="file" name="file" accept=".jpg,.jpeg,.png" class="hidden" onchange="this.closest('form').submit();">
                        </label>
                    </form>
                </div>
                <div>
                    <div class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Profile Photo</div>
                    <div class="text-xs" style="color:var(--text-muted);">JPG or PNG, max 10MB. Click the camera icon to upload.</div>
                </div>
            </div>
        </div>

        {{-- Profile form --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 20px; font-family:'Plus Jakarta Sans',sans-serif;">Profile Information</h3>

            <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

            <form method="post" action="{{ route('agent.portal.profile.update') }}">
                @csrf
                @method('patch')

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:560px;">
                    {{-- Name --}}
                    <div style="grid-column:span 2;">
                        <label for="name" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Name <span style="color:#ef4444;">*</span></label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('name') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Email --}}
                    <div style="grid-column:span 2;">
                        <label for="email" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Email <span style="color:#ef4444;">*</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('email') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div style="margin-top:6px;">
                            <p style="font-size:0.75rem; color:var(--text-secondary);">Your email is unverified.
                                <button form="send-verification" style="background:none; border:none; color:#00d4aa; text-decoration:underline; cursor:pointer; font-size:0.75rem; padding:0;">Re-send verification.</button>
                            </p>
                        </div>
                        @endif
                    </div>

                    {{-- Phone --}}
                    <div>
                        <label for="phone" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Phone</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" placeholder="Landline"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('phone') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Cell --}}
                    <div>
                        <label for="cell" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Cell <span style="color:#ef4444;">*</span></label>
                        <input id="cell" name="cell" type="tel" value="{{ old('cell', $user->cell) }}" placeholder="Mobile" required
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('cell') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Fax --}}
                    <div>
                        <label for="fax" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Fax</label>
                        <input id="fax" name="fax" type="tel" value="{{ old('fax', $user->fax) }}" placeholder="Fax number"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('fax') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Website --}}
                    <div>
                        <label for="website" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Website</label>
                        <input id="website" name="website" type="url" value="{{ old('website', $user->website) }}" placeholder="https://..."
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('website') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- ID Number --}}
                    <div>
                        <label for="id_number" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">ID Number</label>
                        <input id="id_number" name="id_number" type="text" value="{{ old('id_number', $user->id_number) }}" placeholder="SA ID number"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('id_number') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Number --}}
                    <div>
                        <label for="ffc_number" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">FFC Number</label>
                        <input id="ffc_number" name="ffc_number" type="text" value="{{ old('ffc_number', $user->ffc_number) }}" placeholder="FFC number"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('ffc_number') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Expiry Date --}}
                    <div>
                        <label for="ffc_expiry_date" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">FFC Expiry Date</label>
                        <input id="ffc_expiry_date" name="ffc_expiry_date" type="date" value="{{ old('ffc_expiry_date', $user->ffc_expiry_date?->format('Y-m-d')) }}"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('ffc_expiry_date') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Read-only admin fields --}}
                <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#64748b" style="width:14px; height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <span style="font-size:0.7rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Admin Managed</span>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:560px;">
                        <div>
                            <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Designation</div>
                            <div style="padding:9px 12px; border-radius:3px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem; opacity:0.7;">{{ $user->designation ?: 'Not set' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Role</div>
                            <div style="padding:9px 12px; border-radius:3px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem; opacity:0.7;">{{ ucfirst(str_replace('_', ' ', $user->role ?? 'agent')) }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Branch</div>
                            <div style="padding:9px 12px; border-radius:3px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem; opacity:0.7;">{{ $user->branch?->name ?? 'Not assigned' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Agency</div>
                            <div style="padding:9px 12px; border-radius:3px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem; opacity:0.7;">{{ $user->agency?->name ?? 'Not assigned' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">PPRA Status</div>
                            <div style="padding:9px 12px; border-radius:3px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.85rem; opacity:0.7;">{{ ucfirst($user->ppra_status ?? 'Not set') }}</div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" style="padding:9px 24px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all 200ms; font-family:'Plus Jakarta Sans',sans-serif;">Save Profile</button>
                </div>
            </form>
        </div>

        {{-- Theme Preference --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;"
             x-data="{ current: localStorage.getItem('corex-theme') || '{{ $user->theme ?? 'dark' }}' }">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 12px; font-family:'Plus Jakarta Sans',sans-serif;">Theme Preference</h3>
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <button type="button"
                        @click="current='dark'; document.documentElement.classList.add('dark'); localStorage.setItem('corex-theme','dark'); fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:'dark'})})"
                        :style="current === 'dark' ? 'border:2px solid #00d4aa;' : 'border:2px solid var(--border);'"
                        style="border-radius:3px; padding:12px 20px; cursor:pointer; display:flex; align-items:center; gap:10px; background:var(--surface-2); min-width:140px; transition:all 200ms;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#8890a4" style="width:18px; height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" /></svg>
                    <div style="text-align:left;"><div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">Dark</div></div>
                </button>
                <button type="button"
                        @click="current='light'; document.documentElement.classList.remove('dark'); localStorage.setItem('corex-theme','light'); fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:'light'})})"
                        :style="current === 'light' ? 'border:2px solid #00d4aa;' : 'border:2px solid var(--border);'"
                        style="border-radius:3px; padding:12px 20px; cursor:pointer; display:flex; align-items:center; gap:10px; background:var(--surface-2); min-width:140px; transition:all 200ms;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#64748B" style="width:18px; height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" /></svg>
                    <div style="text-align:left;"><div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">Light</div></div>
                </button>
            </div>
        </div>

        {{-- API Token --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;"
             x-data="{
                hasToken: {{ auth()->user()->api_token ? 'true' : 'false' }},
                plaintext: null, loading: false, copied: false,
                async generate() {
                    this.loading = true; this.copied = false;
                    try {
                        const res = await fetch('{{ route('corex.settings.generate-token') }}', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'} });
                        const data = await res.json(); this.plaintext = data.token; this.hasToken = true;
                    } finally { this.loading = false; }
                },
                copyToken() { navigator.clipboard.writeText(this.plaintext); this.copied = true; setTimeout(() => this.copied = false, 2000); }
             }">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 12px; font-family:'Plus Jakarta Sans',sans-serif;">API Token</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Used by the CoreX Chrome extension to authenticate with CoreX.</p>
            <template x-if="plaintext">
                <div>
                    <div style="background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.3); border-radius:3px; padding:10px 14px; margin-bottom:10px;">
                        <div style="font-size:0.75rem; font-weight:700; color:#f59e0b;">Copy this token now -- you won't see it again.</div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="text" readonly :value="plaintext" style="flex:1; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.75rem; font-family:monospace; box-sizing:border-box;">
                        <button @click="copyToken()" :style="copied ? 'background:#00d4aa;' : 'background:#00d4aa;'" style="padding:9px 16px; border-radius:3px; border:none; color:#0f172a; font-size:0.8rem; font-weight:600; cursor:pointer;"><span x-text="copied ? 'Copied!' : 'Copy'"></span></button>
                    </div>
                    <button @click="generate()" :disabled="loading" style="margin-top:8px; padding:7px 14px; border-radius:3px; border:1px solid var(--border); background:transparent; color:var(--text-secondary); font-size:0.75rem; cursor:pointer;"><span x-text="loading ? 'Generating...' : 'Regenerate'"></span></button>
                </div>
            </template>
            <template x-if="!plaintext">
                <div>
                    <template x-if="hasToken">
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <span style="padding:2px 8px; border-radius:3px; font-size:0.7rem; font-weight:600; background:rgba(0,212,170,0.12); color:#00d4aa;">Token active</span>
                                <span style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;">••••••••••</span>
                            </div>
                            <button @click="generate()" :disabled="loading" style="padding:9px 16px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:600; cursor:pointer;"><span x-text="loading ? 'Generating...' : 'Regenerate Token'"></span></button>
                        </div>
                    </template>
                    <template x-if="!hasToken">
                        <button @click="generate()" :disabled="loading" style="padding:9px 16px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:600; cursor:pointer;"><span x-text="loading ? 'Generating...' : 'Generate Token'"></span></button>
                    </template>
                </div>
            </template>
        </div>

        {{-- Chrome Extension --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 8px; font-family:'Plus Jakarta Sans',sans-serif;">CoreX Chrome Extension</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Pull properties from Property24 directly into CoreX.</p>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('corex.extension.download') }}" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border-radius:3px; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:600; text-decoration:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Download Extension
                </a>
                <a href="/downloads/portal-capture-extension.zip" style="display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border-radius:3px; background:#7c3aed; color:#fff; font-size:0.8rem; font-weight:600; text-decoration:none;" download>Portal Capture Extension</a>
            </div>
        </div>

        {{-- Social Media Accounts --}}
        @if(\Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
        @php
            $fbSocial = $socialAccounts->firstWhere('platform', 'facebook');
            $igSocial = $socialAccounts->firstWhere('platform', 'instagram');
        @endphp
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 12px; font-family:'Plus Jakarta Sans',sans-serif;">Social Media Accounts</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                {{-- Facebook --}}
                <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:3px; padding:14px;">
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:36px; height:36px; border-radius:3px; background:#1877f222; display:flex; align-items:center; justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1877f2" style="width:18px; height:18px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                        <div>
                            <div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">Facebook</div>
                            @if($fbSocial)
                            <span style="font-size:0.6rem; font-weight:700; padding:1px 6px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa;">Connected</span>
                            @else
                            <span style="font-size:0.6rem; font-weight:700; padding:1px 6px; border-radius:3px; background:rgba(148,163,184,0.12); color:var(--text-muted);">Not Connected</span>
                            @endif
                        </div>
                    </div>
                    @if($fbSocial)
                    <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">@csrf<input type="hidden" name="platform" value="facebook">
                        <button type="submit" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); cursor:pointer;">Disconnect</button>
                    </form>
                    @else
                    <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'facebook']) }}" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:#1877f2; color:#fff; text-decoration:none; font-weight:600;">Connect</a>
                    @endif
                </div>
                {{-- Instagram --}}
                <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:3px; padding:14px;">
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:36px; height:36px; border-radius:3px; background:#e1306c22; display:flex; align-items:center; justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e1306c" style="width:18px; height:18px;"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                        </div>
                        <div>
                            <div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">Instagram</div>
                            @if($igSocial)
                            <span style="font-size:0.6rem; font-weight:700; padding:1px 6px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa;">Connected</span>
                            @else
                            <span style="font-size:0.6rem; font-weight:700; padding:1px 6px; border-radius:3px; background:rgba(148,163,184,0.12); color:var(--text-muted);">Not Connected</span>
                            @endif
                        </div>
                    </div>
                    @if($igSocial)
                    <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">@csrf<input type="hidden" name="platform" value="instagram">
                        <button type="submit" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); cursor:pointer;">Disconnect</button>
                    </form>
                    @else
                    <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'instagram']) }}" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color:#fff; text-decoration:none; font-weight:600;">Connect</a>
                    @endif
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: DOCUMENTS
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'documents'" x-cloak>
        @php
            $docTypeConfig = [
                ['type' => 'ffc_certificate', 'label' => 'FFC Certificate', 'has_expiry' => true,
                 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>'],
                ['type' => 'id_copy', 'label' => 'ID Copy', 'has_expiry' => false,
                 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>'],
                ['type' => 'pi_insurance', 'label' => 'PI Insurance', 'has_expiry' => true,
                 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>'],
                ['type' => 'tax_clearance', 'label' => 'Tax Clearance', 'has_expiry' => true,
                 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>'],
                ['type' => 'photo', 'label' => 'Profile Photo', 'has_expiry' => false,
                 'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>'],
            ];

            $docTypeToKey = ['ffc_certificate' => 'ffc_certificate', 'id_copy' => 'id_copy', 'pi_insurance' => 'pi_insurance', 'tax_clearance' => 'tax_clearance', 'photo' => 'profile_photo'];
            $statusPills = [
                'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b', 'text' => 'Pending verification'],
                'verified' => ['bg' => 'rgba(0,212,170,0.12)', 'color' => '#00d4aa', 'text' => 'Verified'],
                'rejected' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'text' => 'Rejected'],
                'expired' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'text' => 'Expired'],
                'missing' => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#64748b', 'text' => 'Missing'],
            ];
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($docTypeConfig as $docCfg)
            @php
                $docKey = $docTypeToKey[$docCfg['type']] ?? $docCfg['type'];
                $doc = $documents->get($docKey);
                $docStatus = $doc ? $doc->status : 'missing';
                $pill = $statusPills[$docStatus];
            @endphp
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 18px; display:flex; flex-direction:column; gap:10px;">
                {{-- Header --}}
                <div class="flex items-center gap-3">
                    <div style="color:var(--text-muted); flex-shrink:0;">{!! $docCfg['icon'] !!}</div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.8rem; font-weight:700; color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">{{ $docCfg['label'] }}</div>
                        <span style="display:inline-block; font-size:0.6rem; font-weight:600; padding:1px 6px; border-radius:3px; background:{{ $pill['bg'] }}; color:{{ $pill['color'] }}; margin-top:2px;">{{ $pill['text'] }}</span>
                    </div>
                </div>

                {{-- File info --}}
                @if($doc)
                <div style="font-size:0.7rem; color:var(--text-muted);">
                    {{ $doc->file_name }} &middot; {{ $doc->created_at->format('d M Y') }}
                </div>
                @if($doc->expiry_date)
                @php $daysLeft = (int) now()->diffInDays($doc->expiry_date, false); @endphp
                <div style="font-size:0.7rem; color:{{ $daysLeft <= 30 ? '#ef4444' : ($daysLeft <= 60 ? '#f59e0b' : '#00d4aa') }};">
                    Expires {{ $doc->expiry_date->format('d M Y') }} ({{ $daysLeft > 0 ? "in {$daysLeft} days" : 'EXPIRED' }})
                </div>
                @endif
                @if($docStatus === 'rejected' && $doc->rejected_reason)
                <div style="font-size:0.7rem; color:#ef4444; background:rgba(239,68,68,0.06); padding:6px 8px; border-radius:3px;">{{ $doc->rejected_reason }}</div>
                @endif
                @endif

                {{-- Actions --}}
                <div class="flex items-center gap-2 mt-auto">
                    @if($doc)
                    <a href="{{ asset('storage/' . $doc->file_path) }}" target="_blank" style="font-size:0.7rem; padding:4px 10px; border-radius:3px; border:1px solid var(--border); color:var(--text-muted); text-decoration:none;">View</a>
                    @endif

                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data" class="flex items-center gap-1"
                          x-data="{ fileName: '' }">
                        @csrf
                        <input type="hidden" name="document_type" value="{{ $docCfg['type'] }}">
                        @if($docCfg['has_expiry'])
                        <input type="date" name="expiry_date" placeholder="Expiry" title="Document expiry date"
                               style="font-size:0.65rem; padding:4px 6px; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); width:120px;">
                        @endif
                        <label style="font-size:0.7rem; padding:4px 10px; border-radius:3px; cursor:pointer; background:rgba(0,212,170,0.12); color:#00d4aa; border:1px solid rgba(0,212,170,0.25); white-space:nowrap;">
                            {{ $doc ? 'Replace' : 'Upload' }}
                            <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden"
                                   @change="fileName = $event.target.files[0]?.name || ''; if(fileName) $el.closest('form').submit();">
                        </label>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: COMPLIANCE
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'compliance'" x-cloak>

        {{-- Overall status card --}}
        @php $overallColor = $overallColors[$complianceStatus['overall']] ?? '#64748b'; @endphp
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:24px; margin-bottom:20px; text-align:center;">
            <div style="width:48px; height:48px; border-radius:50%; background:{{ $overallColor }}20; margin:0 auto 12px; display:flex; align-items:center; justify-content:center;">
                <span style="width:20px; height:20px; border-radius:50%; background:{{ $overallColor }}; display:block;"></span>
            </div>
            <div style="font-size:1rem; font-weight:800; color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">
                @if($complianceStatus['overall'] === 'green') Your compliance is up to date
                @elseif($complianceStatus['overall'] === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                @else Action required @endif
            </div>
        </div>

        {{-- Breakdown list --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Compliance Breakdown</h3>
            </div>

            @php
                $complianceItems = [
                    ['key' => 'ffc_number', 'label' => 'FFC Number', 'action_tab' => 'profile', 'action_text' => 'Update in Profile'],
                    ['key' => 'ffc_certificate', 'label' => 'FFC Certificate', 'action_tab' => 'documents', 'action_text' => 'Upload document'],
                    ['key' => 'ffc_expiry', 'label' => 'FFC Expiry Date', 'action_tab' => 'profile', 'action_text' => 'Set expiry date'],
                    ['key' => 'id_copy', 'label' => 'ID Copy', 'action_tab' => 'documents', 'action_text' => 'Upload document'],
                    ['key' => 'pi_insurance', 'label' => 'PI Insurance', 'action_tab' => 'documents', 'action_text' => 'Upload document'],
                    ['key' => 'tax_clearance', 'label' => 'Tax Clearance', 'action_tab' => 'documents', 'action_text' => 'Upload document'],
                    ['key' => 'rmcp_acknowledged', 'label' => 'FICA Training & RMCP Acknowledgement', 'action_tab' => null, 'action_text' => 'Acknowledge RMCP', 'action_route' => true],
                    ['key' => 'employee_screening', 'label' => 'Employee Screening', 'action_tab' => null, 'action_text' => 'View records', 'action_route' => 'screening'],
                ];
            @endphp

            <div class="divide-y" style="border-color:var(--border);">
                @foreach($complianceItems as $ci)
                @php $ciData = $complianceStatus[$ci['key']]; @endphp
                <div class="flex items-center justify-between px-5 py-3">
                    <div class="flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$ciData['status']] ?? '#64748b' }};"></span>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $ci['label'] }}</div>
                            <div class="text-[10px]" style="color:var(--text-muted);">{{ $ciData['label'] }}</div>
                            @if(!empty($ciData['override']))
                            <div class="text-[9px] mt-0.5" style="color:var(--text-muted);">Set by {{ $ciData['override_by'] ?? 'Admin' }}{{ !empty($ciData['override_date']) ? ' on ' . $ciData['override_date'] : '' }}</div>
                            @endif
                            @if(!empty($ciData['admin_upload']))
                            <div class="text-[9px] mt-0.5" style="color:var(--text-muted);">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 inline-block" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                                Admin verified
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @php
                            $badgeLabel = $ciData['status'] === 'grey' ? 'Override' : ucfirst($ciData['status']);
                        @endphp
                        <span style="display:inline-block; font-size:0.6rem; font-weight:600; padding:2px 8px; border-radius:3px; background:{{ $dotColors[$ciData['status']] ?? '#64748b' }}1a; color:{{ $dotColors[$ciData['status']] ?? '#64748b' }};">{{ $badgeLabel }}</span>
                        @if(!in_array($ciData['status'], ['green', 'grey']))
                            @if(!empty($ci['action_route']) && $ci['key'] === 'rmcp_acknowledged')
                                @if($rmcpAckStatus === 'in_progress')
                                <a href="{{ route('rmcp.ack.step', 1) }}" style="font-size:0.65rem; padding:3px 8px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; text-decoration:none; font-weight:600;">Continue</a>
                                @else
                                <form method="POST" action="{{ route('rmcp.ack.start') }}" style="display:inline;">@csrf
                                <button type="submit" style="font-size:0.65rem; padding:3px 8px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; border:none; cursor:pointer; font-weight:600;">{{ $ci['action_text'] }}</button>
                                </form>
                                @endif
                            @elseif(!empty($ci['action_route']) && $ci['action_route'] === 'screening')
                                <a href="{{ route('compliance.screenings.my') }}" style="font-size:0.65rem; padding:3px 8px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; text-decoration:none; font-weight:600;">{{ $ci['action_text'] }}</a>
                            @else
                            <button @click="setTab('{{ $ci['action_tab'] }}')" style="font-size:0.65rem; padding:3px 8px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; border:none; cursor:pointer;">{{ $ci['action_text'] }}</button>
                            @endif
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: TRAINING
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'training'" x-cloak>
        {{-- RMCP Acknowledgement — primary card --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$rmcpStatus] }};"></span>
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">RMCP Acknowledgement</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $rmcpLabel }}</div>
                    </div>
                </div>
                @if($rmcpAckStatus === 'valid' && $rmcpAck)
                <a href="{{ route('rmcp.ack.receipt', $rmcpAck) }}" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; text-decoration:none; font-weight:600;">View Receipt</a>
                @elseif($rmcpAckStatus === 'in_progress')
                <a href="{{ route('rmcp.ack.step', 1) }}" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(234,179,8,0.12); color:#eab308; text-decoration:none; font-weight:600;">Continue</a>
                @elseif(in_array($rmcpAckStatus, ['not_started', 'expired']))
                <form method="POST" action="{{ route('rmcp.ack.start') }}" style="display:inline;">@csrf
                <button type="submit" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; border:none; cursor:pointer; font-weight:600;">Start Acknowledgement</button>
                </form>
                @endif
            </div>
        </div>

        {{-- Other training courses --}}
        @if($trainingItems->isNotEmpty())
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 16px; font-family:'Plus Jakarta Sans',sans-serif;">Other Training</h3>
            <div class="space-y-3">
                @foreach($trainingItems as $item)
                <div class="flex items-center justify-between py-3 px-4" style="border:1px solid var(--border); border-radius:3px;">
                    <div class="flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] }};"></span>
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $item['title'] }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $item['label'] }}</div>
                        </div>
                    </div>
                    @if($item['status'] !== 'green')
                    <a href="{{ route('training.show', $item['id']) }}" style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; text-decoration:none; font-weight:600;">Continue</a>
                    @else
                    <span style="font-size:0.7rem; padding:5px 12px; border-radius:3px; background:rgba(0,212,170,0.12); color:#00d4aa; font-weight:600;">Completed</span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: PASSWORD
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'password'" x-cloak>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 6px; font-family:'Plus Jakarta Sans',sans-serif;">Update Password</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 20px;">Ensure your account is using a long, random password to stay secure.</p>

            <form method="post" action="{{ route('password.update') }}">
                @csrf
                @method('put')

                <div style="display:flex; flex-direction:column; gap:16px; max-width:400px;">
                    <div>
                        <label for="update_password_current_password" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Current Password</label>
                        <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('current_password', 'updatePassword') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">New Password</label>
                        <input id="update_password_password" name="password" type="password" autocomplete="new-password"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('password', 'updatePassword') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password_confirmation" style="display:block; font-size:0.7rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Confirm Password</label>
                        <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                               style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='#00d4aa'" onblur="this.style.borderColor='var(--border)'">
                        @error('password_confirmation', 'updatePassword') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <button type="submit" style="padding:9px 24px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:700; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">Update Password</button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Delete Account --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:20px 24px; margin-top:20px;" x-data="{ confirmDelete: false }">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 6px; font-family:'Plus Jakarta Sans',sans-serif; border-left:3px solid #ef4444; padding-left:12px;">Delete Account</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 16px;">Once your account is deleted, all of its resources and data will be permanently deleted.</p>

            <button @click="confirmDelete = true" x-show="!confirmDelete" style="padding:8px 16px; border-radius:3px; border:none; background:#ef4444; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">Delete Account</button>

            <div x-show="confirmDelete" x-cloak x-transition style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:3px; padding:16px; max-width:400px;">
                <p style="font-size:0.8rem; font-weight:600; color:#ef4444; margin:0 0 4px;">Are you sure?</p>
                <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Enter your password to confirm.</p>
                <form method="post" action="{{ route('profile.destroy') }}">
                    @csrf
                    @method('delete')
                    <input name="password" type="password" placeholder="Password"
                           style="width:100%; max-width:260px; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.85rem; box-sizing:border-box; margin-bottom:10px;">
                    @error('password', 'userDeletion') <p style="font-size:0.7rem; color:#ef4444; margin-bottom:8px;">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button type="button" @click="confirmDelete = false" style="padding:7px 14px; border-radius:3px; border:1px solid var(--border); background:transparent; color:var(--text-secondary); font-size:0.8rem; cursor:pointer;">Cancel</button>
                        <button type="submit" style="padding:7px 14px; border-radius:3px; border:none; background:#ef4444; color:#fff; font-size:0.8rem; font-weight:600; cursor:pointer;">Confirm Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

        </div>{{-- .max-w-5xl --}}
    </div>{{-- .p-4 --}}
</div>{{-- .-m-4 x-data --}}
@endsection
