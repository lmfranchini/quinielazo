<?php
require_once 'config.php';

try {
    $db = getDB();
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    $db->exec("TRUNCATE TABLE `Prediction`");
    $db->exec("TRUNCATE TABLE `User`");
    $db->exec("SET FOREIGN_KEY_CHECKS=1");

    // Verificar
    $users = $db->query("SELECT COUNT(*) FROM `User`")->fetchColumn();
    $preds = $db->query("SELECT COUNT(*) FROM `Prediction`")->fetchColumn();

    echo "✅ Tablas limpiadas.\n";
    echo "   User: $users registros\n";
    echo "   Prediction: $preds registros\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
