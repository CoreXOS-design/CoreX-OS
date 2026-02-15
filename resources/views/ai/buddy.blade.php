@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">HF AI Buddy</h1>
            <div class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Chat to your assistant. (This is private to your login.)
            </div>
        </div>
        <div class="text-xs text-slate-500 dark:text-slate-400">
            Powered by HF AI service
        </div>
    </div>

    <div class="rounded-2xl border border-slate-200/60 dark:border-slate-700/60 bg-white/70 dark:bg-slate-900/40 shadow-sm overflow-hidden">
        <div id="chatLog" class="p-4 space-y-3 h-[480px] overflow-auto">
            <div class="text-sm text-slate-500 dark:text-slate-400">
                Hi {{ $user?->name ?? 'there' }} — ask me anything about what to do next.
            </div>
        </div>

        <div class="border-t border-slate-200/60 dark:border-slate-700/60 p-3">
            <form id="chatForm" class="flex gap-2">
                <input id="chatInput" type="text" autocomplete="off"
                       class="flex-1 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 px-3 py-2 text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400"
                       placeholder="Type your message…"/>
                <button id="sendBtn" type="submit"
                        class="rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 px-4 py-2 text-sm font-medium">
                    Send
                </button>
            </form>
            <div id="chatHint" class="mt-2 text-xs text-slate-500 dark:text-slate-400"></div>
        </div>
    </div>
</div>

<script>
(function () {
    const log = document.getElementById('chatLog');
    const form = document.getElementById('chatForm');
    const input = document.getElementById('chatInput');
    const btn = document.getElementById('sendBtn');
    const hint = document.getElementById('chatHint');

    function addBubble(text, who) {
        const wrap = document.createElement('div');
        wrap.className = 'flex ' + (who === 'me' ? 'justify-end' : 'justify-start');

        const b = document.createElement('div');
        b.className =
            'max-w-[85%] rounded-2xl px-4 py-2 text-sm ' +
            (who === 'me'
                ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                : 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100');

        b.textContent = text;
        wrap.appendChild(b);
        log.appendChild(wrap);
        log.scrollTop = log.scrollHeight;
    }

    async function send(message) {
        hint.textContent = '';
        btn.disabled = true;
        btn.classList.add('opacity-70');

        try {
            const res = await fetch('/ai/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });

            if (!res.ok) {
                const t = await res.text();
                throw new Error('HTTP ' + res.status + ' ' + t);
            }

            const data = await res.json();
            addBubble(data.reply || '(no reply)', 'bot');
        } catch (e) {
            hint.textContent = 'Error: ' + (e && e.message ? e.message : e);
        } finally {
            btn.disabled = false;
            btn.classList.remove('opacity-70');
        }
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const msg = (input.value || '').trim();
        if (!msg) return;
        input.value = '';
        addBubble(msg, 'me');
        send(msg);
    });

    setTimeout(() => input && input.focus(), 150);
})();
</script>
@endsection
