{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-216 (DR2 · WS-PIPELINE) — pipeline tracking board for one DR2 deal.
    PURE TRACKING OVERLAY: attaching a pipeline / completing a step never changes the
    DR1 deal's state — only the pipeline's own steps + the deal's pipeline pointer.
--}}
@extends('layouts.corex')

@section('corex-content')
@php
    $statusStyles = [
        'not_started' => ['Not started', '#6b7280', '#f3f4f6'],
        'active'      => ['Active',      '#1d4ed8', '#dbeafe'],
        'completed'   => ['Completed',   '#047857', '#d1fae5'],
        'not_applicable' => ['N/A',       '#6b7280', '#f3f4f6'],
        'skipped'     => ['Skipped',     '#6b7280', '#f3f4f6'],
    ];
@endphp
<div class="corex-page">
    <div class="corex-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 class="corex-page-title">Deal Pipeline</h1>
            <p class="corex-page-subtitle">
                Deal {{ $deal->deal_no ?? $deal->id }}
                @if($deal->property_address) — {{ $deal->property_address }} @endif
            </p>
        </div>
        <a href="{{ route('deals-dr2.index') }}" class="corex-btn-secondary">← DR2 Register</a>
    </div>

    @if(session('info'))
        <div class="corex-alert corex-alert-info" style="margin:1rem 0;">{{ session('info') }}</div>
    @endif
    @if(session('error'))
        <div class="corex-alert corex-alert-danger" style="margin:1rem 0;">{{ session('error') }}</div>
    @endif

    @if($steps->isEmpty())
        {{-- No pipeline yet → attach one. --}}
        <div class="corex-card" style="margin-top:1rem;padding:1.5rem;max-width:520px;">
            <h2 style="margin:0 0 .75rem;font-size:1.05rem;">Attach a pipeline</h2>
            @if($templates->isEmpty())
                <p style="color:var(--corex-text-muted,#6b7280);">
                    No active pipeline templates for this agency yet. Create one under
                    @if(\Illuminate\Support\Facades\Route::has('deals-v2.pipeline.index'))
                        <a href="{{ route('deals-v2.pipeline.index') }}" class="corex-link">Pipeline Setup</a>.
                    @else Pipeline Setup. @endif
                </p>
            @else
            @permission('create_deals')
            <form method="POST" action="{{ route('deals-dr2.pipeline.attach', $deal) }}">
                @csrf
                <label for="template_id" style="display:block;margin-bottom:.35rem;font-weight:600;">
                    Template
                    @if($deal->deal_type)
                        <span style="font-weight:400;color:var(--corex-text-muted,#6b7280);">— defaulted from deal type "{{ $deal->deal_type }}"; change if needed</span>
                    @endif
                </label>
                <select name="template_id" id="template_id" class="corex-input" required style="width:100%;">
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}" {{ (isset($defaultTemplateId) && $t->id === $defaultTemplateId) ? 'selected' : '' }}>{{ $t->name }}{{ $t->deal_type ? ' · '.$t->deal_type : '' }}{{ $t->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-primary" style="margin-top:1rem;">Attach pipeline</button>
            </form>
            @endpermission
            @endif
        </div>
    @else
        {{-- Step board — one card per step, with per-step operations + comments. --}}
        <div class="corex-card" style="margin-top:1rem;padding:.5rem;">
            @foreach($steps as $row)
                @php($s = $row['model'])
                @php($badge = $row['na'] ? ['N/A', '#6b7280', '#f3f4f6'] : ($statusStyles[$s->status] ?? [ucfirst($s->status), '#6b7280', '#f3f4f6']))
                @php($terminal = in_array($s->status, ['completed', 'skipped'], true))
                <div x-data="{ na:false, cm:false }" style="border-bottom:1px solid var(--corex-border,#e5e7eb);padding:.65rem .5rem;{{ $row['na'] ? 'opacity:.6;' : '' }}">
                    <div style="display:flex;align-items:flex-start;gap:.6rem;">
                        <span title="{{ ucfirst($row['rag']) }}" style="flex:0 0 auto;margin-top:.35rem;display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:{{ $row['colour'] }};"></span>
                        <div style="flex:1 1 auto;min-width:0;">
                            <div style="{{ $row['na'] ? 'text-decoration:line-through;' : '' }}">
                                <strong>{{ $s->name }}</strong>
                                @if($s->is_milestone)<span style="font-size:.7rem;color:#b45309;margin-left:.35rem;">◆ milestone</span>@endif
                                @if($s->is_custom)<span style="font-size:.7rem;color:#2563eb;margin-left:.35rem;">+ custom</span>@endif
                            </div>
                            @if($row['blocked'])<div style="font-size:.75rem;color:#6b7280;">{{ $row['blocked'] }}</div>@endif
                            @if($row['na'] && $s->na_reason)<div style="font-size:.75rem;color:#6b7280;">Excused: {{ $s->na_reason }}</div>@endif
                        </div>
                        <div style="flex:0 0 auto;text-align:right;font-size:.8rem;">
                            <span style="display:inline-block;padding:.15rem .5rem;border-radius:1rem;font-size:.72rem;color:{{ $badge[1] }};background:{{ $badge[2] }};">{{ $badge[0] }}</span>
                            <div style="color:#6b7280;margin-top:.15rem;">{{ $s->due_date ? \Illuminate\Support\Carbon::parse($s->due_date)->format('d M Y') : '—' }}</div>
                        </div>
                    </div>

                    {{-- Action bar --}}
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem;padding-left:1.3rem;">
                        @if($s->status === 'active')
                            @permission('create_deals')
                            <form method="POST" action="{{ route('deals-dr2.pipeline.step.complete', [$deal, $s]) }}">@csrf
                                <button type="submit" class="corex-btn-secondary" style="padding:.2rem .6rem;font-size:.78rem;">Mark complete</button>
                            </form>
                            @endpermission
                        @endif
                        @unless($terminal)
                            @permission('create_deals')
                            <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="na = !na">N/A</button>
                            @endpermission
                        @endunless
                        @permission('view_deals')
                        <button type="button" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;" @click="cm = !cm">Comments ({{ $s->comments->count() }})</button>
                        @endpermission
                        @permission('create_deals')
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.remove', [$deal, $s]) }}" onsubmit="return confirm('Remove this step? It is archived, not deleted.');">@csrf
                            <button type="submit" class="corex-btn-outline" style="padding:.2rem .6rem;font-size:.78rem;color:#b91c1c;">Remove</button>
                        </form>
                        @endpermission
                    </div>

                    {{-- N/A reason form --}}
                    @unless($terminal)
                    <div x-show="na" x-cloak style="margin:.4rem 0 0 1.3rem;">
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.na', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
                            <input type="text" name="reason" placeholder="Why is this step not applicable? (e.g. no gas on the property)" class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Mark N/A</button>
                        </form>
                    </div>
                    @endunless

                    {{-- Comment thread --}}
                    <div x-show="cm" x-cloak style="margin:.5rem 0 0 1.3rem;">
                        @forelse($s->comments as $c)
                            <div style="font-size:.8rem;margin-bottom:.35rem;">
                                <span style="color:#374151;">{{ $c->body }}</span>
                                <span style="color:#9ca3af;font-size:.72rem;"> — {{ $c->user->name ?? 'Someone' }}, {{ $c->created_at?->format('d M H:i') }}</span>
                            </div>
                        @empty
                            <div style="font-size:.78rem;color:#9ca3af;margin-bottom:.35rem;">No comments yet.</div>
                        @endforelse
                        @permission('view_deals')
                        <form method="POST" action="{{ route('deals-dr2.pipeline.step.comment', [$deal, $s]) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;">@csrf
                            <input type="text" name="body" placeholder="Add a note for this step…" required class="corex-input" style="flex:1 1 260px;font-size:.8rem;">
                            <button type="submit" class="corex-btn-secondary" style="padding:.2rem .7rem;font-size:.78rem;">Post</button>
                        </form>
                        @endpermission
                    </div>
                </div>
            @endforeach

            {{-- Add a custom step --}}
            @permission('create_deals')
            <div x-data="{ add:false }" style="padding:.65rem .5rem;">
                <button type="button" class="corex-btn-outline" style="padding:.25rem .7rem;font-size:.8rem;" @click="add = !add">+ Add custom step</button>
                <div x-show="add" x-cloak style="margin-top:.5rem;">
                    <form method="POST" action="{{ route('deals-dr2.pipeline.step.add', $deal) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">@csrf
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Step name</label>
                            <input type="text" name="name" required placeholder="e.g. Plans approved" class="corex-input" style="font-size:.85rem;"></div>
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Due date</label>
                            <input type="date" name="due_date" class="corex-input" style="font-size:.85rem;"></div>
                        <div><label style="display:block;font-size:.72rem;color:#6b7280;">Insert after</label>
                            <select name="after_step_id" class="corex-input" style="font-size:.85rem;">
                                <option value="">— at the end —</option>
                                @foreach($steps as $r2)
                                    <option value="{{ $r2['model']->id }}">{{ $r2['model']->name }}</option>
                                @endforeach
                            </select></div>
                        <button type="submit" class="corex-btn-primary" style="padding:.3rem .8rem;font-size:.82rem;">Add step</button>
                    </form>
                </div>
            </div>
            @endpermission
        </div>
    @endif
</div>
@endsection
