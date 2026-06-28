{{-- Shared dompdf <head> for buyer-pack segments. Same design language as the
     presentation cover (navy/teal/champagne, Inter, A4, sharp corners). --}}
@php $fontDir = str_replace('\\', '/', base_path('resources/fonts/inter')); @endphp
<meta charset="utf-8">
<style>
    @font-face { font-family:'Inter'; font-weight:400; font-style:normal; src:url('{{ $fontDir }}/Inter-400.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:500; font-style:normal; src:url('{{ $fontDir }}/Inter-500.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:600; font-style:normal; src:url('{{ $fontDir }}/Inter-600.ttf') format('truetype'); }
    @font-face { font-family:'Inter'; font-weight:700; font-style:normal; src:url('{{ $fontDir }}/Inter-700.ttf') format('truetype'); }
    :root {
        --brand: #0b2a4a;
        --brand-light: #1a4a73;
        --teal: #00d4aa;
        --text: #1e293b;
        --text-muted: #64748b;
        --bg-alt: #f8fafc;
        --line: #e2e8f0;
    }
    @page { margin: 0; }
    html, body { margin: 0; padding: 0; background: #ffffff; }
    * { box-sizing: border-box; }
    body { font-family: 'Inter', 'DejaVu Sans', sans-serif; color: #1e293b; }
    p { margin: 0; }
    /* No min-height: forcing full A4 height rounds over the page box in dompdf
       and emits a trailing blank page. Content-height + page-break-before on
       later pages keeps every segment exactly its own page(s), no blanks. */
    .pg { width: 794px; padding: 48px 56px; position: relative; }
</style>
