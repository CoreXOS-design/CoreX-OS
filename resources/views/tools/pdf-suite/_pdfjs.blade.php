{{-- PDF.js loader — pinned to 3.11.174 (last UMD release that exposes window.pdfjsLib). --}}
@once
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
    if (window['pdfjsLib']) {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
</script>
@endonce
