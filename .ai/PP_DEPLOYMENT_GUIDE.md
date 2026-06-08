# Private Property Syndication — Server Deployment Guide

> Follow these steps exactly when deploying PP syndication to a new server (staging or production).

---

## Prerequisites

- Server running Ubuntu with Nginx + PHP-FPM
- PHP 8.x installed
- Laravel codebase deployed

---

## Step 1 — Install PHP SOAP Extension

```bash
# Check PHP version
php -v

# Install SOAP for your PHP version (replace 8.4 with your version)
sudo apt install php8.4-soap

# If multiple PHP versions are installed, install for all that might be used:
sudo apt install php8.2-soap php8.3-soap php8.4-soap

# Restart PHP-FPM (ALL versions that are running)
sudo systemctl restart php8.2-fpm php8.3-fpm php8.4-fpm

# Restart Nginx (NOT Apache — this server uses Nginx)
sudo systemctl restart nginx

# Verify SOAP is loaded
php -r "var_dump(class_exists('SoapClient'));"
# Must output: bool(true)
```

**Important:** Do NOT try to restart Apache. This server uses Nginx + PHP-FPM. Apache is not the web server.

---

## Step 2 — Add Environment Variables

Edit the `.env` file on the server:

```bash
nano /corex/.env    # production
# or
nano /corex-staging/.env    # staging
```

Add these lines at the bottom:

### For Sandbox (testing):
```env
PP_USERNAME=HFCoastalUser
PP_PASSWORD=4SnhWMBgatQLL
PP_BRANCH_GUID=AF7DCE26-ED1B-4541-A88B-F35DF2B1BAB5
PP_WSDL=https://services.sandbox.pp.co.za/AgentImport/AgentImport.asmx?WSDL
PP_SANDBOX=true
PP_IMAGE_BASE_URL=https://corex.hfcoastal.co.za
```

### For Production (when PP provides production credentials):
```env
PP_USERNAME=<production username from PP>
PP_PASSWORD=<production password from PP>
PP_BRANCH_GUID=<production branch GUID from PP — may be the same>
PP_WSDL=https://services.pp.co.za/AgentImport/AgentImport.asmx?WSDL
PP_SANDBOX=false
PP_IMAGE_BASE_URL=https://corex.hfcoastal.co.za
```

**Note:** `PP_IMAGE_BASE_URL` must point to a publicly accessible domain on port 80 or 443. PP's servers fetch images from this URL. `localhost` or custom ports (e.g. `:8084`) will NOT work.

---

## Step 3 — Clear and Cache Config

```bash
cd /corex  # or /corex-staging
php artisan config:clear
php artisan config:cache
```

**This step is critical.** Laravel caches config on production. If you skip this, the new env vars won't be read.

---

## Step 4 — Run Database Migration

```bash
php artisan migrate
```

This creates the PP syndication columns on the `properties` table:
- `pp_syndication_enabled`, `pp_syndication_status`, `pp_ref`, `pp_listing_feed_ref`
- `pp_last_submitted_at`, `pp_activated_at`, `pp_exclusive_days`, `pp_delay_until`
- `pp_last_error`, `pp_images_last_synced_at`, `pp_listing_last_synced_at`
- `pp_suburb_id`

---

## Step 5 — Ensure Storage Symlink Exists

```bash
cd /corex  # or /corex-staging
php artisan storage:link
```

This creates `public/storage -> storage/app/public` so property images are served via the web.

---

## Step 6 — Ensure Property Images Are Accessible

PP's servers fetch images from the URL in `PP_IMAGE_BASE_URL`. The images must physically exist at that path.

If staging images are stored separately from production:
```bash
# Copy staging property images to production (if needed)
cp -r /corex-staging/storage/app/public/properties/ /corex/storage/app/public/properties/
```

Test accessibility:
```bash
curl -I https://corex.hfcoastal.co.za/storage/properties/16/001_3FU4hTDt.jpg
# Must return HTTP 200
```

---

## Step 7 — Verify Connection

```bash
cd /corex  # or /corex-staging
php artisan pp:smoke-test
```

Expected output:
```
[PP Smoke Test] Calling GetBranchDetails...
[PP Smoke Test] SUCCESS — Branch details retrieved.
Branch Name: Home Finders Coastal
...
```

If it fails with "Class SoapClient not found" → Step 1 was incomplete (FPM not restarted).
If it fails with timeout → Try again, sandbox can be slow.

---

## Step 8 — Verify Config Loaded

```bash
php artisan tinker --execute="echo config('services.private_property.branch_guid');"
```

Must output the GUID. If empty, re-run Step 3.

---

## Step 9 — Set Up Scheduled Jobs (Production Only)

The PP activation polling job runs every 15 minutes. Ensure Laravel's scheduler is in cron:

```bash
crontab -e
```

Add this line if not already present:
```
* * * * * cd /corex && php artisan schedule:run >> /dev/null 2>&1
```

---

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `Class "SoapClient" not found` | SOAP extension not installed or FPM not restarted | `sudo apt install php8.4-soap && sudo systemctl restart php8.4-fpm nginx` |
| `Missing required field: BranchId` | `PP_BRANCH_GUID` not in .env or config not cached | Add to .env, run `php artisan config:cache` |
| `Error Fetching http headers` | SOAP timeout (sandbox is slow) | Retry. Built-in retry logic handles most cases. |
| `PP120 - Image server returned failures` | PP can't reach image URLs | Check `PP_IMAGE_BASE_URL` points to public URL on port 80/443. Check images exist at path. |
| `PP102 - Agent does not exist` | Agent not registered on PP | System auto-registers agents. Ensure agent has a phone number in their profile. |
| `PP106 - Cannot provide SuburbId with town` | Both SuburbId and name fields sent | Already handled in code — SuburbId is used exclusively when set. |
| `PP119 - Street required` | Property missing street address | Fill in the property address before submitting. |

---

## Key Facts

- **WSDL caching:** Enabled (`WSDL_CACHE_BOTH`) so the WSDL is fetched once and cached.
- **Timeouts:** 60s socket, 30s connection, 120s PHP global. Auto-retry on timeout (2 attempts).
- **Logs:** `storage/logs/private_property.log` — all SOAP calls and responses logged.
- **Image limit:** Max 20 images per listing (PP's server can't handle more in a single transaction).
- **Agent auto-registration:** Agents are automatically registered on PP before listing submission. They need a phone number.
- **Photo sync optimization:** Images are not re-sent if they haven't changed since last sync.

---

## Switching from Sandbox to Production

1. Get production credentials from PP (username, password, WSDL URL)
2. Update `.env`:
   ```
   PP_USERNAME=<production>
   PP_PASSWORD=<production>
   PP_WSDL=https://services.pp.co.za/AgentImport/AgentImport.asmx?WSDL
   PP_SANDBOX=false
   ```
3. `php artisan config:cache`
4. `php artisan pp:smoke-test`
5. All previously submitted listings will need to be re-submitted (sandbox and production are separate systems)
