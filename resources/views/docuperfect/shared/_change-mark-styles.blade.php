{{-- ═══════════════════════════════════════════════════════════════════════════════════════════════════
     WET-INK CHANGE-MARK STYLES — THE ONE SOURCE OF TRUTH (Johan 2026-08-06).

     Every surface that shows a wet-ink amendment (strike / reword / the per-party "Initial this change"
     blocks) renders it from the SAME HTML (SelectionEditService authors the marks; DocumentChangeHighlighter
     authors the field-diff table rows) and MUST style it from THIS one stylesheet — so a pure strike-out and
     a reword render identically at Fill & Review, Sign & Send, the signing view AND the printed PDF, and can
     never ping-pong between paths again.

     Consumed by:
       • the Fill & Review preview panel (esign/wizard.blade.php) — @include of this partial
       • the signing view + PDF (CanonicalDocumentRenderer compose → DocumentChangeHighlighter::styleBlock(),
         which renders THIS partial inline so it travels into dompdf identically)
       • the interactive signing affordance (_change-initial-affordance.blade.php) layers ONLY the
         click-to-initial / filled states on top of this base — it does not restyle the base.

     Handles BOTH initial-row markups: SelectionEditService's <div>/<span class="cir-slot"> and
     DocumentChangeHighlighter's <table>/<td class="cir-slot">. --}}
<style>
    /* Strike + insert + cross-reference marks */
    .change-del { text-decoration: line-through; text-decoration-thickness: 1.5px; color: #b91c1c; }
    .change-ins { background: #fef08a; color: #111827; text-decoration: none; padding: 0 2px; border-radius: 2px; font-weight: 600; }
    .change-clause { display: inline; }
    .change-xref { margin-left: 4px; padding: 0 5px; background: #dbeafe; color: #1e40af; border-radius: 3px;
        font-size: .72rem; font-weight: 600; white-space: nowrap; }
    .change-initialed { margin-left: 4px; padding: 0 5px; background: #dcfce7; color: #166534; border-radius: 3px;
        font-size: .68rem; font-weight: 600; white-space: nowrap; }
    .change-anchor { position: relative; }

    /* Per-party "Initial this change" block — <div> (SelectionEditService) variant */
    .change-initial-row { display: block; margin: .5rem 0 1.1rem 0; padding: .55rem .8rem; border: 1px solid #fcd34d;
        background: #fffbeb; border-radius: 8px; font-size: .82rem; page-break-inside: avoid; }
    .change-initial-row .cir-label { display: inline; font-weight: 700; color: #92400e; margin-right: .75rem;
        text-transform: uppercase; letter-spacing: .03em; font-size: .68rem; }
    .change-initial-row .cir-slot { display: inline-flex; align-items: center; gap: .45rem; margin: .2rem .7rem .2rem 0;
        padding: .25rem .55rem; border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; vertical-align: middle; }
    .change-initial-row .cir-name { color: #374151; font-weight: 600; }
    .change-initial-row .cir-ink { min-width: 56px; min-height: 28px; display: inline-flex; align-items: center;
        justify-content: center; color: #9ca3af; border-bottom: 1px solid #d1d5db; font-size: .72rem; }
    .cir-ink-img { height: 28px; max-height: 28px; width: auto; object-fit: contain; }

    /* <table> (DocumentChangeHighlighter field-diff) variant — full-width fixed layout, cells not pills */
    table.change-initial-row { width: 100%; max-width: 100%; border-collapse: collapse; table-layout: fixed;
        padding: 0; }
    table.change-initial-row td.cir-slot { display: table-cell; text-align: center; padding: 6px 8px;
        vertical-align: bottom; margin: 0; border: 0; border-right: 1px solid #e2e8f0; border-radius: 0;
        background: transparent; overflow: hidden; }
    table.change-initial-row td.cir-slot:last-child { border-right: 0; }
    table.change-initial-row td.cir-slot .cir-label { display: block; margin: 0 0 6px 0; color: #475569;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    table.change-initial-row td.cir-slot .cir-ink { display: block; min-height: 18px; }

    @media print {
        .change-del, .change-ins, .change-xref, .change-initialed, .change-initial-row, .cir-slot, .cir-ink {
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
    }
</style>
