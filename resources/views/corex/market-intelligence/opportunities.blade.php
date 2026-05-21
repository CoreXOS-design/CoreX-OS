{{-- MIC Phase D1 — Opportunities tab. Folds Tracked Properties content in Phase D4. --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1640px; margin: 0 auto; padding: 0 20px;">
    @include('corex.market-intelligence.partials.tabs')

    <section style="padding: 20px; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; margin-bottom: 16px;">
        <h1 style="font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin: 0 0 6px 0;">Opportunities</h1>
        <p style="font-size: 0.8125rem; color: var(--text-muted); margin: 0;">
            The full tracked-property universe — every property CoreX has intelligence on, regardless of mandate status.
            Promotable to agency stock, edit-address-aware, source-chain preserved.
        </p>
        <p style="font-size: 0.75rem; color: var(--text-muted); margin: 8px 0 0 0;">
            Full Opportunities surface lands in Phase D4 (folds the Tracked Properties listing + filters into this tab).
            For now: total {{ number_format($tpCount ?? 0) }} tracked {{ ($tpCount ?? 0) === 1 ? 'property' : 'properties' }} in your agency.
        </p>
    </section>

    @if(!empty($tps) && $tps->count() > 0)
        <section style="background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem;">
                <thead>
                    <tr style="background: var(--surface-2); color: var(--text-muted); text-transform: uppercase; font-size: 0.6875rem; letter-spacing: 0.04em;">
                        <th style="text-align: left; padding: 8px 12px;">Address</th>
                        <th style="text-align: left; padding: 8px 12px;">Suburb</th>
                        <th style="text-align: left; padding: 8px 12px;">Status</th>
                        <th style="text-align: left; padding: 8px 12px;">Last enriched</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tps as $tp)
                        <tr style="border-top: 1px solid var(--border);">
                            <td style="padding: 8px 12px;">
                                <a href="{{ route('corex.tracked-properties.show', $tp) }}"
                                   style="text-decoration: none; color: var(--brand-button);">
                                    {{ $tp->primaryAddress?->formatted_address ?? $tp->displayAddress() ?? '(no address)' }}
                                </a>
                            </td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ $tp->suburb ?? '—' }}</td>
                            <td style="padding: 8px 12px; color: var(--text-secondary);">{{ ucfirst((string) $tp->status) }}</td>
                            <td style="padding: 8px 12px; color: var(--text-muted); font-size: 0.75rem;">
                                {{ optional($tp->last_enriched_at)->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if(method_exists($tps, 'links'))
                <div style="padding: 12px;">{{ $tps->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection
