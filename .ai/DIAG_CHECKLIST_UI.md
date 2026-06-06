# CoreX OS — Diagnostic & UI Checklist

When something is broken or behaving unexpectedly, work through this checklist before writing any fix prompt.

---

## General Debug Sequence

1. **Read the full error** — stack trace, not just the last line
2. **Identify the layer** — is it PHP, Blade, Alpine.js, MySQL, or the Python AI service?
3. **Check the logs**
   ```bash
   tail -f /corex/storage/logs/laravel.log
   ```
4. **Check if it's a migration issue** — new column missing?
   ```bash
   php artisan migrate:status
   ```
5. **Check if it's a cache issue** — stale view or config?
   ```bash
   php artisan view:clear
   php artisan cache:clear
   php artisan config:clear
   ```
6. **Check the .env** — missing key? Wrong value?
7. **Check the route** — is the route registered?
   ```bash
   php artisan route:list | grep keyword
   ```
8. **Check the model relationship** — is the foreign key correct? Does the relation method exist?
9. **Check for soft deletes** — is the record there but with `deleted_at` set?
10. **Check the JS console** — Alpine.js errors are silent server-side

---

## Ellie-Specific Diagnostics

- **Zero results from KB:** Check `OPENAI_API_KEY` in `/corex/.env` — missing key = zero embeddings
- **Web search for KB questions:** Check `needs_web()` routing logic in AI service — KB questions should not hit web search
- **Embeddings not updating:** Python AI service at `/opt/hf-ai/app.py` may need restart
- **Slow responses:** Check OpenAI API quota and token usage

---

## Document/Signature Diagnostics

- **PDF not generating:** Is Puppeteer running? Check Node.js version compatibility
- **Signature not saving:** Check Alpine.js canvas — is `imagettftext` available on server?
- **Document not flattening:** Wet-ink upload flow — check the flatten service method
- **Wrong signer sequence:** Check sequential signing logic — `signed_at` ordering

---

## UI Checklist — Before Marking a Feature Complete

- [ ] Page is reachable from sidebar or a contextual button (no orphaned routes)
- [ ] Mobile view tested — functional on small screen
- [ ] Loading states present on async operations
- [ ] Confirmation dialog on any destructive action
- [ ] Status clearly visible on all list/card views
- [ ] Soft delete implemented — no hard deletes
- [ ] Role-based access — does the right role see the right things?
- [ ] Error states handled — what does the user see if something fails?
- [ ] Form validation — client side (Alpine) and server side (Laravel validator)
- [ ] Empty states handled — what does a blank list look like?

---

## Common Gotchas

| Symptom | Likely Cause |
|---------|-------------|
| 419 Page Expired | CSRF token missing from form |
| Blank page, no error | Check `.env APP_DEBUG=true` temporarily |
| Relationship returns null | Missing `with()` eager load or wrong foreign key |
| Migration fails | Conflicting column name or missing dependency migration |
| Alpine.js not reactive | Forgot `x-data` wrapper or typo in directive |
| Queue job not running | Queue worker not started — `php artisan queue:work` |
| Puppeteer timeout | Node.js process issue — check server Node version |
