<?php
// clear_shootout.php
require_once __DIR__ . '/config.php';
try {
    $db = getDB();
    // Clear shootoutData so it forces a re-sync
    $db->query("UPDATE `Match` SET shootoutData = NULL WHERE id = 75 OR id = 1 OR id = 73");
    echo "Cleared in this DB. ";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
