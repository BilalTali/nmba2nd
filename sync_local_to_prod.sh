#!/bin/bash

# ================================================================
# NMBA Sync — Local-to-Production Sync Utility
# This script copies pending events and photos from localhost to
# the production server, allowing you to bypass local network WAF blocks.
# ================================================================

set -e

# --- CONFIG ---
SSH_HOST="92.249.46.36"
SSH_PORT="65002"
SSH_USER="u335000182"
SSH_PASS="Sugen@9313"
REMOTE_DIR="/home/u335000182/domains/nmbabudgam.in/nmbaagent"

LOCAL_DB="nmbaagent"
LOCAL_USER="root"

REMOTE_DB="u335000182_nmbabudgam"
REMOTE_DB_USER="u335000182_nmbabudgam"
REMOTE_DB_PASS="Sugen@9313"

echo -e "\e[1;32m"
echo "╔══════════════════════════════════════════════════╗"
echo "║   NMBA — Local-to-Production Sync Utility        ║"
echo "╚══════════════════════════════════════════════════╝"
echo -e "\e[0m"

# STEP 1 — Export pending database events
echo -e "\e[1;34m▶ Step 1/5 — Exporting local pending events...\e[0m"
mysqldump -u "$LOCAL_USER" --no-create-info --insert-ignore --where="sync_status='pending'" "$LOCAL_DB" events > pending_events.sql
echo -e "\e[1;32m✓ Local pending events exported to pending_events.sql.\e[0m"

# STEP 2 — Copy photos to production
echo -e "\e[1;34m▶ Step 2/5 — Copying new photographs to production server...\e[0m"
sshpass -p "$SSH_PASS" rsync -avz -e "ssh -p $SSH_PORT -o StrictHostKeyChecking=no" \
    ./storage/app/public/ "$SSH_USER@$SSH_HOST:$REMOTE_DIR/storage/app/public/"
echo -e "\e[1;32m✓ Photographs copied successfully.\e[0m"

# STEP 3 — Upload exported SQL file to production
echo -e "\e[1;34m▶ Step 3/5 — Uploading pending events data to production...\e[0m"
sshpass -p "$SSH_PASS" scp -P "$SSH_PORT" -o StrictHostKeyChecking=no pending_events.sql "$SSH_USER@$SSH_HOST:$REMOTE_DIR/pending_events.sql"
echo -e "\e[1;32m✓ SQL data uploaded successfully.\e[0m"

# STEP 4 — Import pending events into remote database
echo -e "\e[1;34m▶ Step 4/5 — Importing pending events on production database...\e[0m"
sshpass -p "$SSH_PASS" ssh -p "$SSH_PORT" -o StrictHostKeyChecking=no "$SSH_USER@$SSH_HOST" \
    "mysql -u $REMOTE_DB_USER -p'$REMOTE_DB_PASS' $REMOTE_DB < $REMOTE_DIR/pending_events.sql"
sshpass -p "$SSH_PASS" ssh -p "$SSH_PORT" -o StrictHostKeyChecking=no "$SSH_USER@$SSH_HOST" \
    "rm -f $REMOTE_DIR/pending_events.sql"
rm -f pending_events.sql
echo -e "\e[1;32m✓ Data imported successfully on production database.\e[0m"

# STEP 5 — Trigger remote queue worker to process the new events
echo -e "\e[1;34m▶ Step 5/5 — Triggering production cron worker to process the events immediately...\e[0m"
curl -s "https://nmbabudgam.in/nmba-cron.php?token=NMBA_CRON_9313" > /dev/null || true
echo -e "\e[1;32m✓ Queue worker triggered. Production will now sync the events in parallel!\e[0m"

echo ""
echo -e "\e[1;32m╔══════════════════════════════════════════════════╗"
echo "║  ✓ Sync Complete! Events successfully transferred. ║"
echo "╚══════════════════════════════════════════════════╝\e[0m"
echo ""
echo "  Check progress at: https://nmbabudgam.in/dashboard"
echo ""
