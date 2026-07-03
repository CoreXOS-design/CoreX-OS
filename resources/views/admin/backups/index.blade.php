@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-163 — System Developer → Backups. Read-only status/health/history over the
     off-box restic backup + audited password reveal + configurable stale alarm. --}}

@php
    $st       = $status;                       // status() array (always well-formed)
    $alarm    = $st['alarm'] ?? true;
    $stateLbl = $st['present'] ? ($alarm ? 'Needs attention' : 'Healthy') : 'No data yet';
    // Health colour token
    $accent   = $alarm ? 'var(--ds-red, #dc2626)' : 'var(--ds-green, #16a34a)';
    // Next scheduled nightly run (cron 03:30 SAST)
    $now      = \Carbon\Carbon::now();
    $nextRun  = $now->copy()->setTime(3, 30, 0);
    if ($nextRun->lessThanOrEqualTo($now)) { $nextRun->addDay(); }
@endphp

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Backups</h1>
                <p class="text-sm text-white/60">
                    Off-box encrypted backup (restic → Hetzner Storage Box) — status, health, snapshots and history.
                </p>
            </div>
            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-semibold"
                  style="background: color-mix(in srgb, {{ $accent }} 18%, transparent); color: {{ $accent }};">
                <span style="width:8px;height:8px;border-radius:9999px;background:{{ $accent }};display:inline-block;"></span>
                {{ $stateLbl }}
            </span>
        </div>
    </div>

    {{-- Flash: reveal error / threshold saved --}}
    @if(session('backup_threshold_saved'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); color: var(--ds-green, #16a34a); border: 1px solid color-mix(in srgb, var(--ds-green, #16a34a) 30%, transparent);">
            {{ session('backup_threshold_saved') }}
        </div>
    @endif
    @if(session('backup_reveal_error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-red, #dc2626) 12%, transparent); color: var(--ds-red, #dc2626); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent);">
            {{ session('backup_reveal_error') }}
        </div>
    @endif

    {{-- Degraded banner when no status file is present/readable --}}
    @unless($st['present'])
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); color: var(--ds-amber, #d97706); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent);">
            No backup status is available yet. The nightly job has not written <code class="font-mono">/var/lib/corex-backup/status.json</code>,
            or it is not readable by the app. The page below shows what it can; nothing here is a failure of this screen.
        </div>
    @endunless

    {{-- ── STATUS / HEALTH ─────────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Status &amp; Health</h2>
        </div>
        <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            @php
                $cell = 'flex flex-col gap-1';
                $lbl  = 'text-xs uppercase tracking-wide';
                $lblStyle = 'color: var(--text-muted, #64748b);';
                $val  = 'font-semibold';
            @endphp
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Last run state</span><span class="{{ $val }}">{{ $st['state'] }}</span></div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Last successful backup</span><span class="{{ $val }}">{{ $st['last_success_human'] ?? '—' }}{{ $st['hours_since_success'] !== null ? " ({$st['hours_since_success']}h ago)" : '' }}</span></div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Stale alarm</span>
                <span class="{{ $val }}" style="color: {{ $st['stale'] ? 'var(--ds-red, #dc2626)' : 'var(--ds-green, #16a34a)' }};">
                    {{ $st['stale'] ? 'STALE — over threshold' : 'OK — within threshold' }} ({{ $st['threshold_hours'] }}h)
                </span>
            </div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Retention policy</span><span class="{{ $val }}">{{ $st['retention'] ?? '7d / 4w / 6m' }}</span></div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Schedule</span><span class="{{ $val }}">{{ $st['schedule'] ?? 'Nightly 03:30' }} (SAST)</span></div>
            <div class="{{ $cell }}"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Next scheduled run</span><span class="{{ $val }}">{{ $nextRun->format('Y-m-d H:i') }} (SAST)</span></div>
            <div class="{{ $cell }} sm:col-span-2 lg:col-span-3"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Repository</span><span class="font-mono text-xs" style="color: var(--text-muted, #64748b);">{{ $st['repo'] ?? '—' }}</span></div>
            @if($st['message'])
                <div class="{{ $cell }} sm:col-span-2 lg:col-span-3"><span class="{{ $lbl }}" style="{{ $lblStyle }}">Message</span><span>{{ $st['message'] }}</span></div>
            @endif
        </div>
    </div>

    {{-- ── STALE ALARM THRESHOLD (configurable) ────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Stale-alarm threshold</h2>
        </div>
        <form method="POST" action="{{ route('admin.backups.threshold') }}" class="p-4 flex flex-wrap items-end gap-3 text-sm">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-1">
                <label for="stale_alarm_hours" class="text-xs uppercase tracking-wide" style="color: var(--text-muted, #64748b);">Alarm if no successful backup within (hours)</label>
                <input type="number" id="stale_alarm_hours" name="stale_alarm_hours" min="1" max="720" value="{{ old('stale_alarm_hours', $threshold) }}"
                       class="w-40 rounded-md px-3 py-2" style="background: var(--input-bg, #fff); border: 1px solid var(--border); color: var(--text);">
                @error('stale_alarm_hours')<span style="color: var(--ds-red, #dc2626);" class="text-xs">{{ $message }}</span>@enderror
            </div>
            <button type="submit" class="corex-btn-outline">Save threshold</button>
            <span class="text-xs" style="color: var(--text-muted, #64748b);">The nightly health check (09:15) alerts when the repo goes stale past this.</span>
        </form>
    </div>

    {{-- ── SNAPSHOTS ───────────────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Snapshots</h2>
            <span class="text-xs" style="color: var(--text-muted, #64748b);">{{ count($snapshots) }} in repository</span>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">ID</th>
                        <th class="text-left">Taken</th>
                        <th class="text-left">Host</th>
                        <th class="text-left">Tags</th>
                        <th class="text-left">Paths</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($snapshots as $snap)
                        <tr>
                            <td class="font-mono">{{ $snap['id'] }}</td>
                            <td>{{ $snap['time'] }}</td>
                            <td>{{ $snap['host'] }}</td>
                            <td>{{ implode(', ', $snap['tags']) }}</td>
                            <td class="text-xs" style="color: var(--text-muted, #64748b);">{{ count($snap['paths']) }} paths</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-4" style="color: var(--text-muted, #64748b);">No snapshot list available (snapshots.json missing or empty).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── RUN HISTORY ─────────────────────────────────────────────── --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--brand-icon, #0ea5e9);">Run history</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="ds-table w-full text-sm">
                <thead>
                    <tr>
                        <th class="text-left">When</th>
                        <th class="text-left">Result</th>
                        <th class="text-left">Duration</th>
                        <th class="text-left">Files</th>
                        <th class="text-left">Added</th>
                        <th class="text-left">Stored</th>
                        <th class="text-left">Snapshot</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($runs as $run)
                        @php $ok = ($run['state'] ?? '') === 'OK'; @endphp
                        <tr>
                            <td>{{ $run['ts'] ?? '—' }}</td>
                            <td><span class="font-semibold" style="color: {{ $ok ? 'var(--ds-green, #16a34a)' : 'var(--ds-red, #dc2626)' }};">{{ $run['state'] ?? '?' }}</span></td>
                            <td>{{ isset($run['duration_s']) ? gmdate('i:s', (int) $run['duration_s']) : '—' }}</td>
                            <td>{{ $run['files'] ?? '—' }}</td>
                            <td>{{ $run['added'] ?: '—' }}</td>
                            <td>{{ $run['stored'] ?: '—' }}</td>
                            <td class="font-mono">{{ $run['snapshot'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4" style="color: var(--text-muted, #64748b);">No run history yet (runs.jsonl empty).</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── ENCRYPTION PASSWORD (gated, audited reveal) ─────────────── --}}
    @if($canReveal)
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--ds-amber, #d97706);">Backup encryption password</h2>
        </div>
        <div class="p-4 space-y-3 text-sm">
            <div class="rounded-md px-4 py-3" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent); color: var(--ds-amber, #d97706); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 28%, transparent);">
                <strong>This copy dies with the server.</strong> The repo password lives in one root-only file on this box.
                If the box is lost, the off-box backup is unrecoverable without this password. Keep an OFFLINE copy in a
                password manager — that is the disaster copy. Every reveal below is recorded in the audit log.
            </div>

            @if(session('revealed_backup_password'))
                <div class="rounded-md px-4 py-3" style="background: var(--surface-alt, #f8fafc); border: 1px dashed var(--border);">
                    Password (shown once — this reveal is logged):
                    <code class="font-mono font-semibold ml-1">{{ session('revealed_backup_password') }}</code>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.backups.reveal') }}"
                  onsubmit="return confirm('Reveal the backup encryption password? Every reveal is recorded in the audit log.');">
                @csrf
                <button type="submit" class="corex-btn-outline" style="border-color: var(--ds-amber, #d97706); color: var(--ds-amber, #d97706);">
                    Reveal backup password
                </button>
            </form>

            @if($recentReveals->isNotEmpty())
                <div class="pt-2">
                    <div class="text-xs uppercase tracking-wide mb-1" style="color: var(--text-muted, #64748b);">Recent reveals (audit)</div>
                    <table class="ds-table w-full text-xs">
                        <thead><tr><th class="text-left">When</th><th class="text-left">By</th><th class="text-left">IP</th></tr></thead>
                        <tbody>
                            @foreach($recentReveals as $rv)
                                <tr>
                                    <td>{{ optional($rv->revealed_at)->format('Y-m-d H:i') }}</td>
                                    <td>{{ optional($rv->revealedBy)->name ?? ('user #'.$rv->revealed_by) }}</td>
                                    <td class="font-mono">{{ $rv->ip_address }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
    @endif

</div>
@endsection
