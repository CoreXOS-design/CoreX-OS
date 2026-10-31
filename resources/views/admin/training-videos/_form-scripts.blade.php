{{--
    Alpine helpers for the training-video form. Spec: .ai/specs/training-videos.md §8.6

    tvForm(...)               on the enclosing <form>: which kind (YouTube / upload) and whether the
                              Save button must wait for an upload to reach 100%.
    trainingVideoUploader(...) on the uploader: sends the chosen file to CoreX in pieces
                              (/api/v1/admin/training-video-uploads), each piece retried, then asks
                              CoreX to stitch and check it is a video. Reports back to the form with
                              a `tv-upload-state` event (a child component cannot write its parent's data).

    Included once per page by _form; defined as plain functions so Alpine finds them when it starts.
--}}
<script>
    window.tvForm = function (cfg) {
        return {
            source: cfg.source,
            isEdit: cfg.isEdit,
            uploadBusy: false,
            uploadDone: false,
            get blocked() {
                return this.uploadBusy || (this.source === 'upload' && !this.isEdit && !this.uploadDone);
            },
        };
    };

    window.trainingVideoUploader = function (cfg) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const headers = { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };

        const errorText = (status, body) => {
            try {
                const j = JSON.parse(body);
                const first = j.errors ? Object.values(j.errors)[0][0] : j.message;
                if (first) return first;
            } catch (e) {}
            return status === 413 ? 'The server refused a piece as too large.' : 'The upload was interrupted (' + status + ').';
        };

        // The chosen File lives OUTSIDE Alpine's reactive state: a reactive proxy around a File
        // breaks file.slice() ("Illegal invocation").
        let fileRef = null;

        return {
            hasFile: false, state: 'idle', percent: 0, message: '', info: '',
            uploadId: '', chunkBytes: 0, total: 0, next: 0,

            get busy() { return this.state === 'uploading' || this.state === 'finishing'; },

            report() {
                this.$dispatch('tv-upload-state', { busy: this.busy, done: this.state === 'done' });
            },

            pick(event) {
                const file = event.target.files[0];
                if (!file) return;
                if (file.size > cfg.maxBytes) {
                    this.state = 'error';
                    this.message = 'That file is larger than ' + cfg.maxLabel + '. Shrink it or export a shorter clip.';
                    this.report();
                    return;
                }
                fileRef = file; this.hasFile = true;
                this.uploadId = ''; this.next = 0; this.percent = 0; this.message = '';
                this.info = file.name + ' · ' + (file.size / 1048576).toFixed(0) + ' MB';
                this.run();
            },

            async run() {
                this.state = 'uploading';
                this.message = '';
                this.report();
                try {
                    if (!this.uploadId) {
                        const res = await fetch(cfg.startUrl, {
                            method: 'POST', credentials: 'same-origin',
                            headers: { ...headers, 'Content-Type': 'application/json' },
                            body: JSON.stringify({ name: fileRef.name, size: fileRef.size }),
                        });
                        const body = await res.text();
                        if (!res.ok) throw new Error(errorText(res.status, body));
                        const j = JSON.parse(body);
                        this.uploadId = j.upload_id; this.chunkBytes = j.chunk_bytes; this.total = j.total_chunks;
                    }

                    for (; this.next < this.total; this.next++) {
                        await this.sendWithRetry(this.next);
                        this.percent = Math.min(99, Math.floor(((this.next + 1) * this.chunkBytes) / fileRef.size * 100));
                    }

                    this.state = 'finishing';
                    this.message = 'Checking the video…';
                    this.report();
                    const done = await fetch(cfg.completeUrl.replace('__ID__', this.uploadId), {
                        method: 'POST', credentials: 'same-origin', headers,
                    });
                    const body = await done.text();
                    if (!done.ok) {
                        // A file that is not a video is deleted server-side; start fresh.
                        this.uploadId = ''; this.next = 0;
                        throw new Error(errorText(done.status, body));
                    }
                    this.percent = 100;
                    this.state = 'done';
                    this.message = '';
                } catch (e) {
                    this.state = 'error';
                    this.message = e.message || 'The upload failed.';
                }
                this.report();
            },

            // A dropped connection loses at most one piece; retry it a few times, then offer Resume.
            async sendWithRetry(index) {
                let lastError;
                for (let attempt = 0; attempt < 4; attempt++) {
                    try { return await this.sendChunk(index); }
                    catch (e) {
                        lastError = e;
                        if (e.fatal) break;
                        await new Promise(r => setTimeout(r, 800 * (attempt + 1)));
                    }
                }
                throw lastError;
            },

            sendChunk(index) {
                return new Promise((resolve, reject) => {
                    const start = index * this.chunkBytes;
                    const blob = fileRef.slice(start, Math.min(start + this.chunkBytes, fileRef.size));
                    const fd = new FormData();
                    fd.append('index', index);
                    fd.append('chunk', blob, 'piece');

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', cfg.chunkUrl.replace('__ID__', this.uploadId));
                    Object.entries(headers).forEach(([k, v]) => xhr.setRequestHeader(k, v));
                    xhr.upload.onprogress = (e) => {
                        this.percent = Math.min(99, Math.floor((start + e.loaded) / fileRef.size * 100));
                    };
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) return resolve();
                        const err = new Error(errorText(xhr.status, xhr.responseText));
                        err.fatal = xhr.status === 403 || xhr.status === 404 || xhr.status === 419;
                        reject(err);
                    };
                    xhr.onerror = () => reject(new Error('The connection dropped.'));
                    xhr.send(fd);
                });
            },

            resume() { if (fileRef) this.run(); },

            reset() {
                fileRef = null; this.hasFile = false; this.state = 'idle'; this.percent = 0; this.message = ''; this.info = '';
                this.uploadId = ''; this.next = 0;
                this.report();
            },
        };
    };

    // Don't lose a half-sent file by closing the tab.
    window.addEventListener('beforeunload', function (e) {
        if (document.querySelector('[data-tv-uploading="1"]')) { e.preventDefault(); e.returnValue = ''; }
    });
</script>
