<?php
require_once 'config.php';

$db = getDB();
$standings = getGroupStandings($db);

echo "STANDINGS GRUPO A:\n";
print_r($standings['Grupo A']);

echo "\nPROCESSED MATCHES:\n";
$matches = $db->query("SELECT id, teamA, teamB, scoreA, scoreB, status, isFinished FROM `Match`")->fetchAll();
foreach ($matches as $m) {
    if ($m['scoreA'] !== null && $m['scoreB'] !== null) {
        echo "#{$m['id']}: {$m['teamA']} {$m['scoreA']} - {$m['scoreB']} {$m['teamB']} (isFinished: {$m['isFinished']}, status: {$m['status']})\n";
    }
}
