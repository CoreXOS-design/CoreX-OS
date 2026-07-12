{{--
    DR2 Wave 2 — granted-uniqueness block modal. Shows when a grant was blocked
    because the property already carries a granted/registered deal. The deal
    number is a CLICKABLE link opening THAT deal in a new tab so the user can
    resolve it (e.g. decline the fallen-through deal); the current screen kept
    every entered field (controller returned back()->withInput()), so the user
    returns and continues without loss.
--}}
@if(session('grant_conflict'))
    @php $gc = session('grant_conflict'); @endphp
    <div id="dr2-grant-conflict" role="dialog" aria-modal="true"
         style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.55);">
        <div style="max-width:34rem;width:calc(100% - 2rem);background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.75rem;box-shadow:0 24px 64px rgba(0,0,0,.25);padding:1.25rem 1.35rem;">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <span style="font-size:1.15rem;">⚠️</span>
                <h3 style="font-size:1rem;font-weight:700;color:var(--text-primary,#0b2a4a);margin:0;">This deal may only be set to Declined</h3>
            </div>
            <p style="font-size:.9rem;color:var(--text-secondary,#334155);line-height:1.5;margin:.25rem 0 .75rem;">
                Deal
                <a href="{{ $gc['url'] }}" target="_blank" rel="noopener"
                   style="font-weight:700;color:#2563eb;text-decoration:underline;">#{{ $gc['deal_no'] }}</a>
                already carries a <strong>{{ $gc['status'] }}</strong> status on this property. A property may
                hold more than one offer, but only <strong>one</strong> can be {{ strtolower($gc['status']) }}.
            </p>
            <p style="font-size:.82rem;color:var(--text-muted,#64748b);line-height:1.5;margin:0 0 1rem;">
                Open deal <a href="{{ $gc['url'] }}" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;">#{{ $gc['deal_no'] }}</a>
                in the new tab to resolve it (e.g. decline the fallen-through deal), then return here —
                everything you entered has been kept.
            </p>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <a href="{{ $gc['url'] }}" target="_blank" rel="noopener"
                   style="font-size:.82rem;font-weight:600;padding:.45rem .9rem;border-radius:.5rem;background:#2563eb;color:#fff;text-decoration:none;">Open deal #{{ $gc['deal_no'] }} ↗</a>
                <button type="button" onclick="document.getElementById('dr2-grant-conflict').remove();"
                        style="font-size:.82rem;font-weight:600;padding:.45rem .9rem;border-radius:.5rem;border:1px solid var(--border,#cbd5e1);background:var(--surface-2,#f8fafc);color:var(--text-primary,#0b2a4a);">Return &amp; keep editing</button>
            </div>
        </div>
    </div>
@endif
