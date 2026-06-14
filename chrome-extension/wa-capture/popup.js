/** CoreX WhatsApp Capture — popup config (baseUrl + device token). */
document.addEventListener('DOMContentLoaded', function () {
  const baseUrl = document.getElementById('base-url');
  const token = document.getElementById('device-token');
  const status = document.getElementById('status');

  chrome.storage.local.get(['baseUrl', 'deviceToken'], function (items) {
    if (items.baseUrl) baseUrl.value = items.baseUrl;
    if (items.deviceToken) token.value = items.deviceToken;
    status.textContent = (items.baseUrl && items.deviceToken) ? 'Configured.' : 'Not configured yet.';
  });

  document.getElementById('save').addEventListener('click', function () {
    chrome.storage.local.set({
      baseUrl: baseUrl.value.trim(),
      deviceToken: token.value.trim(),
    }, function () {
      status.textContent = 'Saved. Open web.whatsapp.com to start capturing.';
    });
  });
});
