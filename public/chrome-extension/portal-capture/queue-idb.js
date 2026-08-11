/**
 * CoreX — Durable send-queue (IndexedDB)
 *
 * Replaces the old chrome.storage.local array queue, which:
 *   - had no way to know a save failed (quota) → batches were silently lost
 *   - rewrote the WHOLE array on every op → overlapping capture + flush clobbered each other
 *
 * IndexedDB gives us:
 *   - a large quota (paired with the "unlimitedStorage" manifest permission)
 *   - per-record atomic add()/delete() → no whole-array read-modify-write, so an
 *     overlapping capture and flush can never overwrite one another
 *   - explicit transaction success/abort → a failed (full) write throws instead of
 *     pretending it saved
 *
 * Loaded via importScripts() in the service worker; exposes self.CoreXQueue.
 * FIFO order = ascending auto-increment key (oldest batch drains first).
 */
(function (global) {
  'use strict';

  const DB_NAME    = 'corex_capture';
  const STORE      = 'send_queue';
  const DB_VERSION = 1;

  function openDb() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error || new Error('IndexedDB open failed'));
    });
  }

  // Append one batch. Resolves only when the write is durably committed;
  // rejects (does NOT silently drop) if the transaction aborts — e.g. quota.
  async function add(payload) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).add({ payload: payload, queued_at: Date.now() });
      tx.oncomplete = () => resolve(true);
      tx.onerror    = () => reject(tx.error || new Error('IDB add failed'));
      tx.onabort    = () => reject(tx.error || new Error('IDB add aborted (storage full?)'));
    });
  }

  // Atomic all-or-nothing insert of many batches (used once, to migrate the
  // legacy localStorage queue in). Either every row lands or none do.
  async function addMany(payloads) {
    if (!payloads || payloads.length === 0) return 0;
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      const store = tx.objectStore(STORE);
      const now = Date.now();
      for (const p of payloads) store.add({ payload: p, queued_at: now });
      tx.oncomplete = () => resolve(payloads.length);
      tx.onerror    = () => reject(tx.error || new Error('IDB addMany failed'));
      tx.onabort    = () => reject(tx.error || new Error('IDB addMany aborted (storage full?)'));
    });
  }

  // Oldest `limit` batches, each as { id, payload }. Read-only.
  async function peek(limit) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const out = [];
      const cur = tx.objectStore(STORE).openCursor(); // ascending key = FIFO
      cur.onsuccess = () => {
        const c = cur.result;
        if (c && out.length < limit) {
          out.push({ id: c.key, payload: c.value.payload });
          c.continue();
        } else {
          resolve(out);
        }
      };
      cur.onerror = () => reject(cur.error || new Error('IDB peek failed'));
    });
  }

  async function remove(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).delete(id);
      tx.oncomplete = () => resolve(true);
      tx.onerror    = () => reject(tx.error || new Error('IDB delete failed'));
    });
  }

  async function count() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx  = db.transaction(STORE, 'readonly');
      const req = tx.objectStore(STORE).count();
      req.onsuccess = () => resolve(req.result);
      req.onerror   = () => reject(req.error || new Error('IDB count failed'));
    });
  }

  // Fraction of the browser storage bucket in use (0..1). Best-effort.
  async function pressure() {
    try {
      if (global.navigator && navigator.storage && navigator.storage.estimate) {
        const est   = await navigator.storage.estimate();
        const usage = est.usage || 0;
        const quota = est.quota || 0;
        return { usage: usage, quota: quota, ratio: quota > 0 ? usage / quota : 0 };
      }
    } catch (e) { /* ignore — treat as unknown/low pressure */ }
    return { usage: 0, quota: 0, ratio: 0 };
  }

  global.CoreXQueue = { add, addMany, peek, remove, count, pressure };
})(self);
