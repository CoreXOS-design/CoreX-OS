{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@php
    $photoUrl = $user->profilePhotoUrl();
    // Sub-tab each tab opens on. A failed profile save reopens the section that holds the error.
    $portalProfileSub = ($errors->has('cover_image') || $errors->has('link_url')) ? 'articles'
        : (($errors->any() && ! $errors->has('signature')) ? 'details' : 'signature');
    $overallColors = ['green' => 'var(--ds-green)', 'amber' => 'var(--ds-amber)', 'red' => 'var(--ds-crimson)'];
    $overallColor = $overallColors[$complianceStatus['overall']] ?? 'var(--text-muted)';
@endphp

@section('corex-content')
<style>
    /* My Portal (specs my-portal-restyle.md sections 2 and 9). Under each tab a second row lists its sections; one
       section shows at a time. Ruling 26 Oct 2026: NO page scroll at 1366x768 / 1536x735 - header, tabs and the
       open section all fit inside the one scroller, so every spacing here is deliberately tight. CoreX tokens only. */
    .pg-subs { display:flex; gap:22px; padding:0 2px; overflow-x:auto; scrollbar-width:none; border-bottom:1px solid var(--border); margin-top:6px; }
    .pg-sub { border:0; background:none; padding:6px 0; font-size:0.8125rem; font-weight:600; color:var(--text-muted); cursor:pointer; white-space:nowrap; border-bottom:2px solid transparent; margin-bottom:-1px; display:inline-flex; align-items:center; gap:7px; }
    .pg-sub:hover { color:var(--text-primary); }
    .pg-sub[aria-current="page"] { color:var(--text-primary); border-bottom-color:var(--text-primary); }
    .pg-pane { padding:10px 2px 0; max-width:980px; }
    .pg-pane.pg-wide { max-width:none; }
    .pg-pane-col { display:flex; flex-direction:column; gap:8px; }
    .pg-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .mp-h { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:8px; }
    .mp-h h3 { font-size:0.875rem; font-weight:700; color:var(--text-primary); margin:0; }
    .mp-card { background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:8px 10px; min-width:0; }
    .mp-lbl { font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); line-height:1.25; }
    .mp-val { font-size:1.125rem; font-weight:700; color:var(--text-primary); line-height:1.3; }
    .mp-note { font-size:0.6875rem; color:var(--text-muted); line-height:1.3; }
    .mp-grid { display:grid; gap:8px; }
    .mp-g2 { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .mp-g3 { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .mp-g4 { grid-template-columns:repeat(4, minmax(0, 1fr)); }
    .mp-g5 { grid-template-columns:repeat(5, minmax(0, 1fr)); }
    .mp-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:5px 10px; border:1px solid var(--border); border-radius:6px; min-width:0; }
    .mp-more { font-size:0.75rem; font-weight:600; color:var(--brand-icon); text-decoration:none; background:none; border:0; padding:0; cursor:pointer; }
    .mp-field label { display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:3px; text-transform:uppercase; letter-spacing:0.05em; }
    .mp-field input, .mp-field select, .mp-field textarea { width:100%; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:6px 10px; font-size:0.8125rem; box-sizing:border-box; }
    @media (max-width: 900px) { .mp-g3, .mp-g4, .mp-g5 { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 560px) { .mp-g2, .mp-g3, .mp-g4, .mp-g5 { grid-template-columns:minmax(0, 1fr); } }
</style>
<div class="w-full"
     x-data="{
        tab: (window.location.hash || '#overview').replace('#', ''),
        sub: { overview: '{{ $overviewSub ?? (($isAssistant ?? false) ? 'compliance' : 'earnings') }}', profile: '{{ $portalProfileSub }}', favourites: 'intro', tools: '{{ request('pane') === 'social' ? 'social' : 'theme' }}', documents: '', training: 'rmcp', password: 'update' },
        setTab(t) { this.tab = t; history.replaceState(null, '', '#' + t); }
     }"
     x-init="window.addEventListener('hashchange', () => tab = (window.location.hash || '#overview').replace('#', ''))">

    {{-- Frozen top block: page header + agent identity strip stay pinned to the top of the
         scroll area while the tabs and tab content scroll beneath.
         • Full-bleed (-mx / px cancel <main>'s p-4 / lg:p-6) so the opaque background also
           covers the side gutters — .corex-page-banner is itself full-bleed via negative margins.
         • Negative top margin + matching padding absorb <main>'s top padding; the banner's own
           -mt then lands flush at the very top of the scroll area.
         • Sticky offset is NEGATIVE <main>'s padding: browsers measure a sticky box against the
           scroll container's content box (inside its padding), so top:0 would pin it 1.5rem
           down and leave a bare strip of background above the header. --}}
    <div class="sticky -top-4 lg:-top-6 z-20 -mt-4 pt-4 lg:-mt-6 lg:pt-6 -mx-4 px-4 lg:-mx-6 lg:px-6 pb-2" style="background:var(--bg);">

    {{-- Page header (Pattern A, AT-336) - ONE row: title + who you are on the left, status + controls on the right --}}
    <div class="rounded-md px-4 py-2 corex-page-banner">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
            <div class="flex items-center gap-3 text-sm min-w-0" style="color:var(--text-muted);" data-tour="portal-home-intro">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">My Portal</h1>
                <span style="width:1px; height:16px; background:var(--border); display:inline-block;"></span>
                @if($photoUrl)
                <img src="{{ $photoUrl }}" alt="" style="width:24px; height:24px; object-fit:cover; border-radius:50%; border:1px solid var(--border);">
                @else
                <div style="width:24px; height:24px; border-radius:50%; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:0.6875rem; font-weight:700; color:var(--text-muted);">{{ $user->initials() }}</div>
                @endif
                <span style="color:var(--text-primary); font-weight:600;">{{ $user->name }}</span>
                <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
                <span>{{ $user->designation ?? 'No designation' }}</span>
                <span style="width:3px; height:3px; border-radius:50%; background:var(--text-muted); display:inline-block;"></span>
                <span>{{ $user->branch?->display_name ?? 'No branch' }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($profilePercent < 100)
                <button type="button" @click="setTab('compliance')" class="flex items-center gap-2" style="background:none; border:none; cursor:pointer; padding:0;">
                    <div class="ds-progress-track" style="width:80px;">
                        <div class="ds-progress-bar ds-bar-green" style="width:{{ $profilePercent }}%;"></div>
                    </div>
                    <span class="text-xs font-medium" style="color:var(--text-muted);">{{ $profilePercent }}% complete</span>
                </button>
                @endif
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
                <span class="inline-flex items-center gap-2 rounded-md px-3 py-1"
                      style="background:var(--surface-2); border:1px solid var(--border);">
                    <span style="width:8px; height:8px; border-radius:50%; background:{{ $overallColor }}; display:inline-block;"></span>
                    <span class="text-xs font-semibold" style="color: var(--text-secondary);">
                        @if($complianceStatus['overall'] === 'green') Compliant
                        @elseif($complianceStatus['overall'] === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                        @else Action required @endif
                    </span>
                </span>
                @permission('access_settings')
                <a href="{{ url('/corex/settings?section=my-portal&s=my-portal') }}"
                   title="My Portal Settings"
                   aria-label="My Portal Settings"
                   class="inline-flex items-center justify-center rounded-md transition-colors"
                   style="width:32px; height:32px; background: transparent; border: 1px solid var(--border); color: var(--text-secondary);"
                   onmouseover="this.style.background='var(--surface-2)'; this.style.borderColor='var(--border-hover)';"
                   onmouseout="this.style.background='transparent'; this.style.borderColor='var(--border)';">
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

    </div>{{-- /frozen top block --}}

    <div>
        <div class="w-full">

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
    <div style="border-bottom:1px solid var(--border);" data-tour="portal-home-tabs">
        <nav class="-mb-px flex gap-1 overflow-x-auto" aria-label="Tabs">
            @php
                // AT-267 §10 — an assistant's own portal is stripped to identity + FICA only.
                // Tools (agent marketing), Payslips and Leave are hidden outright; Compliance
                // shows only when FICA is required of the assistant.
                $portalTabs = [
                    'overview' => 'Overview',
                    'profile' => 'Profile',
                    // Sidebar Favourites — personal navigation, available to every
                    // signed-in user. Spec: .ai/specs/sidebar-favourites.md §6.3
                    'favourites' => 'Favourites',
                ];
                if (!($isAssistant ?? false)) {
                    $portalTabs['tools'] = 'Tools';
                }
                $portalTabs['documents'] = 'Documents';
                if (!($isAssistant ?? false) || $user->fica_required) {
                    $portalTabs['compliance'] = 'Compliance';
                }
                $portalTabs['training'] = 'Training';
                $portalTabs['password'] = 'Password';
                if (!($isAssistant ?? false) && auth()->user()->hasPermission('view_own_payslips')) {
                    $portalTabs['payslips'] = 'Payslips';
                }
                if (!($isAssistant ?? false) && auth()->user()->hasPermission('apply_for_leave')) {
                    $portalTabs['leave'] = 'Leave';
                }
            @endphp
            @foreach($portalTabs as $key => $label)
            <button type="button"
                    @if($key === 'overview' || $key === 'compliance') data-tour="portal-home-tab-{{ $key }}" @endif
                    data-portal-tab="{{ $key }}" @click="setTab('{{ $key }}')"
                    class="whitespace-nowrap px-4 py-2 text-sm border-b-2 transition-colors"
                    :style="tab === '{{ $key }}'
                        ? 'color:var(--brand-icon); font-weight:600; border-bottom-color:var(--brand-icon);'
                        : 'color:var(--text-muted); font-weight:500; border-bottom-color:transparent;'">
                {{ $label }}
            </button>
            @endforeach
        </nav>
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: OVERVIEW
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'overview'" x-cloak data-portal-pane="overview">
            {{-- Training progress data (rendered as its own sub-tab below) --}}
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
        <nav class="pg-subs" aria-label="Sections">
            @unless($isAssistant ?? false)
            @if(! empty($myPortal))
            <button type="button" class="pg-sub" data-portal-sub="whereami" @click="sub.overview = 'whereami'" :aria-current="sub.overview === 'whereami' ? 'page' : null">Where I Am</button>
            <button type="button" class="pg-sub" data-portal-sub="targets" @click="sub.overview = 'targets'" :aria-current="sub.overview === 'targets' ? 'page' : null">Targets &amp; Budget</button>
            <button type="button" class="pg-sub" data-portal-sub="myactivity" @click="sub.overview = 'myactivity'" :aria-current="sub.overview === 'myactivity' ? 'page' : null">My Activity</button>
            @endif
            @if(! empty($myPerformance))
            <button type="button" class="pg-sub" data-portal-sub="performance" @click="sub.overview = 'performance'" :aria-current="sub.overview === 'performance' ? 'page' : null">My Performance</button>
            @endif
            <button type="button" class="pg-sub" data-portal-sub="earnings" @click="sub.overview = 'earnings'" :aria-current="sub.overview === 'earnings' ? 'page' : null">My Earnings</button>
            @endunless
            <button type="button" class="pg-sub" data-portal-sub="compliance" @click="sub.overview = 'compliance'" :aria-current="sub.overview === 'compliance' ? 'page' : null">Compliance Overview</button>
            @unless($isAssistant ?? false)
            @if(isset($presentationStats))
            <button type="button" class="pg-sub" data-portal-sub="presentations" @click="sub.overview = 'presentations'" :aria-current="sub.overview === 'presentations' ? 'page' : null">My Presentations</button>
            @endif
            @endunless
            <button type="button" class="pg-sub" data-portal-sub="activity" @click="sub.overview = 'activity'" :aria-current="sub.overview === 'activity' ? 'page' : null">Recent Activity</button>
            @if($trainingTotal > 0)
            <button type="button" class="pg-sub" data-portal-sub="training" @click="sub.overview = 'training'" :aria-current="sub.overview === 'training' ? 'page' : null">Training Progress</button>
            @endif
        </nav>
        <div>
            @unless($isAssistant ?? false)
            @if(! empty($myPortal))
                @include('agent.portal-blocks._where-am-i')
                @include('agent.portal-blocks._targets-budget')
                @include('agent.portal-blocks._my-activity')
            @endif
            @if(! empty($myPerformance))
                @include('agent.portal-blocks._my-performance')
            @endif
            @endunless
            {{-- Earnings snapshot — AT-267 §10: hidden for assistants (no commission of their own) --}}
            @unless($isAssistant ?? false)
            <div x-show="sub.overview === 'earnings'" x-cloak class="pg-pane " data-tour="portal-home-earnings">
                <div class="mp-h"><h3>My Earnings</h3>
                @feature('commission-management')
                <a href="{{ route('commission.dashboard') }}" class="mp-more">View Full Earnings &rarr;</a>
                @endfeature
                </div>
                <div class="mp-grid mp-g2" style="margin-bottom:8px;">
                    <div class="mp-card"><div class="mp-lbl">This Month</div><div class="mp-val">R {{ number_format($thisMonthEarnings, 0) }}</div></div>
                    <div class="mp-card"><div class="mp-lbl">This Year</div><div class="mp-val">R {{ number_format($thisYearEarnings, 0) }}</div></div>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs" style="color:var(--text-muted);">Cap Progress</span>
                        <span class="text-xs font-bold" style="color:{{ $isCapped ? 'var(--ds-amber)' : 'var(--text-primary)' }};">{{ $isCapped ? 'CAPPED' : $capPercent . '%' }}</span>
                    </div>
                    <div class="ds-progress-track">
                        <div class="ds-progress-bar {{ $isCapped ? 'ds-bar-amber' : 'ds-bar-green' }}" style="width:{{ $capPercent }}%;"></div>
                    </div>
                </div>
            </div>
            @endunless

            {{-- Quick compliance card --}}
            <div x-show="sub.overview === 'compliance'" x-cloak class="pg-pane " data-tour="portal-home-compliance">
                <div class="mp-h"><h3>Compliance Overview</h3><button @click="setTab('compliance')" class="mp-more">View full compliance &rarr;</button></div>
                @php $dotColors = ['green' => 'var(--ds-green)', 'amber' => 'var(--ds-amber)', 'red' => 'var(--ds-crimson)', 'grey' => 'var(--text-muted)', 'missing' => 'var(--text-muted)']; @endphp
                @php
                    // AT-267 §10 / AUDIT 2026-07-26 (F9) — filtered against the keys
                    // computeComplianceStatus() actually produced, rather than a second hardcoded
                    // list. An assistant holds no FFC / PI / tax clearance, so those keys are
                    // absent for them. Deriving the rows from the data means the controller stays
                    // the single source of truth and this card can never desync from it again.
                    $overviewItems = array_filter([
                        'ffc_number' => 'FFC Number',
                        'ffc_certificate' => 'FFC Certificate',
                        'ffc_expiry' => 'FFC Expiry',
                        'id_copy' => 'ID Copy',
                        'pi_insurance' => 'PI Insurance',
                        'tax_clearance' => 'Tax Clearance',
                    ], fn ($label, $key) => array_key_exists($key, $complianceStatus), ARRAY_FILTER_USE_BOTH);
                @endphp
                <div class="mp-grid mp-g2">
                    @foreach($overviewItems as $key => $label)
                    @php $item = $complianceStatus[$key]; @endphp
                    <div class="mp-row">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] ?? 'var(--text-muted)' }};"></span>
                            <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $label }}</span>
                        </div>
                        <span class="text-[10px]" style="color:{{ $dotColors[$item['status']] ?? 'var(--text-muted)' }};">{{ $item['label'] }}</span>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Phase 9a G2 — Presentations widget (light agent stats). AT-267 §10: hidden for assistants. --}}
        @unless($isAssistant ?? false)
        @if(isset($presentationStats))
        <div x-show="sub.overview === 'presentations'" x-cloak class="pg-pane ">
            <div class="mp-h">
                <h3>My Presentations</h3>
                @if(\Illuminate\Support\Facades\Route::has('presentations.index'))
                    <a href="{{ route('presentations.index') }}" class="mp-more">View all →</a>
                @endif
            </div>
            <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:1px;background:var(--border);">
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">This week</div>
                    <div style="font-size:1.5rem;color:var(--text-primary);font-weight:700;margin-top:3px;">{{ $presentationStats['this_week_count'] }}</div>
                </div>
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Awaiting outcome</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:3px;color:{{ $presentationStats['awaiting_outcome_count'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-primary)' }};">
                        {{ $presentationStats['awaiting_outcome_count'] }}
                    </div>
                    @if($presentationStats['awaiting_outcome_count'] > 0 && \Illuminate\Support\Facades\Route::has('corex.presentations.outcomes.index'))
                        <a href="{{ route('corex.presentations.outcomes.index') }}?status=open" style="font-size:0.625rem;color:var(--ds-amber, #f59e0b);text-decoration:none;">Record →</a>
                    @endif
                </div>
                <div style="background:var(--surface);padding:14px;text-align:center;">
                    <div style="font-size:0.625rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;">Refresh requests</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:3px;color:{{ $presentationStats['refresh_requests_count'] > 0 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-primary)' }};">
                        {{ $presentationStats['refresh_requests_count'] }}
                    </div>
                    @if($presentationStats['refresh_requests_count'] > 0 && \Illuminate\Support\Facades\Route::has('corex.presentations.refresh-requests.index'))
                        <a href="{{ route('corex.presentations.refresh-requests.index') }}" style="font-size:0.625rem;color:var(--ds-amber, #f59e0b);text-decoration:none;">Open inbox →</a>
                    @endif
                </div>
            </div>
        </div>
        @endif
        @endunless

        {{-- Recent activity --}}
        <div x-show="sub.overview === 'activity'" x-cloak class="pg-pane pg-wide">
            <div class="mp-h">
                <h3>Recent Activity</h3>
                @unless($isAssistant ?? false)
                @feature('commission-management')
                <a href="{{ route('commission.dashboard') }}" class="mp-more">Show all &rarr;</a>
                @endfeature
                @endunless
            </div>
            @if($recentActivity->isEmpty())
                <div class="text-xs" style="color:var(--text-muted); padding:8px 0;">No commission entries yet.</div>
            @else
            <div class="divide-y" style="border-color:var(--border);" data-portal-rows="recent-activity" data-portal-cap="8">
                @foreach($recentActivity->take(8) as $tx)
                <div class="flex items-center justify-between px-2 py-1.5">
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
        </div>{{-- /activity pane --}}
            @if($trainingTotal > 0)
            <div x-show="sub.overview === 'training'" x-cloak class="pg-pane ">
                <div class="mp-h"><h3>Training Progress</h3><span class="mp-note">Required for your role: {{ $trainingTotal }} {{ Str::plural('guide', $trainingTotal) }}</span></div>
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

    {{-- Impersonation audit (visible to all users on Overview tab) --}}
    <div x-show="tab === 'overview'" x-cloak data-portal-pane="overview">
        @if($impersonationLogs->count() > 0)
        <div class="rounded-md px-3 py-2" style="margin-top:8px; background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);" data-portal-rows="admin-access" data-portal-cap="4">
            <div class="flex items-center gap-2 mb-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--ds-amber);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <span class="text-xs font-bold uppercase tracking-wider" style="color:var(--ds-amber);">Admin Access Log</span>
            </div>
            <div class="mp-grid mp-g2">
                @foreach($impersonationLogs->take(4) as $log)
                <div class="flex items-center justify-between text-xs">
                    <span style="color:var(--text-primary);">{{ $log->admin?->name ?? 'Unknown admin' }}</span>
                    <span style="color:var(--text-muted);">{{ $log->created_at->format('d M Y, H:i') }}</span>
                </div>
                @endforeach
            </div>
            @if($impersonationLogs->count() > 4)<div class="mp-note" style="margin-top:2px;">and {{ $impersonationLogs->count() - 4 }} earlier in the last 30 days</div>@endif
        </div>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: PROFILE
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'profile'" x-cloak data-portal-pane="profile">
        <nav class="pg-subs" aria-label="Sections">
            <button type="button" class="pg-sub" data-portal-sub="signature" @click="sub.profile = 'signature'" :aria-current="sub.profile === 'signature' ? 'page' : null">Signature &amp; PIN</button>
            <button type="button" class="pg-sub" data-portal-sub="photo" @click="sub.profile = 'photo'" :aria-current="sub.profile === 'photo' ? 'page' : null">Photo &amp; Public Page</button>
            <button type="button" class="pg-sub" data-portal-sub="details" @click="sub.profile = 'details'" :aria-current="sub.profile === 'details' ? 'page' : null">Contact &amp; Licence</button>
            @unless($isAssistant ?? false)
            <button type="button" class="pg-sub" data-portal-sub="website" @click="sub.profile = 'website'" :aria-current="sub.profile === 'website' ? 'page' : null">Public Website Profile</button>
            @endunless
            <button type="button" class="pg-sub" data-portal-sub="admin" @click="sub.profile = 'admin'" :aria-current="sub.profile === 'admin' ? 'page' : null">Admin Managed</button>
            @if(!empty($canSelfAssignBranches) && $selfAssignAgencyBranches->count() > 0)
            <button type="button" class="pg-sub" data-portal-sub="branches" @click="sub.profile = 'branches'" :aria-current="sub.profile === 'branches' ? 'page' : null">Branches I Manage</button>
            @endif
            <button type="button" class="pg-sub" data-portal-sub="articles" @click="sub.profile = 'articles'" :aria-current="sub.profile === 'articles' ? 'page' : null">Articles</button>
        </nav>

        <div x-show="sub.profile === 'signature'" x-cloak class="pg-pane pg-wide">
            @include('agent._signature-settings')
        </div>

        <div x-show="sub.profile === 'photo'" x-cloak class="pg-pane ">
        {{-- Live preview of the public agent page --}}
        <div style="margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
            <div>
                <div class="text-sm font-bold" style="color:var(--text-primary);">Your public agent page</div>
            </div>
            <a href="{{ auth()->user()->publicProfileUrl() }}" target="_blank" class="corex-btn-primary text-sm" style="white-space:nowrap;">
                Preview my agent page ↗
            </a>
        </div>

        {{-- Profile photo upload --}}
        <div x-data="{ open:true }">
            <button type="button" @click="open=!open" style="width:100%; display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 0 8px; background:none; border:0; cursor:pointer; text-align:left;">
                <div>
                    <div class="text-sm font-bold" style="color:var(--text-primary);">Profile Photo</div>
                </div>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--text-muted); flex:0 0 16px; transition:transform .2s;" :style="{ transform: open ? 'rotate(180deg)' : 'none' }"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>
            <div x-show="open" x-cloak>
                <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="document_type" value="photo">
                    <x-agent-photo-cropper name="file" :current="$photoUrl" :autosubmit="true" :size="80" />
                </form>
            </div>
        </div>

        </div>

        <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

        <form method="post" action="{{ route('agent.portal.profile.update') }}">
            @csrf
            @method('patch')

            <div x-show="sub.profile === 'details'" x-cloak class="pg-pane ">
                <div class="mp-grid mp-g3 mp-field">
                    {{-- Name --}}
                    <div>
                        <label for="name">Name <span class="text-red-500">*</span></label>
                        <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autocomplete="name">
                        @error('name') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Email --}}
                    <div style="grid-column:span 2;">
                        @if($user->isSubUser())
                        {{-- AT-423 — a sub-user's username is changed only by an admin. --}}
                        <label for="email">Username</label>
                        <input id="email" type="text" value="{{ $user->email }}" disabled>
                        <p style="font-size:0.6875rem; color:var(--text-muted); margin-top:3px;">Sign-in username; CoreX emails go to {{ $user->deliveryEmail() ?? 'your agency\'s shared inbox' }}. Only your admin can change it.</p>
                        @else
                        <label for="email">Email <span class="text-red-500">*</span></label>
                        <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                        @endif
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
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" placeholder="Landline">
                        @error('phone') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- Cell --}}
                    <div>
                        <label for="cell">Cell <span class="text-red-500">*</span></label>
                        <input id="cell" name="cell" type="tel" value="{{ old('cell', $user->cell) }}" placeholder="Mobile" required>
                        @error('cell') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- WhatsApp --}}
                    <div>
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:3px;">
                            <label for="whatsapp_number" style="margin:0;">WhatsApp</label>
                            <button type="button"
                                    onclick="const f=this.closest('form'); const w=f.querySelector('[name=whatsapp_number]'); w.value=f.querySelector('[name=cell]').value; w.dispatchEvent(new Event('input'));"
                                    style="font-size:0.6875rem; font-weight:600; color:var(--brand-button); background:none; border:none; cursor:pointer; padding:0;">Same as cell</button>
                        </div>
                        <input id="whatsapp_number" name="whatsapp_number" type="tel" value="{{ old('whatsapp_number', $user->whatsapp_number) }}" placeholder="WhatsApp number">
                        @error('whatsapp_number') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- ID Number --}}
                    <div>
                        <label for="id_number">ID Number</label>
                        <input id="id_number" name="id_number" type="text" value="{{ old('id_number', $user->id_number) }}" placeholder="SA ID number">
                        @error('id_number') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Number / Expiry — AT-267 §10: assistants are not PPRA practitioners --}}
                    @unless($isAssistant ?? false)
                    {{-- FFC Number --}}
                    <div>
                        <label for="ffc_number">FFC Number</label>
                        <input id="ffc_number" name="ffc_number" type="text" value="{{ old('ffc_number', $user->ffc_number) }}" placeholder="FFC number">
                        @error('ffc_number') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    {{-- FFC Expiry Date --}}
                    <div>
                        <label for="ffc_expiry_date">FFC Expiry Date</label>
                        <input id="ffc_expiry_date" name="ffc_expiry_date" type="date" value="{{ old('ffc_expiry_date', $user->ffc_expiry_date?->format('Y-m-d')) }}">
                        @error('ffc_expiry_date') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>
                    @endunless
                </div>

                <div style="margin-top:10px;">
                    <button type="submit" class="corex-btn-primary">Save Profile</button>
                </div>
            </div>

            @unless($isAssistant ?? false)
            <div x-show="sub.profile === 'website'" x-cloak class="pg-pane ">
                {{-- Public website profile — About me + personal social links.
                     Shown on the agent's public website page (distinct from the
                     ad/OAuth accounts under the Tools tab). AT-267 §10: assistants have no public page. --}}
                @unless($isAssistant ?? false)
                <div>
                    <div class="mp-h">
                        <h3>Public Website Profile</h3>
                        <a href="{{ auth()->user()->publicProfileUrl() }}" target="_blank" class="mp-more">Preview ↗</a>
                    </div>
                    <div class="mp-field">
                        <div style="margin-bottom:8px;">
                            <label for="about_me" style="display:block; font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">About me</label>
                            <textarea id="about_me" name="about_me" rows="4" placeholder="A short bio shown on your website profile…"
                                      style="resize:vertical;">{{ old('about_me', $user->about_me) }}</textarea>
                            @error('about_me') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                        </div>
                        <div class="mp-grid mp-g4">
                            @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube'] as $net => $label)
                            <div>
                                <label for="website_social_{{ $net }}">{{ $label }}</label>
                                <input id="website_social_{{ $net }}" name="website_social_{{ $net }}" type="text"
                                       value="{{ old('website_social_'.$net, $user->{'website_social_'.$net}) }}" placeholder="Profile URL">
                                @error('website_social_'.$net) <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endunless

                <div style="margin-top:10px;">
                    <button type="submit" class="corex-btn-primary">Save Profile</button>
                </div>
            </div>
            @endunless

            <div x-show="sub.profile === 'admin'" x-cloak class="pg-pane ">
                {{-- Read-only admin fields --}}
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px; height:14px; color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        <span style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Admin Managed</span>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px;">
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Designation</div>
                            <div style="padding:6px 10px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->designation ?: 'Not set' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Role</div>
                            <div style="padding:6px 10px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ ucfirst(str_replace('_', ' ', $user->role ?? 'agent')) }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Branch</div>
                            <div style="padding:6px 10px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->branch?->display_name ?? 'Not assigned' }}</div>
                        </div>
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">Agency</div>
                            <div style="padding:6px 10px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">{{ $user->agency?->name ?? 'Not assigned' }}</div>
                        </div>
                        @unless($isAssistant ?? false)
                        <div>
                            <div style="font-size:0.6875rem; font-weight:600; color:var(--text-muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em;">PPRA Status</div>
                            <div style="padding:6px 10px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary); font-size:0.8125rem; opacity:0.7;">
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
                        @endunless
                    </div>
                </div>

            </div>
        </form>

        {{-- Branches I Manage (Admin Multi-Branch Manager) — only for admins
             holding branches.self_assign_managed. Identity only: picking
             branches here lets you "act as" their manager from the sidebar;
             it never changes what you can see. --}}
        @if(!empty($canSelfAssignBranches) && $selfAssignAgencyBranches->count() > 0)
        <div x-show="sub.profile === 'branches'" x-cloak class="pg-pane">
            <div class="mp-h"><h3>Branches I Manage</h3><span class="mp-note">Tick each branch you manage and mark one default (opens when you sign in). Switch between them from the sidebar.</span></div>
            <form method="POST" action="{{ route('agent.portal.managed-branches.update') }}">
                @csrf
                @method('PATCH')
                <div class="mp-grid mp-g3" style="margin-bottom:10px;" data-portal-rows="managed-branches" data-portal-cap="12">
                    @foreach($selfAssignAgencyBranches->take(12) as $b)
                        @php
                            $isManaged = $managedBranchIds->contains((int) $b->id);
                            $isDefault = $defaultManagedBranchId === (int) $b->id;
                        @endphp
                        <div class="mp-row" style="background:var(--surface-2);">
                            <label style="display:flex; align-items:center; gap:8px; flex:1; cursor:pointer; margin:0; min-width:0;">
                                <input type="checkbox" name="managed_branches[]" value="{{ $b->id }}" {{ $isManaged ? 'checked' : '' }}>
                                <span class="truncate" style="font-size:0.8125rem; color:var(--text-primary);">{{ $b->name }}@if($b->code)<span style="color:var(--text-muted);"> ({{ $b->code }})</span>@endif</span>
                            </label>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.75rem; color:var(--text-muted); margin:0;">
                                <input type="radio" name="default_branch_id" value="{{ $b->id }}" {{ $isDefault ? 'checked' : '' }}>
                                Default
                            </label>
                        </div>
                    @endforeach
                </div>
                <button type="submit" class="corex-btn-primary">Save Managed Branches</button>
            </form>
        </div>
        @endif

        {{-- Articles - agent-authored content for the public website profile.
             One form slot on the left (Add, or Edit when a row's Edit is pressed); the agent's articles on the right,
             six to a page, so the pane never grows with the number of articles. --}}
        <div x-data="{ editingId: null, page: 0, per: 6, total: {{ $articles->count() }} }" x-show="sub.profile === 'articles'" x-cloak class="pg-pane pg-wide mp-field">
            @error('cover_image') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin:0 0 6px;">{{ $message }}</p> @enderror
            @error('link_url') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin:0 0 6px;">{{ $message }}</p> @enderror
            <div class="mp-grid" style="grid-template-columns:minmax(0, 5fr) minmax(0, 7fr); align-items:start;">
                <div>
                    {{-- Add article --}}
                    <form x-show="editingId === null" method="POST" action="{{ route('agent.portal.articles.store') }}" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:6px;">
                        @csrf
                        <div class="mp-h" style="margin:0;"><h3>Add article</h3><span class="mp-note">Tick Publish on a row to put it on your website</span></div>
                        <input name="title" required maxlength="200" placeholder="Article title">
                        <input name="excerpt" maxlength="500" placeholder="Short summary (optional)">
                        <textarea name="body" rows="4" placeholder="Article content…" style="resize:vertical;"></textarea>
                        <div class="mp-grid mp-g2">
                            <input name="link_url" type="url" maxlength="500" placeholder="Connecting link (optional)">
                            <input name="tags" maxlength="500" placeholder="Tags, comma separated">
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <input name="cover_image" type="file" accept="image/jpeg,image/png,image/webp" title="Cover image (optional)" style="font-size:0.75rem; padding:3px 6px;">
                            <button type="submit" class="corex-btn-primary" style="font-size:0.8125rem; white-space:nowrap;">Add Article</button>
                        </div>
                    </form>

                    {{-- Edit panels (take the form's place when a row's Edit is pressed) --}}
                    @foreach($articles as $article)
                        <form x-show="editingId === {{ $article->id }}" x-cloak method="POST" action="{{ route('agent.portal.articles.update', $article) }}" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:6px;">
                            @csrf @method('PUT')
                            <div class="mp-h" style="margin:0;"><h3>Editing: {{ \Illuminate\Support\Str::limit($article->title, 40) }}</h3></div>
                            <input name="title" required maxlength="200" value="{{ $article->title }}">
                            <input name="excerpt" maxlength="500" value="{{ $article->excerpt }}" placeholder="Short summary">
                            <textarea name="body" rows="4" style="resize:vertical;">{{ $article->body }}</textarea>
                            <div class="mp-grid mp-g2">
                                <input name="link_url" type="url" maxlength="500" value="{{ $article->link_url }}" placeholder="Connecting link (optional)">
                                <input name="tags" maxlength="500" value="{{ $article->tags }}" placeholder="Tags, comma separated">
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="flex items-center gap-2">
                                    <input name="cover_image" type="file" accept="image/jpeg,image/png,image/webp" title="{{ $article->coverImageUrl() ? 'Replace' : 'Add' }} cover image" style="font-size:0.75rem; padding:3px 6px;">
                                    @if($article->coverImageUrl())
                                        <label style="font-size:0.6875rem; color:var(--text-secondary); display:flex; align-items:center; gap:4px; cursor:pointer; margin:0; text-transform:none; letter-spacing:0;"><input type="checkbox" name="remove_cover" value="1" style="width:auto; accent-color:var(--ds-crimson);"> Remove cover</label>
                                    @endif
                                </span>
                                <span class="flex gap-2">
                                    <button type="button" @click="editingId = null" style="font-size:0.8125rem; padding:5px 12px; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text-secondary); cursor:pointer;">Cancel</button>
                                    <button type="submit" class="corex-btn-primary" style="font-size:0.8125rem;">Save</button>
                                </span>
                            </div>
                        </form>
                    @endforeach
                </div>

                <div>
                    <div class="mp-h" style="margin-bottom:6px;"><h3>My articles <span class="mp-note" style="font-weight:400;">({{ $articles->count() }})</span></h3>
                        <span class="flex items-center gap-2" x-show="total > per" x-cloak>
                            <button type="button" class="mp-more" @click="page = Math.max(0, page - 1)" :disabled="page === 0" :style="page === 0 ? 'opacity:.4' : ''">&larr; Newer</button>
                            <span class="mp-note" x-text="(page + 1) + ' / ' + Math.ceil(total / per)"></span>
                            <button type="button" class="mp-more" @click="page = Math.min(Math.ceil(total / per) - 1, page + 1)" :disabled="(page + 1) * per >= total" :style="(page + 1) * per >= total ? 'opacity:.4' : ''">Older &rarr;</button>
                        </span>
                    </div>
                    @if($articles->isEmpty())
                        <p style="font-size:0.8125rem; color:var(--text-muted); margin:0;">No articles yet. Add one on the left.</p>
                    @else
                        <div style="display:flex; flex-direction:column; gap:5px;" data-portal-rows="articles" data-portal-cap="6">
                            @foreach($articles as $article)
                                <div class="mp-row" x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per" @if($loop->index >= 6) x-cloak @endif>
                                    <div class="flex items-center gap-2 min-w-0">
                                        @if($article->coverImageUrl())
                                            <img src="{{ $article->coverImageUrl() }}" alt="" style="width:30px; height:30px; object-fit:cover; border-radius:4px; border:1px solid var(--border); flex:0 0 30px;">
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate" style="font-size:0.8125rem; font-weight:600; color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($article->title, 48) }}</div>
                                            <div class="mp-note">{{ $article->readMinutes() }} min &middot; {{ number_format($article->wordCount()) }} words</div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3" style="flex:0 0 auto;">
                                        <span class="ds-badge {{ $article->is_published ? 'ds-badge-success' : 'ds-badge-default' }}">{{ $article->is_published ? 'Published' : 'Draft' }}</span>
                                        <form method="POST" action="{{ route('agent.portal.articles.publish', $article) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="is_published" value="0">
                                            <label style="display:flex; align-items:center; gap:4px; font-size:0.6875rem; color:var(--text-secondary); cursor:pointer; margin:0; text-transform:none; letter-spacing:0;">
                                                <input type="checkbox" name="is_published" value="1" onchange="this.form.submit()" {{ $article->is_published ? 'checked' : '' }} style="width:auto; accent-color:var(--brand-button);"> On website
                                            </label>
                                        </form>
                                        <a href="{{ route('corex.agents.article.preview', [auth()->user(), $article, $article->previewSlug()]) }}" target="_blank" title="Preview" style="font-size:0.75rem; color:var(--brand-icon); text-decoration:none;">↗</a>
                                        <button type="button" @click="editingId = (editingId === {{ $article->id }} ? null : {{ $article->id }})" style="font-size:0.6875rem; font-weight:600; color:var(--brand-icon); background:none; border:none; cursor:pointer;">Edit</button>
                                        <form method="POST" action="{{ route('agent.portal.articles.destroy', $article) }}" onsubmit="return confirm('Delete this article?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" title="Delete" style="font-size:0.75rem; font-weight:700; color:var(--ds-crimson); background:none; border:none; cursor:pointer;">✕</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>{{-- /tab: profile --}}

    {{-- ═══════════════════════════════════════════
         TAB: FAVOURITES — choose the pages that appear in the sidebar
         Favourites panel above your name.
         The checklist is built client-side from the live sidebar
         (window.CorexNavSearch) so it always mirrors exactly what THIS user
         can open — no hand-maintained page registry, nothing to keep in sync.
         Spec: .ai/specs/sidebar-favourites.md §6.3, §6.4
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'favourites'" x-cloak data-portal-pane="favourites">
        <nav class="pg-subs" aria-label="Sections">
            <button type="button" class="pg-sub" data-portal-sub="intro" @click="sub.favourites = 'intro'" :aria-current="sub.favourites === 'intro' ? 'page' : null">Favourites</button>
            <button type="button" class="pg-sub" data-portal-sub="mine" @click="sub.favourites = 'mine'" :aria-current="sub.favourites === 'mine' ? 'page' : null">My Favourites</button>
            <button type="button" class="pg-sub" data-portal-sub="all" @click="sub.favourites = 'all'" :aria-current="sub.favourites === 'all' ? 'page' : null">All Pages</button>
        </nav>
        <form method="POST" action="{{ route('agent.portal.favourites.update') }}"
              x-data="corexFavouritesPicker(@js($navFavourites->map(fn($f) => ['key' => $f->nav_key, 'label' => $f->label])->values()), {{ $navFavouriteMax }}, @js((bool) $user->nav_favourites_autoopen))"
              x-init="init()">
            @csrf
            @method('PUT')

            <div>
                <div x-show="sub.favourites === 'intro'" x-cloak class="pg-pane">
                <div class="mp-h"><h3>Favourites</h3><span class="mp-note">Your most-used pages, in a list above your name in the sidebar.</span></div>

                {{-- ── Auto-open ─────────────────────────────────────────── --}}
                {{-- The hidden 0 keeps auto_open ALWAYS present in this post, so
                     unticking the box actually turns the setting off. An unchecked
                     checkbox submits nothing, and the controller's $request->has()
                     guard (which protects partial posts from other forms) would
                     otherwise read "absent" and leave the setting untouched. --}}
                <input type="hidden" name="auto_open" value="0">
                <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:8px 12px; border:1px solid var(--border); border-radius:6px; background:var(--surface-2);">
                    <input type="checkbox" name="auto_open" value="1" x-model="autoOpen"
                           style="margin-top:2px; width:16px; height:16px; accent-color:var(--brand-button, #0ea5e9);">
                    <span>
                        <span style="display:block; font-size:0.8125rem; font-weight:500; color:var(--text-primary);">Open my Favourites automatically when I sign in</span>
                        <span style="display:block; font-size:0.75rem; color:var(--text-muted); margin-top:2px;">
                            The list opens by itself the first time you land in CoreX after signing in.
                        </span>
                    </span>
                </label>

                </div>{{-- /pane: intro --}}

                {{-- ── My favourites (ordered) ───────────────────────────── --}}
                <div x-show="sub.favourites === 'mine'" x-cloak class="pg-pane">
                    <div class="mp-h">
                        <h3>My favourites <span class="mp-note" style="font-weight:400;"><span x-text="chosen.length"></span> of {{ $navFavouriteMax }}</span></h3>
                        <span class="mp-note">Drag to reorder, or use the arrows - this is the sidebar order.</span>
                    </div>

                    <template x-if="chosen.length === 0">
                        <div style="padding:16px; border:1px dashed var(--border); border-radius:6px; font-size:0.8125rem; color:var(--text-muted);">
                            You haven't chosen any pages yet — tick them below.
                        </div>
                    </template>

                    <div x-show="chosen.length > 0" style="display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:4px;">
                        <template x-for="(fav, i) in chosen" :key="fav.key">
                            <div draggable="true"
                                 @dragstart="startDrag(i)" @dragover.prevent="dragOver(i)"
                                 @drop.prevent="endDrag()" @dragend="endDrag()"
                                 {{-- Object syntax, never a string: Alpine's setStylesFromString()
                                      does setAttribute('style', value), which REPLACES the static
                                      style attribute — a string binding here wiped display:flex and
                                      wrapped every row. Object syntax merges per property. --}}
                                 :style="{ opacity: i === dragIndex ? 0.45 : 1 }"
                                 style="display:flex; align-items:center; gap:6px; padding:4px 8px; border:1px solid var(--border); border-radius:6px; background:var(--surface); cursor:grab; min-width:0;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:14px; height:14px; color:var(--text-muted); flex-shrink:0;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9h16.5m-16.5 6.75h16.5" />
                                </svg>
                                <span style="flex:1; font-size:0.8125rem; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" x-text="fav.label" :title="fav.label"></span>
                                <button type="button" @click="move(i, -1)" :disabled="i === 0" title="Move up"
                                        style="padding:3px; border:none; background:transparent; cursor:pointer; color:var(--text-muted);"
                                        :style="{ opacity: i === 0 ? 0.3 : 1, cursor: i === 0 ? 'default' : 'pointer' }">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" @click="move(i, 1)" :disabled="i === chosen.length - 1" title="Move down"
                                        style="padding:3px; border:none; background:transparent; cursor:pointer; color:var(--text-muted);"
                                        :style="{ opacity: i === chosen.length - 1 ? 0.3 : 1, cursor: i === chosen.length - 1 ? 'default' : 'pointer' }">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                                <button type="button" @click="removeAt(i)" title="Remove from favourites"
                                        style="padding:3px; border:none; background:transparent; cursor:pointer; color:var(--ds-crimson, #dc2626);">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                                <input type="hidden" :name="'favourites[' + i + '][key]'" :value="fav.key">
                                <input type="hidden" :name="'favourites[' + i + '][label]'" :value="fav.label">
                            </div>
                        </template>
                    </div>

                    <p x-show="atLimit()" x-cloak style="margin-top:8px; font-size:0.75rem; color:var(--ds-amber, #d97706);">
                        You've reached the limit of {{ $navFavouriteMax }} favourites. Remove one to add another.
                    </p>
                </div>

                {{-- ── All pages you can open ────────────────────────────── --}}
                <div x-show="sub.favourites === 'all'" x-cloak class="pg-pane pg-wide">
                    <div class="flex flex-wrap items-center gap-2" style="margin-bottom:8px;">
                        <h3 style="font-size:0.875rem; font-weight:700; color:var(--text-primary); margin:0;">All pages you can open</h3>
                        <input type="text" x-model="q" placeholder="Search pages…" autocomplete="off" spellcheck="false"
                               aria-label="Search pages"
                               style="flex:1; min-width:200px; padding:5px 10px; font-size:0.8125rem; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary);">
                        <select x-model="filterMode" aria-label="Filter pages"
                                style="padding:5px 10px; font-size:0.8125rem; border-radius:6px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary);">
                            <option value="all">All pages</option>
                            <option value="mine">Only my favourites</option>
                        </select>
                    </div>

                    <template x-if="!ready">
                        <p style="font-size:0.8125rem; color:var(--text-muted);">Reading your menu…</p>
                    </template>

                    <template x-if="ready && failed">
                        <p style="font-size:0.8125rem; color:var(--text-muted);">Couldn't read your menu — please reload the page.</p>
                    </template>

                    <template x-if="ready && !failed && shownGroups().length === 0">
                        <p style="font-size:0.8125rem; color:var(--text-muted);"
                           x-text="filterMode === 'mine' ? 'You haven\'t chosen any pages yet.' : 'No pages match “' + q + '”.'"></p>
                    </template>

                    {{-- With ~150 pages in a dozen sections a list always ran off the screen. So: pick a section, see ITS pages
                         four across; a search or "Only my favourites" shows matches from every section instead. At most
                         forty pages are drawn at once - the search narrows the rest. --}}
                    <div x-show="ready && !failed && !searching()" class="flex flex-wrap gap-1" style="margin-bottom:8px;">
                        <template x-for="section in sections" :key="section.label">
                            <button type="button" @click="activeSection = section.label"
                                    :style="currentSection() === section.label
                                        ? 'background:color-mix(in srgb, var(--brand-button) 14%, transparent); color:var(--brand-button); border-color:color-mix(in srgb, var(--brand-button) 35%, transparent);'
                                        : 'background:var(--surface-2); color:var(--text-secondary); border-color:var(--border);'"
                                    style="font-size:0.75rem; padding:3px 9px; border-radius:999px; border:1px solid; cursor:pointer;">
                                <span x-text="section.label"></span><span x-show="chosenIn(section) > 0" style="opacity:.7;" x-text="' · ' + chosenIn(section)"></span>
                            </button>
                        </template>
                    </div>

                    <div x-show="ready && !failed && shownGroups().length > 0" style="display:flex; flex-direction:column; gap:6px;">
                        <template x-for="group in shownGroups()" :key="group.label">
                            <div>
                                <div x-show="searching()" style="font-size:0.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); margin-bottom:2px;" x-text="group.label"></div>
                                <div style="display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:2px 12px;">
                                    <template x-for="page in group.pages" :key="page.key">
                                        <label style="display:flex; align-items:center; gap:8px; font-size:0.8125rem; color:var(--text-primary); padding:2px 0; min-width:0;"
                                               :style="{ opacity: (!isChosen(page.key) && atLimit()) ? 0.45 : 1, cursor: (!isChosen(page.key) && atLimit()) ? 'default' : 'pointer' }">
                                            <input type="checkbox" :checked="isChosen(page.key)"
                                                   :disabled="!isChosen(page.key) && atLimit()"
                                                   @change="togglePage(page)"
                                                   style="width:16px; height:16px; border-radius:4px; flex:0 0 16px; accent-color:var(--brand-button, #0ea5e9);">
                                            <span x-text="page.label" :title="page.label" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <p x-show="hiddenCount() > 0" x-cloak style="font-size:0.75rem; color:var(--text-muted); margin:0;" x-text="hiddenCount() + ' more match - type in the search box to narrow them down.'"></p>
                    </div>
                </div>

                {{-- One Save for the whole form, always visible whichever section is open. --}}
                <div class="pg-pane" style="padding-top:8px; padding-bottom:0;">
                    <button type="submit" class="corex-btn-primary">Save Favourites</button>
                </div>
            </div>
        </form>
    </div>{{-- /tab: favourites --}}

    <script>
    // ── Favourites picker ────────────────────────────────────────────────
    // Spec: .ai/specs/sidebar-favourites.md §6.3, §6.4
    // Plain (non-deferred) script so the factory exists before Alpine (loaded as
    // a deferred module by the Vite bundle) evaluates the x-data above.
    function corexFavouritesPicker(saved, max, autoOpen) {
        return {
            ready: false,
            failed: false,
            max: max || 25,
            autoOpen: !!autoOpen,
            chosen: (saved || []).map(function (f) { return { key: f.key, label: f.label }; }),
            sections: [],
            q: '',
            filterMode: 'all',
            dragIndex: null,
            activeSection: '',
            cap: 40,

            init() {
                const run = () => this.build();
                if (window.CorexNavSearch) run();
                else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
                else run();
            },

            // Must match NavFavouriteService::normalisePath() on the server —
            // same-origin pathname, trailing slash stripped — so a page is one
            // favourite, not two.
            navKeyFor(entry) {
                if (!entry.href) return null;
                try {
                    let path = new URL(entry.href, location.origin).pathname;
                    if (path.length > 1) path = path.replace(/\/+$/, '');
                    return 'p:' + path;
                } catch (e) {
                    return null;
                }
            },

            build() {
                if (!window.CorexNavSearch) { this.failed = true; this.ready = true; return; }

                const entries = window.CorexNavSearch.build();
                const map = {};
                const order = [];
                const seen = {};

                entries.forEach((e) => {
                    // Decision #1 — only entries that open a page. A group toggle
                    // just slides a sub-menu open and is not pinnable.
                    if (e.group) return;
                    const key = this.navKeyFor(e);
                    if (!key) return;

                    const section = e.parent || 'Pages';
                    if (!map[section]) { map[section] = { label: section, pages: [] }; order.push(section); }

                    const dedupe = section + '|' + key;
                    if (seen[dedupe]) return;
                    seen[dedupe] = true;
                    map[section].pages.push({ key: key, label: e.label });
                });

                const sections = order.map((s) => map[s]);
                sections.forEach((s) => s.pages.sort((a, b) => a.label.localeCompare(b.label)));
                sections.sort((a, b) => {
                    if (a.label === 'Pages') return -1;
                    if (b.label === 'Pages') return 1;
                    return a.label.localeCompare(b.label);
                });

                // Refresh stored labels from the live sidebar, so a page renamed
                // since it was pinned shows its current name.
                const labels = {};
                sections.forEach((s) => s.pages.forEach((p) => { labels[p.key] = p.label; }));
                this.chosen.forEach((c) => { if (labels[c.key]) c.label = labels[c.key]; });

                this.sections = sections;
                this.failed = sections.length === 0;
                this.ready = true;
            },

            isChosen(key) { return this.chosen.some((c) => c.key === key); },

            atLimit() { return this.chosen.length >= this.max; },

            togglePage(page) {
                const i = this.chosen.findIndex((c) => c.key === page.key);
                if (i >= 0) { this.chosen.splice(i, 1); return; }
                if (this.atLimit()) return;
                this.chosen.push({ key: page.key, label: page.label });
            },

            removeAt(i) { this.chosen.splice(i, 1); },

            move(i, d) {
                const j = i + d;
                if (j < 0 || j >= this.chosen.length) return;
                const row = this.chosen.splice(i, 1)[0];
                this.chosen.splice(j, 0, row);
            },

            startDrag(i) { this.dragIndex = i; },

            dragOver(i) {
                if (this.dragIndex === null || this.dragIndex === i) return;
                const row = this.chosen.splice(this.dragIndex, 1)[0];
                this.chosen.splice(i, 0, row);
                this.dragIndex = i;
            },

            endDrag() { this.dragIndex = null; },

            // True when the list shows matches across every section instead of the one chosen section.
            searching() { return (this.q || '').trim() !== '' || this.filterMode === 'mine'; },

            currentSection() { return this.activeSection || (this.sections[0] ? this.sections[0].label : ''); },

            chosenIn(section) { return section.pages.filter((p) => this.isChosen(p.key)).length; },

            allGroups() {
                if (this.searching()) return this.visibleSections();
                const s = this.sections.find((x) => x.label === this.currentSection());
                return s ? [{ label: s.label, pages: s.pages }] : [];
            },

            shownGroups() {
                let budget = this.cap;
                const out = [];
                this.allGroups().forEach((g) => {
                    if (budget <= 0) return;
                    const pages = g.pages.slice(0, budget);
                    budget -= pages.length;
                    out.push({ label: g.label, pages: pages });
                });
                return out;
            },

            hiddenCount() {
                const total = this.allGroups().reduce((n, g) => n + g.pages.length, 0);
                return Math.max(0, total - this.cap);
            },

            visibleSections() {
                const q = (this.q || '').trim().toLowerCase();
                const mine = this.filterMode === 'mine';
                const out = [];
                this.sections.forEach((s) => {
                    const pages = s.pages.filter((p) => {
                        if (mine && !this.isChosen(p.key)) return false;
                        if (!q) return true;
                        return p.label.toLowerCase().indexOf(q) >= 0 || s.label.toLowerCase().indexOf(q) >= 0;
                    });
                    if (pages.length) out.push({ label: s.label, pages: pages });
                });
                return out;
            },
        };
    }
    </script>

    {{-- ═══════════════════════════════════════════
         TAB: TOOLS — utilities moved out of Profile
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'tools'" x-cloak data-portal-pane="tools">
        <nav class="pg-subs" aria-label="Sections">
            <button type="button" class="pg-sub" data-portal-sub="theme" @click="sub.tools = 'theme'" :aria-current="sub.tools === 'theme' ? 'page' : null">Theme</button>
            <button type="button" class="pg-sub" data-portal-sub="app" @click="sub.tools = 'app'" :aria-current="sub.tools === 'app' ? 'page' : null">App Access</button>
            @if($user->portal_show_api_token)
            <button type="button" class="pg-sub" data-portal-sub="chrome" @click="sub.tools = 'chrome'" :aria-current="sub.tools === 'chrome' ? 'page' : null">Chrome extension</button>
            @endif
            @if($user->portal_show_social_accounts && \Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
            <button type="button" class="pg-sub" data-portal-sub="social" @click="sub.tools = 'social'" :aria-current="sub.tools === 'social' ? 'page' : null">Social Media</button>
            @endif
            <button type="button" class="pg-sub" data-portal-sub="qr" @click="sub.tools = 'qr'" :aria-current="sub.tools === 'qr' ? 'page' : null">Client QR Code</button>
            @if(auth()->user()->hasPermission('access_communication'))
            <button type="button" class="pg-sub" data-portal-sub="wa" @click="sub.tools = 'wa'" :aria-current="sub.tools === 'wa' ? 'page' : null">WhatsApp</button>
            @endif
        </nav>

        {{-- Theme Preference --}}
        <div x-show="sub.tools === 'theme'" x-cloak class="pg-pane "
             x-data="{ current: localStorage.getItem('corex-theme') || '{{ $user->theme ?? 'dark' }}' }">
            <div class="mp-h"><h3>Theme Preference</h3><span class="mp-note">Synced across your devices.</span></div>
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

        {{-- App Access — mobile "Delete my account" (Apple 5.1.1(v)).
             Spec: .ai/specs/mobile-app-access.md §5. Unconditional: every
             agent has this, unlike the settings-gated cards below. --}}
        <div x-show="sub.tools === 'app'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>App Access</h3><span class="mp-note">Whether you can sign in to the CoreX mobile app. Turning it off in the app never affects your account here.</span></div>
            @if($user->hasAppAccess())
                <span class="ds-badge ds-badge-success">Enabled</span>
            @else
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="ds-badge ds-badge-warning">Disabled &mdash; turned off in the mobile app on {{ $user->app_access_revoked_at->format('d M Y') }}</span>
                    <form method="POST" action="{{ route('agent.portal.app-access.restore') }}">
                        @csrf
                        <button type="submit" class="corex-btn-primary text-xs">Turn App Access back on</button>
                    </form>
                </div>
            @endif
        </div>

        @if($user->portal_show_api_token)
        {{-- Chrome extension - ONE sub-tab (Johan, 26 Oct 2026): the API token exists only for the extension, so the token, then
             the install, then the connect step sit together, in that order. --}}
        @php
            $corexExtVersion = null;
            $corexExtManifest = public_path('chrome-extension/portal-capture/manifest.json');
            if (is_file($corexExtManifest)) {
                $corexExtVersion = json_decode(@file_get_contents($corexExtManifest), true)['version'] ?? null;
            }
        @endphp
        <div x-show="sub.tools === 'chrome'" x-cloak class="pg-pane pg-wide" data-testid="chrome-extension-pane"
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
            <div class="mp-h"><h3>CoreX Chrome extension
                @if($corexExtVersion)<span style="font-size:0.7rem; font-weight:600; color:var(--text-muted); border:1px solid var(--border); border-radius:4px; padding:1px 6px; margin-left:6px; vertical-align:middle;">v{{ $corexExtVersion }}</span>@endif
            </h3><span class="mp-note">Pull properties from Property24 straight into CoreX.</span></div>
            <div class="mp-grid mp-g3" style="align-items:start;">
                {{-- 1. The token --}}
                <div class="mp-card" data-testid="chrome-step-token">
                    <div class="mp-lbl" style="margin-bottom:6px;">1 &middot; Your API token</div>
                    <template x-if="error"><div class="text-xs" style="color:var(--ds-crimson); margin-bottom:6px;" x-text="error"></div></template>
                    <template x-if="plaintext">
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--ds-amber); margin-bottom:6px;">Copy this token now &mdash; you won't see it again.</div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" readonly :value="plaintext" style="flex:1; min-width:0; border-radius:6px; border:1px solid var(--border); background:var(--surface); color:var(--text-primary); padding:6px 8px; font-size:0.75rem; font-family:monospace; box-sizing:border-box;">
                                <button @click="copyToken()" type="button" class="corex-btn-primary text-xs"><span x-text="copied ? 'Copied!' : 'Copy'"></span></button>
                            </div>
                            <button @click="generate()" :disabled="loading" type="button" class="corex-btn-outline text-xs disabled:opacity-40 disabled:cursor-not-allowed" style="margin-top:6px;"><span x-text="loading ? 'Generating...' : 'Regenerate'"></span></button>
                        </div>
                    </template>
                    <template x-if="!plaintext">
                        <div>
                            <template x-if="hasToken">
                                <div>
                                    <div class="flex items-center gap-2" style="margin-bottom:6px;">
                                        <span class="ds-badge ds-badge-success">Token active</span>
                                        <span style="font-size:0.75rem; color:var(--text-muted); font-family:monospace;">••••••••••</span>
                                    </div>
                                    <button @click="generate()" :disabled="loading" type="button" class="corex-btn-primary text-xs disabled:opacity-40 disabled:cursor-not-allowed"><span x-text="loading ? 'Generating...' : 'Regenerate Token'"></span></button>
                                </div>
                            </template>
                            <template x-if="!hasToken">
                                <button @click="generate()" :disabled="loading" type="button" class="corex-btn-primary text-xs disabled:opacity-40 disabled:cursor-not-allowed"><span x-text="loading ? 'Generating...' : 'Generate Token'"></span></button>
                            </template>
                        </div>
                    </template>
                </div>
                {{-- 2. Install --}}
                <div class="mp-card" data-testid="chrome-step-install">
                    <div class="mp-lbl" style="margin-bottom:6px;">2 &middot; Install the extension</div>
                    <a href="https://chromewebstore.google.com/detail/corex/hkphgfonopgghdpdjabdfpfdjlhedkcc?authuser=0&hl=en" target="_blank" rel="noopener" class="corex-btn-primary text-xs">Get it on the Chrome Web Store &nearr;</a>
                    <div class="mp-note" style="margin-top:6px;">Chrome keeps it up to date. If you loaded a downloaded copy before, remove it in <code>chrome://extensions</code> first.</div>
                </div>
                {{-- 3. Connect --}}
                <div class="mp-card" data-testid="chrome-step-connect">
                    <div class="mp-lbl" style="margin-bottom:6px;">3 &middot; Connect it</div>
                    <div class="text-xs" style="color:var(--text-secondary);">Open the CoreX extension in Chrome and paste the token into its <strong>API Token</strong> field. Capture works as soon as it is saved.</div>
                </div>
            </div>
        </div>
        @endif

        {{-- Social Media Accounts --}}
        @if($user->portal_show_social_accounts && \Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
        @php
            $fbSocial = $socialAccounts->firstWhere('platform', 'facebook');
        @endphp
        <div x-show="sub.tools === 'social'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>Social Media Accounts</h3></div>
            <div style="display:grid; grid-template-columns:1fr; gap:8px; max-width:420px;">
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
                            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">{{ $fbSocial->platform_page_name }}</div>
                            @else
                            <span class="ds-badge ds-badge-default">Not Connected</span>
                            @endif
                        </div>
                    </div>
                    @if($fbSocial)
                    <form method="POST" action="{{ route('corex.marketing.social.disconnect') }}">@csrf<input type="hidden" name="platform" value="facebook"><input type="hidden" name="return" value="portal">
                        <button type="submit" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 25%, transparent); cursor:pointer;">Disconnect</button>
                    </form>
                    @else
                    <button type="button" onclick="corexConnectSocial('facebook')" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:#1877f2; color:#fff; border:none; cursor:pointer; font-weight:600;">Connect</button>
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
        <div x-show="sub.tools === 'qr'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>Your Client QR Code</h3><span class="mp-note">Prospects who scan it in the CoreX app sign up as your client.</span></div>
            <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
                <div style="background:#ffffff; padding:10px; border-radius:6px; border:1px solid var(--border); width:184px; height:184px; display:flex; align-items:center; justify-content:center;">{{-- White is intentional: QR code requires high-contrast white background --}}
                    <img src="{{ $qrImgSrc }}" alt="Your client onboarding QR code"
                         style="width:164px; height:164px; display:block;">
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

        {{-- ═══════════════════════════════════════════
             WHATSAPP LINK — AT-156 (spec: .ai/specs/whatsapp-link.md)
             In-app capture-device pairing. Server proxies WAHA; the API key
             never reaches the browser. States driven by /communications/wa-link/status.
             ═══════════════════════════════════════════ --}}
        @if(auth()->user()->hasPermission('access_communication'))
        <div x-data="waLink()" x-init="init()"
             x-show="sub.tools === 'wa'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>WhatsApp Link</h3><span class="mp-note">Captures your client conversations into the compliance archive. Read-only - CoreX never sends from your number.</span></div>

            {{-- loading --}}
            <div x-show="state === 'loading'" style="font-size:0.8125rem; color:var(--text-muted);">Checking link status…</div>

            {{-- disabled by agency --}}
            <div x-show="state === 'disabled'" style="font-size:0.8125rem; color:var(--text-secondary);">
                WhatsApp self-linking is turned off for your agency. Ask your administrator to enable it.
            </div>

            {{-- blocked (super-admin / agency-less) --}}
            <div x-show="state === 'blocked'"
                 style="font-size:0.8125rem; color:var(--text-secondary); background:var(--surface-2, rgba(0,0,0,0.03)); border:1px solid var(--border); border-radius:6px; padding:12px 14px;">
                WhatsApp capture linking is only available to agency agents. A platform/owner account cannot own captured threads — sign in as the agency agent whose WhatsApp will be captured.
            </div>

            {{-- WAHA unavailable --}}
            <div x-show="state === 'waha_down'">
                <p style="font-size:0.8125rem; color:var(--text-secondary); margin:0 0 10px;">The WhatsApp capture service is temporarily unavailable.</p>
                <button type="button" class="corex-btn-outline" @click="retry()">Retry</button>
            </div>

            {{-- not linked --}}
            <div x-show="state === 'not_linked'">
                <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
                    <span style="font-size:0.75rem; color:var(--text-muted);">Consent to date —</span>
                    <span style="font-size:0.75rem; color:var(--text-primary);">Opted in: <b x-text="consent.opted_in ?? 0"></b></span>
                    <span style="font-size:0.75rem; color:var(--text-primary);">Pending: <b x-text="consent.pending ?? 0"></b></span>
                    <span style="font-size:0.75rem; color:var(--text-primary);">Opted out: <b x-text="consent.opted_out ?? 0"></b></span>
                </div>
                <button type="button" class="corex-btn-primary" @click="link()" x-bind:disabled="busy">
                    <span x-show="!busy">Link WhatsApp</span><span x-show="busy" x-cloak>Starting…</span>
                </button>
            </div>

            {{-- awaiting scan --}}
            <div x-show="state === 'awaiting_scan'">
                <div style="background:var(--surface-2, rgba(0,0,0,0.03)); border:1px solid var(--border); color:var(--text-secondary); border-radius:6px; padding:10px 14px; font-size:0.75rem; line-height:1.5; margin-bottom:14px;">
                    Capture is <b>read-only</b> — CoreX never sends messages from this number. It's fine to link the same number you use for your own manual WhatsApp outreach. Just don't connect this number to any external automated or bulk-sending bot/service.
                </div>
                <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">
                    <div style="background:#ffffff; padding:8px; border-radius:6px; border:1px solid var(--border); width:200px; height:200px; display:flex; align-items:center; justify-content:center;">
                        <img :src="qrSrc" alt="WhatsApp pairing QR" style="width:184px; height:184px; display:block;"
                             x-on:error="qrError = true" x-show="!qrError">
                        <span x-show="qrError" x-cloak style="font-size:0.75rem; color:var(--text-muted); text-align:center;">Waiting for QR…</span>
                    </div>
                    <div style="flex:1; min-width:220px;">
                        <ol style="font-size:0.8125rem; color:var(--text-secondary); padding-left:18px; margin:0 0 14px; line-height:1.7;">
                            <li>Open WhatsApp on the capture phone.</li>
                            <li>Tap <b>Linked devices → Link a device</b>.</li>
                            <li>Scan the code on the left.</li>
                        </ol>
                        <button type="button" class="corex-btn-outline" @click="unlink()">Cancel</button>
                    </div>
                </div>
            </div>

            {{-- linked --}}
            <div x-show="state === 'linked'">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                    <span style="width:9px; height:9px; border-radius:999px; background:var(--ds-green, #059669); display:inline-block;"></span>
                    <span style="font-size:0.875rem; font-weight:700; color:var(--text-primary);">Linked &amp; capturing</span>
                </div>
                <div style="font-size:0.8125rem; color:var(--text-secondary); line-height:1.7; margin-bottom:14px;">
                    <div>Number: <b x-text="device?.number || '—'"></b></div>
                    <div>Linked since: <span x-text="device?.linked_since || '—'"></span></div>
                    <div>Session health: <span x-text="wahaStatus || 'WORKING'"></span></div>
                </div>
                <button type="button" class="corex-btn-outline" @click="unlink()" x-bind:disabled="busy"
                        style="border-color:var(--ds-crimson, #c41e3a); color:var(--ds-crimson, #c41e3a);">Unlink</button>
            </div>

            {{-- failed --}}
            <div x-show="state === 'failed'">
                <p style="font-size:0.8125rem; color:var(--ds-crimson, #c41e3a); margin:0 0 10px;">The WhatsApp session failed. Restart to try again.</p>
                <button type="button" class="corex-btn-outline" @click="restart()" x-bind:disabled="busy">Restart session</button>
            </div>
        </div>

        <script>
        function waLink() {
            return {
                state: 'loading', consent: {}, device: null, wahaStatus: null,
                busy: false, qrSrc: '', qrError: false, _poll: null,
                _csrf() { return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''; },
                async _get() {
                    const r = await fetch('{{ route('communications.wa-link.status') }}', { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) throw new Error('status ' + r.status);
                    return r.json();
                },
                async _post(url) {
                    const r = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this._csrf() } });
                    return r.json().catch(() => ({}));
                },
                apply(d) {
                    this.state = d.state || 'not_linked';
                    this.consent = d.consent || this.consent;
                    this.device = d.device ?? null;
                    this.wahaStatus = d.waha_status ?? null;
                    if (this.state === 'awaiting_scan') { this.refreshQr(); this.startPoll(); }
                    else { this.stopPoll(); }
                },
                refreshQr() { this.qrError = false; this.qrSrc = '{{ route('communications.wa-link.qr') }}?_=' + Date.now(); },
                startPoll() { if (this._poll) return; this._poll = setInterval(() => this.tick(), 5000); },
                stopPoll() { if (this._poll) { clearInterval(this._poll); this._poll = null; } },
                async tick() { try { const d = await this._get(); if (this.state === 'awaiting_scan' && d.state === 'awaiting_scan') this.refreshQr(); this.apply(d); } catch (e) { this.state = 'waha_down'; this.stopPoll(); } },
                async init() { try { this.apply(await this._get()); } catch (e) { this.state = 'waha_down'; } },
                async retry() { this.state = 'loading'; await this.init(); },
                async link() { this.busy = true; try { this.apply(await this._post('{{ route('communications.wa-link.link') }}')); } catch (e) { this.state = 'waha_down'; } this.busy = false; },
                async restart() { this.busy = true; try { this.apply(await this._post('{{ route('communications.wa-link.restart') }}')); } catch (e) { this.state = 'waha_down'; } this.busy = false; },
                async unlink() { if (!confirm('Unlink this WhatsApp device? Capture will stop.')) return; this.busy = true; try { this.apply(await this._post('{{ route('communications.wa-link.unlink') }}')); } catch (e) {} this.busy = false; },
            };
        }
        </script>
        @endif
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: DOCUMENTS
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'documents'" x-cloak data-portal-pane="documents">
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

            // AT-267 §10 — an assistant's Documents tab shows only identity docs (ID Copy +
            // Profile Photo), never practitioner docs (FFC / PI / Tax Clearance). NOTE: the
            // Proof-of-Residence card is still to be built (needs its upload endpoint wired —
            // UserDocument::DOCUMENT_TYPE_PROOF_OF_ADDRESS exists); do that on the render lane.
            if ($isAssistant ?? false) {
                $docTypeConfig = array_values(array_filter(
                    $docTypeConfig,
                    fn ($c) => in_array($c['type'], ['id_copy', 'photo'], true)
                ));
            }

            $docTypeToKey = ['ffc_certificate' => 'ffc_certificate', 'id_copy' => 'id_copy', 'pi_insurance' => 'pi_insurance', 'tax_clearance' => 'tax_clearance', 'photo' => 'profile_photo'];
            $statusPills = [
                'pending' => ['class' => 'ds-badge-warning', 'text' => 'Pending'],
                'verified' => ['class' => 'ds-badge-success', 'text' => 'Verified'],
                'rejected' => ['class' => 'ds-badge-danger', 'text' => 'Rejected'],
                'expired' => ['class' => 'ds-badge-danger', 'text' => 'Expired'],
                'missing' => ['class' => 'ds-badge-default', 'text' => 'Missing'],
            ];
        @endphp

        @php
            $firstDocKey = ! empty($docTypeConfig) ? ($docTypeToKey[$docTypeConfig[0]['type']] ?? $docTypeConfig[0]['type']) : '';
            $docDotColors = ['verified' => 'var(--ds-green)', 'pending' => 'var(--ds-amber)', 'rejected' => 'var(--ds-crimson)', 'expired' => 'var(--ds-crimson)', 'missing' => 'var(--text-muted)'];
        @endphp
        <div x-init="@if((session('ppra_letter_flash') || $errors->has('signed_copy')) && ($ppraLetterCanReceive ?? false)) sub.documents = 'ppra_employment_letter'; @endif if (! sub.documents) sub.documents = '{{ $firstDocKey }}'"></div>
        <nav class="pg-subs" aria-label="Sections">
            @foreach($docTypeConfig as $docCfg)
            @php
                $navKey = $docTypeToKey[$docCfg['type']] ?? $docCfg['type'];
                $navDoc = $documents->get($navKey);
                $navStatus = $navDoc ? $navDoc->status : 'missing';
            @endphp
            <button type="button" class="pg-sub" data-portal-sub="{{ $navKey }}" @click="sub.documents = '{{ $navKey }}'" :aria-current="sub.documents === '{{ $navKey }}' ? 'page' : null">{{ $docCfg['label'] }}<span class="pg-dot" style="background:{{ $docDotColors[$navStatus] ?? 'var(--text-muted)' }};" title="{{ $statusPills[$navStatus]['text'] ?? '' }}"></span></button>
            @endforeach
            @unless($isAssistant ?? false)
            @if(($ppraLetterCanReceive ?? false) && feature('ppra-employment-letters'))
            <button type="button" class="pg-sub" data-portal-sub="ppra_employment_letter" @click="sub.documents = 'ppra_employment_letter'" :aria-current="sub.documents === 'ppra_employment_letter' ? 'page' : null">PPRA Employment Letter</button>
            @endif
            @endunless
        </nav>
        <div>
            @foreach($docTypeConfig as $docCfg)
            @php
                $docKey = $docTypeToKey[$docCfg['type']] ?? $docCfg['type'];
                $doc = $documents->get($docKey);
                $docStatus = $doc ? $doc->status : 'missing';
                $pill = $statusPills[$docStatus];
            @endphp
            <div x-show="sub.documents === '{{ $docKey }}'" x-cloak class="pg-pane pg-pane-col">
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
                    <a href="{{ route('user-documents.download', $doc) }}" target="_blank" style="font-size:0.6875rem; padding:4px 10px; border-radius:6px; border:1px solid var(--border); color:var(--text-muted); text-decoration:none;">View</a>
                    @endif

                    @if($docCfg['type'] === 'id_copy')
                    {{-- ID Copy: choose PDF or front+back photos (combined into one PDF server-side) --}}
                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data" class="w-full"
                          x-data="{ mode: 'pdf', pdfName: '', frontName: '', backName: '' }">
                        @csrf
                        <input type="hidden" name="document_type" value="id_copy">
                        <input type="hidden" name="id_upload_mode" :value="mode">

                        {{-- Mode toggle --}}
                        <div class="flex items-center gap-1" style="margin-bottom:8px;">
                            <button type="button" @click="mode = 'pdf'"
                                    :style="mode === 'pdf'
                                        ? 'font-size:0.6875rem; padding:4px 12px; border-radius:6px; cursor:pointer; background:color-mix(in srgb, var(--brand-button) 14%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 30%, transparent);'
                                        : 'font-size:0.6875rem; padding:4px 12px; border-radius:6px; cursor:pointer; background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);'">PDF</button>
                            <button type="button" @click="mode = 'photo'"
                                    :style="mode === 'photo'
                                        ? 'font-size:0.6875rem; padding:4px 12px; border-radius:6px; cursor:pointer; background:color-mix(in srgb, var(--brand-button) 14%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 30%, transparent);'
                                        : 'font-size:0.6875rem; padding:4px 12px; border-radius:6px; cursor:pointer; background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border);'">Photo</button>
                        </div>

                        {{-- PDF mode --}}
                        <div x-show="mode === 'pdf'">
                            <label style="display:inline-block; font-size:0.75rem; padding:4px 10px; border-radius:6px; cursor:pointer; background:color-mix(in srgb, var(--brand-button) 12%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent); white-space:nowrap;">
                                {{ $doc ? 'Replace PDF' : 'Upload PDF' }}
                                <input type="file" name="file" accept=".pdf" class="hidden"
                                       @change="pdfName = $event.target.files[0]?.name || ''; if(pdfName) $el.closest('form').submit();">
                            </label>
                        </div>

                        {{-- Photo mode: front + back --}}
                        <div x-show="mode === 'photo'" x-cloak class="flex flex-col" style="gap:6px;">
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:0.75rem; padding:4px 10px; border-radius:6px; cursor:pointer; background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                <span x-text="frontName || 'Front photo'" :style="frontName ? '' : 'color:var(--text-muted);'" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
                                <span style="color:var(--brand-button); flex-shrink:0;" x-text="frontName ? 'Change' : 'Choose'"></span>
                                <input type="file" name="id_front" accept=".jpg,.jpeg,.png,.webp" capture="environment" class="hidden"
                                       @change="frontName = $event.target.files[0]?.name || ''">
                            </label>
                            <label style="display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:0.75rem; padding:4px 10px; border-radius:6px; cursor:pointer; background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                <span x-text="backName || 'Back photo'" :style="backName ? '' : 'color:var(--text-muted);'" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"></span>
                                <span style="color:var(--brand-button); flex-shrink:0;" x-text="backName ? 'Change' : 'Choose'"></span>
                                <input type="file" name="id_back" accept=".jpg,.jpeg,.png,.webp" capture="environment" class="hidden"
                                       @change="backName = $event.target.files[0]?.name || ''">
                            </label>
                            <button type="submit" :disabled="!frontName || !backName"
                                    :style="(frontName && backName)
                                        ? 'font-size:0.75rem; padding:5px 10px; border-radius:6px; cursor:pointer; background:color-mix(in srgb, var(--brand-button) 12%, transparent); color:var(--brand-button); border:1px solid color-mix(in srgb, var(--brand-button) 25%, transparent);'
                                        : 'font-size:0.75rem; padding:5px 10px; border-radius:6px; cursor:not-allowed; background:var(--surface-2); color:var(--text-muted); border:1px solid var(--border); opacity:0.7;'">
                                {{ $doc ? 'Replace with both sides' : 'Upload both sides' }}
                            </button>
                        </div>
                    </form>
                    @else
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
                    @endif
                </div>
            </div>
            @endforeach

            {{-- PPRA FFC Employment Letter — .ai/specs/ppra-ffc-employment-letter.md.
                 Deliberately placed HERE, inside the DOCUMENTS tab wrapper (tab === 'documents'),
                 not the Compliance tab — its own nav button (above) and the x-show key
                 (sub.documents === 'ppra_employment_letter') both live in this tab. A previous
                 version nested this pane inside the Compliance tab wrapper, which made it
                 permanently invisible: clicking the sub-tab set sub.documents correctly, but the
                 enclosing tab === 'compliance' check stayed false while viewing Documents, so the
                 pane never rendered — a blank area with no error, for every user, every time. --}}
            @unless($isAssistant ?? false)
            @feature('ppra-employment-letters')
            <div x-show="sub.documents === 'ppra_employment_letter'" x-cloak class="pg-pane pg-pane-col">
                <div class="mp-h" style="margin-bottom:6px;"><h3>PPRA FFC Employment Letter</h3></div>

                @if(($ppraLetterMissing ?? []) !== [])
                <div class="rounded-md" style="font-size:0.75rem; color:var(--ds-amber); background:color-mix(in srgb, var(--ds-amber) 10%, transparent); padding:8px 10px; margin-bottom:10px;">
                    <div style="font-weight:600; margin-bottom:4px;">Before you can start a letter:</div>
                    <ul style="margin:0; padding-left:16px;">
                        @foreach($ppraLetterMissing as $m)
                        <li>@if(! empty($m['fix_url']))<a href="{{ $m['fix_url'] }}" style="color:var(--ds-amber); text-decoration:underline;">{{ $m['label'] }}</a>@else{{ $m['label'] }}@endif</li>
                        @endforeach
                    </ul>
                </div>
                @elseif($ppraLetterCanCreate ?? false)
                <form method="POST" action="{{ route('ppra-employment-letters.store') }}" style="margin-bottom:12px;">
                    @csrf
                    <button type="submit" class="corex-btn-primary" style="font-size:0.75rem; padding:5px 12px;">Start new letter</button>
                </form>
                @endif

                @if(session('success'))
                <div class="rounded-md" style="font-size:0.75rem; color:var(--ds-green); background:color-mix(in srgb, var(--ds-green) 10%, transparent); padding:8px 10px; margin-bottom:10px;">{{ session('success') }}</div>
                @endif
                @if($errors->has('signed_copy') || session('error'))
                <div class="rounded-md" style="font-size:0.75rem; color:var(--ds-crimson); background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); padding:8px 10px; margin-bottom:10px;">{{ $errors->first('signed_copy') ?: session('error') }}</div>
                @endif

                @if(($ppraLetters ?? collect())->isNotEmpty())
                <div x-data="{ page: 0, per: 3, total: {{ $ppraLetters->count() }} }" style="margin-bottom:8px;">
                    <div class="flex items-center justify-end gap-2" x-show="total > per" x-cloak style="margin-bottom:4px;">
                        <button type="button" class="mp-more" @click="page = Math.max(0, page - 1)" :disabled="page === 0" :style="page === 0 ? 'opacity:.4' : ''">&larr; Newer</button>
                        <span class="mp-note" x-text="(page + 1) + ' / ' + Math.ceil(total / per)"></span>
                        <button type="button" class="mp-more" @click="page = Math.min(Math.ceil(total / per) - 1, page + 1)" :disabled="(page + 1) * per >= total" :style="(page + 1) * per >= total ? 'opacity:.4' : ''">Older &rarr;</button>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:6px;" data-portal-rows="ppra-letters" data-portal-cap="3">
                    @foreach($ppraLetters as $letter)
                    <div class="py-1 px-3" style="border:1px solid var(--border); border-radius:6px;" x-show="{{ $loop->index }} >= page * per && {{ $loop->index }} < (page + 1) * per" @if($loop->index >= 3) x-cloak @endif>
                        <div class="flex items-center justify-between">
                            <div>
                                <div style="font-size:0.75rem; font-weight:600; color:var(--text-primary);">{{ \App\Models\Compliance\PpraEmploymentLetter::statusLabel($letter->status) }}</div>
                                <div style="font-size:0.6875rem; color:var(--text-muted);">{{ $letter->created_at->format('d M Y') }}</div>
                            </div>
                            <a href="{{ route('ppra-employment-letters.show', $letter) }}" style="font-size:0.6875rem; padding:4px 10px; border-radius:6px; background:color-mix(in srgb, var(--brand-button) 12%, transparent); color:var(--brand-button); text-decoration:none; font-weight:600;">Open</a>
                        </div>
                        <div style="margin-top:2px;">
                            @include('compliance.ppra-employment-letters._signed-copies', [
                                'letter'         => $letter,
                                'uploadUrl'      => route('ppra-employment-letters.upload', $letter),
                                'scanRouteName'  => 'ppra-employment-letters.signed-copy',
                                'showHistory'    => false,
                            ])
                        </div>
                    </div>
                    @endforeach
                    </div>
                </div>
                @endif

                @if(($ppraLetters ?? collect())->isEmpty() && ($ppraLetterMissing ?? []) === [])
                <p style="font-size:0.75rem; color:var(--text-muted);">No letters yet.</p>
                @endif
            </div>
            @endfeature
            @endunless
        </div>
    </div>

    {{-- ═══════════════════════════════════════════
         TAB: COMPLIANCE
         ═══════════════════════════════════════════ --}}
    <div x-show="tab === 'compliance'" x-cloak data-portal-pane="compliance" data-tour="portal-home-compliance-tab">

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
        <div class="pg-pane pg-wide" style="padding-bottom:0;">
            <div class="flex items-center gap-3" data-testid="compliance-overall">
                <span class="flex items-center justify-center" style="width:28px; height:28px; border-radius:50%; background:{{ $overallTint }};">
                    <span style="width:12px; height:12px; border-radius:50%; background:{{ $overallColor }}; display:block;"></span>
                </span>
                <span class="text-base font-bold" style="color:var(--text-primary);">
                    @if($overallStatus === 'green') Your compliance is up to date
                    @elseif($overallStatus === 'amber') {{ $complianceStatus['issues_count'] }} item(s) need attention
                    @else Action required @endif
                </span>
            </div>
        </div>

        {{-- Breakdown list --}}
        <div class="pg-pane pg-wide" data-tour="portal-home-compliance-breakdown">
            <div class="mp-h"><h3>Compliance Breakdown</h3></div>

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

                // AT-267 §10 / AUDIT 2026-07-26 (F9) — same rule as the overview card above: show
                // exactly the items computeComplianceStatus() produced. An assistant is not a
                // practitioner, so FFC / PI / tax-clearance rows are simply not in the data.
                $complianceItems = array_values(array_filter(
                    $complianceItems,
                    fn ($ci) => array_key_exists($ci['key'], $complianceStatus)
                ));
            @endphp

            <div class="mp-grid mp-g2" data-portal-rows="compliance-items" data-portal-cap="8">
                @foreach($complianceItems as $ci)
                @php $ciData = $complianceStatus[$ci['key']]; @endphp
                <div class="mp-row" style="padding:6px 10px;">
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
    <div x-show="tab === 'training'" x-cloak data-portal-pane="training">
        <nav class="pg-subs" aria-label="Sections">
            <button type="button" class="pg-sub" data-portal-sub="rmcp" @click="sub.training = 'rmcp'" :aria-current="sub.training === 'rmcp' ? 'page' : null">RMCP Acknowledgement</button>
            @isset($outstandingPolicies)
            @if($outstandingPolicies->isNotEmpty())
            <button type="button" class="pg-sub" data-portal-sub="policies" @click="sub.training = 'policies'" :aria-current="sub.training === 'policies' ? 'page' : null">Policies</button>
            @endif
            @endisset
            @if($trainingItems->isNotEmpty())
            <button type="button" class="pg-sub" data-portal-sub="other" @click="sub.training = 'other'" :aria-current="sub.training === 'other' ? 'page' : null">Other Training</button>
            @endif
        </nav>
        {{-- RMCP Acknowledgement — primary card --}}
        <div x-show="sub.training === 'rmcp'" x-cloak class="pg-pane ">
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

        {{-- Agency Policies (AT-29) — outstanding sign-offs --}}
        @isset($outstandingPolicies)
        @if($outstandingPolicies->isNotEmpty())
        <div x-show="sub.training === 'policies'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>Policies to acknowledge</h3><span class="mp-note">{{ $outstandingPolicies->count() }} {{ \Illuminate\Support\Str::plural('policy', $outstandingPolicies->count()) }} need your sign-off.</span></div>
            <div class="mp-grid mp-g2" data-portal-rows="policies" data-portal-cap="10">
                @foreach($outstandingPolicies->take(10) as $row)
                @php $statusColor = $row['status'] === 'in_progress' ? 'var(--ds-amber)' : 'var(--ds-crimson)'; @endphp
                <div class="mp-row">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $statusColor }};"></span>
                        <div>
                            <div class="text-sm font-semibold" style="color:var(--text-primary);">{{ $row['policy']->name }}</div>
                            <div class="text-xs" style="color:var(--text-muted);">{{ ucfirst(str_replace('_', ' ', $row['status'])) }}</div>
                        </div>
                    </div>
                    @if($row['status'] === 'in_progress')
                    <a href="{{ route('policy.ack.step', [$row['policy']->policy_key, 1]) }}" style="font-size:0.75rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); text-decoration:none; font-weight:600;">Continue</a>
                    @else
                    <form method="POST" action="{{ route('policy.ack.start', $row['policy']->policy_key) }}" style="display:inline;">@csrf
                        <button type="submit" style="font-size:0.6875rem; padding:5px 12px; border-radius:6px; background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:none; cursor:pointer; font-weight:600;">Start Acknowledgement</button>
                    </form>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif
        @endisset

        {{-- Other training courses --}}
        @if($trainingItems->isNotEmpty())
        <div x-show="sub.training === 'other'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>Other Training</h3>@if(\Illuminate\Support\Facades\Route::has('training.index'))<a href="{{ route('training.index') }}" class="mp-more">Show all{{ $trainingItems->count() > 10 ? ' (' . $trainingItems->count() . ')' : '' }} &rarr;</a>@endif</div>
            <div class="mp-grid mp-g2" data-portal-rows="training-items" data-portal-cap="10">
                @foreach($trainingItems->take(10) as $item)
                <div class="mp-row">
                    <div class="flex items-center gap-3 min-w-0">
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
    <div x-show="tab === 'password'" x-cloak data-portal-pane="password">
        <nav class="pg-subs" aria-label="Sections">
            <button type="button" class="pg-sub" data-portal-sub="update" @click="sub.password = 'update'" :aria-current="sub.password === 'update' ? 'page' : null">Update Password</button>
            @unless($isAssistant ?? false)
            <button type="button" class="pg-sub" data-portal-sub="delete" @click="sub.password = 'delete'" :aria-current="sub.password === 'delete' ? 'page' : null">Delete Account</button>
            @endunless
        </nav>
        <div x-show="sub.password === 'update'" x-cloak class="pg-pane ">
            <div class="mp-h"><h3>Update Password</h3><span class="mp-note">Use a long, random password.</span></div>

            <form method="post" action="{{ route('password.update') }}">
                @csrf
                @method('put')

                <div class="mp-grid mp-g3 mp-field" style="align-items:end;">
                    <div>
                        <label for="update_password_current_password">Current Password</label>
                        <input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password">
                        @error('current_password', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password">New Password</label>
                        <input id="update_password_password" name="password" type="password" autocomplete="new-password">
                        @error('password', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="update_password_password_confirmation">Confirm Password</label>
                        <input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
                        @error('password_confirmation', 'updatePassword') <p style="font-size:0.6875rem; color:var(--ds-crimson); margin-top:3px;">{{ $message }}</p> @enderror
                    </div>

                </div>
                <div style="margin-top:10px;">
                    <button type="submit" class="corex-btn-primary">Update Password</button>
                </div>
            </form>
        </div>

        {{-- Delete Account — AT-267 §10: deleting an assistant is an admin action, not self-service --}}
        @unless($isAssistant ?? false)
        <div x-show="sub.password === 'delete'" x-cloak class="pg-pane " x-data="{ confirmDelete: false }">
            <div class="mp-h"><h3 style="border-left:3px solid var(--ds-crimson); padding-left:10px;">Delete Account</h3><span class="mp-note">Deleting your account permanently deletes all of its data.</span></div>

            <button @click="confirmDelete = true" x-show="!confirmDelete" type="button" class="corex-btn-primary" style="background:var(--ds-crimson, #c41e3a); box-shadow:none;">Delete Account</button>

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
                        <button type="submit" class="corex-btn-primary" style="background:var(--ds-crimson, #c41e3a); box-shadow:none;">Confirm Delete</button>
                    </div>
                </form>
            </div>
        </div>
        @endunless
    </div>

    {{-- ══ Payslips tab ══ --}}
    @if(!($isAssistant ?? false) && auth()->user()->hasPermission('view_own_payslips'))
    <div x-show="tab === 'payslips'" x-cloak data-portal-pane="payslips">
        <div class="pg-pane">
            <div class="mp-h"><h3>My Payslips</h3>@if(isset($latestPayslip) && $latestPayslip)<a href="{{ route('my-portal.payslips') }}" class="mp-more">View All Payslips &rarr;</a>@endif</div>

            @if(isset($latestPayslip) && $latestPayslip)
                <div class="mp-grid mp-g3">
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

            @else
                <p style="font-size:0.8125rem; color:var(--text-muted); padding:8px 0;">No payslips yet. Your payslips will appear here once your employer finalises a payroll run.</p>
            @endif
        </div>
    </div>
    @endif

    {{-- ══ Leave tab ══ · AT-267 §10: hidden for assistants (v1) --}}
    @if(!($isAssistant ?? false) && auth()->user()->hasPermission('apply_for_leave'))
    <div x-show="tab === 'leave'" x-cloak data-portal-pane="leave">
        <div class="pg-pane">
            <div class="mp-h"><h3>My Leave</h3><span class="mp-note">Balances, applications and tracking.</span></div>

            <a href="{{ route('my-portal.leave.index') }}" class="corex-btn-primary" style="display:inline-flex; align-items:center; gap:6px;">
                View Leave Dashboard
                <svg style="width:14px; height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>
    </div>
    @endif

        </div>{{-- .w-full content --}}
    </div>{{-- .p-4 --}}
</div>{{-- .-m-4 x-data --}}

@if($user->portal_show_social_accounts && \Illuminate\Support\Facades\Route::has('corex.social.oauth.redirect'))
@push('scripts')
@include('partials.facebook-connect-explainer')
<script>
    // Meta requires Page permissions (pages_show_list, pages_manage_posts, etc.)
    // for a Business-owned app to be requested via a Facebook Login for Business
    // "Configuration" through the JS SDK — a plain server-side redirect to
    // /dialog/oauth silently strips them down to public_profile only, even for
    // an app Administrator. This is Meta's own documented pattern for config_id.
    window.fbAsyncInit = function () {
        FB.init({
            appId: '{{ config('services.meta.app_id') }}',
            cookie: true,
            xfbml: false,
            version: 'v19.0',
        });
    };
    (function (d, s, id) {
        var js, fjs = d.getElementsByTagName(s)[0];
        if (d.getElementById(id)) { return; }
        js = d.createElement(s); js.id = id;
        js.src = 'https://connect.facebook.net/en_US/sdk.js';
        fjs.parentNode.insertBefore(js, fjs);
    }(document, 'script', 'facebook-jssdk'));

    async function corexConnectSocial(platform) {
        if (platform === 'facebook' && !(await window.corexFacebookConnectExplainer())) { return; }
        var configId = @json(config('services.meta.login_config_id'));
        var state = btoa(JSON.stringify({ user_id: {{ auth()->id() }}, platform: platform }));

        // No Business Login configuration set up for this platform yet, or the
        // SDK failed to load — fall back to the classic scope-based redirect.
        if (!configId || typeof FB === 'undefined') {
            window.location.href = '{{ route('corex.social.oauth.redirect') }}?platform=' + encodeURIComponent(platform);
            return;
        }

        FB.login(function (response) {
            // Default response_type ('token') hands back a ready-to-use user
            // access token directly — avoids the /oauth/access_token code
            // exchange entirely, which rejects codes minted by FB.login()
            // because they're tied to an internal xd_arbiter redirect_uri
            // our server can't reproduce.
            if (response.authResponse && response.authResponse.accessToken) {
                window.location.href = '{{ route('corex.social.oauth.callback') }}'
                    + '?flow=js'
                    + '&access_token=' + encodeURIComponent(response.authResponse.accessToken)
                    + '&state=' + encodeURIComponent(state);
            } else {
                window.location.href = '{{ route('agent.portal', ['pane' => 'social']) }}#tools';
            }
        }, {
            config_id: configId,
            auth_type: 'rerequest',
        });
    }
</script>
@endpush
@endif
@endsection
