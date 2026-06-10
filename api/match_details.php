<?php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'No autenticado'));
    exit;
}

$matchId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($matchId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'ID de partido no válido'));
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT * FROM `Match` WHERE id = ?");
$stmt->execute(array($matchId));
$match = $stmt->fetch();

if (!$match) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Partido no encontrado'));
    exit;
}

$eventId = isset($match['externalId']) ? intval($match['externalId']) : 0;
if ($eventId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'El partido no tiene un ID de ESPN asignado o no ha comenzado'));
    exit;
}

// Fetch ESPN summary
$url = "https://site.api.espn.com/apis/site/v2/sports/soccer/" . ESPN_LEAGUE . "/summary?event=" . $eventId;

$response = null;
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
} else {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header'  => "User-Agent: Mozilla/5.0\r\n",
        ]
    ]);
    $response = @file_get_contents($url, false, $ctx);
}

if (!$response) {
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'No se pudo obtener la información de ESPN'));
    exit;
}

$data = json_decode($response, true);

// Parse general info
$gameInfo = isset($data['gameInfo']) ? $data['gameInfo'] : array();
$venue = isset($gameInfo['venue']['fullName']) ? $gameInfo['venue']['fullName'] : ($match['venue'] ? $match['venue'] : '');
$attendance = isset($gameInfo['attendance']) ? intval($gameInfo['attendance']) : null;
$referee = null;
if (isset($gameInfo['officials'])) {
    foreach ($gameInfo['officials'] as $official) {
        if (isset($official['role']['name']) && $official['role']['name'] === 'Referee') {
            $referee = $official['displayName'];
            break;
        }
    }
    if (!$referee && !empty($gameInfo['officials'])) {
        $referee = $gameInfo['officials'][0]['displayName'];
    }
}

// Parse team mapping
$competitors = isset($data['header']['competitions'][0]['competitors']) ? $data['header']['competitions'][0]['competitors'] : array();
$teamMap = getTeamNameMap();
$espnTeamAId = null;
$espnTeamBId = null;

foreach ($competitors as $c) {
    $espnName = isset($c['team']['displayName']) ? $c['team']['displayName'] : '';
    if (empty($espnName) && isset($c['team']['shortDisplayName'])) {
        $espnName = $c['team']['shortDisplayName'];
    }
    $translated = isset($teamMap[$espnName]) ? $teamMap[$espnName] : $espnName;
    
    if ($translated === $match['teamA']) {
        $espnTeamAId = $c['team']['id'];
    } elseif ($translated === $match['teamB']) {
        $espnTeamBId = $c['team']['id'];
    }
}

// Fallback to home/away positions if translations differ
if (!$espnTeamAId || !$espnTeamBId) {
    foreach ($competitors as $c) {
        if ($c['homeAway'] === 'home') {
            $espnTeamAId = $c['team']['id'];
        } else {
            $espnTeamBId = $c['team']['id'];
        }
    }
}

// Parse Stats
$stats = array(
    'possessionPct' => array('teamA' => '50%', 'teamB' => '50%'),
    'totalShots' => array('teamA' => '0', 'teamB' => '0'),
    'shotsOnTarget' => array('teamA' => '0', 'teamB' => '0'),
    'wonCorners' => array('teamA' => '0', 'teamB' => '0'),
    'foulsCommitted' => array('teamA' => '0', 'teamB' => '0'),
    'saves' => array('teamA' => '0', 'teamB' => '0'),
    'offsides' => array('teamA' => '0', 'teamB' => '0'),
);

$teamsStats = isset($data['boxscore']['teams']) ? $data['boxscore']['teams'] : array();
foreach ($teamsStats as $t) {
    $tId = $t['team']['id'];
    $isTeamA = ($tId == $espnTeamAId);
    $key = $isTeamA ? 'teamA' : 'teamB';
    
    if (isset($t['statistics'])) {
        foreach ($t['statistics'] as $s) {
            $name = $s['name'];
            if (isset($stats[$name])) {
                $stats[$name][$key] = $s['displayValue'];
            }
        }
    }
}

// Parse rosters
$rosters = array(
    'teamA' => array('starters' => array(), 'bench' => array()),
    'teamB' => array('starters' => array(), 'bench' => array())
);

$rawRosters = isset($data['rosters']) ? $data['rosters'] : array();
foreach ($rawRosters as $r) {
    $tId = $r['team']['id'];
    $isTeamA = ($tId == $espnTeamAId);
    $key = $isTeamA ? 'teamA' : 'teamB';
    
    $rosterList = isset($r['roster']) ? $r['roster'] : array();
    foreach ($rosterList as $p) {
        $player = array(
            'name' => $p['athlete']['displayName'],
            'jersey' => isset($p['jersey']) ? $p['jersey'] : '',
            'position' => isset($p['position']['displayName']) ? $p['position']['displayName'] : '',
            'subbedIn' => isset($p['subbedIn']) ? (bool)$p['subbedIn'] : false,
            'subbedOut' => isset($p['subbedOut']) ? (bool)$p['subbedOut'] : false
        );
        if (isset($p['starter']) && $p['starter']) {
            $rosters[$key]['starters'][] = $player;
        } else {
            $rosters[$key]['bench'][] = $player;
        }
    }
}

// Parse substitutions
$substitutions = array('teamA' => array(), 'teamB' => array());
$keyEvents = isset($data['keyEvents']) ? $data['keyEvents'] : array();
foreach ($keyEvents as $ev) {
    $typeText = isset($ev['type']['text']) ? $ev['type']['text'] : '';
    if (stripos($typeText, 'Substitution') !== false) {
        $tId = $ev['team']['id'];
        $isTeamA = ($tId == $espnTeamAId);
        $key = $isTeamA ? 'teamA' : 'teamB';
        
        $pIn = isset($ev['participants'][0]['athlete']['displayName']) ? $ev['participants'][0]['athlete']['displayName'] : '';
        $pOut = isset($ev['participants'][1]['athlete']['displayName']) ? $ev['participants'][1]['athlete']['displayName'] : '';
        $min = isset($ev['clock']['displayValue']) ? $ev['clock']['displayValue'] : '';
        
        if ($pIn && $pOut) {
            $substitutions[$key][] = array(
                'in' => $pIn,
                'out' => $pOut,
                'minute' => $min
            );
        }
    }
}

header('Content-Type: application/json');
echo json_encode(array(
    'matchId' => $matchId,
    'teamA' => $match['teamA'],
    'teamB' => $match['teamB'],
    'flagA' => getFlagUrl($match['teamA']),
    'flagB' => getFlagUrl($match['teamB']),
    'status' => $match['status'],
    'minute' => $match['matchMinute'],
    'scoreA' => $match['scoreA'],
    'scoreB' => $match['scoreB'],
    'venue' => $venue,
    'referee' => $referee,
    'attendance' => $attendance,
    'stats' => $stats,
    'rosters' => $rosters,
    'substitutions' => $substitutions
));
