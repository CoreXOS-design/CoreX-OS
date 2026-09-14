{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Approvals hub — spec .ai/specs/esign-compliance-approval-gate.md §8.3.
     Three separately labelled, separately counted groups. Never one merged list. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Approvals</h1>
                <p class="text-xs" style="color: var(--text-muted);">Everything waiting on an officer's decision, in three groups. Each group opens its own queue.</p>
            </div>
            <div class="text-xs font-semibold" style="color: var(--text-secondary);">
                {{ number_format($counts['total']) }} waiting on you
            </div>
        </div>
    </div>

    @php
        $ficaTotal = $counts['fica']['ro'] + $counts['fica']['co'];
        $groups = [
            [
                'key'    => 'fica',
                'title'  => 'FICA',
                'sub'    => 'Routine, cleared in batches',
                'count'  => $ficaTotal,
                'url'    => url('/corex/compliance/fica?tab=' . ($counts['fica']['co'] > 0 ? 'co_queue' : 'ro_queue')),
                'link'   => 'Open the FICA queue',
                'colour' => 'var(--brand-icon, #0ea5e9)',
                'empty'  => 'No FICA packs waiting for you.',
            ],
            [
                'key'    => 'esign',
                'title'  => 'Documents awaiting release',
                'sub'    => 'Blocking a deal and a person right now',
                'count'  => $counts['esign'],
                'url'    => route('docuperfect.approvals.index'),
                'link'   => 'Open the documents queue',
                'colour' => 'var(--ds-amber, #f59e0b)',
                'empty'  => 'No documents held for compliance approval.',
            ],
            [
                'key'    => 'whistleblow',
                'title'  => 'Compliance reports',
                'sub'    => 'Rare and serious',
                'count'  => $counts['whistleblow'],
                'url'    => route('compliance.whistleblow.index', ['status' => 'pending_approval']),
                'link'   => 'Open compliance reporting',
                'colour' => 'var(--ds-crimson, #dc2626)',
                'empty'  => 'No compliance reports waiting for a decision.',
            ],
        ];
    @endphp

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        @foreach($groups as $g)
        <section class="rounded-md flex flex-col" style="background: var(--surface); border: 1px solid var(--border); border-top: 3px solid {{ $g['colour'] }};">
            <header class="px-4 py-3 flex items-start justify-between gap-3" style="border-bottom: 1px solid var(--border);">
                <div>
                    <h2 class="text-sm font-bold" style="color: var(--text-primary);">{{ $g['title'] }}</h2>
                    <p class="text-[11px]" style="color: var(--text-muted);">{{ $g['sub'] }}</p>
                </div>
                <span class="inline-flex items-center justify-center rounded-full text-sm font-bold px-2.5" style="min-width: 32px; height: 28px; background: color-mix(in srgb, {{ $g['colour'] }} 15%, transparent); color: {{ $g['colour'] }};">{{ number_format($g['count']) }}</span>
            </header>

            <div class="flex-1 divide-y" style="border-color: var(--border);">
                @if($g['key'] === 'fica')
                    @foreach($ficaCo as $s)
                        <a href="{{ url('/corex/compliance/fica/' . $s->id . '/compliance-review') }}" class="block px-4 py-2.5 hover:opacity-90 no-underline">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ trim((optional($s->contact)->first_name ?? '') . ' ' . (optional($s->contact)->last_name ?? '')) ?: 'A contact' }}</div>
                            <div class="text-[11px]" style="color: var(--text-muted);">Referred to you · {{ $s->updated_at?->diffForHumans() }}</div>
                        </a>
                    @endforeach
                    @foreach($ficaRo as $s)
                        <a href="{{ url('/corex/compliance/fica/' . $s->id . '/compliance-review') }}" class="block px-4 py-2.5 hover:opacity-90 no-underline">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ trim((optional($s->contact)->first_name ?? '') . ' ' . (optional($s->contact)->last_name ?? '')) ?: 'A contact' }}</div>
                            <div class="text-[11px]" style="color: var(--text-muted);">Waiting for reviewer approval · {{ optional($s->requestedBy)->name ?? 'an agent' }} · {{ $s->updated_at?->diffForHumans() }}</div>
                        </a>
                    @endforeach
                    @if($ficaRo->isEmpty() && $ficaCo->isEmpty())
                        <p class="px-4 py-6 text-xs text-center" style="color: var(--text-muted);">{{ $g['empty'] }}</p>
                    @endif
                @elseif($g['key'] === 'esign')
                    @forelse($esignItems as $a)
                        <a href="{{ route('docuperfect.approvals.index') }}" class="block px-4 py-2.5 hover:opacity-90 no-underline">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $a->signatureTemplate?->document?->name ?? 'Untitled document' }}</div>
                            <div class="text-[11px]" style="color: var(--text-muted);">{{ $a->requester?->name ?? 'A former user' }} · held {{ $a->created_at?->diffForHumans() }}</div>
                        </a>
                    @empty
                        <p class="px-4 py-6 text-xs text-center" style="color: var(--text-muted);">{{ $g['empty'] }}</p>
                    @endforelse
                @else
                    @forelse($wbItems as $c)
                        <a href="{{ route('compliance.whistleblow.show', $c) }}" class="block px-4 py-2.5 hover:opacity-90 no-underline">
                            <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">CDX-WB-{{ $c->id }} · {{ $c->property_address ?: 'a property' }}</div>
                            <div class="text-[11px]" style="color: var(--text-muted);">{{ $c->reporter?->name ?? 'A former user' }} · {{ str_replace('_', ' ', $c->tier) }} · {{ $c->created_at?->diffForHumans() }}</div>
                        </a>
                    @empty
                        <p class="px-4 py-6 text-xs text-center" style="color: var(--text-muted);">{{ $g['empty'] }}</p>
                    @endforelse
                @endif
            </div>

            <footer class="px-4 py-3" style="border-top: 1px solid var(--border);">
                <a href="{{ $g['url'] }}" class="text-xs font-semibold no-underline" style="color: var(--brand-icon, #0ea5e9);">{{ $g['link'] }} →</a>
            </footer>
        </section>
        @endforeach
    </div>

    <p class="text-[11px]" style="color: var(--text-muted);">You only see items you are appointed to decide, inside the branch or agency you may act on. Counts refresh every time a page loads; the popup checks for new items every minute.</p>
</div>
@endsection
