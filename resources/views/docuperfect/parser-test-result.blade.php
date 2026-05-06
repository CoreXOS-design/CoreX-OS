<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDS Parser Result â€” {{ $title }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('css/corex-document.css') }}" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Figtree', sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .stats-bar {
            background: #1e293b;
            border-bottom: 1px solid #334155;
            padding: 10px 24px;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .stats-bar .badge { background: #dc2626; color: white; padding: 2px 8px; border-radius:6px; font-size: 10px; font-weight: 600; letter-spacing: 0.5px; }
        .stats-bar .stat { color: #94a3b8; margin-left: 16px; }
        .stats-bar .stat strong { color: #e2e8f0; }
        .stats-bar a { color: #00d4aa; text-decoration: none; font-weight: 500; }
        .stats-bar a:hover { text-decoration: underline; }
        .panels {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        .panel {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        .panel-left {
            border-right: 1px solid #334155;
            background: #0f172a;
        }
        .panel-right {
            background: #f1f5f9;
        }
        .panel-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 8px;
        }
        .panel-left pre {
            font-size: 11px;
            line-height: 1.5;
            color: #cbd5e1;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 600px;
            overflow-y: auto;
            background: #1e293b;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #334155;
        }
    </style>
</head>
<body>

<div class="stats-bar">
    <div style="display:flex; align-items:center;">
        <span class="badge">CDS</span>
        <span class="stat">Title: <strong>{{ $title }}</strong></span>
        <span class="stat">Sections: <strong>{{ $sectionCount }}</strong></span>
        <span class="stat">Headings: <strong>{{ collect($cds['sections'] ?? [])->where('type', 'heading')->count() }}</strong></span>
        <span class="stat">Clauses: <strong>{{ collect($cds['sections'] ?? [])->where('type', 'clause')->count() }}</strong></span>
        <span class="stat">Tables: <strong>{{ collect($cds['sections'] ?? [])->where('type', 'table')->count() }}</strong></span>
    </div>
    <a href="{{ route('docuperfect.parser-test') }}">Upload Another</a>
</div>

<div class="panels">
    {{-- Left panel: Raw CDS JSON --}}
    <div class="panel panel-left">
        <div class="panel-label">CDS JSON</div>
        <pre>{{ json_encode($cds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    {{-- Right panel: Rendered HTML --}}
    <div class="panel panel-right">
        <div class="panel-label" style="color:#475569;">Rendered Output</div>
        <div class="corex-document-wrapper">
            <div class="corex-page">
                {!! $html !!}
            </div>
        </div>
    </div>
</div>

</body>
</html>
