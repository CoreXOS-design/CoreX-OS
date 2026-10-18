@extends('layouts.corex-app')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">RCR Questionnaires (admin)</h1>
                <p class="text-xs" style="color: var(--text-muted);">Manage the FIC questionnaire templates. Upload the FIC's question PDF as CSV to populate the full question set.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif

    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead>
                    <tr style="background: var(--surface-2);">
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Key</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Title</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Directive</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Sections</th>
                        <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Questions</th>
                        <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deadline</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($questionnaires as $q)
                        <tr>
                            <td class="px-4 py-3 text-xs font-mono" style="color: var(--text-muted);">{{ $q->key }}</td>
                            <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ $q->title }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $q->directive_reference }}</td>
                            <td class="px-4 py-3 text-right">{{ $q->sections_count }}</td>
                            <td class="px-4 py-3 text-right">{{ $q->questions_count }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $q->submission_deadline->format('j M Y') }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('corex.admin.rcr.questionnaires.show', $q->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Open &rarr;</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">No questionnaires yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
