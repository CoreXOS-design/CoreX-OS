{{--
    .ai/specs/rental-work-orders.md §14.27.6 item 4 — the crew's per-job view,
    shared by the per-job link (Build 1) and the crew page (Build 2).

    Renders CrewJobService::payload() — a flat array of plain values, never a
    model, so nothing the crew must not see can leak in here by accident.

    Expects:
      $job      array   CrewJobService::payload($card, $ctx)
      $actions  array   ['tick' => url containing the literal "__TASK__" placeholder,
                         'photos' => url, 'complete' => url]
      $errors / session('success') are shown by the including page.

    Mobile, one-handed: every tap target is >= 48px, the two actions sit at the
    bottom, and every form also works with JavaScript off (the script below only
    saves a page reload on a tick and guards a double-tap on upload / sign).
--}}
<div class="crew-job">
    <style>
        .crew-job { --cj-border: rgba(0,0,0,.09); --cj-muted: #5a6472; --cj-ok: #1f8a4c; --cj-accent: #00b4d8; --cj-text: #141821; color: var(--cj-text); }
        .crew-job * { box-sizing: border-box; }
        .crew-job .cj-card { background: #fff; border: 1px solid var(--cj-border); border-radius: 14px; padding: 16px; margin-bottom: 14px; }
        .crew-job h2 { font-size: 15px; margin: 0 0 10px; font-weight: 700; }
        .crew-job .cj-title { font-size: 19px; font-weight: 800; margin: 0 0 6px; line-height: 1.25; }
        .crew-job .cj-muted { color: var(--cj-muted); font-size: 13px; }
        .crew-job .cj-chip { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: #eef3f8; color: #26435f; }
        .crew-job .cj-chip.ok { background: #eafaf0; color: var(--cj-ok); }
        .crew-job .cj-row { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; border-top: 1px solid var(--cj-border); font-size: 14px; }
        .crew-job .cj-row:first-of-type { border-top: 0; }
        .crew-job .cj-row .q { white-space: nowrap; font-weight: 700; }
        .crew-job .cj-task { display: flex; align-items: center; gap: 12px; width: 100%; min-height: 52px; padding: 10px 12px; margin-bottom: 8px; border: 1px solid var(--cj-border); border-radius: 12px; background: #fff; font: inherit; font-size: 15px; text-align: left; cursor: pointer; color: inherit; }
        .crew-job .cj-task[disabled] { cursor: default; opacity: .7; }
        .crew-job .cj-task .box { flex: 0 0 26px; width: 26px; height: 26px; border: 2px solid #9aa6b2; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 17px; line-height: 1; color: #fff; }
        .crew-job .cj-task.done { background: #f3fbf6; border-color: #bfe6cd; }
        .crew-job .cj-task.done .box { background: var(--cj-ok); border-color: var(--cj-ok); }
        .crew-job .cj-task.done .txt { color: var(--cj-muted); text-decoration: line-through; }
        .crew-job label.cj-label { display: block; font-size: 13px; font-weight: 700; margin: 12px 0 5px; }
        .crew-job input[type=text], .crew-job select { width: 100%; min-height: 48px; padding: 11px 12px; border: 1px solid var(--cj-border); border-radius: 10px; font: inherit; font-size: 16px; background: #fff; }
        .crew-job .cj-btn { display: block; width: 100%; min-height: 52px; padding: 13px; border: 0; border-radius: 12px; font: inherit; font-size: 16px; font-weight: 800; text-align: center; cursor: pointer; margin-top: 12px; }
        .crew-job .cj-btn.primary { background: var(--cj-accent); color: #fff; }
        .crew-job .cj-btn.ok { background: var(--cj-ok); color: #fff; }
        .crew-job .cj-btn.ghost { background: #eef3f8; color: #26435f; text-decoration: none; }
        .crew-job .cj-btn[disabled] { opacity: .55; }
        .crew-job .cj-pills { display: flex; gap: 8px; }
        .crew-job .cj-pills label { flex: 1; }
        .crew-job .cj-pills input { position: absolute; opacity: 0; pointer-events: none; }
        .crew-job .cj-pills span { display: block; text-align: center; padding: 13px 6px; border: 1px solid var(--cj-border); border-radius: 12px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .crew-job .cj-pills input:checked + span { background: #e6f7fb; border-color: var(--cj-accent); color: #076e84; }
        .crew-job .cj-photos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 12px; }
        .crew-job .cj-photos a { display: block; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: #e8edf2; }
        .crew-job .cj-photos img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .crew-job .cj-confirm { display: flex; gap: 12px; align-items: flex-start; margin-top: 12px; font-size: 14px; line-height: 1.4; }
        .crew-job .cj-confirm input { flex: 0 0 26px; width: 26px; height: 26px; margin: 0; }
        .crew-job .cj-banner { background: #eafaf0; border: 1px solid #bfe6cd; color: var(--cj-ok); border-radius: 12px; padding: 14px; font-weight: 700; font-size: 15px; }
    </style>

    <div class="cj-card">
        <div class="cj-title">{{ $job['title'] }}</div>
        <div style="margin-bottom:8px;">
            <span class="cj-chip {{ $job['crew_completed'] ? 'ok' : '' }}">{{ $job['crew_completed'] ? 'Work completed' : $job['status_label'] }}</span>
        </div>
        @if($job['address'] !== '')
            <div style="font-size:15px;">{{ $job['address'] }}</div>
        @endif
        @if($job['map_url'])
            <a class="cj-btn ghost" href="{{ $job['map_url'] }}" target="_blank" rel="noopener" style="margin-top:10px;">Open in Maps</a>
        @endif
        @if($job['scheduled_at'] || $job['due_at'])
            <div class="cj-muted" style="margin-top:10px;">
                @if($job['scheduled_at'])<div>Scheduled: <strong>{{ $job['scheduled_at'] }}</strong></div>@endif
                @if($job['due_at'])<div>Due: <strong>{{ $job['due_at'] }}</strong></div>@endif
            </div>
        @endif
        @if($job['crew_name'])<div class="cj-muted" style="margin-top:6px;">Crew: {{ $job['crew_name'] }}</div>@endif
        @if($job['access_notes'])
            <div style="margin-top:12px; padding:10px 12px; background:#fff8e6; border:1px solid #f1dfa6; border-radius:10px; font-size:14px;"><strong>Access:</strong> {{ $job['access_notes'] }}</div>
        @endif
        @if($job['tenant'])
            <div style="margin-top:12px; font-size:14px;">
                @foreach($job['tenant'] as $t)
                    <div><strong>Tenant:</strong> {{ $t['name'] }}@if($t['phone']) &middot; <a href="tel:{{ preg_replace('/[^0-9+]/', '', $t['phone']) }}">{{ $t['phone'] }}</a>@endif</div>
                @endforeach
            </div>
        @endif
    </div>

    @if(count($job['tasks']))
        <div class="cj-card">
            <h2>Tasks</h2>
            @foreach($job['tasks'] as $task)
                <form method="POST" action="{{ str_replace('__TASK__', $task['id'], $actions['tick']) }}" class="cj-tick-form">
                    @csrf
                    <button type="submit" class="cj-task {{ $task['is_done'] ? 'done' : '' }}" @disabled(! $job['is_open'])>
                        <span class="box">{{ $task['is_done'] ? '✓' : '' }}</span>
                        <span class="txt">{{ $task['number'] }}. {{ $task['description'] }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    @endif

    @if(count($job['materials']))
        <div class="cj-card">
            <h2>What to load</h2>
            @foreach($job['materials'] as $m)
                <div class="cj-row">
                    <div>{{ $m['description'] ?? '' }}@if(!empty($m['code']))<div class="cj-muted">{{ $m['code'] }}</div>@endif</div>
                    <div class="q">{{ $m['quantity'] ?? '' }} {{ $m['unit'] ?? '' }}@if($job['show_prices'] && isset($m['line_total']))<div class="cj-muted">R {{ $m['line_total'] }}</div>@endif</div>
                </div>
            @endforeach
        </div>
    @endif

    @if(count($job['labour']))
        <div class="cj-card">
            <h2>Labour</h2>
            @foreach($job['labour'] as $l)
                <div class="cj-row">
                    <div>{{ $l['description'] ?? '' }}</div>
                    <div class="q">{{ $l['quantity'] ?? '' }} {{ $l['unit'] ?? '' }}@if($job['show_prices'] && isset($l['line_total']))<div class="cj-muted">R {{ $l['line_total'] }}</div>@endif</div>
                </div>
            @endforeach
            @if($job['show_prices'] && $job['total'] !== null)
                <div class="cj-row"><div><strong>Total</strong></div><div class="q">R {{ $job['total'] }}</div></div>
            @endif
        </div>
    @endif

    <div class="cj-card">
        <h2>Photos</h2>
        @if($job['is_open'])
            <form method="POST" action="{{ $actions['photos'] }}" enctype="multipart/form-data" class="cj-upload-form">
                @csrf
                <div class="cj-pills">
                    <label><input type="radio" name="photo_type" value="in_progress" checked><span>Work in progress</span></label>
                    <label><input type="radio" name="photo_type" value="completed"><span>Completed</span></label>
                </div>
                <label class="cj-label" for="cj-photo-files">Take or choose photos</label>
                <input id="cj-photo-files" type="file" name="photos[]" accept="image/*" capture="environment" multiple required style="width:100%; min-height:48px; font-size:15px;">
                <label class="cj-label" for="cj-photo-caption">Note (optional)</label>
                <input id="cj-photo-caption" type="text" name="caption" maxlength="255" autocomplete="off">
                <div class="cj-keys"></div>
                <button type="submit" class="cj-btn primary">Upload photos</button>
            </form>
        @endif
        @if(count($job['photos']))
            <div class="cj-photos">
                @foreach($job['photos'] as $p)
                    <a href="{{ $p['url'] }}" target="_blank" rel="noopener" title="{{ trim(($p['caption'] ?? '') . ' ' . ($p['at'] ?? '')) }}"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>
                @endforeach
            </div>
        @elseif(! $job['is_open'])
            <div class="cj-muted">No photos.</div>
        @endif
    </div>

    @if($job['crew_completed'])
        <div class="cj-banner">Completed — signed by {{ $job['crew_completed']['name'] }}<div class="cj-muted" style="font-weight:400;">{{ $job['crew_completed']['at'] }}</div></div>
    @elseif($job['is_open'])
        <div class="cj-card">
            <h2>Mark work completed</h2>
            <form method="POST" action="{{ $actions['complete'] }}" class="cj-complete-form">
                @csrf
                <label class="cj-label" for="cj-full-name">Your full name</label>
                <input id="cj-full-name" type="text" name="full_name" required maxlength="191" autocomplete="name" value="{{ old('full_name') }}">
                <label class="cj-confirm">
                    <input type="checkbox" name="confirm" value="1" required>
                    <span>I confirm the work on this job is completed.</span>
                </label>
                <button type="submit" class="cj-btn ok">Mark work completed</button>
            </form>
        </div>
    @endif

    <script>
        (function () {
            var root = document.currentScript.parentNode;
            // A tick without a page reload; the form still posts normally when JS is off or fetch fails.
            root.querySelectorAll('.cj-tick-form').forEach(function (form) {
                form.addEventListener('submit', function (e) {
                    if (!window.fetch) { return; }
                    var btn = form.querySelector('.cj-task');
                    if (!btn || btn.disabled) { e.preventDefault(); return; }
                    e.preventDefault();
                    btn.disabled = true;
                    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
                        .then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); })
                        .then(function (data) {
                            btn.classList.toggle('done', !!data.is_done);
                            btn.querySelector('.box').textContent = data.is_done ? '✓' : '';
                            btn.disabled = false;
                        })
                        .catch(function () { form.submit(); });
                });
            });
            // One client UUID per chosen file, so a flaky connection that re-sends cannot double-post.
            var upload = root.querySelector('.cj-upload-form');
            if (upload) {
                upload.addEventListener('submit', function () {
                    var box = upload.querySelector('.cj-keys');
                    var files = upload.querySelector('input[type=file]').files;
                    box.innerHTML = '';
                    for (var i = 0; i < files.length; i++) {
                        var k = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); });
                        var input = document.createElement('input');
                        input.type = 'hidden'; input.name = 'client_keys[]'; input.value = k;
                        box.appendChild(input);
                    }
                    var b = upload.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Uploading…';
                });
            }
            var complete = root.querySelector('.cj-complete-form');
            if (complete) {
                complete.addEventListener('submit', function () { var b = complete.querySelector('button[type=submit]'); b.disabled = true; });
            }
        })();
    </script>
</div>
