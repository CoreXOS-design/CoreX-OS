# Mobile — Fix Photo Orientation At The Source — BUILD PROMPT

Paste this into a fresh session in the **CoreX Mobile** repo
(`corex_mobile/`, Flutter — see `.ai/MOBILE_APP.md` in the main CoreX repo for
the last-known tech stack snapshot). It is self-contained.

---

## The problem (confirmed in production, not a guess)

Property gallery photos uploaded from the mobile app sometimes land sideways
on the property. This is a **client-side capture defect**, not a network or
server bug — confirmed by tracing it against real uploads on 2026-08-03:

- **HUAWEI Mate X6** (software `ICL-L29 15.0.0.209`): every photo it uploads
  writes EXIF `Orientation => 0` — not a valid value (valid range is 1-8) —
  while the JPEG's actual pixel buffer is stored in the sensor's raw,
  non-upright orientation. 33/33 sampled photos from this device in one
  gallery needed the same 90° correction.
- **HONOR BRP-NX1**: same defect, one variant worse — it writes **no**
  `Orientation` tag at all. Pixels equally wrong.

Both devices hand the app a JPEG whose embedded metadata cannot be trusted to
describe its own pixel content. This is upstream of anything the CoreX web
backend can fix reliably: the backend has already been patched with a
targeted, evidence-based heuristic (`ImageOrientationNormalizer` in the main
CoreX repo, `app/Services/Images/ImageOrientationNormalizer.php`) that
recognizes these two specific device signatures and corrects them after the
fact — but it can only cover devices it has already seen sideways in
production. **The real fix is here, at capture time**, so every device is
correct on arrival, not just the ones logged so far.

Full history: `.ai/specs/image-orientation-normalization.md` in the main
CoreX repo (not this one) — read it if you want the complete forensic trail,
but you don't need repo access to it to do this work; everything you need is
in this prompt.

---

## Step 1 — find out how photos are actually captured (do this before writing any fix code)

The fix is different depending on which of these the app currently does, so
confirm it first by reading the actual capture code:

- **(A) Delegates to the OS camera / gallery** via `image_picker` (or
  similar) — the app never sees the camera feed, only the final JPEG file
  the OEM camera app already wrote to disk. This is the most common pattern
  and the most likely one here.
- **(B) Custom in-app camera** via the `camera` plugin — the app controls the
  capture pipeline directly and has access to the device's live sensor
  orientation at the moment of capture.

Grep for `image_picker`, `ImagePicker`, `camera:` (the package), and
`CameraController` in `pubspec.yaml` and `lib/` to confirm which applies.
Report which one it is before proceeding — the two paths below are not
interchangeable.

---

## Step 2A — if the app uses `image_picker` (OS camera/gallery)

This is the harder case: you only ever see the OEM's already-written file, so
you are at the mercy of whatever that OEM camera app did. Two things must
both happen:

1. **Trust the tag when it's valid, bake it in immediately.** As soon as
   `image_picker` returns a file (both from camera capture and from gallery
   pick — a user can pick an old sideways photo from their gallery too), read
   its EXIF `Orientation` and rotate the actual pixel buffer to match, then
   strip/normalize the tag to 1, BEFORE it is added to the upload queue. Use
   a package that does the actual pixel rotation (not just a display-time CSS
   transform) — `flutter_exif_rotation` is the standard package for exactly
   this ("photo appears sideways on Android"); alternatively decode with the
   `image` package (`package:image`) and use `bakeOrientation()` /
   `copyRotate()` after reading the tag with `package:exif`. Do this
   regardless of whether you also do step 2 below — most devices (iPhones,
   most Android OEMs) write a correct tag, and this alone fixes the vast
   majority of "sideways photo" reports industry-wide.

2. **For the HUAWEI/HONOR-class defect specifically (invalid or absent tag,
   genuinely-wrong pixels): there is no metadata signal to rotate by** — this
   is the actual reason the defect exists on these devices, not a gap in your
   EXIF-reading code. Do not try to guess a rotation from an absent tag on
   the mobile side either; that risks the same wrong-guess problem the server
   heuristic deliberately avoids for unknown devices (see the spec's "narrow,
   evidence-backed exception" reasoning — copy the same discipline here).
   Two legitimate options, in order of preference:
   - **Best: stop delegating to the OS camera for in-app capture.** Move to
     Step 2B (custom camera via the `camera` plugin) for the in-app
     "take a photo for this listing" flow specifically — this sidesteps the
     OEM bug entirely because you read the true sensor orientation yourself,
     not the OEM's (buggy) file metadata. You can keep `image_picker` for
     "choose an existing photo from gallery" where this defect is rarer
     (it's specifically a live-capture firmware quirk).
   - **Acceptable interim: detect known-bad device signatures client-side
     too**, mirroring the server's `HEURISTIC_MAKES` list (currently
     `HUAWEI`, `HONOR` — read `device_info_plus`'s `AndroidDeviceInfo.manufacturer`
     at capture time) and apply the same verified 90° correction locally
     before upload, exactly matching what the server does today. This is a
     stopgap, not the fix — it only covers devices already caught red-handed,
     same limitation as the server-side patch. Do not present this as the
     complete fix if you choose it; say so explicitly and note the mobile
     work item to eventually move to 2B remains open.

## Step 2B — if the app uses (or is moved to) a custom `camera` plugin capture

This is the robust fix: you have the real sensor reading, so you never need
to trust the OEM's EXIF at all.

- Read the device's actual orientation at the moment of shutter press —
  `CameraController.value.deviceOrientation` / the `camera` plugin's own
  orientation-aware capture, or `native_device_orientation` for a
  platform-native reading — and bake that rotation into the captured image
  buffer yourself before it ever touches disk or the upload queue.
- This produces a JPEG that is correct by construction, regardless of what
  Orientation tag (if any) the platform encoder happens to write, and closes
  the gap for every device — not just HUAWEI/HONOR, but any future OEM with
  the same class of firmware bug.

---

## Step 3 — verification (do not skip; this is exactly the bug that shipped silently before)

1. **Real-device test on both confirmed-bad devices if available** (a HUAWEI
   or HONOR phone, any recent model) — take a photo in portrait, upload it,
   confirm it renders upright on the property gallery. If neither device is
   available to you, at minimum simulate the defect: construct a test JPEG
   with `Orientation` absent/0 and portrait dimensions swapped versus its
   actual content (ask for the CoreX-repo test fixtures
   `tests/Fixtures/Images/huawei-orientation0.jpg` and
   `tests/Fixtures/Images/honor-no-orientation-tag.jpg` as a starting point
   for what the raw defect looks like) and confirm your fix corrects it.
2. **Regression-test devices that were already correct** — an iPhone photo
   and a "normal" Android photo (Samsung/Pixel/etc., valid Orientation 1 or
   6) must upload upright exactly as before. Do not let the fix introduce a
   double-rotation on devices that were never broken.
3. **Gallery-picked (not freshly captured) photos** — confirm an
   already-sideways photo picked from the device gallery (not taken fresh in
   the app) is also corrected, since `image_picker` serves both flows through
   the same code path.
4. Confirm upload still succeeds end-to-end against
   `POST /api/v1/mobile/properties/{id}/images` (or the legacy
   `/api/mobile/...` path — see `MobilePropertyController` in the main repo)
   — this fix must not change the multipart request shape, only the bytes of
   the file being sent.

---

## What NOT to do

- Do not try to "fix" this by asking the CoreX backend to guess harder. The
  backend fix is already in place, is deliberately narrow (named devices
  only, logged otherwise), and is designed to be a safety net under whatever
  you ship here — not a substitute for it. Fixing it here is what actually
  closes the gap for devices nobody has hit yet.
- Do not silently swallow a device you can't confidently correct. If you keep
  the Step 2A option-2 stopgap (client-side device-signature list), log which
  devices you don't recognize (analogous to the server's
  `Log::warning('Image orientation: ... left as-is')`) so this doesn't become
  invisible again the next time a new OEM shows the same bug.

---

## Report back

When done, report: which capture path the app actually uses (A or B), which
fix approach you took, and the verification results from Step 3 (which
devices/scenarios you actually tested, not just "should work").
