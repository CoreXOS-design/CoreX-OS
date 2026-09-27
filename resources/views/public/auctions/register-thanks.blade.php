<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registration Received — {{ $auction->title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md text-center">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <h1 class="text-xl font-bold text-slate-800 mb-2">Registration received</h1>
            <p class="text-sm text-slate-500 mb-4">
                Thanks for registering to bid on {{ $auction->title }}. Our team will be in touch shortly to
                verify your FICA documentation and get the Rules of Auction signed — your paddle issues once
                that's complete.
            </p>
            <p class="text-xs text-slate-400">If you don't hear from us and the auction is approaching, please contact the agency directly.</p>
        </div>
    </div>
</body>
</html>
