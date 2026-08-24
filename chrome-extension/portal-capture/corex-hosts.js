// Single source of truth for "is this tab a CoreX presentation page?".
//
// This pattern was hardcoded in THREE places (background.js, popup.js twice) and
// recognised exactly one production hostname: corex.hfcoastal.co.za. When that
// hostname was retired to a redirect on 2026-07-17, every copy stopped matching —
// an agent working on corexos.co.za could no longer capture, because after the
// redirect a tab can never carry the old hostname again. Three copies meant three
// chances to miss one. One pattern, one place.
//
// Loaded by popup.html via <script> and by background.js via importScripts().

// Matches any CoreX host serving /presentations/{id}:
//   corexos.co.za, www./staging./qatesting1. etc. (any subdomain)
//   corex.hfcoastal.co.za — legacy, kept only so a stale tab opened before the
//     redirect still resolves; it costs nothing and fails safe.
//   127.0.0.1:8000 / localhost:8000 — local dev.
var COREX_PRESENTATION_RE =
    /^https?:\/\/(?:(?:[a-z0-9-]+\.)?corexos\.co\.za|corex\.hfcoastal\.co\.za|(?:127\.0\.0\.1|localhost):8000)\/presentations\/(\d+)/i;

// Returns the regex match (match[1] === presentation id) or null.
function matchCorexPresentation(url) {
    return (url || '').match(COREX_PRESENTATION_RE);
}

// Truthy test for callers that only need "is this a presentation tab?".
function isCorexPresentationUrl(url) {
    return COREX_PRESENTATION_RE.test(url || '');
}
