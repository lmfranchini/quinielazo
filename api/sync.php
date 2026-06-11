<?php
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * CRON JOB – Consultar resultados en vivo desde ESPN (GRATIS, sin API key)
 * 
 * Configurar en cPanel → Trabajos Cron:
 *   * / 5 * * * * php /home/maplemx/public_html/api/sync.php
 */

require_once __DIR__ . '/../config.php';

$logMessages = array();

function logMsg($msg) {
    global $logMessages;
    $logMessages[] = date('H:i:s') . ' ' . $msg;
}

try {

// ── 1. Consultar ESPN para hoy y ayer ──
$dates = array(gmdate('Ymd'), gmdate('Ymd', strtotime('-1 day')));
$allEvents = array();

foreach ($dates as $date) {
    $data = fetchEspnScoreboard($date);
    if ($data && isset($data['events'])) {
        foreach ($data['events'] as $ev) {
            $allEvents[$ev['id']] = $ev;
        }
        logMsg('📡 Fecha ' . $date . ': ' . count($data['events']) . ' partidos.');
    } else {
        logMsg('📡 Fecha ' . $date . ': sin datos.');
    }
}

if (empty($allEvents)) {
    logMsg('ℹ️  No se encontraron partidos del Mundial en ESPN para hoy.');
    outputLog();
    exit;
}

logMsg('📡 Total: ' . count($allEvents) . ' partidos encontrados.');

// ── 2. Mapear y actualizar en la DB ──
$db = getDB();
$teamMap = getTeamNameMap();
$updated = 0;
$liveCount = 0;

foreach ($allEvents as $event) {
    $comp = isset($event['competitions'][0]) ? $event['competitions'][0] : null;
    if (!$comp) continue;
    
    $homeTeamApi = '';
    $awayTeamApi = '';
    $scoreHome = null;
    $scoreAway = null;
    
    $homeTeamId = '';
    $awayTeamId = '';
    foreach ($comp['competitors'] as $c) {
        $teamName = isset($c['team']['displayName']) ? $c['team']['displayName'] : '';
        if (empty($teamName) && isset($c['team']['shortDisplayName'])) {
            $teamName = $c['team']['shortDisplayName'];
        }
        $score = isset($c['score']) ? intval($c['score']) : null;
        $teamId = isset($c['team']['id']) ? $c['team']['id'] : '';
        
        if ($c['homeAway'] === 'home') {
            $homeTeamApi = $teamName;
            $scoreHome = $score;
            $homeTeamId = $teamId;
        } else {
            $awayTeamApi = $teamName;
            $scoreAway = $score;
            $awayTeamId = $teamId;
        }
    }
    
    if (empty($homeTeamApi) || empty($awayTeamApi)) continue;
    
    // Traducir nombres al español
    $homeTeam = isset($teamMap[$homeTeamApi]) ? $teamMap[$homeTeamApi] : $homeTeamApi;
    $awayTeam = isset($teamMap[$awayTeamApi]) ? $teamMap[$awayTeamApi] : $awayTeamApi;
    
    // Buscar en nuestra DB
    $stmt = $db->prepare("SELECT id, status, isFinished, scorersData, cardsData FROM `Match` WHERE teamA = ? AND teamB = ?");
    $stmt->execute(array($homeTeam, $awayTeam));
    $dbMatch = $stmt->fetch();
    
    if (!$dbMatch) {
        // Intentar invertido
        $stmt->execute(array($awayTeam, $homeTeam));
        $dbMatch = $stmt->fetch();
        if ($dbMatch) {
            $tmp = $scoreHome;
            $scoreHome = $scoreAway;
            $scoreAway = $tmp;
        }
    }
    
    if (!$dbMatch) {
        logMsg('⚠️  No encontrado: ' . $homeTeamApi . ' vs ' . $awayTeamApi);
        continue;
    }
    
    // Status y minuto
    $espnStatus = isset($event['status']) ? $event['status'] : array();
    $espnState  = isset($espnStatus['type']['state']) ? $espnStatus['type']['state'] : 'pre';
    $espnName   = isset($espnStatus['type']['name']) ? $espnStatus['type']['name'] : '';
    $ourStatus  = mapEspnStatus($espnState, $espnName);
    $minute     = isset($espnStatus['displayClock']) ? $espnStatus['displayClock'] : null;

    if ($ourStatus === 'SCHEDULED') {
        $scoreHome = null;
        $scoreAway = null;
    }
    
    // Limpiar el minuto
    if ($minute) {
        $minute = trim(str_replace("'", '', $minute));
        $cleanMin = str_replace('+', '', str_replace(' ', '', $minute));
        if (!is_numeric($cleanMin)) {
            $minute = null;
        }
    }
    
    if ($ourStatus === 'LIVE' || $ourStatus === 'HALFTIME') {
        $liveCount++;
    }
    
    // No sobrescribir partidos ya terminados manualmente
    if ($dbMatch['isFinished'] && $ourStatus !== 'FINISHED') {
        continue;
    }
    
    $isFinished = ($ourStatus === 'FINISHED') ? 1 : 0;
    $eventId = isset($event['id']) ? intval($event['id']) : 0;
    
    // Obtener detalles de goleadores y tarjetas si está en vivo o terminado
    $scorersDataJson = isset($dbMatch['scorersData']) ? $dbMatch['scorersData'] : null;
    $cardsDataJson = isset($dbMatch['cardsData']) ? $dbMatch['cardsData'] : null;
    if ($ourStatus === 'LIVE' || $ourStatus === 'HALFTIME' || $ourStatus === 'FINISHED') {
        // Si hay goles pero no tenemos goleadores registrados, debemos forzar la consulta
        $hasScorers = false;
        if ($scorersDataJson) {
            $dec = json_decode($scorersDataJson, true);
            if (!empty($dec['teamA']) || !empty($dec['teamB'])) {
                $hasScorers = true;
            }
        }
        $totalGoals = ($scoreHome !== null && $scoreAway !== null) ? ($scoreHome + $scoreAway) : 0;
        $needsScorers = ($totalGoals > 0 && !$hasScorers);

        // Solo consultar si no está finalizado en DB o si no tenemos datos aún o si faltan goleadores
        if ((!$dbMatch['isFinished'] || $scorersDataJson === null || $cardsDataJson === null || $needsScorers) && $eventId > 0 && !empty($homeTeamId) && !empty($awayTeamId)) {
            $details = fetchEspnDetails($eventId, $homeTeamId, $awayTeamId);
            $scorersDataJson = json_encode($details['scorers']);
            $cardsDataJson = json_encode($details['cards']);
        }
    }
    
    // Actualizar en la DB
    $upd = $db->prepare("UPDATE `Match` SET 
        scoreA = ?, scoreB = ?, status = ?, matchMinute = ?, 
        isFinished = ?, externalId = ?, lastApiUpdate = NOW(), scorersData = ?, cardsData = ?, updatedAt = NOW()
        WHERE id = ?");
    $upd->execute(array(
        $scoreHome, $scoreAway, $ourStatus, $minute,
        $isFinished, $eventId, $scorersDataJson, $cardsDataJson, $dbMatch['id']
    ));
    
    $updated++;
    
    $emojis = array('LIVE' => '🟢', 'HALFTIME' => '🟡', 'FINISHED' => '🏁');
    $emoji = isset($emojis[$ourStatus]) ? $emojis[$ourStatus] : '⏳';
    $minStr = $minute ? " (min $minute)" : '';
    logMsg("$emoji $homeTeam $scoreHome - $scoreAway $awayTeam$minStr [$ourStatus]");
    
    // Si el partido acaba de terminar, calcular puntos
    if ($isFinished && !$dbMatch['isFinished'] && $scoreHome !== null && $scoreAway !== null) {
        logMsg('   → Calculando puntos definitivos...');
        calculateMatchPoints($db, $dbMatch['id'], intval($scoreHome), intval($scoreAway));
    }
}

logMsg("✅ $updated partidos actualizados, $liveCount en vivo.");

} catch (Exception $e) {
    logMsg('❌ Error: ' . $e->getMessage());
}

outputLog();

// ── Funciones ──

function calculateMatchPoints($db, $matchId, $scoreA, $scoreB) {
    $preds = $db->prepare("SELECT * FROM `Prediction` WHERE matchId = ?");
    $preds->execute(array($matchId));
    
    foreach ($preds->fetchAll() as $pred) {
        $pts = calculatePoints(intval($pred['scoreA']), intval($pred['scoreB']), $scoreA, $scoreB);
        $db->prepare("UPDATE `Prediction` SET points = ?, updatedAt = NOW() WHERE id = ?")
           ->execute(array($pts, $pred['id']));
    }
    
    recalcTotalPoints($db);
}

function recalcTotalPoints($db) {
    $db->exec("UPDATE `User` u SET points = (
        SELECT COALESCE(SUM(p.points), 0) 
        FROM `Prediction` p 
        INNER JOIN `Match` m ON p.matchId = m.id 
        WHERE p.userId = u.id AND m.isFinished = 1
    )");
}

function outputLog() {
    global $logMessages;
    if (php_sapi_name() === 'cli') {
        if (ob_get_length()) {
            @ob_end_clean();
        }
        foreach ($logMessages as $msg) {
            echo $msg . "\n";
        }
    } else {
        if (ob_get_length()) {
            @ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode(array('log' => $logMessages));
    }
}

function fetchEspnDetails($eventId, $homeTeamId, $awayTeamId) {
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
    
    $scorers = array('teamA' => array(), 'teamB' => array());
    $cards = array(
        'teamA' => array('yellow' => array(), 'red' => array()),
        'teamB' => array('yellow' => array(), 'red' => array())
    );
    
    if (!$response) {
        return array('scorers' => $scorers, 'cards' => $cards);
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['keyEvents'])) {
        foreach ($data['keyEvents'] as $event) {
            $typeText = isset($event['type']['text']) ? $event['type']['text'] : '';
            $typeType = isset($event['type']['type']) ? $event['type']['type'] : '';
            $teamId = isset($event['team']['id']) ? $event['team']['id'] : '';
            $minute = isset($event['clock']['displayValue']) ? $event['clock']['displayValue'] : '';
            
            $playerName = '';
            if (isset($event['participants'][0]['athlete']['displayName'])) {
                $playerName = $event['participants'][0]['athlete']['displayName'];
            }
            if (empty($playerName) && isset($event['text'])) {
                $parts = explode(' (', $event['text']);
                $playerName = trim($parts[0]);
            }
            
            if (empty($playerName)) continue;
            
            // 1. Goles
            $isGoal = (stripos($typeText, 'Goal') !== false || stripos($typeType, 'goal') !== false);
            if ($isGoal) {
                $suffix = '';
                if (stripos($typeText, 'penalty') !== false) {
                    $suffix = ' (p.)';
                } elseif (stripos($typeText, 'own goal') !== false || stripos($typeText, 'own-goal') !== false || stripos($typeText, 'auto') !== false) {
                    $suffix = ' (ag.)';
                }
                
                $scorerStr = $playerName . " " . $minute . $suffix;
                if ($teamId == $homeTeamId) {
                    $scorers['teamA'][] = $scorerStr;
                } elseif ($teamId == $awayTeamId) {
                    $scorers['teamB'][] = $scorerStr;
                }
            }
            
            // 2. Tarjetas
            $isYellow = (stripos($typeText, 'Yellow Card') !== false || stripos($typeType, 'yellow-card') !== false);
            $isRed = (stripos($typeText, 'Red Card') !== false || stripos($typeType, 'red-card') !== false);
            
            if ($isYellow || $isRed) {
                $cardStr = $playerName . " " . $minute;
                $cardType = $isYellow ? 'yellow' : 'red';
                
                if ($teamId == $homeTeamId) {
                    $cards['teamA'][$cardType][] = $cardStr;
                } elseif ($teamId == $awayTeamId) {
                    $cards['teamB'][$cardType][] = $cardStr;
                }
            }
        }
    }
    
    return array('scorers' => $scorers, 'cards' => $cards);
}
