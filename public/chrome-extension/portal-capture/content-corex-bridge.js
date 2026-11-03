/**
 * CoreX - page bridge (3.16.0). Runs on the CoreX site only. It lets a CoreX page ask this extension to read ONE portal advert page
 * ("Get full details" on the MIC advert card, spec .ai/specs/mic-work-tab.md 13.16) without knowing the extension's id:
 *   page  --window.postMessage({source:'corex-page', type:'ping' | 'read-advert', ...})-->  this script  --chrome.runtime.sendMessage-->  background.js
 *   page  <--window.postMessage({source:'corex-ext', type:'pong' | 'read-advert-result', ...})--  this script
 * It answers only messages posted by its own window, from its own origin. It carries no data of its own and reads nothing from the page.
 */
(function () {
  'use strict';
  if (window.__corexBridge) return;
  window.__corexBridge = true;

  function version() {
    try { return chrome.runtime.getManifest().version; } catch (e) { return null; }
  }

  function say(msg) {
    window.postMessage(Object.assign({ source: 'corex-ext', version: version() }, msg), window.location.origin);
  }

  window.addEventListener('message', function (ev) {
    if (ev.source !== window || ev.origin !== window.location.origin) return;
    var d = ev.data;
    if (!d || d.source !== 'corex-page') return;

    if (d.type === 'ping') { say({ type: 'pong' }); return; }

    if (d.type === 'read-advert') {
      try {
        chrome.runtime.sendMessage({ action: 'micReadAdvert', portal: d.portal, url: d.url, ref: d.ref }, function (res) {
          var failed = chrome.runtime.lastError;
          say({ type: 'read-advert-result', id: d.id, ok: !failed && !!(res && res.ok), reason: failed ? 'extension_error' : ((res && res.reason) || null) });
        });
      } catch (e) {
        // the extension was reloaded under this page: the old script is orphaned
        say({ type: 'read-advert-result', id: d.id, ok: false, reason: 'extension_error' });
      }
    }
  });

  say({ type: 'pong' });
})();
