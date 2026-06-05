#!/bin/bash
# ================================================================
# Automatic Event Sync: nmbabudgam.in -> ctetmonktest.fun
# Runs every minute via cron
# Photos are shared via symlink — no file copying needed.
# ================================================================

# Sync Database Records only
# Photos are served directly from nmbabudgam.in via a symlink.
php /home/u335000182/domains/nmbabudgam.in/nmbaagent/auto_sync_db.php
