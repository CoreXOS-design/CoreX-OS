/** CoreX WhatsApp Capture — popup config (baseUrl + device token + toggles). */
document.addEventListener('DOMContentLoaded', function () {
  const baseUrl = document.getElementById('base-url');
  const token = document.getElementById('device-token');
  const historySweep = document.getElementById('history-sweep');
  const waDebug = document.getElementById('wa-debug');
  const status = document.getElementById('status');

  chrome.storage.local.get(['baseUrl', 'deviceToken', 'historySweepEnabled', 'waDebug'], function (items) {
    if (items.baseUrl) baseUrl.value = items.baseUrl;
    if (items.deviceToken) token.value = items.deviceToken;
    // History backfill defaults ON (spec FIX 2); explicit false disables it.
    historySweep.checked = items.historySweepEnabled !== false;
    waDebug.checked = !!items.waDebug;
    status.textContent = (items.baseUrl && items.deviceToken) ? 'Configured.' : 'Not configured yet.';
  });

  document.getElementById('save').addEventListener('click', function () {
    chrome.storage.local.set({
      baseUrl: baseUrl.value.trim(),
      deviceToken: token.value.trim(),
      historySweepEnabled: historySweep.checked,
      waDebug: waDebug.checked,
    }, function () {
      status.textContent = 'Saved. Open web.whatsapp.com to start capturing.';
    });
  });
});
