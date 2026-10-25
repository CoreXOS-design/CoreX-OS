{{-- ════════════════════════════════════════════════════════════════════════
     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

     GRADUAL FEATURE ROLLOUT POP-UPS (AT-478 Part 3). Spec: .ai/specs/agency-feature-rollout.md
     §2.5–2.6, §3.5a, §5.3–5.4, §19.3. Same shape as the System Updates pop-up: one include in each app
     layout, server-rendered, endpoints addressed RELATIVELY (see system-update-modal for why).

     At most ONE of these per page (FeatureRolloutAccess::popupFor), in this order:
       1. review_released — the admin checks who has access to groups that went live without a review
       2. review_upcoming — the admin chooses who gets the group switching on next release day
       3. review_admin    — an "Admin decides" group switched on for the admins only: who else should see it (§18)
       4. release         — "New in CoreX", once per user per group, only features they can open
     It renders ABOVE the System Updates pop-up (z-index) so the admin's prompt is seen first.
     No plan for the agency = one indexed query and nothing rendered.

     §19.3 — both pop-ups use most of the screen: the features on the left, the selected feature's full page
     on the right (summary, full explanation, video, links, tour; role ticks in the review). The wording comes
     from FeatureRolloutCopy (Dev Settings → Feature wording & videos, else the defaults). Every feature's
     section stays in the DOM (x-show), so the review posts every feature's ticks whichever one is on screen.
     While a pop-up is open the alert toasts are hidden (.cx-rollout-open) so nothing sits on top of it.
     ════════════════════════════════════════════════════════════════════════ --}}
@auth
@php
    $__fr = app(\App\Services\FeatureRollout\FeatureRolloutAccess::class)->popupFor(
        auth()->user(),
        array_map('intval', (array) session(\App\Http\Controllers\Api\V1\FeatureRolloutPopupController::LATER_SESSION_KEY, []))
    );
@endphp

@if($__fr)
@php
    $__frWaveIds   = $__fr['waves']->pluck('id')->values()->all();
    $__frTz        = app(\App\Services\FeatureRollout\RolloutCalendar::class)->timezone();
    $__frCopy      = app(\App\Services\FeatureRollout\FeatureRolloutCopy::class);
    $__frBox       = 'position:relative; width:100%; max-width:1200px; height:min(88vh, 940px); background:var(--surface); border:1px solid var(--border);';
@endphp

@once
<style>
    .cx-rollout-open #cxa-stack { visibility: hidden; }
    .cx-rollout-item { width: 100%; text-align: left; border-radius: 6px; padding: .55rem .75rem; border: 1px solid transparent; color: var(--text-primary); transition: background-color 150ms, border-color 150ms; }
    .cx-rollout-item:hover { background: var(--surface); }
    .cx-rollout-item[aria-current="true"] { background: var(--surface); border-color: var(--brand-icon, var(--border)); }
    /* §21 — the Role Manager's Data Scope control (same colours as #rm-root .rm-scope-btn) */
    .cx-rs-btn { transition: all 300ms; }
    .cx-rs-btn[data-active="true"] { color: #fff; font-weight: 600; }
    .cx-rs-btn[data-scope="none"][data-active="true"] { background: var(--border-hover); color: var(--text-primary); }
    .cx-rs-btn[data-scope="own"][data-active="true"] { background: var(--brand-icon, #0ea5e9); }
    .cx-rs-btn[data-scope="branch"][data-active="true"] { background: var(--ds-amber, #f59e0b); }
    .cx-rs-btn[data-scope="all"][data-active="true"] { background: var(--ds-green, #059669); }
</style>
@endonce

{{-- One feature's full page (summary, explanation, video, links). $c = FeatureRolloutCopy::for(). --}}
@php
    $__frStory = function (array $c): string {
        $h = '<div class="text-xl font-bold" style="color:var(--text-primary);">' . e($c['label']) . '</div>';
        if ($c['summary'] !== '') {
            $h .= '<div class="text-sm font-medium mt-1" style="color:var(--text-secondary);">' . e($c['summary']) . '</div>';
        }
        $h .= '<div class="mt-4 space-y-3 text-sm leading-relaxed" style="color:var(--text-primary); max-width:72ch;" data-rollout-explanation="' . e($c['key']) . '">';
        foreach ($c['paragraphs'] as $p) {
            $h .= '<p>' . e($p) . '</p>';
        }
        $h .= '</div>';
        if ($c['video_url'] || $c['links']) {
            $h .= '<div class="mt-4 flex flex-wrap gap-2">';
            if ($c['video_url']) {
                $h .= '<a href="' . e($c['video_url']) . '" target="_blank" rel="noopener noreferrer" data-rollout-video="' . e($c['key']) . '" class="corex-btn-outline text-xs">▶ Watch the video</a>';
            }
            foreach ($c['links'] as $l) {
                $h .= '<a href="' . e($l['url']) . '" target="_blank" rel="noopener noreferrer" class="corex-btn-outline text-xs">' . e($l['label']) . '</a>';
            }
            $h .= '</div>';
        }

        return $h;
    };
@endphp

@if(str_starts_with($__fr['kind'], 'review_'))
@php
    $__frAdmin    = $__fr['kind'] === 'review_admin';
    $__frReleased = in_array($__fr['kind'], ['review_released', 'review_admin'], true);
    $__frAwait    = (array) config('feature-rollout.admin_switch_on', []);
    $__frRoles    = $__fr['roles'];
    $__frWhen     = $__frReleased
        ? $__fr['waves']->min('released_at')?->copy()->setTimezone($__frTz)
        : $__fr['waves']->min('scheduled_for');
    $__frAccess   = app(\App\Services\FeatureRollout\FeatureRolloutAccess::class);
    $__frAgency   = \App\Models\Agency::withoutGlobalScopes()->find($__fr['plan']->agency_id);
    $__frGroups   = $__fr['waves']->map(fn ($w) => ['wave' => $w, 'keys' => $__frAccess->liveFeatures($w)])->filter(fn ($g) => $g['keys'] !== [])->values();
    $__frFirst    = $__frGroups->first()['keys'][0] ?? null;
@endphp
<div x-data="coreXRolloutReview({{ \Illuminate\Support\Js::from($__frWaveIds) }}, {{ \Illuminate\Support\Js::from($__fr['settings']) }}, '{{ route('api.v1.feature-rollout.access-review', [], false) }}', {{ \Illuminate\Support\Js::from($__frFirst) }})"
     x-show="open" x-cloak
     class="fixed inset-0 z-[75] flex items-center justify-center p-2 md:p-6"
     role="dialog" aria-modal="true" aria-labelledby="rollout-review-heading">
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.6);"></div>

    <div class="rounded-md shadow-2xl overflow-hidden flex flex-col" style="{{ $__frBox }}" data-rollout-box>
        <div class="px-6 py-4 shrink-0" style="border-bottom:1px solid var(--border);">
            <div id="rollout-review-heading" class="text-base font-bold" style="color:var(--text-primary);">
                @if($__frAdmin)
                    Ready for you — who else should see these?
                @elseif($__frReleased)
                    These went live on {{ $__frWhen?->format('l j F') }} — check who has access
                @else
                    Switching on {{ $__frWhen?->format('l j F') }} — choose who gets access
                @endif
            </div>
            <div class="text-sm mt-1" style="color:var(--text-secondary);">
                @if($__frAdmin)
                    These features are switched on for you and your fellow admins only. Pick a role and choose what it can do with each one — nobody else gets them until you do. You can change this any time in Settings → Feature rollout.
                @elseif($__frReleased)
                    These features switched on while you were away, with your roles' existing access. Pick a role to see and change what it can do with each one.
                @else
                    Pick a role and choose what it can do with each feature when it switches on, exactly as in the Role Manager. Change nothing and every role keeps what it has.
                @endif
            </div>
            @php $__frLow = $__frReleased ? null : app(\App\Services\FeatureRollout\FeatureRolloutUsage::class)->lowUse($__fr['plan']); @endphp
            @if($__frLow)
                <div class="rounded-md px-4 py-2 mt-3 text-sm" data-low-use style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color: var(--text-primary);">
                    Only {{ $__frLow['opened'] }} of {{ $__frLow['people'] }} people with access have opened what switched on in "{{ $__frLow['group'] }}". If your team needs more time, <strong>Hold this group</strong> pauses the introduction until you resume it.
                </div>
            @endif
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row">
            {{-- Left: the features, grouped --}}
            <nav class="shrink-0 md:w-80 max-h-48 md:max-h-none overflow-y-auto p-3 space-y-4" style="background:var(--surface-2); border-right:1px solid var(--border);" aria-label="Features">
                @foreach($__frGroups as $__frG)
                    <div>
                        <div class="px-2 text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $__frG['wave']->title }}</div>
                        <div class="mt-1 space-y-1">
                            @foreach($__frG['keys'] as $__frKey)
                                <button type="button" class="cx-rollout-item" @click="sel = '{{ $__frKey }}'" :aria-current="sel === '{{ $__frKey }}'" data-rollout-pick="{{ $__frKey }}">
                                    <div class="text-sm font-semibold">{{ $__frCopy->for($__frKey)['label'] }}</div>
                                    <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                                        @if(($__fr['settings']['features'][$__frKey] ?? null) === null)
                                            No separate access to set
                                        @else
                                            <span x-text="summary('{{ $__frKey }}')"></span>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            {{-- Right: the selected feature --}}
            <div class="flex-1 min-h-0 overflow-y-auto px-6 py-5">
                @foreach($__frGroups as $__frG)
                    @foreach($__frG['keys'] as $__frKey)
                        @php $__frC = $__frCopy->for($__frKey); @endphp
                        <section x-show="sel === '{{ $__frKey }}'" @if($__frKey !== $__frFirst) x-cloak @endif data-rollout-feature="{{ $__frKey }}">
                            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $__frG['wave']->title }}</div>
                            <div class="mt-1">{!! $__frStory($__frC) !!}</div>

                            @php $__frMissing = app(\App\Services\FeatureRollout\FeatureReadiness::class)->missing($__frAgency, $__frKey); @endphp
                            @if($__frMissing !== [])
                                <div class="text-sm mt-4 rounded-md px-4 py-3" style="color:var(--text-primary); background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);" data-readiness="{{ $__frKey }}">
                                    Set this up first: add
                                    @foreach($__frMissing as $__frI => $__frM){{ $__frI > 0 ? ($loop->last ? ' and ' : ', ') : '' }}<a href="{{ $__frM['url'] }}" style="color:var(--brand-icon); text-decoration:underline;">{{ $__frM['label'] }}</a>@endforeach.
                                    It waits until then and switches on by itself once it is done.
                                </div>
                            @endif
                            @if(in_array($__frKey, $__frAwait, true))
                                <div class="text-sm mt-4 rounded-md px-4 py-3" style="color:var(--text-primary); background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">This one reaches people outside your agency, so it waits for you to switch it on in Settings → Feature rollout.</div>
                            @endif

                            @php $__frF = $__fr['settings']['features'][$__frKey] ?? null; @endphp
                            <div class="mt-5 rounded-md overflow-hidden" style="border:1px solid var(--border);" data-rollout-who="{{ $__frKey }}">
                                <div class="px-5 py-3" style="background:var(--surface-2); border-bottom:1px solid var(--border);">
                                    <div class="text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Who gets it</div>
                                    @if($__frF === null)
                                        <div class="text-sm mt-2" style="color:var(--text-secondary);">Everyone who can open this part of CoreX gets it — there is no separate access to set.</div>
                                    @elseif($__frRoles->isEmpty())
                                        <div class="text-sm mt-2" style="color:var(--text-secondary);">Your agency has no roles of its own to set yet.</div>
                                    @else
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($__frRoles as $__frRole)
                                                <button type="button" data-rollout-access="{{ $__frKey }}|{{ $__frRole->name }}" @click="role = '{{ $__frRole->name }}'"
                                                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium"
                                                        :style="role === '{{ $__frRole->name }}' ? 'background:var(--brand-icon,#0ea5e9); color:#fff; border:1px solid transparent;' : 'background:var(--surface); color:var(--text-secondary); border:1px solid var(--border);'">
                                                    <span x-text="entryOn('{{ $__frKey }}', '{{ $__frRole->name }}') ? '✓' : '—'" aria-hidden="true"></span>
                                                    {{ $__frRole->label ?: $__frRole->name }}
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="text-xs mt-2" style="color:var(--text-muted);">Editing permissions for: <span x-text="roleLabel()" style="color:var(--brand-icon,#0ea5e9);" class="font-medium"></span></p>
                                    @endif
                                </div>

                                @if($__frF !== null && $__frRoles->isNotEmpty())
                                    @foreach($__frF['modules'] as $__frM)
                                        @php $__frV = $__frM['view']; @endphp
                                        @if(count($__frF['modules']) > 1)
                                            <div class="px-5 pt-3 pb-1 text-sm font-semibold" style="color:var(--text-primary); border-bottom:1px solid var(--border);">{{ $__frM['label'] }}</div>
                                        @endif

                                        {{-- Access permissions (menu / section visibility) --}}
                                        @foreach($__frM['access'] as $__frP)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $__frP['label'] }}</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Menu / section visibility</p>
                                                    @if($__frP['default'])<p class="text-xs mt-0.5" style="color:var(--ds-amber,#f59e0b);">{{ $__frP['default'] }}</p>@endif
                                                </div>
                                                <label class="inline-flex items-center gap-2 cursor-pointer flex-shrink-0">
                                                    <input type="checkbox" x-model="perm['{{ $__frP['key'] }}'][role]" data-rollout-perm="{{ $__frP['key'] }}"
                                                           class="w-5 h-5 rounded-md cursor-pointer" style="accent-color:var(--brand-icon,#0ea5e9); border-color:var(--border);">
                                                    <span class="text-xs" style="color:var(--text-muted);" x-text="perm['{{ $__frP['key'] }}'][role] ? 'Enabled' : 'Disabled'"></span>
                                                </label>
                                            </div>
                                        @endforeach

                                        {{-- Data Scope --}}
                                        @if($__frV && $__frM['mode'] === 'toggle')
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">Data Scope</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Off: user sees only their own records — no agent picker, no My/All toggle. On: user can see other agents' records (limited to their branch when Data Isolation is enabled in Company Settings, otherwise agency-wide).</p>
                                                </div>
                                                <div class="flex-shrink-0 text-right">
                                                    <label class="inline-flex items-center gap-2 cursor-pointer">
                                                        <input type="checkbox" data-rollout-scope="{{ $__frV }}" :checked="['branch','all'].includes(scope['{{ $__frV }}'][role])"
                                                               @change="setScope('{{ $__frV }}', $event.target.checked ? 'all' : 'own')"
                                                               class="w-5 h-5 rounded-md cursor-pointer" style="accent-color:var(--brand-icon,#0ea5e9); border-color:var(--border);">
                                                        <span class="text-xs" style="color:var(--text-muted);" x-text="['branch','all'].includes(scope['{{ $__frV }}'][role]) ? 'On' : 'Off'"></span>
                                                    </label>
                                                    <p x-show="isDef['{{ $__frV }}'][role]" x-cloak class="text-xs mt-1 max-w-md" style="color:var(--ds-amber,#f59e0b);" x-text="defs['{{ $__frV }}']"></p>
                                                </div>
                                            </div>
                                        @elseif($__frV)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">Data Scope</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">None = no access. Own = locked to own records. Branch / All = user can toggle between their own records and other users' records on the list page.</p>
                                                </div>
                                                <div class="flex-shrink-0 text-right">
                                                    @if($__frM['mode'] === 'shared')
                                                        <span class="inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">Shared — all users</span>
                                                    @else
                                                        <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);" data-rollout-scope="{{ $__frV }}">
                                                            @foreach(['none' => 'None', 'own' => 'Own', 'branch' => 'Branch', 'all' => 'All'] as $__frSv => $__frSl)
                                                                <label class="cx-rs-btn inline-flex items-center cursor-pointer px-3 py-1.5 text-xs whitespace-nowrap" style="border-right:1px solid var(--border);"
                                                                       data-scope="{{ $__frSv }}"
                                                                       :data-active="scope['{{ $__frV }}'][role] === '{{ $__frSv }}' ? 'true' : 'false'"
                                                                       :style="scope['{{ $__frV }}'][role] === '{{ $__frSv }}' ? '' : 'background:var(--surface);color:var(--text-muted);'">
                                                                    <input type="radio" class="sr-only" name="cx_scope_{{ $__frV }}" value="{{ $__frSv }}"
                                                                           :checked="scope['{{ $__frV }}'][role] === '{{ $__frSv }}'" @change="setScope('{{ $__frV }}', '{{ $__frSv }}')">
                                                                    {{ $__frSl }}
                                                                </label>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                    <p x-show="isDef['{{ $__frV }}'][role]" x-cloak class="text-xs mt-1 max-w-md" style="color:var(--ds-amber,#f59e0b);" x-text="defs['{{ $__frV }}']"></p>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- Create / Edit / Archive: the configured label is the title, the plain word only a fallback --}}
                                        @foreach($__frM['standard'] as $__frA)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $__frA['label'] ?: ucfirst($__frA['action']) }}</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Can {{ strtolower($__frA['action']) }} records in this module</p>
                                                </div>
                                                <label class="inline-flex items-center gap-2 cursor-pointer flex-shrink-0">
                                                    <input type="checkbox" x-model="perm['{{ $__frA['key'] }}'][role]" data-rollout-perm="{{ $__frA['key'] }}"
                                                           @change="tickAction('{{ $__frV ?? '' }}', '{{ $__frA['key'] }}')"
                                                           @if($__frV) :disabled="scope['{{ $__frV }}'][role] === 'none'" @endif
                                                           class="w-5 h-5 rounded-md cursor-pointer" style="accent-color:var(--brand-icon,#0ea5e9); border-color:var(--border);">
                                                    <span class="text-xs" style="color:var(--text-muted);" x-text="perm['{{ $__frA['key'] }}'][role] ? 'Enabled' : 'Disabled'"></span>
                                                </label>
                                            </div>
                                        @endforeach

                                        {{-- Other actions (manage, send, …) --}}
                                        @foreach($__frM['other'] as $__frA)
                                            <div class="px-5 py-4 flex items-center justify-between gap-4" style="border-bottom:1px solid var(--border);">
                                                <div>
                                                    <p class="text-sm font-medium" style="color:var(--text-primary);">{{ $__frA['label'] ?: \Illuminate\Support\Str::headline($__frA['action']) }}</p>
                                                    <p class="text-xs mt-0.5" style="color:var(--text-muted);">Additional action for this module</p>
                                                </div>
                                                <label class="inline-flex items-center gap-2 cursor-pointer flex-shrink-0">
                                                    <input type="checkbox" x-model="perm['{{ $__frA['key'] }}'][role]" data-rollout-perm="{{ $__frA['key'] }}"
                                                           class="w-5 h-5 rounded-md cursor-pointer" style="accent-color:var(--brand-icon,#0ea5e9); border-color:var(--border);">
                                                    <span class="text-xs" style="color:var(--text-muted);" x-text="perm['{{ $__frA['key'] }}'][role] ? 'Enabled' : 'Disabled'"></span>
                                                </label>
                                            </div>
                                        @endforeach
                                    @endforeach
                                @endif
                            </div>
                        </section>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="shrink-0 flex flex-wrap items-center justify-between gap-2 px-6 py-3"
             style="border-top:1px solid var(--border); background:var(--surface-2);">
            <div class="flex items-center gap-3">
                <button type="button" @click="send('later')" :disabled="busy" class="text-sm" style="color:var(--text-secondary);">Ask me next time</button>
                <span x-show="error" x-text="error" class="text-xs" style="color:var(--ds-crimson, #c41e3a);"></span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @unless($__frReleased)
                    <button type="button" @click="send('hold')" :disabled="busy" class="corex-btn-outline text-sm">Hold this group</button>
                @endunless
                <button type="button" @click="send('keep')" :disabled="busy" class="corex-btn-outline text-sm">{{ $__frAdmin ? 'Keep for admins only' : 'Keep as is' }}</button>
                <button type="button" @click="send('save')" :disabled="busy" class="corex-btn-primary text-sm">Save access</button>
            </div>
        </div>
    </div>
</div>
@else
@php
    $__frOrder = collect($__fr['announcements'])->flatMap(fn ($a) => array_column($a['features'], 'key'))->values()->all();
    $__frFirst = $__frOrder[0] ?? null;
    $__frLabels = collect($__fr['announcements'])->flatMap(fn ($a) => array_column($a['features'], 'label', 'key'))->all();
@endphp
<div x-data="coreXRolloutRelease({{ \Illuminate\Support\Js::from($__frWaveIds) }}, '{{ route('api.v1.feature-rollout.announcements.seen', [], false) }}', {{ \Illuminate\Support\Js::from($__frOrder) }}, {{ \Illuminate\Support\Js::from((object) $__frLabels) }})"
     x-show="open" x-cloak
     @keydown.escape.window="close()"
     class="fixed inset-0 z-[75] flex items-center justify-center p-2 md:p-6"
     role="dialog" aria-modal="true" aria-labelledby="rollout-release-heading">
    <div class="absolute inset-0" style="background:rgba(0,0,0,0.6);" @click="close()"></div>

    <div class="rounded-md shadow-2xl overflow-hidden flex flex-col" style="{{ $__frBox }}" @click.stop data-rollout-box>
        <div class="shrink-0 flex items-start justify-between gap-3 px-6 py-4" style="border-bottom:1px solid var(--border);">
            <div>
                <div id="rollout-release-heading" class="text-base font-bold" style="color:var(--text-primary);">New in CoreX for you</div>
                <div class="text-sm mt-0.5" style="color:var(--text-secondary);">{{ count($__frOrder) === 1 ? 'One new feature has' : count($__frOrder) . ' new features have' }} switched on for you. Pick one to read what it does.</div>
            </div>
            <button type="button" @click="close()" aria-label="Close" class="p-1 rounded-md" style="color:var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 min-h-0 flex flex-col md:flex-row">
            <nav class="shrink-0 md:w-80 max-h-48 md:max-h-none overflow-y-auto p-3 space-y-4" style="background:var(--surface-2); border-right:1px solid var(--border);" aria-label="New features">
                @foreach($__fr['announcements'] as $__frA)
                    <div>
                        <div class="px-2 text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $__frA['wave']->title }}</div>
                        @if($__frA['wave']->intro_text)
                            <div class="px-2 text-[11px] mt-0.5" style="color:var(--text-muted);">{{ $__frA['wave']->intro_text }}</div>
                        @endif
                        <div class="mt-1 space-y-1">
                            @foreach($__frA['features'] as $__frF)
                                <button type="button" class="cx-rollout-item" @click="sel = '{{ $__frF['key'] }}'" :aria-current="sel === '{{ $__frF['key'] }}'" data-rollout-pick="{{ $__frF['key'] }}">
                                    <div class="text-sm font-semibold">{{ $__frF['label'] }}</div>
                                    <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">{{ $__frF['tour'] ? 'Guided tour available' : 'Read what it does' }}</div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>

            <div class="flex-1 min-h-0 overflow-y-auto px-6 py-5">
                @foreach($__fr['announcements'] as $__frA)
                    @foreach($__frA['features'] as $__frF)
                        @php $__frC = $__frCopy->for($__frF['key']); @endphp
                        <section x-show="sel === '{{ $__frF['key'] }}'" @if($__frF['key'] !== $__frFirst) x-cloak @endif data-rollout-feature="{{ $__frF['key'] }}">
                            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">{{ $__frA['wave']->title }}</div>
                            <div class="mt-1">{!! $__frStory($__frC) !!}</div>
                            <div class="mt-6 flex flex-wrap items-center gap-3">
                                @if($__frF['tour'])
                                    <a href="{{ $__frF['tour']['url'] }}" data-rollout-link
                                       class="corex-btn-primary text-sm whitespace-nowrap" title="{{ $__frF['tour']['title'] }}">Take the tour</a>
                                @endif
                                <button type="button" x-show="nextOf('{{ $__frF['key'] }}')" @click="sel = nextOf('{{ $__frF['key'] }}')" class="corex-btn-outline text-sm">
                                    Next: <span x-text="labels[nextOf('{{ $__frF['key'] }}')]"></span> →
                                </button>
                            </div>
                        </section>
                    @endforeach
                @endforeach
            </div>
        </div>

        <div class="shrink-0 flex items-center justify-between gap-3 px-6 py-3" style="border-top:1px solid var(--border); background:var(--surface-2);">
            <a href="{{ route('corex.new-and-coming.index') }}" data-rollout-link class="text-sm" style="color:var(--brand-icon);">See everything new and coming</a>
            <button type="button" @click="close()" class="corex-btn-primary text-sm">Got it</button>
        </div>
    </div>
</div>
@endif

@once
@push('scripts')
<script>
/* AT-478 — gradual rollout pop-ups. Posts mirror the System Updates pop-up (CSRF header, same-origin). */
function coreXRolloutPost(url, payload, keepalive) {
    const body = JSON.stringify(payload);
    return window.CoreX?.api?.fetch
        ? window.CoreX.api.fetch(url, { method: 'POST', keepalive: !!keepalive, headers: { 'Content-Type': 'application/json' }, body })
        : fetch(url, {
              method: 'POST', credentials: 'same-origin', keepalive: !!keepalive,
              headers: {
                  'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
              },
              body,
          }).then((r) => { if (!r.ok) { throw { status: r.status }; } return r; });
}

/* §19.2.1 — nothing (alert toasts) sits on top of an open rollout pop-up. */
function coreXRolloutShade(on) { document.documentElement.classList.toggle('cx-rollout-open', !!on); }

/* The admin access review: the decision must be recorded, so unlike the release pop-up it stays
   open and says so when a save fails. "Ask me next time" is the way out without deciding. */
function coreXRolloutReview(waveIds, cfg, url, first) {
    const copy = (o) => JSON.parse(JSON.stringify(o || {}));
    // view key => { create, edit, archive } permission keys, for the Role Manager's tick rules
    const actions = {};
    Object.values(cfg.features || {}).forEach((f) => (f ? f.modules : []).forEach((m) => {
        if (m.view) { actions[m.view] = {}; (m.standard || []).forEach((a) => { actions[m.view][a.action] = a.key; }); }
    }));
    const start = { perm: copy(cfg.granted) };
    return {
        open: true, busy: false, error: '', sel: first, role: (cfg.roles[0] || {}).name || null,
        perm: copy(cfg.granted), scope: copy(cfg.scope), isDef: copy(cfg.isDefault), defs: cfg.defaults || {}, touched: {},
        init() { coreXRolloutShade(true); },
        roleLabel() { const r = cfg.roles.find((x) => x.name === this.role); return r ? r.label : this.role; },
        entryOn(feature, role) {
            const f = cfg.features[feature];
            return !!f && f.entry.some((k) => this.perm[k] && this.perm[k][role]);
        },
        summary(key) {
            const on = cfg.roles.filter((r) => this.entryOn(key, r.name)).length;
            return on === 0 ? 'Nobody yet' : `${on} of ${cfg.roles.length} roles`;
        },
        // Data Scope: the Role Manager's rules. None removes view/create/edit/archive; anything else is a real view grant.
        setScope(view, value) {
            const role = this.role;
            this.scope[view][role] = value;
            this.isDef[view][role] = false;
            this.touched[view + '|' + role] = true;
            if (this.perm[view]) { this.perm[view][role] = value !== 'none'; }
            if (value === 'none') {
                Object.values(actions[view] || {}).forEach((k) => { if (this.perm[k]) { this.perm[k][role] = false; } });
            }
        },
        // Ticking create / edit / archive needs a view row to sit on.
        tickAction(view, key) {
            const role = this.role;
            if (!view || !this.perm[key][role]) { return; }
            if (this.scope[view][role] === 'none') {
                this.setScope(view, 'own');
            } else if (this.isDef[view][role] && !this.perm[view][role]) {
                this.isDef[view][role] = false;
                this.touched[view + '|' + role] = true;
                this.perm[view][role] = true;
            }
        },
        changes() {
            const settings = {}, scopes = {};
            Object.keys(this.perm).forEach((key) => Object.keys(this.perm[key]).forEach((role) => {
                if (this.perm[key][role] !== start.perm[key][role]) { (settings[role] = settings[role] || {})[key] = this.perm[key][role]; }
            }));
            Object.keys(this.touched).forEach((id) => {
                const [view, role] = id.split('|');
                (scopes[role] = scopes[role] || {})[view] = this.scope[view][role];
            });
            return { settings, scopes };
        },
        send(action) {
            this.busy = true; this.error = '';
            const body = { action, wave_ids: waveIds };
            if (action === 'save') { Object.assign(body, this.changes()); }
            Promise.resolve(coreXRolloutPost(url, body))
                .then(() => {
                    this.open = false;
                    coreXRolloutShade(false);
                    // Keep / save / hold can change what the next page shows (the next pop-up, the menu).
                    if (action !== 'later') { window.location.reload(); }
                })
                .catch((err) => {
                    this.busy = false;
                    this.error = 'That was not saved' + (err?.status ? ` (error ${err.status})` : '') + '. Please try again.';
                });
        },
    };
}

/* The release pop-up: closing always wins, exactly like System Updates. */
function coreXRolloutRelease(waveIds, url, order, labels) {
    return {
        open: true, sent: false, sel: order[0] || null, order, labels: labels || {},
        init() {
            coreXRolloutShade(true);
            this.$el.querySelectorAll('[data-rollout-link]').forEach((a) => a.addEventListener('click', () => this.send()));
        },
        nextOf(key) { const i = this.order.indexOf(key); return i >= 0 && i < this.order.length - 1 ? this.order[i + 1] : null; },
        close() { this.open = false; coreXRolloutShade(false); this.send(); },
        send() {
            if (this.sent) return;
            this.sent = true;
            Promise.resolve(coreXRolloutPost(url, { wave_ids: waveIds }, true)).catch((err) => {
                console.warn('[CoreX] Feature rollout pop-up was not recorded as seen — it will reappear.', err?.status ? `HTTP ${err.status}` : err);
            });
        },
    };
}
</script>
@endpush
@endonce
@endif
@endauth
