@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('presentations.show', $presentation) }}" class="text-xs no-underline" style="color:var(--text-muted);">&larr; Back to presentation</a>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    Deliveries — {{ $presentation->property_address ?: ('Presentation #' . $presentation->id) }}
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    Every email, WhatsApp, and copy-URL send for this presentation.
                </p>
            </div>
        </div>
    </div>

    @if($deliveries->isEmpty())
        <div style="padding:24px;text-align:center;background:var(--surface);border:1px dashed var(--border);border-radius:6px;color:var(--text-muted);font-size:0.875rem;">
            No deliveries yet. Use <strong style="color:var(--text-primary);">Send to Recipient</strong> on the presentation page to start.
        </div>
    @else
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:6px;overflow:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8125rem;">
                <thead>
                    <tr style="background:var(--surface-2);color:var(--text-muted);font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.04em;">
                        <th style="text-align:left;padding:8px 12px;">Recipient</th>
                        <th style="text-align:left;padding:8px 12px;">Channel</th>
                        <th style="text-align:left;padding:8px 12px;">Mode</th>
                        <th style="text-align:left;padding:8px 12px;">Status</th>
                        <th style="text-align:left;padding:8px 12px;">Views</th>
                        <th style="text-align:left;padding:8px 12px;">Sent</th>
                        <th style="text-align:right;padding:8px 12px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($deliveries as $d)
                    @php
                        $statusTones = [
                            'queued'    => 'ds-badge-info',
                            'sent'      => 'ds-badge-info',
                            'delivered' => 'ds-badge-success',
                            'opened'    => 'ds-badge-success',
                            'failed'    => 'ds-badge-danger',
                            'bounced'   => 'ds-badge-warning',
                        ];
                        $statusTone = $statusTones[$d->status] ?? 'ds-badge-muted';
                    @endphp
                    <tr style="border-top:1px solid var(--border);">
                        <td style="padding:8px 12px;">
                            @if($d->contact)
                                <a href="{{ route('corex.contacts.show', $d->contact) }}" style="color:var(--brand-button);font-weight:500;text-decoration:none;">
                                    {{ trim(($d->contact->first_name ?? '') . ' ' . ($d->contact->last_name ?? '')) ?: $d->recipient_name }}
                                </a>
                            @else
                                <span style="color:var(--text-primary);">{{ $d->recipient_name }}</span>
                                <span style="font-size:0.6875rem;color:var(--text-muted);">· ad-hoc</span>
                            @endif
                            <div style="font-size:0.6875rem;color:var(--text-muted);">
                                {{ $d->recipient_email ?: $d->recipient_phone }}
                            </div>
                        </td>
                        <td style="padding:8px 12px;font-size:0.75rem;">
                            @switch($d->channel)
                                @case('email')    📧 Email @break
                                @case('whatsapp') 💬 WhatsApp @break
                                @case('copy')     🔗 Copy URL @break
                                @case('sms')      📱 SMS @break
                                @default          {{ $d->channel }}
                            @endswitch
                        </td>
                        <td style="padding:8px 12px;font-size:0.75rem;">
                            @if($d->mode === 'teaser')
                                <span class="ds-badge ds-badge-info">Teaser</span>
                            @else
                                <span class="ds-badge ds-badge-muted">Full</span>
                            @endif
                        </td>
                        <td style="padding:8px 12px;">
                            <span class="ds-badge {{ $statusTone }}">{{ ucfirst($d->status) }}</span>
                            @if($d->error_message)<div style="font-size:0.6875rem;color:#dc2626;margin-top:2px;">{{ \Illuminate\Support\Str::limit($d->error_message, 50) }}</div>@endif
                        </td>
                        <td style="padding:8px 12px;text-align:center;font-variant-numeric:tabular-nums;">
                            {{ $d->link?->view_count ?? 0 }}
                            @if($d->link?->first_viewed_at)
                                <div style="font-size:0.6875rem;color:var(--text-muted);">First {{ $d->link->first_viewed_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td style="padding:8px 12px;color:var(--text-muted);font-size:0.75rem;">
                            {{ $d->sent_at ? $d->sent_at->diffForHumans() : 'Not yet' }}
                            @if($d->sender)
                                <div style="font-size:0.625rem;">by {{ $d->sender->name }}</div>
                            @endif
                        </td>
                        <td style="padding:8px 12px;text-align:right;white-space:nowrap;">
                            @if($d->link && !$d->link->isRevoked() && !$d->link->isExpired())
                                <a href="{{ route('presentation.public.show', $d->link->token) }}" target="_blank"
                                   class="corex-btn-outline corex-btn-xs" style="text-decoration:none;">View link ↗</a>
                            @endif
                            @if($d->channel === 'whatsapp' && $d->whatsapp_url)
                                <a href="{{ route('corex.deliveries.whatsapp-redirect', $d) }}" target="_blank"
                                   class="corex-btn-outline corex-btn-xs" style="text-decoration:none;">Open WhatsApp ↗</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:12px 4px;">{{ $deliveries->links() }}</div>
    @endif

</div>
@endsection
