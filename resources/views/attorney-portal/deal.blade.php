@extends('attorney-portal.layout')
@section('title', $d['address'])
@section('content')
<p style="margin:0 0 10px;"><a href="{{ route('attorney.deals') }}" style="color:var(--brand);text-decoration:none;">&larr; All my deals</a></p>
<h1>{{ $d['address'] }}</h1>
<p class="sub">
    <span class="pill {{ $d['stage'] === 'Cancelled' ? 'bad' : ($d['finished'] ? 'done' : '') }}">{{ $d['stage'] }}</span>
    @if($d['reference']) &nbsp;<span class="muted">{{ $d['reference'] }}</span>@endif
    &nbsp;<span class="muted">You are the {{ collect($d['roles'])->map(fn ($r) => $r === 'bond_attorney' ? 'bond attorney' : 'transferring attorney')->implode(' and ') }}.</span>
</p>

@if($d['finished'])
    <div class="notice">
        This deal is {{ $d['stage'] === 'Cancelled' ? 'cancelled' : 'registered' }}. You can keep downloading until {{ $d['visible_until']->format('j F Y') }}.
    </div>
@endif

<div class="card">
    <h2>Documents for you</h2>
    @if($d['documents']->isEmpty())
        <p class="muted" style="margin:0;">{{ $d['agent_card']['name'] ?? 'The agent' }} has not filed any documents for you yet. Please check back soon.</p>
    @else
        <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Document</th><th class="hide-sm">Type</th><th>Added</th><th class="hide-sm">Size</th><th></th></tr></thead>
            <tbody>
            @foreach($d['documents'] as $doc)
                <tr>
                    <td>{{ $doc->original_name }}</td>
                    <td class="hide-sm muted">{{ $doc->documentType?->label ?? '—' }}</td>
                    <td>{{ $doc->created_at?->format('j M Y') }}</td>
                    <td class="hide-sm muted">{{ \Illuminate\Support\Number::fileSize((int) $doc->size) }}</td>
                    <td class="act">
                        @if($doc->inlineMimeType())<a class="btn ghost" href="{{ route('attorney.document', [$d['id'], $doc->id, 'view']) }}" target="_blank" rel="noopener">View</a>@endif
                        <a class="btn" href="{{ route('attorney.document', [$d['id'], $doc->id, 'download']) }}">Download</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

<div class="card">
    <h2>Your contact at {{ $agencyName }}</h2>
    @if(!empty($d['agent_card']))
        <dl class="facts">
            <dt>Agent</dt><dd>{{ $d['agent_card']['name'] }}</dd>
            @if($d['agent_card']['phone'])<dt>Phone</dt><dd><a href="tel:{{ preg_replace('/\s+/', '', $d['agent_card']['phone']) }}">{{ $d['agent_card']['phone'] }}</a></dd>@endif
            @if($d['agent_card']['email'])<dt>Email</dt><dd><a href="mailto:{{ $d['agent_card']['email'] }}">{{ $d['agent_card']['email'] }}</a></dd>@endif
        </dl>
    @else
        <p class="muted" style="margin:0;">Please contact {{ $agencyName }} directly.</p>
    @endif
</div>

<div class="card">
    <h2>Who is involved</h2>
    <dl class="facts">
        <dt>Buyer</dt><dd>{{ $d['buyer'] }}</dd>
        <dt>Seller</dt><dd>{{ $d['seller'] }}</dd>
        @if($d['other_attorney'])
            <dt>{{ $d['other_attorney']['label'] }}</dt><dd>{{ $d['other_attorney']['firm'] }}@if($d['other_attorney']['person']) &middot; {{ $d['other_attorney']['person'] }}@endif</dd>
        @endif
    </dl>
</div>

@if(!empty($d['dates']))
<div class="card">
    <h2>Key dates</h2>
    <dl class="facts">
        @foreach($d['dates'] as $row)<dt>{{ $row['label'] }}</dt><dd>{{ $row['date']->format('j F Y') }}</dd>@endforeach
    </dl>
</div>
@endif
@endsection
