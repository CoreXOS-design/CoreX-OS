# Mobile App Prompt — Photo upload reporting (per-photo ping)

> Paste the section below into the Claude session running in the **mobile app repo**.
> Backend spec: `.ai/specs/mobile-photo-upload-telemetry.md`
> **Backend must be deployed before this ships** — the endpoint 404s until then.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add per-photo reporting to the upload pipeline. This is diagnostics, not a
feature the agent sees.

### Why

Twice in four days photos went missing and the server could not say what
happened, because **the server only ever sees the survivors.** A photo that dies
between the camera and the upload queue leaves no trace anywhere.

On 2026-08-31 an agent shot ~40 photos on listing 15753. 28 arrived. The only
reason we could prove the other 12 were never enqueued is that `client_upload_id`
happens to be sequential and ran 1..28 with no gaps. That was luck — the key is
an idempotency token, not a diagnostic. Next time we want an answer, not an
excavation.

### The endpoint

`POST /api/v1/mobile/photo-events` — Sanctum bearer token, same auth as every
other mobile call.

```json
{
  "events": [
    {
      "property_id": 15753,
      "client_upload_id": "1788170293929473_3",
      "phase": "captured",
      "occurred_at": 1788170293929,
      "batch_id": "shoot-8f21",
      "meta": { "app_build": "1.0.11+25", "network": "wifi" }
    }
  ]
}
```

- `property_id`, `client_upload_id`, `phase` — required.
- `occurred_at` — epoch **milliseconds** or ISO-8601. Send the **phone's** clock
  at the moment it happened, not the moment you flush. The whole point is
  measuring capture→arrival lag.
- `batch_id` — one shoot / one screen session. Any stable string.
- `meta` — free-form object. Error text, attempt number, bytes, room tag, app
  build, network state.

Max 200 events per call. Response: `{ message, recorded, skipped }`, always 200.

### The six phases you send

| phase | when |
|---|---|
| `captured` | the shutter fired / the picker returned this photo — **emit before anything else** |
| `queued` | the durable queue row is committed |
| `upload_started` | an HTTP attempt begins |
| `upload_ok` | the server returned 2xx |
| `upload_failed` | an attempt failed — put the reason in `meta.error` |
| `dropped` | you gave up on it, or the agent deleted it |

**Do not send `received`.** The server writes that itself when the bytes land,
and rejects it from a client. That is deliberate: "did it actually arrive" is the
one thing you cannot be the witness for, and it is the whole question.

### How to send it — this is the part that matters

The naive version fails in exactly the case we care about. If you emit
`captured` over the network the moment the shutter fires and the app is killed
two seconds later, that ping dies with the app and the photo is invisible again.

So:

1. **Write the event to a durable local log first** — same durability as the
   upload queue. The network send is a second, separate step.
2. **Flush opportunistically**: on app start, on resume, on the existing 20s
   tick, and after any upload attempt. Batch whatever is pending.
3. **Delete local events only on a 2xx.** Anything else stays for the next flush.
4. **Replays are safe** — the server dedupes on
   `(property_id, client_upload_id, phase)`. Resending is always better than
   losing an event.
5. **Never block a photo on telemetry.** No await in front of a capture, an
   enqueue, or an upload. If the log write fails, drop the event and carry on —
   losing a diagnostic must never cost a photo.
6. Cap the local log (a few thousand events, oldest dropped) so a long offline
   stretch cannot grow unbounded.

### Emit `captured` at the shutter, not at commit

This matters more than the reporting itself, and it is the same fix as the
capture-path bug already raised: the `captured` event and the durable queue row
should be written at the same moment, immediately after the file exists on disk,
before the preview renders and before any "Done" step. If `captured` is emitted
somewhere later, the report will simply agree with the queue and tell us nothing
new.

### Definition of done

1. Shoot 10, force-kill the app from the task switcher immediately. Reopen. The
   report page shows 10 `captured` — even for photos that never uploaded.
2. Shoot in airplane mode, turn it back on. Every photo shows `captured`,
   `queued`, `upload_started`, and the server shows `received`.
3. Kill the app mid-flush. Nothing is double-counted and nothing is lost.
4. Turn the server off (or point at a dead host): photos still capture, queue and
   upload normally once it is back. Telemetry failure changes nothing the agent
   experiences.

## ▲▲▲ END COPY-PASTE ▲▲▲
