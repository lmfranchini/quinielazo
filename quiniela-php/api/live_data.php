<?php
ob_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
/**
 * Endpoint AJAX – Devuelve datos en vivo para actualizar el dashboard sin recargar.
 * Retorna: partidos con marcadores actuales + clasificación con puntos proyectados.
 */
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db = getDB();

// ── 1. Obtener todos los partidos ──
$matches = $db->query("SELECT * FROM `Match` ORDER BY date ASC")->fetchAll();

// ── 2. Obtener pronósticos del usuario ──
$predStmt = $db->prepare("SELECT * FROM `Prediction` WHERE userId = ?");
$predStmt->execute([$userId]);
$predMap = [];
foreach ($predStmt->fetchAll() as $p) {
    $predMap[$p['matchId']] = $p;
}

// ── 3. Construir datos de partidos con puntos proyectados ──
$matchData = [];
$hasLive = false;

foreach ($matches as $m) {
    $pred = $predMap[$m['id']] ?? null;
    $status = $m['status'] ?? 'SCHEDULED';
    
    // Calcular puntos proyectados (en vivo o confirmados)
    $projectedPts = 0;
    if ($pred && ($status === 'LIVE' || $status === 'HALFTIME' || $status === 'FINISHED')) {
        $projectedPts = calculatePoints(
            (int)$pred['scoreA'], (int)$pred['scoreB'],
            $m['scoreA'], $m['scoreB']
        );
    }
    
    if ($status === 'LIVE' || $status === 'HALFTIME') {
        $hasLive = true;
    }
    
    $matchData[] = [
        'id'           => (int)$m['id'],
        'teamA'        => $m['teamA'],
        'teamB'        => $m['teamB'],
        'winner'       => $m['winner'],
        'scoreA'       => is_numeric($m['scoreA']) ? (int)$m['scoreA'] : null,
        'scoreB'       => is_numeric($m['scoreB']) ? (int)$m['scoreB'] : null,
        'shootoutA'    => isset($m['shootoutA']) && is_numeric($m['shootoutA']) ? (int)$m['shootoutA'] : null,
        'shootoutB'    => isset($m['shootoutB']) && is_numeric($m['shootoutB']) ? (int)$m['shootoutB'] : null,
        'status'       => $status,
        'minute'       => $m['matchMinute'],
        'isFinished'   => (bool)$m['isFinished'],
        'prediction'   => $pred ? [
            'scoreA' => (int)$pred['scoreA'],
            'scoreB' => (int)$pred['scoreB'],
        ] : null,
        'projectedPts' => $projectedPts,
        'lastUpdate'   => $m['lastApiUpdate'],
        'scorers'      => $m['scorersData'] ? json_decode($m['scorersData'], true) : null,
        'cards'        => $m['cardsData'] ? json_decode($m['cardsData'], true) : null,
        'probHome'     => is_numeric($m['probHome']) ? (float)$m['probHome'] : null,
        'probDraw'     => is_numeric($m['probDraw']) ? (float)$m['probDraw'] : null,
        'probAway'     => is_numeric($m['probAway']) ? (float)$m['probAway'] : null,
        'probLastUpdate' => $m['probLastUpdate'] ?? null,
    ];
}

// ── 4. Clasificación con puntos confirmados + proyectados ──
$users = $db->query("SELECT id, username, points, hasPaid FROM `User` WHERE role != 'ADMIN' ORDER BY id")->fetchAll();

// Obtener TODOS los pronósticos para calcular puntos proyectados
$allPreds = $db->query("
    SELECT p.userId, p.matchId, p.scoreA AS predA, p.scoreB AS predB, p.points AS confirmedPts,
           m.scoreA AS matchScoreA, m.scoreB AS matchScoreB, m.status, m.isFinished, m.winner, m.teamA, m.teamB
    FROM `Prediction` p
    INNER JOIN `Match` m ON p.matchId = m.id
")->fetchAll();

// Calcular puntos totales por usuario (confirmados + proyectados en vivo)
$userPoints = [];
foreach ($allPreds as $ap) {
    $uid = (int)$ap['userId'];
    if (!isset($userPoints[$uid])) {
        $userPoints[$uid] = ['confirmed' => 0, 'projected' => 0];
    }
    
    if ($ap['isFinished']) {
        // Puntos confirmados
        $userPoints[$uid]['confirmed'] += (int)$ap['confirmedPts'];
    } elseif ($ap['status'] === 'LIVE' || $ap['status'] === 'HALFTIME') {
        // Puntos proyectados (partido en vivo)
        $livePts = calculatePoints(
            (int)$ap['predA'], (int)$ap['predB'],
            $ap['matchScoreA'], $ap['matchScoreB'],
            $ap['winner'] ?? null, $ap['teamA'], $ap['teamB']
        );
        $userPoints[$uid]['projected'] += $livePts;
    }
}

// Obtener partidos en vivo actuales para mapear predicciones en vivo en la respuesta JSON
$liveMatches = [];
foreach ($matches as $m) {
    if ($m['status'] === 'LIVE' || $m['status'] === 'HALFTIME') {
        $liveMatches[$m['id']] = [
            'id'    => (int)$m['id'],
            'teamA' => $m['teamA'],
            'teamB' => $m['teamB'],
            'flagA' => getFlagUrl($m['teamA']),
            'flagB' => getFlagUrl($m['teamB']),
        ];
    }
}

// Indexar predicciones por usuario y partido para rendimiento O(1)
$predsIndex = [];
foreach ($allPreds as $ap) {
    $predsIndex[(int)$ap['userId']][(int)$ap['matchId']] = $ap;
}

$leaderboard = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $pts = $userPoints[$uid] ?? ['confirmed' => 0, 'projected' => 0];
    
    $uLivePreds = [];
    foreach ($liveMatches as $mid => $lm) {
        $pred = isset($predsIndex[$uid][$mid]) ? $predsIndex[$uid][$mid] : null;
        $uLivePreds[] = [
            'matchId' => $mid,
            'teamA'   => $lm['teamA'],
            'teamB'   => $lm['teamB'],
            'flagA'   => $lm['flagA'],
            'flagB'   => $lm['flagB'],
            'scoreA'  => $pred ? (int)$pred['predA'] : null,
            'scoreB'  => $pred ? (int)$pred['predB'] : null,
        ];
    }
    
    $leaderboard[] = [
        'id'              => $uid,
        'username'        => $u['username'],
        'confirmed'       => $pts['confirmed'],
        'projected'       => $pts['projected'],
        'total'           => $pts['confirmed'] + $pts['projected'],
        'isYou'           => ($uid === $userId),
        'hasPaid'         => (bool)$u['hasPaid'],
        'livePredictions' => $uLivePreds,
    ];
}

// Ordenar por total descendente
usort($leaderboard, function($a, $b) {
    return $b['total'] - $a['total'];
});

if (ob_get_length()) {
    @ob_end_clean();
}
header('Content-Type: application/json');
echo json_encode([
    'matches'     => $matchData,
    'leaderboard' => $leaderboard,
    'hasLive'     => $hasLive,
    'standings'   => getGroupStandings($db),
    'topScorers'  => getTopScorers($db),
    'topCards'    => getTopCards($db),
    'tStats'      => getTournamentStats($db),
    'serverTime'  => date('Y-m-d H:i:s'),
]);
