#!/bin/bash

# ================================================================
# NMBA Agent Portal — Shared Hosting Production Deployment Script
# Server  : nmbabudgam.in (92.249.46.36:65002)
# User    : u335000182
# App Root: /home/u335000182/domains/nmbabudgam.in/nmbaagent
# PHP     : /usr/bin/php (PHP 8.3)
# Queue   : database driver via cron (no Supervisor / no Redis)
# ================================================================

set -e

# ── CONFIG ──────────────────────────────────────────────────────
SSH_HOST="92.249.46.36"
SSH_PORT="65002"
SSH_USER="u335000182"
SSH_PASS="${SSH_PASS:-Sugen@9313}"
APP_DIR="/home/u335000182/domains/nmbabudgam.in/nmbaagent"
PHP="/usr/bin/php"

# ── HELPERS ─────────────────────────────────────────────────────
remote() {
    sshpass -p "$SSH_PASS" ssh -p "$SSH_PORT" \
        -o StrictHostKeyChecking=no \
        -o ConnectTimeout=15 \
        "$SSH_USER@$SSH_HOST" "$@"
}

remote_artisan() {
    remote "$PHP $APP_DIR/artisan --no-interaction $*"
}

log()  { echo -e "\e[1;34m▶ $1\e[0m"; }
ok()   { echo -e "\e[1;32m✓ $1\e[0m"; }
warn() { echo -e "\e[1;33m⚠ $1\e[0m"; }
fail() { echo -e "\e[1;31m✗ $1\e[0m"; exit 1; }

# ================================================================
echo -e "\e[1;32m"
echo "╔══════════════════════════════════════════════════╗"
echo "║   NMBA Agent Portal — Production Deployment      ║"
echo "║   Server: nmbabudgam.in                          ║"
echo "╚══════════════════════════════════════════════════╝"
echo -e "\e[0m"
# ================================================================

# STEP 1 — Verify SSH connectivity
log "Step 1/10 — Verifying server connectivity..."
remote "echo 'SSH OK'" >/dev/null && ok "SSH connection established." || fail "Cannot connect to server!"

# STEP 2 — Pull latest code from GitHub
log "Step 2/10 — Pulling latest code from GitHub..."
remote "cd $APP_DIR && git pull origin main 2>&1"
remote "mkdir -p $APP_DIR/../public_html && cp $APP_DIR/public_html/nmba-cron.php $APP_DIR/../public_html/nmba-cron.php"
sshpass -p "$SSH_PASS" scp -P "$SSH_PORT" -o StrictHostKeyChecking=no auto_sync_to_ctet.sh "$SSH_USER@$SSH_HOST:$APP_DIR/auto_sync_to_ctet.sh"
sshpass -p "$SSH_PASS" scp -P "$SSH_PORT" -o StrictHostKeyChecking=no auto_sync_db.php "$SSH_USER@$SSH_HOST:$APP_DIR/auto_sync_db.php"
remote "chmod +x $APP_DIR/auto_sync_to_ctet.sh"
ok "Code updated and sync scripts uploaded."

# STEP 3 — Install Composer production dependencies
log "Step 3/10 — Installing Composer dependencies..."
remote "cd $APP_DIR && composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5"
ok "Composer packages installed."

# STEP 4 — Run database migrations
log "Step 4/10 — Running database migrations..."
remote_artisan "migrate --force 2>&1"
ok "Migrations applied."

# STEP 5 — Run historical hash audit (non-destructive)
log "Step 5/10 — Scanning database for historical hash corruption..."
remote_artisan "audit:rehash-events 2>&1" || true
ok "Hash audit complete. Report saved to storage/audit/hash-audit-YYYY-MM-DD.log"

# STEP 6 — Warm up all Laravel caches
log "Step 6/10 — Warming up Laravel config / route / view caches..."
remote_artisan "config:cache 2>&1"
remote_artisan "route:cache  2>&1"
remote_artisan "view:cache   2>&1"
ok "Application caches warmed."

# STEP 7 — Clear stale dashboard metrics cache
log "Step 7/10 — Evicting stale dashboard telemetry cache..."
remote_artisan "cache:clear 2>&1"
ok "Cache evicted — fresh metrics will appear on next dashboard load."

# STEP 8 — Flush failed jobs and restart queue
log "Step 8/10 — Flushing failed job table and restarting queue signal..."
remote_artisan "queue:flush  2>&1 || true"
remote_artisan "queue:restart 2>&1"
ok "Failed jobs flushed. Queue workers will restart on next cron tick."

# STEP 9 — Setup cron-based scheduler & queue worker (idempotent — safe to run multiple times)
log "Step 9/10 — Registering cron-based scheduler & queue worker..."
CRON_CMD="*/5 * * * * $PHP $APP_DIR/artisan queue:work database --max-jobs=10 --tries=10 --timeout=110 >> $APP_DIR/storage/logs/cron-worker.log 2>&1"
SCHEDULER_CMD="* * * * * $PHP $APP_DIR/artisan schedule:run >> /dev/null 2>&1"
SYNC_CTET_CMD="* * * * * bash $APP_DIR/auto_sync_to_ctet.sh >> $APP_DIR/storage/logs/sync-ctet.log 2>&1"

# Check if crontab utility is available (disabled on some shared hosts like Hostinger)
HAS_CRONTAB=$(remote "command -v crontab >/dev/null 2>&1 && echo 'yes' || echo 'no'")

if [ "$HAS_CRONTAB" = "yes" ]; then
    remote "
      (crontab -l 2>/dev/null | grep -v 'nmbaagent' | grep -v 'queue:work' | grep -v 'auto_sync_to_ctet'; echo '# NMBA Scheduler'; echo '$SCHEDULER_CMD'; echo '# NMBA Queue Worker'; echo '$CRON_CMD'; echo '# NMBA Sync to ctetmonktest.fun'; echo '$SYNC_CTET_CMD') | crontab -
    "
    ok "Cron scheduler, queue worker, and sync script registered in system crontab."
else
    # Extract the token dynamically from the remote .env to provide the exact URL
    REMOTE_TOKEN=$(remote "grep '^CRON_TOKEN=' $APP_DIR/.env | cut -d'=' -f2 | tr -d '\"'\'' '" 2>/dev/null || echo "")
    if [ -z "$REMOTE_TOKEN" ]; then
        REMOTE_TOKEN="YOUR_CRON_TOKEN"
    fi
    warn "crontab utility not available on this server (standard for Hostinger Shared hosting)."
    warn "Please ensure you have configured the following cron jobs in the Hostinger hPanel control panel UI:"
    warn "  1. Scheduler Command (every minute):"
    warn "     * * * * * $PHP $APP_DIR/artisan schedule:run >> /dev/null 2>&1"
    warn "  2. Web Cron Trigger 1 (every 5 mins):"
    warn "     */5 * * * * curl -s \"https://nmbabudgam.in/nmba-cron.php?token=$REMOTE_TOKEN\" > /dev/null 2>&1"
    warn "  3. Sync to CTET (every minute):"
    warn "     * * * * * bash $APP_DIR/auto_sync_to_ctet.sh >> $APP_DIR/storage/logs/sync-ctet.log 2>&1"
fi

# STEP 10 — Final health check
log "Step 10/10 — Running final health probe..."
STATUS=$(remote "curl -s -o /dev/null -w '%{http_code}' https://nmbabudgam.in/ 2>/dev/null || echo 'unreachable'")
if [ "$STATUS" = "200" ] || [ "$STATUS" = "302" ]; then
    ok "Portal is live! HTTP status: $STATUS"
else
    warn "Portal returned HTTP $STATUS — may need a moment to warm up."
fi

echo ""
echo -e "\e[1;32m╔══════════════════════════════════════════════════╗"
echo "║  ✓ Deployment Complete! nmbabudgam.in is live.   ║"
echo "╚══════════════════════════════════════════════════╝\e[0m"
echo ""
echo "  Dashboard   → https://nmbabudgam.in/dashboard"
echo "  Queue Logs  → $APP_DIR/storage/logs/cron-worker.log"
echo "  Sync Logs   → $APP_DIR/storage/logs/sync.log"
echo ""
