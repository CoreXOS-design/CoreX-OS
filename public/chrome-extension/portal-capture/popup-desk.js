/**
 * CoreX Desk (extension) - the popup card: "Share this tab with CoreX support".
 * Spec .ai/specs/corex-desk.md section 13. Self-contained so popup.js is untouched. The card only appears when the agent has a Desk
 * session open in CoreX (Get help); the share button asks Chrome for access to the site first (a click is the permission gesture).
 */
/* global chrome */
(function () {
  'use strict';
  var card, msg, shareBtn, stopBtn, tab;

  function ask(m) { return new Promise(function (r) { chrome.runtime.sendMessage(m, function (res) { void chrome.runtime.lastError; r(res || {}); }); }); }

  function show(text, mode) {
    card.hidden = false;
    msg.textContent = text;
    shareBtn.hidden = mode !== 'share';
    stopBtn.hidden = mode !== 'stop';
  }

  async function refresh() {
    var st = await ask({ type: 'desk.status', tabId: tab.id, url: tab.url });
    if (st.error || !st.enabled || !st.session) { card.hidden = true; return; }   // nothing open: stay out of the way
    if (st.session.status !== 'live') { show('CoreX support has not joined your session yet.', 'none'); return; }
    if (st.shared) { show('CoreX support can see this tab. ' + (st.sharedCount > 1 ? st.sharedCount + ' tabs are shared.' : ''), 'stop'); return; }
    if (!st.allowedHere) { show('This site cannot be shared with CoreX support.', 'none'); return; }
    show('Show this ' + st.host + ' tab to ' + (st.session.support_name || 'CoreX support') + '. They only see this tab, and you can stop at any time.', 'share');
  }

  async function share() {
    shareBtn.disabled = true;
    var origin;
    try { origin = new URL(tab.url).origin + '/*'; } catch (e) { origin = null; }
    // Sites the extension already has access to need no prompt; for the others Chrome asks once (this runs inside the click).
    var granted = true;
    if (origin) {
      granted = await new Promise(function (r) { chrome.permissions.contains({ origins: [origin] }, function (ok) { r(ok); }); });
      if (!granted) granted = await new Promise(function (r) { chrome.permissions.request({ origins: [origin] }, function (ok) { r(!!ok); }); });
    }
    if (!granted) { shareBtn.disabled = false; show('Chrome needs your OK to let CoreX see this site. Press the button again and choose Allow.', 'share'); return; }
    var res = await ask({ type: 'desk.share', tabId: tab.id });
    shareBtn.disabled = false;
    if (!res.ok) { show(res.error || 'Could not share this tab.', 'share'); return; }
    await refresh();
  }

  async function stopSharing() {
    stopBtn.disabled = true;
    await ask({ type: 'desk.stop', tabId: tab.id });
    stopBtn.disabled = false;
    await refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    card = document.getElementById('deskCard');
    if (!card) return;
    msg = document.getElementById('deskMsg');
    shareBtn = document.getElementById('deskShare');
    stopBtn = document.getElementById('deskStop');
    shareBtn.addEventListener('click', share);
    stopBtn.addEventListener('click', stopSharing);
    chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
      tab = tabs && tabs[0];
      if (tab && tab.url && /^https?:/.test(tab.url)) refresh();
    });
  });
})();
