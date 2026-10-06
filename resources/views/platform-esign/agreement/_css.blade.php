{{-- Shared typesetting for the Subscription Agreement (spec §11.6): the SAME rules style the screen sheets and the PDF.
     $pdf = true under DomPDF (no flex/grid, DejaVu Sans). --}}
<style>
    .agr { color:#111827; font-size: {{ !empty($pdf) ? '8.6pt' : '14px' }}; line-height: 1.4; font-family: {!! !empty($pdf) ? "'DejaVu Sans', sans-serif" : "'Figtree', -apple-system, 'Segoe UI', Roboto, Arial, sans-serif" !!}; }
    .agr p.cover-title { font-size: {{ !empty($pdf) ? '24pt' : '2em' }}; font-weight:bold; color:#0b2a4a; margin: {{ !empty($pdf) ? '28pt 0 2pt' : '1em 0 .1em' }}; }
    .agr p.cover-sub { font-size: {{ !empty($pdf) ? '14pt' : '1.2em' }}; color:#334155; margin: 0 0 {{ !empty($pdf) ? '8pt' : '.5em' }}; }
    .agr p { margin: 0 0 {{ !empty($pdf) ? '5pt' : '.55em' }}; }
    .agr h1 { font-size: {{ !empty($pdf) ? '14pt' : '1.5em' }}; color:#0b2a4a; margin: 0 0 {{ !empty($pdf) ? '8pt' : '.5em' }}; line-height:1.2; }
    .agr h2 { font-size: {{ !empty($pdf) ? '10.5pt' : '1.15em' }}; color:#0b2a4a; margin: {{ !empty($pdf) ? '9pt 0 4pt' : '.9em 0 .4em' }}; line-height:1.25; }
    .agr h3 { font-size: {{ !empty($pdf) ? '9.5pt' : '1.05em' }}; margin: .7em 0 .3em; }
    .agr ul, .agr ol { margin: 0 0 {{ !empty($pdf) ? '5pt' : '.55em' }} 1.2em; padding:0; }
    .agr blockquote { margin: 0 0 {{ !empty($pdf) ? '5pt 14pt' : '.55em 1.2em' }}; padding:0 0 0 {{ !empty($pdf) ? '8pt' : '.7em' }}; border-left: 2px solid #cbd5e1; }
    .agr blockquote p { margin-bottom: 3pt; }
    .agr table { width:100%; border-collapse: collapse; margin: 0 0 {{ !empty($pdf) ? '6pt' : '.7em' }}; {{ !empty($pdf) ? '' : '' }} }
    .agr th, .agr td { border: 1px solid #cbd5e1; padding: {{ !empty($pdf) ? '3pt 4pt' : '.35em .5em' }}; vertical-align: top; text-align:left; }
    .agr th { background:#f1f5f9; }
    .agr td p { margin: 0 0 2pt; }
    .agr .val { border-bottom: 1px solid #475569; padding: 0 2px; }
    .agr .blank { display:inline-block; min-width: {{ !empty($pdf) ? '70pt' : '5.5em' }}; border-bottom: 1px solid #94a3b8; }
    .agr .sigline { min-width: {{ !empty($pdf) ? '150pt' : '12em' }}; }
    .agr .box { font-family: 'DejaVu Sans', sans-serif; font-size: 1.15em; }
    .agr .sigimg { height: {{ !empty($pdf) ? '34pt' : '48px' }}; }
    .agr .masked { background:#fef3c7; }
    .agr .sigbox { display:block; height: {{ !empty($pdf) ? '34pt' : '48px' }}; }
    .agr a { color:#0b2a4a; }
    .agr .ctl { display:block; margin: 0 0 .5em; }
    /* ── alignment (spec §11.21): signature blocks and the mandate grid — same rules on screen, preview, RR screen and both PDFs ── */
    .agr table.sigtable { table-layout: fixed; width:100%; }
    .agr table.sigtable th, .agr table.sigtable td { width:50%; padding: {{ !empty($pdf) ? '4pt 6pt' : '.5em .7em' }}; }
    .agr .sigtable td p.sr { margin:0; height: {{ !empty($pdf) ? '17pt' : '40px' }}; line-height: {{ !empty($pdf) ? '17pt' : '40px' }}; white-space: nowrap; overflow:hidden; }
    .agr .sigtable td p.sr-signature { height: {{ !empty($pdf) ? '56pt' : '176px' }}; line-height: {{ !empty($pdf) ? '17pt' : '26px' }}; white-space: normal; overflow: visible; }
    .agr .sigtable td p.sr .val, .agr .sigtable td p.sr .blank, .agr .sigtable td p.sr .fld { display:inline-block; width: 68%; min-width:0; box-sizing: border-box; height: {{ !empty($pdf) ? '14pt' : '32px' }}; line-height: {{ !empty($pdf) ? '14pt' : '30px' }}; vertical-align: middle; border-bottom: 1px solid #475569; text-align:left; overflow:hidden; white-space:nowrap; }
    .agr .sigtable td p.sr-signature .sigimg, .agr .sigtable td p.sr-signature .sigline, .agr .sigtable td p.sr-signature .blank.sigline, .agr .sigtable td p.sr-signature .sigpad { display:block; width: {{ !empty($pdf) ? '90%' : '100%' }}; max-width:none; box-sizing:border-box; height: {{ !empty($pdf) ? '34pt' : '132px' }}; line-height: normal; min-width:0; border-bottom: 1px solid #475569; }
    .agr .sigtable td p.sr-signature .sigimg { object-fit: contain; }
    .agr p.mf { margin: 0 0 {{ !empty($pdf) ? '5pt' : '4px' }}; {{ !empty($pdf) ? '' : 'height: 48px; white-space: nowrap;' }} line-height: {{ !empty($pdf) ? '12pt' : '24px' }}; }
    .agr p.mf .mf-l { display:inline-block; width: {{ !empty($pdf) ? '34%' : '24%' }}; vertical-align: {{ !empty($pdf) ? 'top' : 'middle' }}; white-space: normal; line-height: {{ !empty($pdf) ? '12pt' : '18px' }}; }
    .agr p.mf .mf-v { display:inline-block; width: {{ !empty($pdf) ? '62%' : '50%' }}; vertical-align: {{ !empty($pdf) ? 'top' : 'middle' }}; white-space: normal; line-height: {{ !empty($pdf) ? '12pt' : '20px' }}; }
    .agr p.mf .mf-v .fld { width:100%; box-sizing:border-box; }
    .agr p.mf .mf-v .val { padding: 0 2px; }
</style>
