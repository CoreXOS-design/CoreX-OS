{{--
    Contractor Secure Link — AT-445, .ai/specs/rental-portal-access.md §4
    No login. The token in this URL IS the access control. Mobile-first.
--}}
@php
    $jobCard = $workOrder->jobCard ?? null;
    $tasks = $jobCard ? $jobCard->tasks()->orderBy('sort_order')->get() : collect();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Job — {{ $workOrder->title }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root { --bg:#f4f6fb; --surface:#fff; --border:rgba(0,0,0,.08); --text:#141821; --muted:#5a6472; --brand:#0b2a4a; --accent:#00b4d8; --ok:#1f8a4c; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font-family:'Figtree', system-ui, sans-serif; }
        .wrap { max-width: 480px; margin: 0 auto; padding: 16px 16px 48px; }
        header.top { background:var(--brand); color:#fff; padding:18px 16px; }
        header.top h1 { font-size:16px; margin:0; font-weight:700; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:16px; margin-bottom:14px; }
        h2 { font-size:15px; margin:0 0 10px; }
        label { display:block; font-size:13px; font-weight:600; margin:10px 0 4px; }
        input, textarea { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:15px; font-family:inherit; }
        .btn { display:flex; align-items:center; justify-content:center; box-sizing:border-box; width:100%; min-height:46px; text-align:center; padding:10px 12px; border-radius:10px; border:none; font-weight:700; font-size:15px; line-height:1.2; cursor:pointer; margin-top:10px; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-ok { background:var(--ok); color:#fff; }
        .muted { color:var(--muted); font-size:13px; }
        ul.tasks { padding-left:18px; margin:0; }
        .alert-success { background:#eafaf0; border:1px solid var(--ok); color:var(--ok); padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .alert-error { background:#fdecea; border:1px solid #c0392b; color:#c0392b; padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:14px; }
    </style>
</head>
<body>
    <header class="top"><h1>Job #{{ $workOrder->id }}</h1></header>
    <div class="wrap">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        <div class="card" data-contractor-job>
            <h2>{{ $workOrder->title }}</h2>
            <p class="muted">{{ $workOrder->property?->buildDisplayAddress() }} &middot; from {{ $agencyName }}</p>
            <p>{{ $workOrder->description }}</p>
            @if (! empty($mapUrl))
                <p><a href="{{ $mapUrl }}" target="_blank" rel="noopener" data-open-in-maps style="display:inline-block;padding:10px 16px;background:#eef3f8;color:#26435f;border-radius:10px;font-weight:700;text-decoration:none;">Open in Maps</a></p>
            @endif
            @if (! empty($access['any']))
                <p class="muted" data-access-contact><strong>Getting in:</strong>
                    @if ($access['name'] || $access['phone']){{ $access['name'] }}@if ($access['phone'])@if ($access['name']) &middot; @endif<a href="tel:{{ $access['tel'] }}">{{ $access['phone'] }}</a>@endif @endif
                    @if ($access['note'])<br>{{ $access['note'] }}@endif
                </p>
            @endif
            @foreach (($tenants ?? []) as $t)
                <p class="muted" data-tenant><strong>Tenant:</strong> {{ $t['name'] }}@if ($t['phone']) &middot; <a href="tel:{{ $t['tel'] }}">{{ $t['phone'] }}</a>@endif</p>
            @endforeach
            @if (! empty($lookAndQuote) && empty($unlocked))
                <p data-look-and-quote><strong>This is a visit to look at the problem and give a quote.</strong> Any repair work is agreed with the owner separately, before it starts.</p>
            @endif
        </div>

        @if (count($photos))
            @include('partials.photo-viewer')
            <div class="card" data-contractor-photos>
                <h2>Photos of the problem</h2>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    @foreach ($photos as $i => $p)
                        <button type="button" data-open-job-photo="{{ $i }}" aria-label="Open photo {{ $i + 1 }}" style="padding:0;border:0;background:none;cursor:zoom-in;border-radius:8px;"><img src="{{ $p['thumb_url'] ?? $p['url'] }}" alt="{{ $p['alt'] ?? 'Photo' }}" loading="lazy" style="width:96px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--border);"></button>
                    @endforeach
                </div>
                <p class="muted" style="margin-top:8px;">Tap a photo to open it. Our marks and notes are shown on it; you can switch them off.</p>
            </div>
            <script type="application/json" id="job-photos">@json($photos)</script>
            <script>
                (function () {
                    var list = JSON.parse(document.getElementById('job-photos').textContent || '[]');
                    document.addEventListener('click', function (e) {
                        var b = e.target.closest ? e.target.closest('[data-open-job-photo]') : null;
                        if (!b || !window.CorexPhotoViewer) return;
                        e.preventDefault();
                        window.CorexPhotoViewer.open({ photos: JSON.parse(JSON.stringify(list)), index: parseInt(b.getAttribute('data-open-job-photo'), 10) || 0, editable: false, returnFocus: b });
                    });
                })();
            </script>
        @endif

        {{-- section 22.7 - the owner stopped this job: nothing to do until the agency says so --}}
        @if (! empty($onHold))
            <div class="card" data-on-hold style="border:2px solid #c0392b;">
                <h2 style="color:#c0392b;">On hold &mdash; please do not attend</h2>
                <p>This job is on hold. Please do not attend until you hear from us. You do not need to do anything on this page for now.</p>
            </div>
        @else
        {{-- AT-452 round 2 (§19) - the contractor's answer. --}}
        @if (empty($unlocked))
        <div class="card" data-contractor-answer>
            <h2>Can you take this job?</h2>
            <p class="muted" data-contractor-answer-state>{{ $response ? $responseLabel : 'Not answered yet' }}@if ($response === 'declined' && $workOrder->contractor_decline_reason) &mdash; {{ $workOrder->contractor_decline_reason }}@endif</p>
            @if (! in_array($workOrder->status, ['completed', 'cancelled'], true) && (! $response || $response === 'pending'))
                <form method="POST" action="{{ route('rentals.secure-link.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="answer" value="accept">
                    <button class="btn btn-ok" type="submit" data-contractor-accept>Accept the job</button>
                </form>
                <form method="POST" action="{{ route('rentals.secure-link.respond', $token) }}">
                    @csrf
                    <input type="hidden" name="answer" value="decline">
                    <label>If you cannot take it, tell us why (required)</label>
                    <textarea name="reason" rows="2" maxlength="1000">{{ old('reason') }}</textarea>
                    <button class="btn" type="submit" style="background:#fff;border:1px solid var(--border);color:var(--text);" data-contractor-decline>Decline the job</button>
                </form>
            @endif
        </div>
        @endif

        @if ($tasks->count())
            <div class="card">
                <h2>Tasks</h2>
                <ul class="tasks">
                    @foreach ($tasks as $task)
                        <li>{{ $task->description }} @if ($task->is_done) ✓ @endif</li>
                    @endforeach
                </ul>
            </div>
        @endif


        @php
            // a quote the agency has already chosen for this job (not declined, not replaced): the contractor is told where it stands instead of being offered the form again
            $heldQuote = $workOrder->quotes->first(fn ($q) => $q->is_selected && ! $q->declined_at && ! $q->isSuperseded() && (int) $q->agency_service_provider_id === (int) $workOrder->agency_service_provider_id);
            $heldWhere = match ($workOrder->owner_approval_status) {
                \App\Models\RentalWorkOrder::APPROVAL_PENDING => 'is with the owner',
                \App\Models\RentalWorkOrder::APPROVAL_APPROVED => 'has been approved',
                default => 'has been received',
            };
        @endphp
        @if ($response !== 'declined' && empty($unlocked) && $heldQuote)
        <div class="card" data-contractor-quote-held>
            <h2>Your quote</h2>
            <p>Your quote of R{{ number_format((float) $heldQuote->amount, 2) }} {{ $heldWhere }}. The agency will tell you when you can go ahead.</p>
        </div>
        @elseif ($response !== 'declined' && empty($unlocked))
        <div class="card" data-contractor-quote>
            <h2>Upload a quote</h2>
            <form method="POST" action="{{ route('rentals.secure-link.quote', $token) }}" enctype="multipart/form-data">
                @csrf
                <label>Amount (R)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required value="{{ old('amount') }}">
                <label>Quote date</label>
                <input type="date" name="quote_date" required value="{{ old('quote_date', now()->toDateString()) }}">
                <label>Details of the quote <span class="muted">(optional)</span></label>
                <textarea name="detail_text" rows="2">{{ old('detail_text') }}</textarea>
                <label>Quote document <span class="muted">(optional)</span></label>
                <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif">
                <button class="btn btn-primary" type="submit">Submit quote</button>
            </form>
        </div>
        @endif

        @if (empty($unlocked) && $appointmentText)
        {{-- an appointment that exists is always shown - the mail says this link "shows the details" (cc5 walk 2). Booking now waits for the owner's go-ahead (spec 22.17 / 22.18 E). --}}
        <div class="card" data-contractor-appointment>
            <h2>Appointment</h2>
            <p><strong>{{ $appointmentText }}</strong></p>
            @if ($appointmentNote)<p class="muted">{{ $appointmentNote }}</p>@endif
            @if (! empty($appointmentContractorNote))<p data-contractor-note><strong>Note from the agent:</strong> {{ $appointmentContractorNote }}</p>@endif
        </div>
        @endif

        @if (! empty($unlocked))
        {{-- spec 22.17 - the cost is authorised: everything the contractor needs to finish the job is on this page --}}
        <div class="card" data-go-ahead style="border:2px solid var(--ok);">
            <h2 style="color:var(--ok);">You may go ahead</h2>
            <p>The owner has authorised the cost{!! $authorisedAmount !== null ? ': <strong>R' . e(number_format($authorisedAmount, 2, '.', ',')) . '</strong>' : '' !!}.
                Any work that would take the cost above that must first be authorised by the owner - use &ldquo;Ask for extra money&rdquo; below and wait for the answer.</p>
        </div>

        <div class="card" data-contractor-appointment>
            <h2>Appointment</h2>
            @if ($appointmentText)
                <p><strong>{{ $appointmentText }}</strong></p>
                @if ($appointmentNote)<p class="muted">{{ $appointmentNote }}</p>@endif
                @if (! empty($appointmentContractorNote))<p data-contractor-note><strong>Note from the agent:</strong> {{ $appointmentContractorNote }}</p>@endif
            @else
                <p class="muted">No appointment has been set yet. The agency arranges it and will tell you.</p>
            @endif
        </div>

        @if (! empty($canStart))
        <div class="card" data-contractor-started>
            <h2>Starting the work?</h2>
            <p class="muted">Press this when you begin, so the agency, the owner and the tenant can see the job is under way. It is optional.</p>
            <form method="POST" action="{{ route('rentals.secure-link.started', $token) }}">
                @csrf
                <button class="btn btn-primary" type="submit">I have started</button>
            </form>
        </div>
        @elseif (! empty($hasStarted))
        <p class="muted" data-contractor-started-done>The job is in progress.</p>
        @endif

        <div class="card" data-contractor-photos>
            <h2>Photos</h2>
            <form method="POST" action="{{ route('rentals.secure-link.photo', $token) }}" enctype="multipart/form-data">
                @csrf
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;"><input type="radio" name="phase" value="before" style="width:auto;"> Before or during the work</label>
                <label style="display:flex;align-items:center;gap:8px;font-weight:500;"><input type="radio" name="phase" value="after" style="width:auto;" checked> After the work</label>
                <input type="file" name="photo" accept="image/*" capture="environment" required style="margin-top:8px;">
                <button class="btn btn-primary" type="submit">Upload photo</button>
            </form>
        </div>

        <div class="card" data-contractor-extra>
            <h2>Ask for extra money</h2>
            @if ($extraOpen)
                <p data-extra-waiting><strong>Waiting for the owner.</strong> {{ $extraOpen->note }}</p>
                <p class="muted">Please do not do the extra work until we tell you the owner has agreed.</p>
            @else
                @if ($extraLast)
                    <p class="muted" data-extra-last>Your last request ({{ $extraLast->note }}): <strong>{{ $extraLast->status === 'approved' ? 'the owner agreed' : 'the owner did not agree' }}</strong>.</p>
                @endif
                <p class="muted">Only if the work needs more than the authorised amount - ask BEFORE doing the extra work. Nothing above the authorised amount is accepted until the owner agrees.</p>
                <form method="POST" action="{{ route('rentals.secure-link.extra', $token) }}">
                    @csrf
                    <label>Extra amount needed (R)</label>
                    <input type="number" step="0.01" min="0.01" name="extra_amount" required value="{{ old('extra_amount') }}">
                    <label>Why is it needed?</label>
                    <textarea name="reason" rows="2" maxlength="1000" required>{{ old('reason') }}</textarea>
                    <button class="btn btn-primary" type="submit">Send to the owner</button>
                </form>
            @endif
        </div>

        <div class="card" data-contractor-invoice>
            <h2>Your invoice</h2>
            @foreach ($invoices as $inv)
                <p class="muted" style="margin:0 0 4px;">Sent: {{ $inv->describe() }}</p>
            @endforeach
            <form method="POST" action="{{ route('rentals.secure-link.invoice', $token) }}" enctype="multipart/form-data">
                @csrf
                <label>Invoice number</label>
                <input type="text" name="invoice_number" required maxlength="100" value="{{ old('invoice_number') }}">
                <label>Invoice date</label>
                <input type="date" name="invoice_date" required value="{{ old('invoice_date', now()->toDateString()) }}">
                <label>Invoice amount (R)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required value="{{ old('amount', $invoiceSuggested !== null ? number_format($invoiceSuggested, 2, '.', '') : '') }}" data-invoice-amount>
                @if ($invoiceSuggested !== null)<p class="muted" data-invoice-suggested>Filled in from what was authorised for you, less what you have already invoiced - change it if your invoice is different.</p>@endif
                <label>Invoice file</label>
                <input type="file" name="document" required>
                <p class="muted">{{ $invoiceLimits }}</p>
                <button class="btn btn-primary" type="submit">Send invoice</button>
            </form>
        </div>

        <div class="card" data-contractor-done>
            <h2>The work is finished</h2>
            @if ($finishedAwaitingAgent)
                <p data-contractor-finished-waiting><strong>Thank you - you have told us the work is finished.</strong> The agency will check it and close the job. You can still send your invoice above.</p>
            @else
            <form method="POST" action="{{ route('rentals.secure-link.mark-done', $token) }}">
                @csrf
                @include('rentals._report-back-fields', ['workOrder' => $workOrder])
                <button class="btn btn-ok" type="submit">Work finished</button>
            </form>
            @endif
        </div>

        @elseif (in_array($response, ['accepted', 'quote_uploaded'], true))
        {{-- before the cost is authorised: only the old after-photo and done controls (kept as they were) --}}
        <div class="card" data-contractor-photos>
            <h2>Upload after photos</h2>
            <form method="POST" action="{{ route('rentals.secure-link.photo', $token) }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="photo" accept="image/*" capture="environment" required>
                <button class="btn btn-primary" type="submit">Upload photo</button>
            </form>
        </div>

        <div class="card" data-contractor-done>
            <h2>The work is finished</h2>
            @if ($finishedAwaitingAgent)
                <p data-contractor-finished-waiting><strong>Thank you - you have told us the work is finished.</strong> The agency will check it and close the job.</p>
            @else
            <form method="POST" action="{{ route('rentals.secure-link.mark-done', $token) }}">
                @csrf
                @include('rentals._report-back-fields', ['workOrder' => $workOrder])
                <button class="btn btn-ok" type="submit">Work finished</button>
            </form>
            @endif
        </div>
        <p class="muted">You can send your invoice and ask for extra money here once the owner has authorised the cost.</p>
        @endif
        @endif
    </div>
</body>
</html>
