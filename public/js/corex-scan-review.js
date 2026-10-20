/**
 * corex-scan-review.js - AT-472 / .ai/specs/rental-inspection-form.md §13.14.
 *
 * The scan review screen (scan-review.blade.php): on every row the agent can capture the item's note and add photos, saved AS THEY ARE
 * CAPTURED, not on Apply - the same way the capture screen does it, through the capture screen's own endpoints:
 *   - note: POST /observations (RentalInspectionRecordingController::storeObservation), 800 ms after the last keystroke, one retry key
 *     (client_idempotency_key) per save so a retried request is answered from the row that is already there;
 *   - photos: POST /photos (storePhotos) through public/js/corex-photo-batch-uploader.js (one key per file), tagged to the item.
 * Plain DOM, no Alpine. Rules this file keeps:
 *   - the note box is ALWAYS there (a detected note is never hidden); only SAVING waits for a condition: the photo controls start closed
 *     and open when a condition is picked, and a note typed before a condition is picked is saved the moment one is (§13.15);
 *   - nothing is ever recorded without a condition - no placeholder observations: a photo on a row whose picked condition is not what is
 *     recorded yet first records that condition (with the note typed so far), and a condition that needs a note refuses to record without
 *     one (the server's own 422, shown in the row) so the photo waits for the note;
 *   - §13.16: the sticky header's count line and blockers are live (refreshStatus) - what still blocks Apply ("2 rows need a condition", "1 Damaged
 *     row needs a note") updates as rows are fixed, each blocker is a link that steps to the next offending row and focuses its control, and the
 *     Apply button is disabled while any blocker remains;
 *   - the note box always posts with the form too, so Apply sends the confirmed note (an AI pre-filled one the agent never touched included).
 */
(function () {
    var root = document.getElementById('scan-review');
    if (!root || root.dataset.readonly === '1') return;

    var DEBOUNCE_MS = 800;
    var csrf = root.dataset.csrf;
    var uploader = typeof window.corexPhotoBatchUploader === 'function'
        ? window.corexPhotoBatchUploader({ csrf: csrf, uploadUrl: root.dataset.photosUrl, photos: [] })
        : null;
    var newKey = window.cpuNewUuid || function () {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    };

    var header = document.getElementById('scan-header');
    var applyButton = document.querySelector('[data-scan-apply]');
    var countEl = document.querySelector('[data-scan-count]');
    var blockersEl = document.querySelector('[data-scan-blockers]');
    var notesRequired = (root.dataset.notesRequired || '').split(',').filter(Boolean);
    var conditionLabels = {};
    try { conditionLabels = JSON.parse(root.dataset.conditionLabels || '{}'); } catch (e) { /* labels fall back to the key */ }
    var lastJump = {};       // blocker type -> the row id last jumped to, so "next" steps through them
    var blocked = {};        // blocker type -> the rows blocking right now

    // Recomputes the count line, the blockers and the Apply button from what is on the screen right now.
    function refreshStatus() {
        if (!countEl || !blockersEl) return;
        var counts = { read: 0, unsure: 0, missed: 0 };
        var noCondition = [];
        var noNote = {};
        root.querySelectorAll('[data-scan-row]').forEach(function (row) {
            var select = row.querySelector('[data-scan-condition]');
            var note = row.querySelector('[data-scan-note]');
            var value = select ? select.value : '';
            var applied = row.dataset.applied === '1';
            if (!applied) {
                // a row the agent has set counts as read; an untouched one keeps what the reader made of it (a cleared clear tick is to check)
                counts[value ? 'read' : (row.dataset.tick === 'read' ? 'unsure' : row.dataset.tick)]++;
            }
            if (!applied && !value) {
                noCondition.push(row);
            } else if (value && notesRequired.indexOf(value) >= 0 && (!note || note.value.trim() === '')) {
                (noNote[value] = noNote[value] || []).push(row);
            }
        });
        countEl.textContent = counts.read + ' read, ' + counts.unsure + ' to check, ' + counts.missed + ' not picked up';

        blocked = {};
        blockersEl.textContent = '';
        function addBlocker(type, text, rows) {
            blocked[type] = rows;
            var a = document.createElement('a');
            a.href = '#';
            a.className = 'underline font-semibold';
            a.style.color = 'var(--ds-crimson)';
            a.setAttribute('data-scan-next', type);
            a.textContent = text;
            blockersEl.appendChild(a);
        }
        if (noCondition.length) addBlocker('condition', noCondition.length + (noCondition.length === 1 ? ' row needs' : ' rows need') + ' a condition', noCondition);
        Object.keys(noNote).forEach(function (key) {
            var n = noNote[key].length;
            addBlocker('note:' + key, n + ' ' + (conditionLabels[key] || key) + (n === 1 ? ' row needs' : ' rows need') + ' a note', noNote[key]);
        });

        var hasBlockers = Object.keys(blocked).length > 0;
        if (applyButton) {
            applyButton.disabled = hasBlockers;
            applyButton.style.opacity = hasBlockers ? '0.5' : '';
            applyButton.style.cursor = hasBlockers ? 'not-allowed' : '';
        }
    }

    // Scrolls to a row and focuses the control the blocker or error is about, with a brief outline so the eye finds it under the sticky header.
    function goToRow(row, what) {
        if (!row) return;
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var control = what === 'note' ? row.querySelector('[data-scan-note]') : row.querySelector('[data-scan-condition]');
        if (control) control.focus({ preventScroll: true });
        row.style.outline = '2px solid var(--ds-amber)';
        setTimeout(function () { row.style.outline = ''; }, 1800);
    }

    if (header) {
        header.addEventListener('click', function (e) {
            var next = e.target.closest('[data-scan-next]');
            if (next) {
                e.preventDefault();
                var type = next.getAttribute('data-scan-next');
                var rows = blocked[type] || [];
                if (!rows.length) return;
                var at = rows.findIndex(function (r) { return r.id === lastJump[type]; });
                var row = rows[(at + 1) % rows.length];
                lastJump[type] = row.id;
                goToRow(row, type === 'condition' ? 'condition' : 'note');

                return;
            }
            var go = e.target.closest('[data-scan-goto]');
            if (go) {
                e.preventDefault();
                goToRow(document.getElementById('scan-row-' + go.getAttribute('data-scan-goto')), go.getAttribute('data-focus'));
            }
        });
    }
    root.addEventListener('submit', function (e) {
        refreshStatus();
        if (applyButton && applyButton.disabled) e.preventDefault();
    });

    root.querySelectorAll('[data-scan-row]').forEach(initRow);
    refreshStatus();

    function initRow(row) {
        var select = row.querySelector('[data-scan-condition]');
        var controls = row.querySelector('[data-scan-controls]');
        var note = row.querySelector('[data-scan-note]');
        var status = row.querySelector('[data-scan-status]');
        var strip = row.querySelector('[data-scan-photos]');
        var addButton = row.querySelector('[data-scan-add-photo]');
        var fileInput = row.querySelector('[data-scan-photo-input]');
        if (!select || !controls || !note) return;

        var itemId = row.dataset.itemId;
        // What the inspection already holds for this item on screen: the condition (empty = nothing recorded yet) and its note.
        var saved = { condition: row.dataset.recordedCondition || '', note: (row.dataset.recordedNote || '').trim() };
        var pendingKey = null;   // the retry key of the save in flight / last failed by network - reused until the server answers
        var busy = false;
        var again = false;
        var timer = null;
        var edited = false;      // the agent has changed the note box: it is theirs now, no longer "read from scan"
        var readBadge = row.querySelector('[data-scan-read-badge]');
        var unreadable = row.querySelector('[data-scan-unreadable]');

        function say(text) { if (status) status.textContent = text || ''; }

        select.addEventListener('change', function () {
            controls.style.display = select.value ? '' : 'none';
            say('');
            refreshStatus();
            // A note the agent typed while no condition was picked saves as soon as one is (an untouched AI note waits for Apply).
            if (select.value && edited) { clearTimeout(timer); timer = setTimeout(saveNote, DEBOUNCE_MS); }
        });

        note.addEventListener('input', function () {
            edited = true;
            refreshStatus();
            if (readBadge) { readBadge.remove(); readBadge = null; }
            if (unreadable && note.value.trim() !== '') { unreadable.remove(); unreadable = null; }
            clearTimeout(timer);
            timer = setTimeout(saveNote, DEBOUNCE_MS);
        });
        note.addEventListener('blur', function () {
            if (timer) { clearTimeout(timer); timer = null; saveNote(); }
        });

        // Records the picked condition + typed note as an ordinary observation. Resolves true when the inspection holds exactly that now.
        async function saveNote() {
            clearTimeout(timer);
            timer = null;
            var condition = select.value;
            if (!condition) return false;
            var text = note.value.trim();
            if (condition === saved.condition && text === saved.note) return true;
            if (busy) { again = true; return false; }

            busy = true;
            say('Saving…');
            pendingKey = pendingKey || newKey();
            var ok = false;
            try {
                var res = await fetch(root.dataset.observationsUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        rental_inspection_item_id: itemId,
                        condition: condition,
                        notes: text === '' ? null : text,
                        source: root.dataset.source,
                        client_idempotency_key: pendingKey,
                    }),
                });
                var body = {};
                try { body = await res.json(); } catch (e) { /* not JSON */ }
                if (res.ok) {
                    saved = { condition: condition, note: text };
                    pendingKey = null;
                    ok = true;
                    say('Saved');
                } else {
                    // A refusal (validation, locked) is final for this attempt: the next save is a new one. A server error keeps the key so a retry replays.
                    if (res.status < 500) pendingKey = null;
                    say(body.message || (res.status === 419 || res.status === 401
                        ? 'Your session has ended. Sign in again in a new tab, then edit the note again.'
                        : 'Could not save (HTTP ' + res.status + ').'));
                }
            } catch (e) {
                say('Network error - will retry when you edit again.');
            }
            busy = false;
            if (again) { again = false; return saveNote(); }

            return ok;
        }

        if (addButton && fileInput) {
            addButton.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', async function () {
                var files = Array.from(fileInput.files || []);
                fileInput.value = '';
                if (!files.length || !uploader) return;
                if (!select.value) return;
                // No placeholder observations: the picked condition (and the note typed so far) is recorded first when the inspection does not hold it yet.
                if (select.value !== saved.condition || note.value.trim() !== saved.note) {
                    if (!(await saveNote())) return;
                }
                var before = uploader.uploadBatches.length;
                say('Uploading…');
                await uploader.uploadFiles(files, { rental_inspection_item_id: itemId }, function (body) {
                    (body.photos || []).forEach(function (p) {
                        if (!strip || !p.storage_path) return;
                        var img = document.createElement('img');
                        img.src = p.storage_path;
                        img.alt = '';
                        img.className = 'rounded-md';
                        img.style.cssText = 'width: 56px; height: 56px; object-fit: cover; border: 1px solid var(--border);';
                        strip.appendChild(img);
                    });
                });
                var failed = uploader.uploadBatches.slice(before).filter(function (b) { return b.status === 'failed'; });
                say(failed.length ? (failed[0].error || 'Upload failed.') : 'Saved');
            });
        }
    }
})();
