<?php
ob_start();
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
            $m['scoreA'] !== null ? (int)$m['scoreA'] : null,
            $m['scoreB'] !== null ? (int)$m['scoreB'] : null
        );
    }
    
    if ($status === 'LIVE' || $status === 'HALFTIME') {
        $hasLive = true;
    }
    
    $matchData[] = [
        'id'           => (int)$m['id'],
        'teamA'        => $m['teamA'],
        'teamB'        => $m['teamB'],
        'scoreA'       => $m['scoreA'] !== null ? (int)$m['scoreA'] : null,
        'scoreB'       => $m['scoreB'] !== null ? (int)$m['scoreB'] : null,
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
    ];
}

// ── 4. Clasificación con puntos confirmados + proyectados ──
$users = $db->query("SELECT id, username, points FROM `User` WHERE role != 'ADMIN' ORDER BY id")->fetchAll();

// Obtener TODOS los pronósticos para calcular puntos proyectados
$allPreds = $db->query("
    SELECT p.userId, p.matchId, p.scoreA AS predA, p.scoreB AS predB, p.points AS confirmedPts,
           m.scoreA AS matchScoreA, m.scoreB AS matchScoreB, m.status, m.isFinished
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
            $ap['matchScoreA'] !== null ? (int)$ap['matchScoreA'] : null,
            $ap['matchScoreB'] !== null ? (int)$ap['matchScoreB'] : null
        );
        $userPoints[$uid]['projected'] += $livePts;
    }
}

$leaderboard = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $pts = $userPoints[$uid] ?? ['confirmed' => 0, 'projected' => 0];
    $leaderboard[] = [
        'id'        => $uid,
        'username'  => $u['username'],
        'confirmed' => $pts['confirmed'],
        'projected' => $pts['projected'],
        'total'     => $pts['confirmed'] + $pts['projected'],
        'isYou'     => ($uid === $userId),
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
