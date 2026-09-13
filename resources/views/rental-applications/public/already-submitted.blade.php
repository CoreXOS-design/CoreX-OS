@php
    $initialDocuments = $application->documents->map(fn ($d) => [
        'id' => $d->id,
        'name' => $d->original_name,
        'view_url' => route('rental-applications.public.documents.view', [$application->token, $d->id]),
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Application Received</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen p-4">
{{--
    Johan, QA1 — "no user action on either form may EVER discard typed
    input." This page had the SAME synchronous form-POST-and-reload defect
    the main show.blade.php form was already fixed for: selecting files
    here reloaded the whole page. There's no other typed data on this
    specific page to lose, but the upload itself must still never reload —
    consistency with the rest of this feature, and a partial upload
    (several files chosen, one rejected) must not force reselecting the
    ones that were fine. Mirrors show.blade.php's async pattern exactly.
--}}
<div class="w-full max-w-md mx-auto" x-data="alreadySubmittedDocuments()">

    @if(session('success'))
        <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-700 text-sm mb-4">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="p-3 rounded-xl bg-red-50 border border-red-100 text-red-700 text-sm mb-4">{{ session('error') }}</div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-center">
        @if($isTerminallyClosed ?? false)
            {{-- AT-392 round 2, 2026-09-13 — Johan, verbatim: "it must not
                 read as a dead end... does not imply they have done
                 something wrong." Same honest message for withdrawn and
                 declined, names the way back (the agent can reopen it). --}}
            <h1 class="text-xl font-bold text-slate-800 mb-2">This application is closed</h1>
            <p class="text-sm text-slate-500">{{ $documentUploadsClosedMessage }}</p>
        @else
            <h1 class="text-xl font-bold text-slate-800 mb-2">Application already received</h1>
            <p class="text-sm text-slate-500">
                Your rental application is already being processed
                @if($application->submitted_at)
                    (submitted {{ $application->submitted_at->format('d M Y') }}).
                @else
                    .
                @endif
            </p>
            <p class="text-sm text-slate-500 mt-2">Please contact your agent if you need to change anything.</p>
        @endif
    </div>

    @if($ficaOutstanding ?? false)
        {{-- FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan: "flagged
             ... on the applicant's confirmation" if FICA was abandoned.
             Plain, actionable, no jargon. Two distinct cases, not one:
             ficaAwaitingApplicantAction tells apart "you haven't started"
             from "you already submitted it, we're reviewing it" — telling
             someone who did their part to go contact their agent would be
             actively wrong, not just unhelpful. --}}
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mt-4 text-left">
            @if($ficaAwaitingApplicantAction ?? true)
                <p class="text-sm font-semibold text-amber-800 mb-1">One more step needed</p>
                <p class="text-sm text-amber-700">
                    Your application has been received, but we still need to verify your identity documents (FICA)
                    before it can move forward.
                </p>
                {{--
                    Return leg, AT-392 round 5, 2026-09-13 — Johan, walking
                    this page live: it told the applicant to finish FICA and
                    then gave them nothing to click — "the one thing that
                    page should obviously do... it does not do." Wording
                    deliberately echoes the submit button's own copy
                    ("Submit and Complete FICA Verification") so this reads
                    as finishing that same step, not a new task. Only
                    rendered when $ficaContinueUrl resolved to a real,
                    unexpired, tokenised link — never a generic one.
                --}}
                @if($ficaContinueUrl ?? null)
                    <a href="{{ $ficaContinueUrl }}"
                       class="inline-block mt-3 px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-semibold hover:bg-amber-700">
                        Complete FICA Verification
                    </a>
                    <p class="text-xs text-amber-700 mt-2">Something not working? Contact your agent.</p>
                @else
                    <p class="text-sm text-amber-700 mt-1">Please contact your agent to finish this step.</p>
                @endif
            @else
                <p class="text-sm font-semibold text-amber-800 mb-1">Verification in progress</p>
                <p class="text-sm text-amber-700">
                    Thank you — your identity verification (FICA) has been submitted and is being reviewed.
                    No action is needed from you right now; your agent will be in touch if anything else is required.
                </p>
            @endif
        </div>
    @endif

    {{--
        AT-392, Johan 2026-09-07 — spec §5: supporting documents are
        uploadable "both BEFORE signing... and AFTER signing" (matching the
        e-sign precedent this feature is modelled on). uploadDocuments()
        never blocked on status — only this VIEW never offered the form
        once submitted, silently cutting off exactly the post-submission
        upload path the backend already supported.
    --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 mt-4 text-left">
        <h2 class="font-semibold text-slate-700 mb-2">Supporting Documents</h2>
        @if($documentUploadsOpen ?? true)
            <p class="text-xs text-slate-500 mb-3">Upload payslips, bank statements, ID or proof of residence — whatever you have.</p>
        @endif

        <ul class="text-sm text-slate-600 mb-3 space-y-2" x-show="documents.length">
            <template x-for="doc in documents" :key="doc.id">
                <li class="flex items-center justify-between gap-2 border border-slate-100 rounded-lg px-3 py-2">
                    <a :href="doc.view_url" target="_blank" rel="noopener" class="text-slate-700 hover:underline" x-text="'✓ ' + doc.name"></a>
                    <span class="text-xs text-slate-400 flex-shrink-0">Submitted — locked</span>
                </li>
            </template>
        </ul>

        @if($documentUploadsOpen ?? true)
            <p class="text-xs text-slate-500 mb-3" x-show="documents.length" x-cloak>
                The documents above were submitted with your application and can't be changed. Need to send something else? Add it below — your agent will see it as a new document.
            </p>

            <template x-for="u in uploading" :key="u.tempId">
                <p class="text-xs mb-2" :class="u.error ? 'text-red-600' : 'text-slate-500'">
                    <span x-show="!u.error" x-text="'Uploading ' + u.name + '…'"></span>
                    <span x-show="u.error" x-text="u.name + ': ' + u.error"></span>
                </p>
            </template>

            <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                   class="block w-full text-sm text-slate-600 mb-1"
                   @change="onFilesSelected($event.target.files); $event.target.value = ''">
            <p class="text-[11px] text-slate-400">Files attach automatically — no separate upload button needed. Documents submitted with your application are locked; anything added here shows up as a new document for your agent.</p>
        @elseif($isTerminallyClosed ?? false)
            {{-- AT-392 round 2, 2026-09-13 — withdrawn/declined: the top
                 message already explains the closure and the way back, so
                 this box only needs to note documents specifically without
                 repeating the whole paragraph. --}}
            <p class="text-xs text-slate-500" x-show="documents.length" x-cloak>
                The documents above were submitted with your application. No new documents can be added while this application is closed.
            </p>
            <p class="text-xs text-slate-500" x-show="!documents.length">
                No new documents can be added while this application is closed.
            </p>
        @else
            {{-- Approved, agency has turned uploads off for this stage —
                 the page above still reads as "already received", not
                 "closed", so this box carries the reason on its own. --}}
            <p class="text-xs text-slate-500">{{ $documentUploadsClosedMessage }}</p>
        @endif
    </div>

</div>

<script>
function alreadySubmittedDocuments() {
    return {
        documents: @json($initialDocuments),
        uploading: [],

        csrfToken() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        async uploadFile(file) {
            const tempId = 'u' + Date.now() + Math.random();
            this.uploading.push({ tempId, name: file.name, error: null });
            const formData = new FormData();
            formData.append('supporting_files[]', file);
            formData.append('_token', this.csrfToken());
            try {
                const res = await fetch('{{ route('rental-applications.public.documents', $application->token) }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken() },
                    body: formData,
                });
                const data = await res.json().catch(() => ({}));
                const item = this.uploading.find(u => u.tempId === tempId);
                if (!res.ok) {
                    item.error = (data.errors && Object.values(data.errors)[0]?.[0]) || data.message || 'Upload failed.';
                    return false;
                }
                this.documents.push(...(data.documents || []));
                this.uploading = this.uploading.filter(u => u.tempId !== tempId);
                return true;
            } catch (e) {
                const item = this.uploading.find(u => u.tempId === tempId);
                if (item) item.error = 'Network error — please try again.';
                return false;
            }
        },

        async onFilesSelected(fileList) {
            await Promise.all(Array.from(fileList).map(file => this.uploadFile(file)));
        },
    };
}
</script>
</body>
</html>
