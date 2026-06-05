<?php
// auto_sync_db.php
// Syncs ALL new events from nmbabudgam database to ctetmonktest database
// Uses INSERT IGNORE to safely skip duplicates — no data is ever overwritten.

try {
    $pdoSource = new PDO('mysql:host=127.0.0.1;dbname=u335000182_nmbabudgam', 'u335000182_nmbabudgam', 'Sugen@9313');
    $pdoTarget = new PDO('mysql:host=127.0.0.1;dbname=u335000182_database', 'u335000182_database', 'Sugen@9313');

    $pdoSource->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdoTarget->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the set of IDs already in the target database
    $existingIds = $pdoTarget->query('SELECT id FROM events')->fetchAll(PDO::FETCH_COLUMN, 0);
    $existingIds = array_flip($existingIds); // flip for fast O(1) lookup

    // Fetch ALL events from the source
    $stmt = $pdoSource->query('SELECT * FROM events');
    $allSourceEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter to only the ones not yet in target
    $newEvents = array_filter($allSourceEvents, function($event) use ($existingIds) {
        return !isset($existingIds[$event['id']]);
    });

    if (empty($newEvents)) {
        echo "[" . date('Y-m-d H:i:s') . "] No new events to sync. Target is up to date.\n";
        exit;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($newEvents) . " missing events. Syncing...\n";

    $columns = array_keys(reset($newEvents));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $colNames = implode(',', array_map(function($c) { return "`$c`"; }, $columns));

    $insertStmt = $pdoTarget->prepare("INSERT IGNORE INTO events ($colNames) VALUES ($placeholders)");

    $count = 0;
    foreach ($newEvents as $event) {
        // Keep sync_status as 'pending' so ctetmonktest.fun syncs events independently.
        // Both sites act as parallel upload agents to the enterprise portal.
        $event['sync_status'] = 'pending';
        $event['sync_attempts'] = 0; // Reset attempts so fresh sync is tried
        $values = array_values($event);
        $insertStmt->execute($values);
        $count++;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Successfully synced $count events to target database.\n";
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Sync failed: " . $e->getMessage() . "\n";
    exit(1);
}
