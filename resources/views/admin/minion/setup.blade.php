{{-- AT-284 — P24 Chrome-minion setup page (the control surface). DESIGN SYSTEM: tokens via var(). --}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="max-width:1100px;margin:0 auto;padding:1rem;">
  <h1 style="font-size:1.4rem;font-weight:700;color:var(--corex-heading,#0b2a4a);">P24 Auto-Import (Chrome Minion)</h1>
  <p style="color:var(--corex-muted,#6b7280);margin:.25rem 0 1rem;">
    Pick the areas the minion captures from Property24's public search results. Ticked areas = the capture universe;
    the nightly schedule splits them across runs to cycle the full set within the window.
    Currently ticked: <strong id="ticked-count">{{ $tickedCount }}</strong> suburb(s).
  </p>

  @if(session('success'))
    <div style="background:var(--corex-success-bg,#ecfdf5);color:var(--corex-success,#065f46);padding:.6rem .9rem;border-radius:.5rem;margin-bottom:.75rem;">{{ session('success') }}</div>
  @endif
  @if(session('error'))
    <div style="background:var(--corex-danger-bg,#fef2f2);color:var(--corex-danger,#991b1b);padding:.6rem .9rem;border-radius:.5rem;margin-bottom:.75rem;">{{ session('error') }}</div>
  @endif
  @if($errors->any())
    <div style="background:var(--corex-danger-bg,#fef2f2);color:var(--corex-danger,#991b1b);padding:.6rem .9rem;border-radius:.5rem;margin-bottom:.75rem;">{{ $errors->first() }}</div>
  @endif

  {{-- Cadence + master switch --}}
  <section style="border:1px solid var(--corex-border,#e5e7eb);border-radius:.6rem;padding:1rem;margin-bottom:1rem;">
    <h2 style="font-weight:600;margin-bottom:.5rem;">Schedule &amp; cadence</h2>
    <form method="POST" action="{{ route('admin.minion.settings.save') }}">
      @csrf
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;">
        <label>Suburbs per night
          <input type="number" name="targets_per_night" min="1" max="500" value="{{ $settings['targets_per_night'] }}" class="corex-input" style="width:100%;">
        </label>
        <label>Cycle target (days)
          <input type="number" name="cycle_days" min="1" max="31" value="{{ $settings['cycle_days'] }}" class="corex-input" style="width:100%;">
        </label>
        <label>Run at (HH:MM)
          <input type="time" name="run_at" value="{{ $settings['run_at'] }}" class="corex-input" style="width:100%;">
        </label>
        <label>Polite gap min (s)
          <input type="number" name="pace_min_seconds" min="2" max="600" value="{{ $settings['pace_min_seconds'] }}" class="corex-input" style="width:100%;">
        </label>
        <label>Polite gap max (s)
          <input type="number" name="pace_max_seconds" min="2" max="600" value="{{ $settings['pace_max_seconds'] }}" class="corex-input" style="width:100%;">
        </label>
      </div>
      <div style="margin:.6rem 0;">
        <span style="color:var(--corex-muted,#6b7280);">Run days:</span>
        @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d)
          <label style="margin-right:.6rem;"><input type="checkbox" name="run_days[]" value="{{ $d }}" @checked(in_array($d, (array)($settings['run_days'] ?? []))) > {{ $d }}</label>
        @endforeach
      </div>
      <div style="display:flex;gap:1.25rem;align-items:center;margin:.5rem 0;">
        <label><input type="checkbox" name="alert_enabled" value="1" @checked($settings['alert_enabled']) > Failure alerts</label>
        <label title="Turning the nightly schedule ON is the agency's decision.">
          <input type="checkbox" name="enabled" value="1" @checked($settings['enabled']) >
          <strong>Nightly schedule ENABLED</strong>
        </label>
      </div>
      <button type="submit" class="corex-btn-primary" style="margin-top:.5rem;">Save cadence</button>
    </form>
  </section>

  {{-- Run now --}}
  <section style="border:1px solid var(--corex-border,#e5e7eb);border-radius:.6rem;padding:1rem;margin-bottom:1rem;">
    <h2 style="font-weight:600;margin-bottom:.5rem;">Run now</h2>
    <form method="POST" action="{{ route('admin.minion.run-now') }}" style="display:flex;gap:.5rem;align-items:end;flex-wrap:wrap;">
      @csrf
      <label>Town (P24 city name)
        <input type="text" name="town" placeholder="e.g. Margate" class="corex-input" style="min-width:220px;">
      </label>
      <button type="submit" class="corex-btn-outline">Run this town now</button>
      <span style="color:var(--corex-muted,#6b7280);">Queued — captures every ticked suburb in that town, paced.</span>
    </form>
  </section>

  {{-- The tree --}}
  <section style="border:1px solid var(--corex-border,#e5e7eb);border-radius:.6rem;padding:1rem;margin-bottom:1rem;">
    <h2 style="font-weight:600;margin-bottom:.5rem;">Capture universe — Province › Region › Town › Suburb</h2>
    <div id="tree">
      @foreach($provinces as $p)
        <div class="mn-prov" data-pid="{{ $p->id }}" style="border-bottom:1px solid var(--corex-border,#eee);padding:.35rem 0;">
          <button type="button" class="mn-prov-toggle corex-btn-outline" style="padding:.15rem .5rem;">▸</button>
          <strong>{{ $p->name }}</strong>
          <span style="color:var(--corex-muted,#6b7280);">({{ $p->suburb_count }} suburbs)</span>
          <div class="mn-prov-body" style="display:none;margin:.4rem 0 .4rem 1.25rem;"></div>
        </div>
      @endforeach
    </div>
  </section>

  {{-- Run-log --}}
  <section style="border:1px solid var(--corex-border,#e5e7eb);border-radius:.6rem;padding:1rem;">
    <h2 style="font-weight:600;margin-bottom:.5rem;">Run log (latest 60 sessions)</h2>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
        <thead><tr style="text-align:left;color:var(--corex-muted,#6b7280);">
          <th style="padding:.3rem .5rem;">Area</th><th>Status</th><th>Captured</th><th>New</th><th>Updated</th>
          <th>Deactivated</th><th>Failures</th><th>Started</th><th>Duration</th>
        </tr></thead>
        <tbody>
        @forelse($runs as $r)
          <tr style="border-top:1px solid var(--corex-border,#f1f1f1);">
            <td style="padding:.3rem .5rem;">{{ $r->area_label }}</td>
            <td><span style="font-weight:600;color:{{ $r->status==='ok' ? 'var(--corex-success,#065f46)' : ($r->status==='failed' ? 'var(--corex-danger,#991b1b)' : 'var(--corex-muted,#6b7280)') }};">{{ strtoupper($r->status) }}</span></td>
            <td>{{ $r->captured }}</td><td>{{ $r->listings_new }}</td><td>{{ $r->listings_updated }}</td>
            <td>{{ $r->listings_deactivated }}</td>
            <td title="{{ $r->failures_json ? implode('; ', (array)$r->failures_json) : '' }}">{{ $r->failures }}</td>
            <td>{{ optional($r->started_at)->format('Y-m-d H:i') }}</td>
            <td>{{ $r->duration_ms ? round($r->duration_ms/1000,1).'s' : '' }}</td>
          </tr>
        @empty
          <tr><td colspan="9" style="padding:.6rem;color:var(--corex-muted,#6b7280);">No runs yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
(function () {
  const CSRF = '{{ csrf_token() }}';
  const R = {
    towns:   '{{ route('admin.minion.tree.towns') }}',
    suburbs: '{{ route('admin.minion.tree.suburbs') }}',
    toggle:  '{{ route('admin.minion.areas.toggle') }}',
  };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  async function toggle(scope, id, ticked) {
    const res = await fetch(R.toggle, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
      body: JSON.stringify({ scope, id, ticked: ticked ? 1 : 0 }),
    });
    if (res.ok) { const j = await res.json(); document.getElementById('ticked-count').textContent = j.ticked_count; }
    return res.ok;
  }

  async function loadTowns(pid, body) {
    body.innerHTML = '<em style="color:#6b7280;">Loading…</em>';
    const rows = await (await fetch(R.towns + '?province_id=' + pid)).json();
    let region = null, html = '';
    rows.forEach(t => {
      if (t.region !== region) { region = t.region; html += '<div style="margin-top:.4rem;font-weight:600;color:#374151;">' + esc(region || '—') + '</div>'; }
      const full = Number(t.ticked_count) === Number(t.suburb_count) && Number(t.suburb_count) > 0;
      html += '<div class="mn-town" data-city="' + t.city_id + '" style="margin:.15rem 0 .15rem .75rem;">'
        + '<label><input type="checkbox" class="mn-town-cb"' + (full ? ' checked' : '') + '> <strong>' + esc(t.town) + '</strong></label> '
        + '<span style="color:#6b7280;">(' + t.ticked_count + '/' + t.suburb_count + ' ticked)</span> '
        + '<button type="button" class="mn-town-toggle corex-btn-outline" style="padding:.05rem .4rem;">suburbs ▾</button>'
        + '<div class="mn-town-body" style="display:none;margin:.25rem 0 .25rem 1.25rem;"></div>'
        + '</div>';
    });
    body.innerHTML = html || '<em style="color:#6b7280;">No towns.</em>';
  }

  async function loadSuburbs(cityId, body) {
    body.innerHTML = '<em style="color:#6b7280;">Loading…</em>';
    const rows = await (await fetch(R.suburbs + '?city_id=' + cityId)).json();
    body.innerHTML = rows.map(s =>
      '<label style="display:inline-block;min-width:180px;margin:.1rem .5rem;">'
      + '<input type="checkbox" class="mn-sub-cb" data-id="' + s.id + '"' + (Number(s.ticked) ? ' checked' : '') + '> ' + esc(s.name)
      + '</label>'
    ).join('') || '<em style="color:#6b7280;">No suburbs.</em>';
  }

  document.getElementById('tree').addEventListener('click', async (e) => {
    const provToggle = e.target.closest('.mn-prov-toggle');
    if (provToggle) {
      const prov = provToggle.closest('.mn-prov');
      const body = prov.querySelector('.mn-prov-body');
      const open = body.style.display !== 'none';
      body.style.display = open ? 'none' : 'block';
      provToggle.textContent = open ? '▸' : '▾';
      if (!open && !body.dataset.loaded) { await loadTowns(prov.dataset.pid, body); body.dataset.loaded = '1'; }
      return;
    }
    const townToggle = e.target.closest('.mn-town-toggle');
    if (townToggle) {
      const town = townToggle.closest('.mn-town');
      const body = town.querySelector('.mn-town-body');
      const open = body.style.display !== 'none';
      body.style.display = open ? 'none' : 'block';
      if (!open && !body.dataset.loaded) { await loadSuburbs(town.dataset.city, body); body.dataset.loaded = '1'; }
      return;
    }
  });

  document.getElementById('tree').addEventListener('change', async (e) => {
    if (e.target.classList.contains('mn-town-cb')) {
      const town = e.target.closest('.mn-town');
      const ok = await toggle('town', Number(town.dataset.city), e.target.checked);
      if (ok) {
        const body = town.querySelector('.mn-town-body');
        if (body.dataset.loaded) { body.querySelectorAll('.mn-sub-cb').forEach(cb => cb.checked = e.target.checked); }
      } else { e.target.checked = !e.target.checked; }
    } else if (e.target.classList.contains('mn-sub-cb')) {
      const ok = await toggle('suburb', Number(e.target.dataset.id), e.target.checked);
      if (!ok) e.target.checked = !e.target.checked;
    }
  });
})();
</script>
@endsection
