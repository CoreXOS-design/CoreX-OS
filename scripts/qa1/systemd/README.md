# QA1 queue worker units

These are the systemd units that run QA1's queue workers. The host copies live in
`/etc/systemd/system/`; this folder is the record of what they must contain.

| Unit | Queues | Why separate |
|------|--------|--------------|
| `corex-qa1-queue` | default, p24import, p24images, matching, bg_removal, webhooks | the shared worker — short jobs only |
| `corex-qa1-queue-mail` | mail | every queued mail (rental application invite/decision/decline/approved, work-order, notices…). On its own so it can NEVER wait behind a long job |
| `corex-qa1-queue-buyer-matching` | buyer-matching | `RegenerateBuyerMatchesJob` is CPU-bound for ~8 min; on the shared worker it stalled all mail (2026-10-07). `Nice=10` keeps it from slowing page loads |
| `corex-qa1-queue-images`, `corex-qa1-queue-mail-slow` | p24images, mail-slow | already separate (not in this folder) |

Install / refresh on the host (idempotent):

```bash
sudo install -m 644 scripts/qa1/systemd/corex-qa1-queue*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now corex-qa1-queue corex-qa1-queue-mail corex-qa1-queue-buyer-matching
```

`scripts/qa-deploy.sh` restarts the shared and mail units on every deploy and sends
`queue:restart` to the rest (they finish their current job, exit and systemd respawns them on
the new code). It warns if the host copy of a unit differs from this folder.
