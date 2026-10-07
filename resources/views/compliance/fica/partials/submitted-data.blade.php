{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    The client's online FICA submission as QUESTIONS AND ANSWERS (Johan, 6 Oct 2026, from
    Elize): the agent, RO and CO each need to see what the client was asked, not just what
    they typed. ONE partial, included by BOTH review screens (compliance.fica.show and
    compliance.fica.compliance-review) so they can never disagree.

    The question list comes from App\Support\Compliance\FicaQuestionnaire — the wording the
    client saw on the public form (see that class for why it can be trusted and how it is
    kept honest). Read-only; no permission or scope logic lives here — both screens already
    gate access (access_compliance + own/branch/company scope; the CO screen also requires an
    appointed officer) before this partial is reached.

    Needs only $submission (documents + requestedBy loaded by both controllers).
--}}
@php
    $isWetInk = $submission->isWetInk();
    $qa = $isWetInk ? null : \App\Support\Compliance\FicaQuestionnaire::forSubmission($submission);
    $answers = is_array($submission->form_data) ? $submission->form_data : [];
    $completedBy = trim((string) data_get($answers, 'personal.full_name'));
    $rowStyle = 'display:grid; grid-template-columns:minmax(0,5fr) minmax(0,6fr); column-gap:12px; padding:3px 12px; border-top:1px solid var(--border);';
    $docLink = fn ($doc) => route('compliance.fica.documents.view', [$submission, $doc]);
@endphp

<div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);" data-fica-qa>

    {{-- Who / when --}}
    <div class="px-3 py-2 text-xs" style="background:var(--surface-2); color:var(--text-secondary);">
        <span class="font-bold" style="color:var(--text-primary);">Questions &amp; answers</span>
        @if($isWetInk)
            &mdash; paper (wet-ink) form received {{ $submission->wet_ink_received_date?->format('d M Y') ?? '—' }}
        @elseif($submission->signed_at)
            &mdash; submitted {{ $submission->signed_at->format('d M Y H:i') }}
            by {{ $completedBy !== '' ? $completedBy : ($submission->contact?->full_name ?? 'the client') }}
        @else
            &mdash; not yet submitted by the client
        @endif
        &middot; link sent by {{ $submission->requestedBy->name ?? 'unknown' }} on {{ $submission->created_at?->format('d M Y') }}
        @if(! $isWetInk && $qa)
            <span style="color:var(--text-muted);">&middot; form wording {{ $qa['version'] }}</span>
        @endif
        {{-- The Download / Print actions for this form live in the page HEADER of both screens (partials/qa-header-actions) — one place, not repeated here. --}}
    </div>

    @if($isWetInk)
        <div class="px-3 py-2 text-xs" style="border-top:1px solid var(--border); color:var(--text-secondary);">
            The client completed a signed paper form &mdash; their questions and answers are on the uploaded form below, not captured online.
        </div>
        @foreach(['method' => 'Received by method', 'received_date' => 'Date received', 'received_by' => 'Received by', 'source' => 'Source'] as $k => $lbl)
            @if(filled(data_get($answers, 'intake.' . $k)))
                <div style="{{ $rowStyle }}">
                    <div class="text-xs" style="color:var(--text-secondary);">{{ $lbl }}</div>
                    <div class="text-sm break-words" style="color:var(--text-primary);">{{ data_get($answers, 'intake.' . $k) }}</div>
                </div>
            @endif
        @endforeach
        <div class="px-3 py-1 text-xs font-bold uppercase tracking-wide" style="border-top:1px solid var(--border); background:var(--surface-2); color:var(--text-secondary);">Uploaded documents</div>
        @forelse($submission->documents as $doc)
            <div style="{{ $rowStyle }}">
                <div class="text-xs" style="color:var(--text-secondary);">{{ $doc->document_type_label }}</div>
                <div class="text-sm break-words" style="color:var(--text-primary);">{{ $doc->file_name }}
                    <a href="{{ $docLink($doc) }}" target="_blank" rel="noopener" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);" aria-label="View {{ $doc->document_type_label }}">View</a></div>
            </div>
        @empty
            <div style="{{ $rowStyle }}"><div class="text-xs" style="color:var(--text-muted);">No documents uploaded.</div><div></div></div>
        @endforelse

    @elseif(! $qa['has_answers'])
        <div class="px-3 py-3 text-sm" style="border-top:1px solid var(--border); color:var(--text-muted);">
            The client has not completed the form yet &mdash; there are no answers to show.
        </div>

    @else
        @if($qa['caution'])
            <div class="px-3 py-2 text-xs" style="border-top:1px solid var(--border); background:color-mix(in srgb, var(--ds-amber,#f59e0b) 12%, transparent); color:var(--text-primary);">{{ $qa['caution'] }}</div>
        @endif

        @foreach($qa['sections'] as $section)
            <div class="px-3 py-1 text-xs font-bold uppercase tracking-wide" style="border-top:1px solid var(--border); background:var(--surface-2); color:var(--text-secondary);">{{ $section['title'] }}</div>

            @foreach($section['rows'] as $row)
                <div style="{{ $rowStyle }}" data-fica-row>
                    <div data-q class="text-xs" style="color:var(--text-secondary); {{ $row['followUp'] ? 'padding-left:14px; border-left:2px solid var(--border);' : '' }}">{{ $row['label'] }}</div>
                    <div class="text-sm break-words" style="color:var(--text-primary);">
                        @if($row['type'] === 'doc')
                            @forelse($row['docs'] as $doc)
                                <div>{{ $doc->file_name }}
                                    <a href="{{ $docLink($doc) }}" target="_blank" rel="noopener" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);" aria-label="View {{ $row['label'] }}">View</a></div>
                            @empty
                                <span class="text-xs italic" style="color:var(--text-muted);">not uploaded</span>
                            @endforelse
                        @elseif($row['type'] === 'signature')
                            @if($row['answered'])
                                <img src="{{ $submission->signature_data }}" alt="Client signature" style="max-height:40px; background:#fff; border:1px solid var(--border); padding:2px;">
                                <span class="text-xs" style="color:var(--text-muted);">{{ $row['text'] }}</span>
                            @else
                                <span class="text-xs italic" style="color:var(--text-muted);">not answered</span>
                            @endif
                        @elseif(! $row['answered'])
                            <span class="text-xs italic" style="color:var(--text-muted);">not answered</span>
                        @elseif($row['type'] === 'list')
                            @foreach($row['items'] as $item)
                                <div>
                                    @foreach($item as $part)
                                        <span class="text-xs" style="color:var(--text-muted);">{{ $part['label'] }}</span> {{ $part['value'] }}@if(! $loop->last) <span style="color:var(--text-muted);">&middot;</span> @endif
                                    @endforeach
                                </div>
                            @endforeach
                        @elseif($row['type'] === 'multi')
                            @foreach($row['list'] as $picked)
                                <div>{{ $picked }}</div>
                            @endforeach
                        @else
                            <span @if($row['flag']) class="font-semibold" style="color:var(--ds-crimson,#c41e3a);" @endif>{{ $row['text'] }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        @endforeach

        {{-- Nothing the client typed is hidden, even if the question list does not account for it. --}}
        @if(count($qa['other_answers']))
            <div class="px-3 py-1 text-xs font-bold uppercase tracking-wide" style="border-top:1px solid var(--border); background:var(--surface-2); color:var(--text-secondary);">Other recorded answers</div>
            @foreach($qa['other_answers'] as $o)
                <div style="{{ $rowStyle }}">
                    <div class="text-xs" style="color:var(--text-muted);">{{ $o['key'] }}</div>
                    <div class="text-sm break-words" style="color:var(--text-primary);">{{ $o['value'] }}</div>
                </div>
            @endforeach
        @endif
    @endif

    {{-- Documents on this record that are not tied to a question the client was asked (e.g. added by staff). --}}
    @if(! $isWetInk && $qa && $qa['other_documents']->isNotEmpty())
        <div class="px-3 py-1 text-xs font-bold uppercase tracking-wide" style="border-top:1px solid var(--border); background:var(--surface-2); color:var(--text-secondary);">Other documents on this record</div>
        @foreach($qa['other_documents'] as $doc)
            <div style="{{ $rowStyle }}">
                <div class="text-xs" style="color:var(--text-secondary);">{{ $doc->document_type_label }}</div>
                <div class="text-sm break-words" style="color:var(--text-primary);">{{ $doc->file_name }}
                    <a href="{{ $docLink($doc) }}" target="_blank" rel="noopener" class="text-xs font-semibold" style="color:var(--brand-icon,#0ea5e9);" aria-label="View {{ $doc->document_type_label }}">View</a></div>
            </div>
        @endforeach
    @endif
</div>

@include('compliance.fica.partials._linked-contact-documents', ['submission' => $submission])
