<?php
require_once __DIR__ . '/config.php';
$db = getDB();
$stmt = $db->query("SELECT externalId FROM `Match` WHERE id = 75");
$m = $stmt->fetch();
$externalId = $m['externalId'];

$url = "http://site.api.espn.com/apis/site/v2/sports/soccer/fifa.world/summary?event=$externalId";
$res = @file_get_contents($url);
$data = json_decode($res, true);

if (isset($data['shootout'])) {
    echo json_encode($data['shootout'], JSON_PRETTY_PRINT);
} else {
    // Try copa america
    $url = "http://site.api.espn.com/apis/site/v2/sports/soccer/conmebol.america/summary?event=$externalId";
    $res = @file_get_contents($url);
    $data = json_decode($res, true);
    if (isset($data['shootout'])) {
        echo json_encode($data['shootout'], JSON_PRETTY_PRINT);
    } else {
        echo "No shootout data";
    }
}
