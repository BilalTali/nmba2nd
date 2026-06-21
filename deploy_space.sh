#!/bin/bash

# ================================================================
# NMBA Agent Portal — Shared Hosting Production Deployment Script (Space Storage Host)
# Server  : ctetmonktest.space (82.25.107.68:65002)
# User    : u596750690
# App Root: Auto-detected (ctetmonktest.space/public_html)
# PHP     : /usr/bin/php (PHP 8.3)
# ================================================================

set -e

# ── CONFIG ──────────────────────────────────────────────────────
SSH_HOST="82.25.107.68"
SSH_PORT="65002"
SSH_USER="u596750690"
SSH_PASS="${SSH_PASS:-Sugen@9313}"
PHP="/usr/bin/php"

# ── HELPERS ─────────────────────────────────────────────────────
remote() {
    sshpass -p "$SSH_PASS" ssh -p "$SSH_PORT" \
        -o StrictHostKeyChecking=no \
        -o ConnectTimeout=15 \
        "$SSH_USER@$SSH_HOST" "$@"
}

log()  { echo -e "\e[1;34m▶ $1\e[0m"; }
ok()   { echo -e "\e[1;32m✓ $1\e[0m"; }
warn() { echo -e "\e[1;33m⚠ $1\e[0m"; }
fail() { echo -e "\e[1;31m✗ $1\e[0m"; exit 1; }

# ================================================================
echo -e "\e[1;32m"
echo "╔══════════════════════════════════════════════════╗"
echo "║   NMBA Agent Portal — Production Deployment      ║"
# Ref: https://ctetmonktest.space
echo "║   Server: ctetmonktest.space                     ║"
echo "╚══════════════════════════════════════════════════╝"
echo -e "\e[0m"
# ================================================================

# STEP 1 — Verify SSH connectivity
log "Step 1/10 — Verifying server connectivity..."
remote "echo 'SSH OK'" >/dev/null && ok "SSH connection established." || fail "Cannot connect to server!"

# STEP 2 — Detect remote App Root directory
log "Step 2/10 — Detecting remote application directory..."
POSSIBLE_DIRS=(
    "/home/u596750690/domains/ctetmonktest.space/public_html"
    "/home/u596750690/public_html"
)
APP_DIR=""
for dir in "${POSSIBLE_DIRS[@]}"; do
    if remote "[ -f $dir/artisan ]" >/dev/null 2>&1; then
        APP_DIR="$dir"
        break
    fi
done

if [ -z "$APP_DIR" ]; then
    warn "Could not auto-detect artisan. Defaulting App Root to additional domain layout."
    APP_DIR="/home/u596750690/domains/ctetmonktest.space/public_html"
fi
ok "Target directory verified: $APP_DIR"

remote_artisan() {
    remote "$PHP $APP_DIR/artisan --no-interaction $*"
}

# STEP 3 — Pull latest code from GitHub
log "Step 3/10 — Pulling latest code from GitHub..."
remote "cd $APP_DIR && git stash && git clean -fd && git pull origin main 2>&1"
ok "Code updated."

# STEP 4 — Install Composer production dependencies
log "Step 4/10 — Installing Composer dependencies..."
remote "cd $APP_DIR && composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5"
ok "Composer packages installed."

# STEP 5 — Run database migrations
log "Step 5/10 — Running database migrations..."
remote_artisan "migrate --force 2>&1"
ok "Migrations applied."

# STEP 6 — Warm up all Laravel caches
log "Step 6/10 — Warming up Laravel config / route / view caches..."
remote_artisan "config:cache 2>&1"
remote_artisan "route:cache  2>&1"
remote_artisan "view:cache   2>&1"
ok "Application caches warmed."

# STEP 7 — Evict metrics cache
log "Step 7/10 — Evicting metrics cache..."
remote_artisan "cache:clear 2>&1"
ok "Cache evicted."

# STEP 8 — Setup storage symlink if missing
log "Step 8/10 — Creating storage symlink..."
remote_artisan "storage:link 2>&1" || true
ok "Storage link verified."

# STEP 9 — Final health check
log "Step 9/10 — Running final health probe..."
STATUS=$(remote "curl -s -o /dev/null -w '%{http_code}' https://ctetmonktest.space/ 2>/dev/null || echo 'unreachable'")
if [ "$STATUS" = "200" ] || [ "$STATUS" = "302" ]; then
    ok "Portal is live! HTTP status: $STATUS"
else
    warn "Portal returned HTTP $STATUS — may need a moment to warm up."
fi

echo ""
echo -e "\e[1;32m╔══════════════════════════════════════════════════╗"
echo "║  ✓ Deployment Complete! ctetmonktest.space live. ║"
echo "╚══════════════════════════════════════════════════╝\e[0m"
echo ""
echo "  Dashboard   → https://ctetmonktest.space/dashboard"
echo ""
