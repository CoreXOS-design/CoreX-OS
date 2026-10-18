{{--
    2026-10-05 overnight re-verification — the fault report / work order
    this job card came from, shared between print.blade.php and
    quote-pdf.blade.php (same reasoning as _pdf-lines-table.blade.php: one
    partial so the two documents can never drift). Mirrors the show
    screen's own "Source" block (resources/views/corex/rental-job-cards/
    show.blade.php), minus the fault-report photo thumbnails — not useful
    in a printed/emailed PDF.
    Expects: $jobCard with rentalFaultReport and workOrder loaded.
--}}
@php
    $pdfFaultReport = $jobCard->rentalFaultReport;
    $pdfWorkOrder = $jobCard->workOrder;
@endphp
<h2>Source</h2>
<div class="box">
    @if(!$pdfFaultReport && !$pdfWorkOrder)
        <p class="muted">No source — created directly.</p>
    @else
        @if($pdfFaultReport)
            <p><strong>Fault report #{{ $pdfFaultReport->id }}</strong> — {{ $pdfFaultReport->title }}</p>
            <p class="muted">{{ $pdfFaultReport->description }}</p>
        @endif
        @if($pdfWorkOrder)
            <p><strong>Work order #{{ $pdfWorkOrder->id }}</strong> — {{ $pdfWorkOrder->title }}</p>
        @endif
    @endif
</div>
