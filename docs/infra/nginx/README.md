# Production nginx vhosts (reference copies)

**These are snapshots, not the deployed source.** nginx is configured on the host
at `/etc/nginx/sites-available/`; nothing deploys from this folder. They are
committed so the 2026-07-17 domain split is reviewable and recoverable.

| File | Serves |
|------|--------|
| `corexos.co.za.conf` | The app. `corexos.co.za` + `www.corexos.co.za` → `/corex/public`, php8.3-fpm. |
| `corex.hfcoastal.co.za.conf` | Nothing. 308-redirects every path to `corexos.co.za`. |

## Before you touch either of these

- **The two files are coupled.** Until 2026-07-17 ONE server block served all three
  hostnames, so deleting the old vhost would have taken `corexos.co.za` down.
- **Keep the old hostname's DNS + TLS cert.** TLS completes before the 308 is ever
  seen — pull the cert and visitors get a security warning instead of a redirect.
  The cert lineage is *named* `corex.hfcoastal.co.za` but its SANs cover
  `corexos.co.za`, so dropping that domain from the cert breaks the MAIN site.
- **The redirect is 308, not 301, deliberately.** Both Chrome extensions POST to an
  agent-configured base URL; a 301 downgrades a redirected POST to GET and silently
  drops the body. Verified: `method_after_redirect=POST`.
- **ACME challenges must out-rank the redirect.** A server-level `return` runs in
  nginx's rewrite phase BEFORE location matching and would swallow them, failing
  renewal and expiring the main site's cert. Hence `location / { return 308 ... }`
  plus an explicit `location ^~ /.well-known/acme-challenge/`. `certbot renew
  --dry-run` passes — keep it that way.
