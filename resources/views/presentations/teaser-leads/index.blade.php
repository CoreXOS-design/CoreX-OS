@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width: 1100px; margin: 0 auto; padding: 0 20px;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div>
            <a href="{{ route('presentations.show', $presentation) }}"
               style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">← Back to presentation</a>
            <h1 style="font-size:1.25rem;font-weight:600;color:var(--text-primary);margin:6px 0 0 0;">
                Teaser Leads — {{ $presentation->property_address ?: 'Presentation #' . $presentation->id }}
            </h1>
            <p style="font-size:0.8125rem;color:var(--text-muted);margin:4px 0 0 0;">
                Captured leads from teaser /p/{token} submissions, across every teaser link for this presentation.
            </p>
        </div>
    </div>

    @if($leads->isEmpty())
        <div style="padding:24px;text-align:center;background:var(--surface);border:1px dashed var(--border);border-radius:6px;color:var(--text-muted);font-size:0.875rem;">
            No teaser leads captured yet.
        </div>
    @else
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:hidden;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">
                        <th style="text-align:left;padding:8px 12px;">Lead</th>
                        <th style="text-align:left;padding:8px 12px;">Contact</th>
                        <th style="text-align:left;padding:8px 12px;">Email / Phone</th>
                        <th style="text-align:left;padding:8px 12px;">Relationship</th>
                        <th style="text-align:left;padding:8px 12px;">Intent</th>
                        <th style="text-align:left;padding:8px 12px;">Captured</th>
                        <th style="text-align:right;padding:8px 12px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($leads as $l)
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:8px 12px;color:var(--text-primary);font-weight:500;">
                            {{ $l->fullName() }}
                            @if($l->notes)
                                <div style="font-size:0.6875rem;color:var(--text-muted);margin-top:2px;font-weight:400;">
                                    Notes: {{ \Illuminate\Support\Str::limit($l->notes, 60) }}
                                </div>
                            @endif
                        </td>
                        <td style="padding:8px 12px;">
                            @if($l->contact)
                                <a href="{{ route('corex.contacts.show', $l->contact) }}" style="color:var(--brand-button);text-decoration:none;font-size:0.75rem;">
                                    Open contact →
                                </a>
                                @if($l->converted_to_contact_at)
                                    <div style="font-size:0.625rem;color:var(--text-muted);">Newly created</div>
                                @else
                                    <div style="font-size:0.625rem;color:var(--text-muted);">Matched existing</div>
                                @endif
                            @else
                                <span style="color:var(--text-muted);font-size:0.75rem;">No contact link</span>
                            @endif
                        </td>
                        <td style="padding:8px 12px;font-size:0.75rem;color:var(--text-secondary);">
                            @if($l->email)<div>{{ $l->email }}</div>@endif
                            @if($l->phone)<div>{{ $l->phone }}</div>@endif
                        </td>
                        <td style="padding:8px 12px;font-size:0.75rem;color:var(--text-secondary);">
                            {{ ucfirst(str_replace('_', ' ', (string) $l->relationship)) }}
                        </td>
                        <td style="padding:8px 12px;font-size:0.75rem;color:var(--text-secondary);">
                            {{ ucfirst(str_replace('_', ' ', (string) $l->intent)) }}
                        </td>
                        <td style="padding:8px 12px;color:var(--text-muted);font-size:0.75rem;">
                            {{ $l->captured_at->diffForHumans() }}
                        </td>
                        <td style="padding:8px 12px;text-align:right;">
                            <a href="{{ route('presentation.public.show', $l->link->token) }}" target="_blank"
                               style="font-size:0.6875rem;color:var(--text-muted);text-decoration:none;">
                                Open link ↗
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:12px 4px;">{{ $leads->links() }}</div>
    @endif

</div>
@endsection
