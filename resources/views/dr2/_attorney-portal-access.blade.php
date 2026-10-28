{{--
    .ai/specs/attorney-portal.md §10 - "Portal access" on the deal: who has an attorney portal link, its state, last sign-in,
    last document opened, and the Send / Resend / Revoke actions. Only the people CHOSEN on this deal appear. Included under
    Email Parties; renders nothing unless the agency has switched the attorney portal on and the user may invite.
    include('dr2._attorney-portal-access', ['deal' => $deal]).
--}}
@php
    $apSvc     = app(\App\Services\AttorneyPortal\AttorneyPortalAccessService::class);
    $apEnabled = \App\Models\AttorneyPortalSetting::forAgency((int) $deal->agency_id)->isEnabled();
    $apCan     = $apEnabled && auth()->user()?->hasPermission('deals_v2.invite_attorney');
    $apRows    = $apCan ? $apSvc->panelFor($deal) : [];
@endphp
@if($apCan && count($apRows))
    <div class="corex-card" style="padding:1rem;margin-top:.75rem;" data-attorney-portal-panel>
        <span class="dr2-sect-title">Attorney portal access</span>
        <p style="font-size:.75rem;color:var(--text-muted,#6b7280);margin:.35rem 0 .6rem;">
            Lets the attorney open this deal on their own page and download the documents set up for their role. Download only. They can only ever see deals they are personally chosen on.
        </p>
        @if(session('success'))<p style="font-size:.78rem;color:#166534;margin:0 0 .5rem;">{{ session('success') }}</p>@endif
        @php($apError = session('errors')?->first('portal'))
        @if($apError)<p style="font-size:.78rem;color:#b91c1c;margin:0 0 .5rem;">{{ $apError }}</p>@endif

        @foreach($apRows as $row)
            <div style="border-top:1px solid var(--border,rgba(0,0,0,.08));padding:.55rem 0;font-size:.8rem;" data-portal-row="{{ $row['role'] }}">
                <div style="font-weight:600;color:var(--text-primary,#111827);">{{ $row['label'] }}: {{ $row['person'] ?: ($row['firm'] ?: '—') }}@if($row['person'] && $row['firm']) <span style="font-weight:400;color:var(--text-muted,#6b7280);">at {{ $row['firm'] }}</span>@endif</div>

                @if(! $row['contact'])
                    {{-- A firm is on the deal but no person: say the next action and put the control right here (first-time-user rule). --}}
                    <div style="color:#92400e;font-weight:600;">Choose the attorney contact for this deal</div>
                    <div style="color:var(--text-secondary,#4b5563);margin-bottom:.3rem;">The portal is opened for one person, so pick who at {{ $row['firm'] ?: 'the firm' }} is handling this deal.</div>
                    @if(! auth()->user()?->hasPermission('create_deals'))
                        <div style="color:var(--text-muted,#6b7280);">You can't edit this deal. Ask someone who can to choose the contact.</div>
                    @elseif($row['candidates']->isEmpty())
                        <div style="color:var(--text-secondary,#4b5563);">{{ $row['firm'] ?: 'This firm' }} has no contacts yet. <a href="{{ route('deals-v2.suppliers.index') }}" style="color:var(--brand-icon,#0ea5e9);">Add a contact in the Supplier Directory</a>, then come back here.</div>
                    @else
                        <form method="POST" action="{{ route('deals-dr2.pipeline.attorney-contact', $deal) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;" data-choose-attorney-contact>@csrf
                            <input type="hidden" name="role" value="{{ $row['role'] }}">
                            <select name="contact_id" required class="corex-input" style="font-size:.78rem;min-width:14rem;">
                                <option value="">Choose a contact&hellip;</option>
                                @foreach($row['candidates'] as $c)
                                    <option value="{{ $c->id }}">{{ $c->label }}{{ $c->email ? ' - ' . $c->email : ' - no email yet' }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="corex-btn-primary" style="font-size:.75rem;padding:.3rem .8rem;">Use this contact</button>
                        </form>
                    @endif
                @elseif(! $row['has_email'])
                    <div style="color:#92400e;">This contact has no email address. Add one in the Supplier Directory first.</div>
                @else
                    <div style="color:var(--text-secondary,#4b5563);">
                        @if(! $row['access'] || $row['status'] === 'revoked')
                            {{ $row['access'] ? 'Access was removed.' : 'No portal link sent yet.' }}
                        @else
                            Link {{ $row['status'] === 'live' ? 'sent, valid until ' . $row['access']->expires_at->format('j M Y') : 'expired on ' . $row['access']->expires_at->format('j M Y') }}.
                            @if($row['last_login']) Last signed in {{ $row['last_login']->format('j M Y H:i') }}.@else Has not signed in yet.@endif
                            @if($row['last_download']) Last opened a document {{ $row['last_download']->format('j M Y H:i') }}.@endif
                        @endif
                    </div>
                    <div style="display:flex;gap:.75rem;margin-top:.35rem;">
                        <form method="POST" action="{{ route('deals-v2.attorney-portal.invite', $row['contact']) }}">@csrf
                            <button type="submit" class="corex-btn-outline" style="font-size:.75rem;padding:.25rem .65rem;" data-send-portal-link>{{ $row['access'] && $row['status'] !== 'revoked' ? 'Resend link' : 'Send portal link' }}</button>
                        </form>
                        @if($row['access'] && $row['status'] !== 'revoked')
                            <form method="POST" action="{{ route('deals-v2.attorney-portal.revoke', $row['access']) }}"
                                  onsubmit="return confirm('Stop this person opening their deals? Their link and any open page stop working straight away.');">@csrf
                                <button type="submit" class="corex-btn-outline" style="font-size:.75rem;padding:.25rem .65rem;color:#b91c1c;border-color:#b91c1c;" data-revoke-portal>Revoke access</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
        <p style="font-size:.7rem;color:var(--text-muted,#9ca3af);margin:.4rem 0 0;">Access covers every deal this person is chosen on, not only this one. Revoking removes all of it.</p>
    </div>
@endif
