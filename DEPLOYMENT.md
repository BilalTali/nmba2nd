# NMBA Agent Portal — Deployment Guide

## hPanel Cron Setup (FIX-ARCH-03)

Two cron entries are required for adequate burst throughput. Configure both in Hostinger hPanel → Cron Jobs.

### Primary Cron (every 5 minutes)
```
*/5 * * * * curl -s "https://nmbabudgam.in/nmba-cron.php?token=YOUR_CRON_TOKEN" > /dev/null 2>&1
```

### Offset Cron (every 5 minutes, offset by 2 minutes)
```
2-57/5 * * * * curl -s "https://nmbabudgam.in/nmba-cron.php?token=YOUR_CRON_TOKEN" > /dev/null 2>&1
```

> Replace `YOUR_CRON_TOKEN` with the value of `CRON_TOKEN` from your `.env` file.
> The lockfile guard in `nmba-cron.php` prevents the two entries from overlapping.

### Throughput Math (v2 — Parallel Batch Architecture)

The scheduler dispatches **5 × `SyncBatchJob`** per minute sweep. Each batch job:
- Opens **1 portal session** (login once)
- Submits **up to 20 events** on that session
- Total per sweep: **5 × 20 = 100 events**

| Scenario | Calculation | Capacity |
|---|---|---|
| 1 sweep/min, 5 slots × 20 events | 100 events × 60 sweeps/hr | **~6,000 events/hr** (portal-limited) |
| Portal takes ~5s/event | 5 slots × 20 events × 5s = 500s → burst limited by portal RTT | ~100 events / ~5-10 min |
| Burst of 500 submissions | 500 ÷ 100 per sweep | ~5 sweeps clearance time |
| Burst of 100 submissions | 100 ÷ 100 per sweep | **~1 minute clearance time** |

> The actual rate is limited by the government portal's own response time (~5–30 seconds per submission).
> The 5-slot design maximises utilisation by keeping all 5 connections busy simultaneously.

**Queue Worker Config**: Update `nmba-worker.conf` → `numprocs=5` to match the 5 slots.


---

## Scheduler Bootstrap

The Laravel scheduler must be triggered by cron to handle:
- `sync:health-check` (every 15 min)
- `portal:check-credentials` (weekly)
- `nmba_sync_orchestration_sweep` (every 5 min)

Add this as a **third** hPanel cron entry:
```
* * * * * /usr/bin/php /home/u335000182/domains/nmbabudgam.in/nmbaagent/artisan schedule:run >> /dev/null 2>&1
```

---

## Environment Variables Checklist

Before going live, confirm all of these are set in `.env`:

- [ ] `APP_KEY` — run `php artisan key:generate`
- [ ] `APP_ENV=production`
- [ ] `APP_URL=https://nmbabudgam.in`
- [ ] `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- [ ] `PORTAL_URL`, `PORTAL_EMAIL`, `PORTAL_PASSWORD`
- [ ] `PORTAL_CREDENTIALS_LAST_ROTATED` — set to today's date
- [ ] `CRON_TOKEN` — run `openssl rand -hex 32`
- [ ] `ADMIN_EMAIL` — receives sync backlog alerts
- [ ] `MAIL_MAILER`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` — for alert emails
- [ ] `SYNC_MODE=async`

---

## First Deployment Steps

```bash
# 1. Pull latest code
cd /home/u335000182/domains/nmbabudgam.in/nmbaagent
git pull origin main

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Run migrations (includes semantic_hash backfill — safe to run on live data)
php artisan migrate --force

# 4. Run the one-time hash audit (after migration)
php artisan audit:rehash-events
# Review: storage/audit/hash-audit-YYYY-MM-DD.log

# 5. Clear and warm caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Verify cron token is working
curl -s "https://nmbabudgam.in/nmba-cron.php?token=YOUR_CRON_TOKEN"
# Expected: [YYYY-MM-DD HH:MM:SS] Queue worker cycle completed (max-jobs=10).

# 7. Verify wrong token returns 403
curl -o /dev/null -w "%{http_code}" "https://nmbabudgam.in/nmba-cron.php?token=wrongtoken"
# Expected: 403
```

---

## Credential Rotation Procedure (Quarterly)

1. Log in to `nashamuktjk.org/enterprise` → Change Password
2. Update `PORTAL_PASSWORD` in `.env` on the server
3. Update `PORTAL_CREDENTIALS_LAST_ROTATED=YYYY-MM-DD` in `.env`
4. Verify: `php artisan portal:check-credentials`
5. Confirm the output shows `SUCCESS`

---

## Log File Locations

| Log | Path | Contents |
|---|---|---|
| Queue worker | `storage/logs/cron-worker.log` | Job execution output |
| Sync operations | `storage/logs/sync-*.log` | Per-event sync status, errors |
| Sync health | `storage/logs/sync-health.log` | Backlog alerts (every 15 min) |
| Credential checks | `storage/logs/credential-checks.log` | Weekly auth test results |
| Hash audit | `storage/audit/hash-audit-YYYY-MM-DD.log` | One-time B-03 audit report |
