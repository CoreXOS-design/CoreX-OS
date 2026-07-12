{{--
    DR2 Wave 2 — auto-decline-on-capture notice. Shown after a NEW offer was
    captured against a property that already carries a granted/registered deal:
    the capture SUCCEEDED (offers are always captured) but the deal landed
    Declined. The blocking deal number is a CLICKABLE link opening THAT deal in a
    new tab. Informational (not a block) — dismissible; the deal is already saved.
--}}
@if(session('capture_declined'))
    @php $cd = session('capture_declined'); @endphp
    <div id="dr2-capture-declined" role="dialog" aria-modal="true"
         style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.55);">
        <div style="max-width:34rem;width:calc(100% - 2rem);background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.75rem;box-shadow:0 24px 64px rgba(0,0,0,.25);padding:1.25rem 1.35rem;">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <span style="font-size:1.15rem;">ℹ️</span>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary,#0b2a4a);margin:0;">Deal captured as Declined</h3>
            </div>
            <p style="font-size:.9rem;color:var(--text-secondary,#334155);line-height:1.5;margin:.25rem 0 .75rem;">
                Your offer was captured, but deal
                <a href="{{ $cd['url'] }}" target="_blank" rel="noopener"
                   style="font-weight:700;color:#2563eb;text-decoration:underline;">#{{ $cd['deal_no'] }}</a>
                already carries a <strong>{{ $cd['status'] }}</strong> status on this property. A property may
                hold more than one offer, but only <strong>one</strong> can be {{ strtolower($cd['status']) }} —
                so this new deal was recorded as <strong>Declined</strong>.
            </p>
            <p style="font-size:.82rem;color:var(--text-muted,#64748b);line-height:1.5;margin:0 0 1rem;">
                If deal <a href="{{ $cd['url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">#{{ $cd['deal_no'] }}</a>
                falls through, you can re-grant this one — a declined deal stays re-grantable while no other
                deal is granted on the property.
            </p>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <a href="{{ $cd['url'] }}" target="_blank" rel="noopener"
                   style="font-size:.82rem;font-weight:600;padding:.45rem .9rem;border-radius:.5rem;background:#2563eb;color:#fff;text-decoration:none;">Open deal #{{ $cd['deal_no'] }} ↗</a>
                <button type="button" onclick="document.getElementById('dr2-capture-declined').remove();"
                        style="font-size:.82rem;font-weight:600;padding:.45rem .9rem;border-radius:.5rem;border:1px solid var(--border,#cbd5e1);background:var(--surface-2,#f8fafc);color:var(--text-primary,#0b2a4a);">Got it</button>
            </div>
        </div>
    </div>
@endif
