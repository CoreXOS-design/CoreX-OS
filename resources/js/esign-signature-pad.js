import SignaturePad from 'signature_pad';

// 2026-08-25 — the external signing pages (sign.blade.php,
// amendment-review.blade.php) loaded this from
// cdn.jsdelivr.net/npm/signature_pad@4.1.7 with no fallback. A client
// mid-signing a legal document with that CDN blocked or slow could not
// draw a signature at all. Bundled via Vite instead — same version
// pinned (4.1.7), same global constructor usage (`new SignaturePad(...)`)
// those pages already call, no other code in this file needs to change.
window.SignaturePad = SignaturePad;
