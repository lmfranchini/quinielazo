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
    file_put_contents(__DIR__ . '/scratch/shootout_data.json', json_encode($data['shootout'], JSON_PRETTY_PRINT));
    echo "Saved shootout data from fifa.world";
} else {
    // Try copa america
    $url = "http://site.api.espn.com/apis/site/v2/sports/soccer/conmebol.america/summary?event=$externalId";
    $res = @file_get_contents($url);
    $data = json_decode($res, true);
    if (isset($data['shootout'])) {
        file_put_contents(__DIR__ . '/scratch/shootout_data.json', json_encode($data['shootout'], JSON_PRETTY_PRINT));
        echo "Saved shootout data from conmebol.america";
    } else {
        echo "No shootout data";
    }
}
