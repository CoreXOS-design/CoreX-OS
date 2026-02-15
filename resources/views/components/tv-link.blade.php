@if(!empty($tvUrl))
    <div class="rounded-2xl border border-slate-700/60 bg-slate-900/40 p-4">
        <div class="font-bold text-slate-200 mb-2">📺 TV Display Link</div>

        <div class="flex gap-2 items-center">
            <input id="tvLinkInput"
                   readonly
                   value="{{ $tvUrl }}"
                   class="w-full px-3 py-2 rounded bg-black text-slate-200 border border-slate-700 text-sm">

            <button onclick="copyTvLink()"
                    class="px-4 py-2 rounded bg-blue-600 text-white font-semibold">
                Copy
            </button>
        </div>

        <div id="copyMsg" class="text-xs text-slate-400 mt-1">
            Open this link on a TV and use full-screen mode.
        </div>
    </div>

    <script>
        function copyTvLink() {
            const input = document.getElementById('tvLinkInput');
            input.select();
            input.setSelectionRange(0, 99999);
            document.execCommand('copy');
            const msg = document.getElementById('copyMsg');
            msg.innerText = "Copied ✅";
            setTimeout(() => msg.innerText = "Open this link on a TV and use full-screen mode.", 1200);
        }
    </script>
@endif
