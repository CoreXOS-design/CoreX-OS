<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Live Link — Demo Preview</title>
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Figtree', sans-serif; background: #0f172a; color: #e2e8f0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; }
    </style>
</head>
<body class="min-h-screen">
    {{-- Demo banner --}}
    <div style="background: #f59e0b; color: #0f172a; text-align: center; padding: 8px; font-size: 12px; font-weight: 600;">
        SAMPLE PREVIEW — Your live link will look like this for your property
    </div>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-white">24 Ocean Drive, Margate</h1>
        <p class="text-sm text-slate-400 mt-1">Live Marketing Update</p>
        <p class="text-xs text-slate-500 mt-2">Hi Seller, here's what's happening with your listing.</p>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-6">
            <div class="card p-4 text-center"><div class="text-2xl font-bold text-white">7</div><div class="text-[10px] uppercase text-slate-500 mt-1">Viewings</div></div>
            <div class="card p-4 text-center"><div class="text-2xl font-bold text-white">23</div><div class="text-[10px] uppercase text-slate-500 mt-1">Days Listed</div></div>
            <div class="card p-4 text-center"><div class="text-lg font-bold text-white">R 2,850,000</div><div class="text-[10px] uppercase text-slate-500 mt-1">Market Value</div></div>
            <div class="card p-4 text-center"><div class="text-lg font-bold text-white">R 2,650,000</div><div class="text-[10px] uppercase text-slate-500 mt-1">Area Average</div></div>
        </div>

        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Agent Insights</h2>
            <div class="flex items-start gap-3">
                <div class="w-2 h-2 rounded-full bg-teal-400 mt-1.5"></div>
                <div><div class="text-sm text-slate-200">Strong buyer interest in this price range — 3 viewings this week</div></div>
            </div>
        </div>

        <div class="card p-5 mb-6">
            <h2 class="text-sm font-semibold text-white mb-3">Marketing Activity</h2>
            <div class="space-y-2 text-sm">
                <div class="flex items-center gap-3"><span class="text-[10px] w-20 text-slate-500">05 May</span><span class="text-slate-300">Listed on Property24</span></div>
                <div class="flex items-center gap-3"><span class="text-[10px] w-20 text-slate-500">04 May</span><span class="text-slate-300">Photos refreshed — professional shoot</span></div>
                <div class="flex items-center gap-3"><span class="text-[10px] w-20 text-slate-500">01 May</span><span class="text-slate-300">Listed on Private Property</span></div>
            </div>
        </div>

        <div class="text-center pt-8" style="border-top: 1px solid #334155;">
            <p class="text-[10px] text-slate-600">Powered by CoreX OS — The real estate operating system</p>
        </div>
    </div>
@include('public.partials.privacy-footer')
</body>
</html>
