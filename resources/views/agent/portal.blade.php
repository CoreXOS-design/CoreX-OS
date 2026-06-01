{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $photoUrl = $user->profilePhotoUrl();
    $overallColors = ['green' => 'var(--ds-green)', 'amber' => 'var(--ds-amber)', 'red' => 'var(--ds-crimson)'];
    $overallColor = $overallColors[$complianceStatus['overall']] ?? 'var(--text-muted)';
@endphp

@section('corex-content')
<div class="space-y-5"
     x-data="{
        tab: (window.location.hash || '#overview').replace('#', ''),
        setTab(t) { this.tab = t; history.replaceState(null, '', '#' + t); }
     }"
     x-init="window.addEventListener('hashchange', () => tab = (window.location.hash || '#overview').replace('#', ''))">

    {{-- Page header (Pattern A — branded, matches Contacts / Core Matches) --}}
    <div class="rounded-md px-6 py-5" style="background:var(--brand-default,#0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">My Portal</h1>
                <p class="text-sm text-white/60">Your profile, documents, compliance, training and earnings in one place.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1.5"
                      style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18);">
                    <span style="width:8px; height:8px; border-radius:50%; background:{{ $overallColor }}; display:inline-block;"></span>
                    <span class="text-xs font-semibold text-white">
                        @if($complianceStatus['overall'] === 'green') Compliant
                        @elseif($complianceStatus['overall'] === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                        @else Action required @endif
                    </span>
                </span>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?section=my-portal&s=my-portal') }}"
                   title="My Portal Settings"
                   aria-label="My Portal Settings"
                   class="inline-flex items-center justify-center rounded-md text-white transition-colors"
                   style="width:30px; height:30px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.18);"
                   onmouseover="this.style.background='rgba(255,255,255,0.18)'"
                   onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </a>
                @endpermission
            </div>
        </div>
    </div>

    <div>
        <div class="max-w-5xl mx-auto space-y-4">

    {{-- Flash messages (alert block §3.9) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('status') === 'profile-updated')
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">Profile updated successfully.</div>
    @endif
    @if(session('status') === 'password-updated')
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">Password updated successfully.</div>
    @endif

    {{-- Tab navigation (fixed spacing) --}}
    <div style="border-bottom:1px solid var(--border);">
        <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
            @php
                $portalTabs = [
                    'overview' => 'Overview',
                    'profile' => 'Profile',
                    'documents' => 'Documents',
                    'compliance' => 'Compliance',
                    'training' => 'Training',
                    'password' => 'Password',
                ];
                if (auth()->user()->hasPermission('view_own_payslips')) {
                    $portalTabs['payslips'] = 'Payslips';
                }
                if (auth()->user()->hasPermission('apply_for_leave')) {
                    $portalTabs['leave'] = 'Leave';
                }
            @endphp
            @foreach($portalTabs as $key => $label)
            <button type="button"
                    @click="setTab('{{ $key }}')"
                    class="whitespace-nowrap px-4 py-3 text-sm border-b-2 transition-colors"
                    :style="tab === '{{ $key }}'
                        ? 'color:var(--brand-icon); font-weight:600; border-bottom-color:var(--brand-icon);'
                        : 'color:var(--text-muted); font-weight:500; border-bottom-color:transparent;'">
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
            <div style="width:24px; height:24px; border-radius:50%; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:0.6875rem; font-weight:700; color:var(--text-muted);">{{ $user->initials() }}</div>
            @endif
            <span style="color:var(--text-primary); font-weight:600;">{{ $user->name }}</span>
            <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
            <span>{{ $user->designation ?? 'No designation' }}</span>
            <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
            <span>{{ $user->branch?->name ?? 'No branch' }}</span>
        </div>
        @if($profilePercent < 100)
        <button type="button" @click="setTab('compliance')" class="flex items-center gap-2" style="background:none; border:none; cursor:pointer; padding:0;">
            <div class="ds-progress-track" style="width:80px;">
                <div class="ds-progress-bar ds-bar-green" style="width:{{ $profilePercent }}%;"></div>
            </div>
            <span class="text-xs font-medium" style="color:var(--text-muted);">{{ $profilePercent }}% complete</span>
        </button>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: OVERVIEW
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'overview'" x-cloak>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- Earnings snapshot --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
                <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">My Earnings</h3>
                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Month</div>
                        <div class="text-lg font-bold" style="color:var(--text-primary);">R {{ number_format($thisMonthEarnings, 0) }}</div>
                    </div>
                    <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                        <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Year</div>
                        <div class="text-lg font-bold" style="color:var(--text-primary);">R {{ number_format($thisYearEarnings, 0) }}</div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs" style="color:var(--text-muted);">Cap Progress</span>
                        <span class="text-xs font-bold" style="color:{{ $isCapped ? 'var(--ds-amber)' : 'var(--text-primary)' }};">{{ $isCapped ? 'CAPPED' : $capPercent . '%' }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar {{ $isCapped ? 'ds-bar-amber' : 'ds-bar-green' }}" style="width:{{ $capPercent }}%;"></div>
                    </div>
                </div>
                <a href="{{ route('commission.dashboard') }}" class="text-xs font-medium no-underline" style="color:var(--brand-icon);">View Full Earnings &rarr;</a>
            </div>

            {{-- Quick compliance card --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
                <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Compliance Overview</h3>
                @php $dotColors = ['green' => 'var(--ds-green)', 'amber' => 'var(--ds-amber)', 'red' => 'var(--ds-crimson)', 'grey' => 'var(--text-muted)', 'missing' => 'var(--text-muted)']; @endphp
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
                    <div class="flex items-center justify-between py-1.5 px-3" style="border:1px solid var(--border); border-radius:6px;">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] ?? 'var(--text-muted)' }};"></span>
                            <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $label }}</span>
                        </div>
                        <span class="text-[10px]" style="color:{{ $dotColors[$item['status']] ?? 'var(--text-muted)' }};">{{ $item['label'] }}</span>
                    </div>
                    @endforeach
                </div>
                <button @click="setTab('compliance')" class="mt-3 text-xs font-medium no-underline" style="color:var(--brand-icon); background:none; border:none; cursor:pointer; padding:0;">View full compliance &rarr;</button>
            </div>
        </div>

        {{-- Phase 9a G2 — Presentations widget (light agent stats) --}}
        @if(isset($presentationStats))
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden; margin-top:16px;">
            <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">My Presentations</h3>
                @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
                    <a href="{{ route('presentations.index') }}" style="font-size:0.6875rem;color:var(--brand-button);text-decoration:none;">View all →</a>
                @endif
            </div>
            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:1px;background:var(--border);">
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">This week</div>
                    <div style="font-size:1.5rem;color:var(--text-primary);font-weight:700;margin-top:3px;">{{ $presentationStats['this_week_count'] }}</div>
                </div>
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Awaiting outcome</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:3px;color:{{ $presentationStats['awaiting_outcome_count'] > 0 ? '#d97706' : 'var(--text-primary)' }};">
                        {{ $presentationStats['awaiting_outcome_count'] }}
                    </div>
                    @if($presentationStats['awaiting_outcome_count'] > 0 && \Illuminate\Support\Facades\Route::has('corex.presentations.outcomes.index'))
                        <a href="{{ route('corex.presentations.outcomes.index') }}?status=open" style="font-size:0.625rem;color:#d97706;text-decoration:none;">Record →</a>
                    @endif
                </div>
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Refresh requests</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:3px;color:{{ $presentationStats['refresh_requests_count'] > 0 ? '#dc2626' : 'var(--text-primary)' }};">
                        {{ $presentationStats['refresh_requests_count'] }}
                    </div>
                    @if($presentationStats['refresh_requests_count'] > 0 && \Illuminate\Support\Facades\Route::has('corex.presentations.refresh-requests.index'))
                        <a href="{{ route('corex.presentations.refresh-requests.index') }}" style="font-size:0.625rem;color:#dc2626;text-decoration:none;">Open inbox →</a>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- Recent activity --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden; margin-top:16px;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Recent Activity</h3>
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
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($tx->net_agent_amount, 0) }}</span>
                        @php
                            $sBadgeClass = match($tx->status) {
                                'pending' => 'ds-badge-warning',
                                'confirmed' => 'ds-badge-info',
                                'paid' => 'ds-badge-success',
                                default => 'ds-badge-default',
                            };
                        @endphp
                        <span class="ds-badge {{ $sBadgeClass }}">{{ ucfirst($tx->status) }}</span>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Training Progress tile --}}
            @php
                $trainingRole = auth()->user()->effectiveRole();
                $trainingReqDocs = \App\Models\Training\TrainingDoc::required()->forRole($trainingRole)->ordered()->get();
                $trainingReads = \App\Models\Training\TrainingDocRead::where('user_id', auth()->id())
                    ->whereIn('doc_id', $trainingReqDocs->pluck('id'))
                    ->get()->keyBy('doc_id');
                $trainingDone = $trainingReqDocs->filter(fn($d) => ($trainingReads->get($d->id)?->completed_at) && !($trainingReads->get($d->id)?->is_outdated_since))->count();
                $trainingTotal = $trainingReqDocs->count();
                $trainingPct = $trainingTotal > 0 ? (int) round(($trainingDone / $trainingTotal) * 100) : 100;
                $trainingNext = $trainingReqDocs->first(fn($d) => !($trainingReads->get($d->id)?->completed_at) || ($trainingReads->get($d->id)?->is_outdated_since));
            @endphp
            @if($trainingTotal > 0)
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
                <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">Training Progress</h3>
                <div class="text-xs mb-2" style="color:var(--text-muted);">Required for your role: {{ $trainingTotal }} {{ Str::plural('guide', $trainingTotal) }}</div>
                <div class="text-lg font-bold mb-2" style="color:var(--text-primary);">{{ $trainingDone }} of {{ $trainingTotal }} completed</div>
                <div class="w-full h-2 rounded-full overflow-hidden mb-3" style="background:var(--surface-2);">
                    <div class="h-full rounded-full transition-all" style="width:{{ $trainingPct }}%; background:{{ $trainingPct >= 100 ? 'var(--ds-green, #059669)' : 'var(--brand-icon, #0ea5e9)' }};"></div>
                </div>
                @if($trainingNext)
                <div class="text-xs mb-2" style="color:var(--text-muted);">Next: <strong style="color:var(--text-primary);">{{ $trainingNext->title }}</strong></div>
                <a href="{{ route('training-help.show', $trainingNext->slug) }}" class="inline-flex items-center gap-1 text-xs font-medium" style="color:var(--brand-icon);">
                    Continue Reading
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                </a>
                @else
                <div class="text-xs font-medium" style="color:var(--ds-green, #059669);">All required training complete</div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Impersonation audit (visible to all users on Overview tab) --}}
    <div x-show="tab === 'overview'" x-cloak>
        @if($impersonationLogs->count() > 0)
        <div class="mt-4 rounded-md px-5 py-4" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
            <div class="flex items-center gap-2 mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--ds-amber);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <span class="text-xs font-bold uppercase tracking-wider" style="color:var(--ds-amber);">Admin Access Log</span>
            </div>
            <p class="text-xs mb-3" style="color:var(--text-muted);">An administrator accessed your account on the following occasions. This is normal for support and compliance review.</p>
            <div class="space-y-1">
                @foreach($impersonationLogs as $log)
                <div class="flex items-center justify-between text-xs py-1" style="border-bottom:1px solid color-mix(in srgb, var(--ds-amber) 15%, transparent);">
                    <span style="color:var(--text-primary);">{{ $log->admin?->name ?? 'Unknown admin' }}</span>
                    <span style="color:var(--text-muted);">{{ $log->created_at->format('d M Y, H:i') }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: PROFILE
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'profile'" x-cloak>

        {{-- Profile photo upload --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-bottom:20px;">
            <div class="flex items-center gap-6">
                <div style="position:relative;">
                    @if($photoUrl)
                    <img src="{{ $photoUrl }}" alt="Profile photo"
                         style="width:80px; height:80px; object-fit:cover; border-radius:50%; border:2px solid var(--border);">
                    @else
                    <div style="width:80px; height:80px; border-radius:50%; background:var(--surface-2); border:2px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:1.5rem; font-weight:700; color:var(--text-muted);">
                        {{ $user->initials() }}
                    </div>
                    @endif
                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data" style="position:absolute; bottom:-4px; right:-4px;">
                        @csrf
                        <input type="hidden" name="document_type" value="photo">
                        <label style="width:28px; height:28px; border-radius:50%; background:var(--brand-button); display:flex; align-items:center; justify-content:center; cursor:pointer; border:2px solid var(--surface);">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="#ffffff" style="width:14px; height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" /></svg>
                            <input type="file" name="file" accept=".jpg,.jpeg,.png" class="hidden" onchange="this.closest('form').submit();">
                        </label>
                    </form>
                </div>
                <div>
                    <div class="text-sm font-bold" style="color:var(--text-primary);">Profile Photo</div>
                    <div class="text-xs" style="color:var(--text-muted);">JPG or PNG, max 10MB. Click the camera icon to upload.</div>
                </div>
            </div>
        </div>

        {{-- Profile form --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 20px;">Profile Information</h3>

            <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

            <form method="post" action="{{ route('agent.portal.profile.update') }}">
                @csrf
                @method('patch')

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; max-width:560px;">
                    {{-- Name --}}
                    <div style="grid-column:span 2;">
                        <label for="name" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Name <span class="text-red-500">*</span></label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('name') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Email --}}
                    <div style="grid-column:span 2;">
                        <label for="email" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Email <span class="text-red-500">*</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('email') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                        <div style="margin-top:6px;">
                            <p style="font-size:0.75rem; color:var(--text-secondary);">Your email is unverified.
                                <button form="send-verification" style="background:none; border:none; color:var(--brand-icon); text-decoration:underline; cursor:pointer; font-size:0.75rem; padding:0;">Re-send verification.</button>
                            </p>
                        </div>
                        @endif
                    </div>

                    {{-- Phone --}}
                    <div>
                        <label for="phone" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Phone</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" placeholder="Landline"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('phone') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Cell --}}
                    <div>
                        <label for="cell" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Cell <span class="text-red-500">*</span></label>
                        <input id="cell" name="cell" type="tel" value="{{ old('cell', $user->cell) }}" placeholder="Mobile" required
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('cell') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- ID Number --}}
                    <div>
                        <label for="id_number" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">ID Number</label>
                        <input id="id_number" name="id_number" type="text" value="{{ old('id_number', $user->id_number) }}" placeholder="SA ID number"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('id_number') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Number --}}
                    <div>
                        <label for="ffc_number" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">FFC Number</label>
                        <input id="ffc_number" name="ffc_number" type="text" value="{{ old('ffc_number', $user->ffc_number) }}" placeholder="FFC number"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('ffc_number') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Expiry Date --}}
                    <div>
                        <label for="ffc_expiry_date" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">FFC Expiry Date</label>
                        <input id="ffc_expiry_date" name="ffc_expiry_date" type="date" value="{{ old('ffc_expiry_date', $user->ffc_expiry_date?->format('Y-m-d')) }}"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('ffc_expiry_date') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Read-only admin fields --}}
                <div style="margin-top:24px; padding-top:20px; border-top:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-3">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px; height:14px; color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <span style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Admin Managed</span>
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:560px;">
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Designation</div>
                            <div style="padding:9px 12px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->designation ?: 'Not set' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Role</div>
                            <div style="padding:9px 12px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ ucfirst(str_replace('_', ' ', $user->role ?? 'agent')) }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Branch</div>
                            <div style="padding:9px 12px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->branch?->name ?? 'Not assigned' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Agency</div>
                            <div style="padding:9px 12px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->agency?->name ?? 'Not assigned' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">PPRA Status</div>
                            <div style="padding:9px 12px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">
                                {{ ucfirst($user->ppra_status ?? 'Not set') }}
                                @if($user->ppra_last_verified_at)
                                    @php $ppraVerifiedAt = \Carbon\Carbon::parse($user->ppra_last_verified_at); @endphp
                                    <span style="font-size:0.75rem; color:var(--text-muted); margin-left:6px;">(verified {{ $ppraVerifiedAt->format('d M Y') }})</span>
                                    @if($ppraVerifiedAt->lt(now()->subYear()))
                                    <span style="font-size:0.6875rem; color:var(--ds-amber); margin-left:4px; font-weight:600;">Overdue — over 12 months</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="corex-btn-primary">Save Profile</button>
                </div>
            </form>
        </div>

        {{-- Theme Preference --}}
        <div class="rounded-md p-5 mt-5" style="background:var(--surface); border:1px solid var(--border);"
             x-data="{ current: localStorage.getItem('corex-theme') || '{{ $user->theme ?? 'dark' }}' }">
            <h3 class="text-base font-bold mb-1" style="color:var(--text-primary);">Theme Preference</h3>
            <p class="text-xs mb-4" style="color:var(--text-muted);">Choose how CoreX looks for you. Synced across your devices.</p>
            <div role="radiogroup" aria-label="Theme"
                 class="inline-flex rounded-md p-1"
                 style="background:var(--surface-2); border:1px solid var(--border);">
                <button type="button" role="radio" :aria-checked="current === 'dark'"
                        @click="current='dark'; document.documentElement.classList.add('dark'); localStorage.setItem('corex-theme','dark'); fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:'dark'})})"
                        :style="current === 'dark'
                            ? 'background:var(--brand-button); color:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.15);'
                            : 'background:transparent; color:var(--text-secondary);'"
                        class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                        style="border:none; cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                    Dark
                </button>
                <button type="button" role="radio" :aria-checked="current === 'light'"
                        @click="current='light'; document.documentElement.classList.remove('dark'); localStorage.setItem('corex-theme','light'); fetch('{{ route('profile.theme') }}',{method:'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},body:JSON.stringify({theme:'light'})})"
                        :style="current === 'light'
                            ? 'background:var(--brand-button); color:#fff; box-shadow:0 1px 2px rgba(0,0,0,0.15);'
                            : 'background:transparent; color:var(--text-secondary);'"
                        class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold transition-colors"
                        style="border:none; cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                    Light
                </button>
            </div>
        </div>

        @if($user->portal_show_api_token)
        {{-- API Token --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;"
             x-data="{
                hasToken: {{ auth()->user()->api_token ? 'true' : 'false' }},
                plaintext: null, loading: false, copied: false, error: null,
                async generate() {
                    this.loading = true; this.copied = false; this.error = null;
                    try {
                        const res = await fetch('{{ route('corex.settings.generate-token') }}', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'} });
                        if (!res.ok) { this.error = 'Failed to generate token (' + res.status + '). Please refresh and try again.'; return; }
                        const data = await res.json();
                        if (!data.token) { this.error = 'Server did not return a token.'; return; }
                        this.plaintext = data.token; this.hasToken = true;
                    } catch (e) { this.error = 'Network error: ' + e.message; }
                    finally { this.loading = false; }
                },
                copyToken() { navigator.clipboard.writeText(this.plaintext); this.copied = true; setTimeout(() => this.copied = false, 2000); }
             }">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 12px;">API Token</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Used by the CoreX Chrome extension to authenticate with CoreX.</p>
            <template x-if="error">
                <div class="rounded-md" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); padding:10px 14px; margin-bottom:10px;">
                    <div class="text-xs font-semibold" style="color:var(--ds-crimson);" x-text="error"></div>
                </div>
            </template>
            <template x-if="plaintext">
                <div>
                    <div class="rounded-md" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); padding:10px 14px; margin-bottom:10px;">
                        <div class="text-xs font-semibold" style="color:var(--ds-amber);">Copy this token now &mdash; you won't see it again.</div>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <input type="text" readonly :value="plaintext" style="flex:1; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.75rem; font-family:monospace; box-sizing:border-box;">
                        <button @click="copyToken()" type="button" class="corex-btn-primary"><span x-text="copied ? 'Copied!' : 'Copy'"></span></button>
                    </div>
                    <button @click="generate()" :disabled="loading" type="button" class="corex-btn-outline mt-2 disabled:opacity-40 disabled:cursor-not-allowed"><span x-text="loading ? 'Generating...' : 'Regenerate'"></span></button>
                </div>
            </template>
            <template x-if="!plaintext">
                <div>
                    <template x-if="hasToken">
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <span class="ds-badge ds-badge-success">Token active</span>
                                <span style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;">••••••••••</span>
                            </div>
                            <button @click="generate()" :disabled="loading" type="button" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed"><span x-text="loading ? 'Generating...' : 'Regenerate Token'"></span></button>
                        </div>
                    </template>
                    <template x-if="!hasToken">
                        <button @click="generate()" :disabled="loading" type="button" class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed"><span x-text="loading ? 'Generating...' : 'Generate Token'"></span></button>
                    </template>
                </div>
            </template>
        </div>

        {{-- Chrome Extension --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 8px;">CoreX Chrome Extension</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Pull properties from Property24 directly into CoreX.</p>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="{{ route('corex.extension.download') }}" class="corex-btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Download Extension
                </a>
                <a href="{{ asset('downloads/portal-capture-extension.zip') }}" class="corex-btn-outline" download>Portal Capture Extension</a>
            </div>
        </div>
        @endif

        {{-- Social Media Accounts --}}
        @if($user->portal_show_social_accounts && \Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
        @php
            $fbSocial = $socialAccounts->firstWhere('platform', 'facebook');
            $igSocial = $socialAccounts->firstWhere('platform', 'instagram');
        @endphp
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 12px;">Social Media Accounts</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                {{-- Facebook --}}
                <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:14px;">
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:36px; height:36px; border-radius:6px; background:#1877f222; display:flex; align-items:center; justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1877f2" style="width:18px; height:18px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                        <div>
                            <div style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);">Facebook</div>
                            @if($fbSocial)
                            <span class="ds-badge ds-badge-success">Connected</span>
                            @else
                            <span class="ds-badge ds-badge-default">Not Connected</span>
                            @endif
                        </div>
                    </div>
                    @if($fbSocial)
                    <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">@csrf<input type="hidden" name="platform" value="facebook">
                        <button type="submit" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); cursor:pointer;">Disconnect</button>
                    </form>
                    @else
                    <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'facebook']) }}" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:#1877f2; color:#fff; text-decoration:none; font-weight:600;">Connect</a>
                    @endif
                </div>
                {{-- Instagram --}}
                <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:14px;">
                    <div class="flex items-center gap-3 mb-3">
                        <div style="width:36px; height:36px; border-radius:6px; background:#e1306c22; display:flex; align-items:center; justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#e1306c" style="width:18px; height:18px;"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
                        </div>
                        <div>
                            <div style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);">Instagram</div>
                            @if($igSocial)
                            <span class="ds-badge ds-badge-success">Connected</span>
                            @else
                            <span class="ds-badge ds-badge-default">Not Connected</span>
                            @endif
                        </div>
                    </div>
                    @if($igSocial)
                    <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">@csrf<input type="hidden" name="platform" value="instagram">
                        <button type="submit" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); cursor:pointer;">Disconnect</button>
                    </form>
                    @else
                    <a href="{{ route('corex.social.oauth.redirect', ['platform' => 'instagram']) }}" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); color:#fff; text-decoration:none; font-weight:600;">Connect</a>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- ═══════════════════════════════════════════
             AGENT QR CODE — spec: .ai/specs/agent-qr-onboarding.md
             Slug is generated once (ensureQrSlug) and locked to the agent.
             QR image is served by /my-portal/qr.svg → pure SVG, no JS, no
             external CDN, so it always renders on any host / CSP.
             ═══════════════════════════════════════════ --}}
        @php
            $qrUrl    = $user->qrCodeUrl();
            $qrParam  = urlencode($qrUrl);
            // Pure <img> rendering via goqr.me — no JS, no CDN script,
            // no CSP issues. Slug is intentionally public.
            // Match the downloaded PNG and the mobile app: ECC=H, margin=8.
            // Same data + same ECC + same margin → identical visual pattern.
            $qrImgSrc = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=8&ecc=H&data={$qrParam}";
            $qrPngSrc = "https://api.qrserver.com/v1/create-qr-code/?size=1024x1024&margin=8&ecc=H&format=png&data={$qrParam}";
        @endphp
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 6px;">Your Client QR Code</h3>
            <p style="font-size:0.8125rem; color:var(--text-secondary); margin:0 0 16px;">
                Hand this to prospects. When they scan it in the CoreX app, they sign up directly as your client.
            </p>
            <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
                <div style="background:#ffffff; padding:12px; border-radius:6px; border:1px solid var(--border); width:200px; height:200px; display:flex; align-items:center; justify-content:center;">{{-- White is intentional: QR code requires high-contrast white background --}}
                    <img src="{{ $qrImgSrc }}" alt="Your client onboarding QR code"
                         style="width:176px; height:176px; display:block;">
                </div>
                <div style="flex:1; min-width:240px;">
                    <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">URL</div>
                    <div style="font-family:monospace; font-size:0.75rem; color:var(--text-primary); word-break:break-all; margin-bottom:12px;">{{ $qrUrl }}</div>
                    <a href="{{ $qrPngSrc }}" download="corex-agent-qr.png" target="_blank" rel="noopener" class="corex-btn-primary" style="display:inline-block;">
                        Download PNG
                    </a>
                </div>
            </div>
        </div>
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
                'pending' => ['class' => 'ds-badge-warning', 'text' => 'Pending'],
                'verified' => ['class' => 'ds-badge-success', 'text' => 'Verified'],
                'rejected' => ['class' => 'ds-badge-danger', 'text' => 'Rejected'],
                'expired' => ['class' => 'ds-badge-danger', 'text' => 'Expired'],
                'missing' => ['class' => 'ds-badge-default', 'text' => 'Missing'],
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
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:16px 18px; display:flex; flex-direction:column; gap:10px;">
                {{-- Header --}}
                <div class="flex items-center gap-3">
                    <div style="color:var(--text-muted); flex-shrink:0;">{!! $docCfg['icon'] !!}</div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.8125rem; font-weight:700; color:var(--text-primary);">{{ $docCfg['label'] }}</div>
                        <span class="ds-badge {{ $pill['class'] }}" style="margin-top:4px;">{{ $pill['text'] }}</span>
                        @if($docCfg['type'] === 'ffc_certificate' && $user->ffc_number)
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">FFC #{{ $user->ffc_number }}
                            <button type="button" @click="setTab('profile')" style="color:var(--brand-icon); background:none; border:none; cursor:pointer; font-size:0.6875rem; padding:0; text-decoration:underline;">Edit in Profile</button>
                        </div>
                        @endif
                        @if($docCfg['type'] === 'id_copy' && $user->id_number)
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">ID: {{ $user->id_number }}
                            <button type="button" @click="setTab('profile')" style="color:var(--brand-icon); background:none; border:none; cursor:pointer; font-size:0.6875rem; padding:0; text-decoration:underline;">Edit in Profile</button>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- File info --}}
                @if($doc)
                <div style="font-size:0.6875rem; color:var(--text-muted);">
                    {{ $doc->file_name }} &middot; {{ $doc->created_at->format('d M Y') }}
                </div>
                @if($doc->expiry_date)
                @php
                    $daysLeft = (int) now()->diffInDays($doc->expiry_date, false);
                    $expiryColor = $daysLeft <= 0 ? 'var(--ds-crimson)' : ($daysLeft <= 60 ? 'var(--ds-amber)' : 'var(--ds-green)');
                @endphp
                <div style="font-size:0.75rem; color:{{ $expiryColor }};">
                    Expires {{ $doc->expiry_date->format('d M Y') }} ({{ $daysLeft > 0 ? "in {$daysLeft} days" : 'EXPIRED' }})
                </div>
                @endif
                @if($docStatus === 'rejected' && $doc->rejected_reason)
                <div class="rounded-md" style="font-size:0.75rem; color:var(--ds-crimson); background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); padding:6px 8px;">{{ $doc->rejected_reason }}</div>
                @endif
                @endif

                {{-- Actions --}}
                <div class="flex items-center gap-2 mt-auto">
                    @if($doc)
                    <a href="{{ asset('storage/' . $doc->file_path) }}" target="_blank" style="font-size:0.6875rem; padding:4px 10px; border-radius:6px; border:1px solid var(--border); color:var(--text-muted); text-decoration:none;">View</a>
                    @endif

                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data" class="flex items-center gap-1"
                          x-data="{ fileName: '' }">
                        @csrf
                        <input type="hidden" name="document_type" value="{{ $docCfg['type'] }}">
                        @if($docCfg['has_expiry'])
                        @php
                            $prefilledExpiry = null;
                            if ($doc && $doc->expiry_date) {
                                $prefilledExpiry = $doc->expiry_date instanceof \Carbon\Carbon ? $doc->expiry_date->format('Y-m-d') : $doc->expiry_date;
                            } elseif ($docCfg['type'] === 'ffc_certificate' && $user->ffc_expiry_date) {
                                $prefilledExpiry = $user->ffc_expiry_date instanceof \Carbon\Carbon ? $user->ffc_expiry_date->format('Y-m-d') : $user->ffc_expiry_date;
                            }
                        @endphp
                        <input type="date" name="expiry_date" value="{{ $prefilledExpiry }}" placeholder="Expiry" title="Document expiry date"
                               style="font-size:0.75rem; padding:4px 6px; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); width:120px;">
                        @endif
                        <label style="font-size:0.75rem; padding:4px 10px; border-radius:6px; cursor:pointer; background:color-mix(in srgb, var(--brand-button) 12%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent); white-space:nowrap;">
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
        @php
            $overallStatus = $complianceStatus['overall'];
            $overallColor = $overallColors[$overallStatus] ?? 'var(--text-muted)';
            $overallTint = match($overallStatus) {
                'green' => 'color-mix(in srgb, var(--ds-green) 18%, transparent)',
                'amber' => 'color-mix(in srgb, var(--ds-amber) 18%, transparent)',
                'red' => 'color-mix(in srgb, var(--ds-crimson) 18%, transparent)',
                default => 'var(--surface-2)',
            };
        @endphp
        <div class="rounded-md p-6 text-center" style="background:var(--surface); border:1px solid var(--border); margin-bottom:20px;">
            <div class="mx-auto mb-3 flex items-center justify-center" style="width:48px; height:48px; border-radius:50%; background:{{ $overallTint }};">
                <span style="width:20px; height:20px; border-radius:50%; background:{{ $overallColor }}; display:block;"></span>
            </div>
            <div class="text-base font-bold" style="color:var(--text-primary);">
                @if($overallStatus === 'green') Your compliance is up to date
                @elseif($overallStatus === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                @else Action required @endif
            </div>
        </div>

        {{-- Breakdown list --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Compliance Breakdown</h3>
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
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$ciData['status']] ?? 'var(--text-muted)' }};"></span>
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
                            $badgeClassMap = [
                                'green' => 'ds-badge-success',
                                'amber' => 'ds-badge-warning',
                                'red' => 'ds-badge-danger',
                                'grey' => 'ds-badge-info',
                                'missing' => 'ds-badge-default',
                            ];
                            $badgeClass = $badgeClassMap[$ciData['status']] ?? 'ds-badge-default';
                        @endphp
                        <span class="ds-badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                        @if(!in_array($ciData['status'], ['green', 'grey']))
                            @if(!empty($ci['action_route']) && $ci['key'] === 'rmcp_acknowledged')
                                @if($rmcpAckStatus === 'in_progress')
                                <a href="{{ route('rmcp.ack.step', 1) }}" style="font-size:0.75rem; padding:3px 8px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); text-decoration:none; font-weight:600;">Continue</a>
                                @else
                                <form method="POST" action="{{ route('rmcp.ack.start') }}" style="display:inline;">@csrf
                                <button type="submit" style="font-size:0.75rem; padding:3px 8px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:none; cursor:pointer; font-weight:600;">{{ $ci['action_text'] }}</button>
                                </form>
                                @endif
                            @elseif(!empty($ci['action_route']) && $ci['action_route'] === 'screening')
                                <a href="{{ route('compliance.screenings.my') }}" style="font-size:0.75rem; padding:3px 8px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); text-decoration:none; font-weight:600;">{{ $ci['action_text'] }}</a>
                            @else
                            <button @click="setTab('{{ $ci['action_tab'] }}')" style="font-size:0.75rem; padding:3px 8px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:none; cursor:pointer;">{{ $ci['action_text'] }}</button>
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
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$rmcpStatus] }};"></span>
                    <div>
                        <div class="text-sm font-semibold" style="color:var(--text-primary);">RMCP Acknowledgement</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $rmcpLabel }}</div>
                    </div>
                </div>
                @if($rmcpAckStatus === 'valid' && $rmcpAck)
                <a href="{{ route('rmcp.ack.receipt', $rmcpAck) }}" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); text-decoration:none; font-weight:600;">View Receipt</a>
                @elseif($rmcpAckStatus === 'in_progress')
                <a href="{{ route('rmcp.ack.step', 1) }}" style="font-size:0.75rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); text-decoration:none; font-weight:600;">Continue</a>
                @elseif(in_array($rmcpAckStatus, ['not_started', 'expired']))
                <form method="POST" action="{{ route('rmcp.ack.start') }}" style="display:inline;">@csrf
                <button type="submit" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:none; cursor:pointer; font-weight:600;">Start Acknowledgement</button>
                </form>
                @endif
            </div>
        </div>

        {{-- Other training courses --}}
        @if($trainingItems->isNotEmpty())
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 16px;">Other Training</h3>
            <div class="space-y-3">
                @foreach($trainingItems as $item)
                <div class="flex items-center justify-between py-3 px-4" style="border:1px solid var(--border); border-radius:6px;">
                    <div class="flex items-center gap-3">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] }};"></span>
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $item['title'] }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ $item['label'] }}</div>
                        </div>
                    </div>
                    @if($item['status'] !== 'green')
                    <a href="{{ route('training.show', $item['id']) }}" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); text-decoration:none; font-weight:600;">Continue</a>
                    @else
                    <span style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); font-weight:600;">Completed</span>
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
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 6px;">Update Password</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 20px;">Ensure your account is using a long, random password to stay secure.</p>

            <form method="post" action="{{ route('password.update') }}">
                @csrf
                @method('put')

                <div style="display:flex; flex-direction:column; gap:16px; max-width:400px;">
                    <div>
                        <label for="update_password_current_password" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Current Password</label>
                        <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('current_password', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">New Password</label>
                        <input id="update_password_password" name="password" type="password" autocomplete="new-password"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('password', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password_confirmation" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Confirm Password</label>
                        <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                               style="width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; transition:border-color 200ms;"
                               onfocus="this.style.borderColor='var(--brand-button)'" onblur="this.style.borderColor='var(--border)'">
                        @error('password_confirmation', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <button type="submit" class="corex-btn-primary">Update Password</button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Delete Account --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px; margin-top:20px;" x-data="{ confirmDelete: false }">
            <h3 class="text-sm font-semibold" style="color:var(--text-primary); margin:0 0 6px; border-left:3px solid var(--ds-crimson); padding-left:12px;">Delete Account</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 16px;">Once your account is deleted, all of its resources and data will be permanently deleted.</p>

            <button @click="confirmDelete = true" x-show="!confirmDelete" type="button" class="corex-btn-primary" style="background:var(--ds-crimson, #dc2626); box-shadow:none;">Delete Account</button>

            <div x-show="confirmDelete" x-cloak x-transition class="rounded-md" style="background:color-mix(in srgb, var(--ds-crimson) 8%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); padding:16px; max-width:400px;">
                <p style="font-size:0.8125rem; font-weight:600; color:var(--ds-crimson); margin:0 0 4px;">Are you sure?</p>
                <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 12px;">Enter your password to confirm.</p>
                <form method="post" action="{{ route('profile.destroy') }}">
                    @csrf
                    @method('delete')
                    <input name="password" type="password" placeholder="Password"
                           style="width:100%; max-width:260px; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:9px 12px; font-size:0.8125rem; box-sizing:border-box; margin-bottom:10px;">
                    @error('password', 'userDeletion') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-bottom:8px;">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button type="button" @click="confirmDelete = false" class="corex-btn-outline">Cancel</button>
                        <button type="submit" class="corex-btn-primary" style="background:var(--ds-crimson, #dc2626); box-shadow:none;">Confirm Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ══ Payslips tab ══ --}}
    @if(auth()->user()->hasPermission('view_own_payslips'))
    <div x-show="tab === 'payslips'" x-cloak>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 6px;">My Payslips</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 20px;">Your finalised payslips from the payroll system.</p>

            @if(isset($latestPayslip) && $latestPayslip)
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:20px;">
                    <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:14px; text-align:center;">
                        <p style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 4px;">Latest Period</p>
                        <p style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">{{ $latestPayslip->period_month->format('M Y') }}</p>
                    </div>
                    <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:14px; text-align:center;">
                        <p style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 4px;">Latest Net Pay</p>
                        <p style="font-size:1rem; font-weight:700; color:var(--brand-icon); margin:0;">R {{ number_format($latestPayslip->net_pay, 2) }}</p>
                    </div>
                    <div style="background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:14px; text-align:center;">
                        <p style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 4px;">Total on File</p>
                        <p style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0;">{{ $payslipCount ?? 0 }}</p>
                    </div>
                </div>

                <a href="{{ route('my-portal.payslips') }}" class="corex-btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                    View All Payslips
                    <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                </a>
            @else
                <p style="font-size:0.8125rem; color:var(--text-muted); text-align:center; padding:24px 0;">No payslips yet. Your payslips will appear here once your employer finalises a payroll run.</p>
            @endif
        </div>
    </div>
    @endif

    {{-- ══ Leave tab ══ --}}
    @if(auth()->user()->hasPermission('apply_for_leave'))
    <div x-show="tab === 'leave'" x-cloak>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 style="font-size:1rem; font-weight:700; color:var(--text-primary); margin:0 0 6px;">My Leave</h3>
            <p style="font-size:0.75rem; color:var(--text-secondary); margin:0 0 20px;">View your leave balances, apply for leave, and track your applications.</p>

            <a href="{{ route('my-portal.leave.index') }}" class="corex-btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                View Leave Dashboard
                <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>
    </div>
    @endif

        </div>{{-- .max-w-5xl --}}
    </div>{{-- .p-4 --}}
</div>{{-- .-m-4 x-data --}}
@endsection
