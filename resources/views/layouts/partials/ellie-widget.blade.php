<!-- ELLIE_WIDGET_2026 -->
<div id="ellie-root" style="position: fixed; bottom: 24px; right: 24px; z-index: 9999;">
    <!-- Button -->
    <button id="ellie-btn" type="button" aria-label="Open Ellie" style="
        width: 84px; height: 84px; border-radius: 9999px;
        border: 0; padding: 0; background: transparent;
        box-shadow: 0 10px 28px rgba(0,0,0,0.35);
          overflow: hidden;
        cursor: pointer;
        transform: translateZ(0);
        animation: ellie-breathe 3.6s ease-in-out infinite;
    ">
        <span style="position: relative; display:block; width:84px; height:84px;">
            <img src="/images/ellie-128-circle.png" alt="Ellie" style="
                width: 84px; height: 84px; border-radius: 9999px; display:block; object-fit: cover; object-position: center;
            ">
        </span>
    </button>

    <!-- Panel -->
    <div id="ellie-panel" style="
        position: absolute;
        right: 0;
        bottom: 98px;
        width: 360px;
        max-width: calc(100vw - 32px);
        height: 520px;
        max-height: calc(100vh - 140px);
        border-radius: 18px;
        overflow: hidden;
        background: rgba(15, 23, 42, 0.96);
        border: 1px solid rgba(255,255,255,0.12);
        box-shadow: 0 18px 60px rgba(0,0,0,0.45);
        display: none;
    ">
        <div style="
            display:flex; align-items:center; justify-content:space-between;
            padding: 12px 14px;
            background: rgba(255,255,255,0.06);
            border-bottom: 1px solid rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.92);
            font-weight: 600;
        ">
            <div style="display:flex; gap:10px; align-items:center;">
                <img src="/images/ellie-32-circle.png" alt="" style="width:32px;height:32px;border-radius:9999px;">
                <div>
                    <div style="font-size:14px; line-height: 1.1;">Ellie</div>
                    <div style="font-size:11px; opacity:0.75; font-weight:500;">HF Coastal Companion</div>
                </div>
            </div>
            <button id="ellie-close" type="button" aria-label="Close Ellie" style="
                border:0; background: transparent; color: rgba(255,255,255,0.75);
                font-size: 18px; line-height: 1; cursor: pointer; padding: 6px 8px;
            ">&times;</button>
        </div>

        <div id="ellie-messages" style="
            padding: 12px;
            height: calc(100% - 112px);
            overflow: auto;
            color: rgba(255,255,255,0.90);
            font-size: 13px;
        ">
            <div style="opacity:0.85; margin-bottom:10px;">
                Hi 👋 I'm Ellie. Ask me anything about your performance, targets, listings, or next actions.
            </div>
        </div>

        <form id="ellie-form" style="
            display:flex; gap:10px;
            padding: 12px;
            border-top: 1px solid rgba(255,255,255,0.10);
            background: rgba(0,0,0,0.18);
        ">
            <input id="ellie-input" type="text" autocomplete="off" placeholder="Message Ellie…" style="
                flex:1;
                border-radius: 12px;
                border: 1px solid rgba(255,255,255,0.14);
                background: rgba(255,255,255,0.06);
                color: rgba(255,255,255,0.92);
                padding: 10px 12px;
                outline: none;
                font-size: 13px;
            ">
            <button id="ellie-send" type="submit" style="
                border:0;
                border-radius: 12px;
                padding: 10px 12px;
                background: rgba(255,255,255,0.14);
                color: rgba(255,255,255,0.95);
                cursor: pointer;
                font-weight: 600;
            ">Send</button>
        </form>
    </div>
</div>

<style>
    @keyframes ellie-breathe { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-3px) } }
</style>

<script>
(function(){
    const btn = document.getElementById('ellie-btn');
    const panel = document.getElementById('ellie-panel');
    const closeBtn = document.getElementById('ellie-close');
    const form = document.getElementById('ellie-form');
    const input = document.getElementById('ellie-input');
    const messages = document.getElementById('ellie-messages');

    if(!btn || !panel || !closeBtn || !form || !input || !messages) return;

    const KEY = 'ellie_open_v1';
    const CONVO_KEY = 'ELLIE_CONVO_ID';
    const csrf = (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')) || '';
    const open = () => { panel.style.display = 'block'; localStorage.setItem(KEY,'1'); setTimeout(()=>input.focus(), 50); };
    const close = () => { panel.style.display = 'none'; localStorage.setItem(KEY,'0'); };

    btn.addEventListener('click', () => {
        if (panel.style.display === 'block') close(); else open();
    });
    closeBtn.addEventListener('click', close);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') close();
    });

    if (localStorage.getItem(KEY) === '1') open();

    function addBubble(text, who){
        const wrap = document.createElement('div');
        wrap.style.margin = '8px 0';
        wrap.style.display = 'flex';
        wrap.style.justifyContent = (who === 'me') ? 'flex-end' : 'flex-start';

        const b = document.createElement('div');
        b.textContent = text;
        b.style.maxWidth = '85%';
        b.style.whiteSpace = 'pre-wrap';
        b.style.padding = '10px 12px';
        b.style.borderRadius = '14px';
        b.style.border = '1px solid rgba(255,255,255,0.10)';
        b.style.background = (who === 'me') ? 'rgba(255,255,255,0.14)' : 'rgba(255,255,255,0.06)';
        b.style.color = 'rgba(255,255,255,0.92)';
        wrap.appendChild(b);

        messages.appendChild(wrap);
        messages.scrollTop = messages.scrollHeight;
    }

    let busy = false;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = (input.value || '').trim();
        if(!msg || busy) return;

        addBubble(msg, 'me');
        input.value = '';
        busy = true;

        const typing = document.createElement('div');
        typing.style.opacity = '0.75';
        typing.style.fontSize = '12px';
        typing.style.marginTop = '6px';
        typing.textContent = 'Ellie is typing…';
        messages.appendChild(typing);
        messages.scrollTop = messages.scrollHeight;

        try{
            let convoId = localStorage.getItem(CONVO_KEY);

                async function postEllie(convoIdValue){
                    const fd = new FormData();
                    if (csrf) fd.append('_token', csrf);
                    if (convoIdValue) fd.append('conversation_id', String(parseInt(convoIdValue, 10)));
                    fd.append('message', msg);

                    const r = await fetch("/ellie/send", {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            ...(csrf ? {'X-CSRF-TOKEN': csrf} : {})
                        },
                        body: fd
                    });

                    const ct = (r.headers.get('content-type') || '').toLowerCase();
                    const raw = await r.text();

                    let data = null;
                    if (ct.includes('application/json')) {
                        try { data = JSON.parse(raw || '{}'); } catch (e) { data = null; }
                    }

                    return { r, raw, data };
                }

                let out = await postEllie(convoId);

                if (!out.r.ok && out.r.status === 404 && (out.raw || '').includes('AiConversation')) {
                    localStorage.removeItem(CONVO_KEY);
                    convoId = null;
                    out = await postEllie(null);
                }

                if (!out.r.ok) {
                    const msg = (out.data && (out.data.message || out.data.error)) ? (out.data.message || out.data.error) : (out.raw || '').slice(0, 220);
                    throw new Error('HTTP ' + out.r.status + (msg ? (': ' + msg) : ''));
                }

                const data = out.data || {};
                if (data && data.conversation_id) localStorage.setItem(CONVO_KEY, String(data.conversation_id));
            typing.remove();
            addBubble((data && data.reply) ? data.reply : 'Sorry, I got no reply.', 'ellie');
        }catch(err){
            typing.remove();
            addBubble('Sorry — I hit an error (' + (err && err.message ? err.message : 'unknown') + ').', 'ellie');
        }finally{
            busy = false;
        }
    });
})();
</script>
<!-- /ELLIE_WIDGET_2026 -->
