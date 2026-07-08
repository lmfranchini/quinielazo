<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

$logFile = __DIR__ . '/../live_forecast_log.txt';
$logData = [
    'time' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'session_user_id' => $_SESSION['user_id'] ?? 'not_set',
    'match_id' => $_REQUEST['match_id'] ?? 'not_set',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
];

if (!isset($_SESSION['user_id'])) {
    $logData['error'] = 'No autenticado';
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$matchId = isset($_REQUEST['match_id']) ? (int)$_REQUEST['match_id'] : 0;
if ($matchId <= 0) {
    $logData['error'] = 'ID de partido inválido';
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    echo json_encode(['error' => 'ID de partido inválido']);
    exit;
}

try {
    $db = getDB();
    
    // Obtener los datos del partido
    $stmtMatch = $db->prepare("SELECT id, teamA, teamB, date FROM `Match` WHERE id = ?");
    $stmtMatch->execute([$matchId]);
    $match = $stmtMatch->fetch();
    
    if (!$match) {
        echo json_encode(['error' => 'Partido no encontrado']);
        exit;
    }
    
    // Obtener el último pronóstico guardado en la base de datos (live o pre-match)
    $stmtConsensus = $db->prepare("SELECT * FROM `ForecastConsensus` WHERE matchId = ? ORDER BY runId DESC LIMIT 1");
    $stmtConsensus->execute([$matchId]);
    $consensus = $stmtConsensus->fetch();
    
    if ($consensus) {
        $runId = intval($consensus['runId']);
        
        // Obtener los agentes
        $stmtAgents = $db->prepare("
            SELECT agentKey as agent, status, probHome as home, probDraw as draw, probAway as away, weightApplied, payloadJson 
            FROM `ForecastAgentPrediction` 
            WHERE matchId = ? AND runId = ?
        ");
        $stmtAgents->execute([$matchId, $runId]);
        $dbAgents = $stmtAgents->fetchAll();
        
        $agents = [];
        $hasOkLineup = false;
        foreach ($dbAgents as $da) {
            $da['probabilities'] = [
                'home' => $da['home'] !== null ? floatval($da['home']) : null,
                'draw' => $da['draw'] !== null ? floatval($da['draw']) : null,
                'away' => $da['away'] !== null ? floatval($da['away']) : null
            ];
            if ($da['agent'] === 'lineup_impact' && $da['status'] === 'ok') {
                $hasOkLineup = true;
            }
            $agents[] = $da;
        }
        
        // Si no hay un lineup_impact 'ok' en este run, buscar el último 'ok' de corridas anteriores para el mismo partido
        if (!$hasOkLineup) {
            $stmtBackupLineup = $db->prepare("
                SELECT agentKey as agent, status, probHome as home, probDraw as draw, probAway as away, weightApplied, payloadJson 
                FROM `ForecastAgentPrediction` 
                WHERE matchId = ? AND agentKey = 'lineup_impact' AND status = 'ok' 
                ORDER BY runId DESC LIMIT 1
            ");
            $stmtBackupLineup->execute([$matchId]);
            $backupLineup = $stmtBackupLineup->fetch();
            if ($backupLineup) {
                $backupLineup['probabilities'] = [
                    'home' => $backupLineup['home'] !== null ? floatval($backupLineup['home']) : null,
                    'draw' => $backupLineup['draw'] !== null ? floatval($backupLineup['draw']) : null,
                    'away' => $backupLineup['away'] !== null ? floatval($backupLineup['away']) : null
                ];
                // Remover el lineup_impact pendiente/anterior
                $agents = array_filter($agents, function($a) {
                    return $a['agent'] !== 'lineup_impact';
                });
                $agents[] = $backupLineup;
                $agents = array_values($agents);
            }
        }
        
        // Obtener los marcadores probables
        $stmtScores = $db->prepare("
            SELECT rankOrder as rank, CONCAT(scoreA, '-', scoreB) as score, scoreA, scoreB, probability, source, sampleSize 
            FROM `ForecastScoreOption` 
            WHERE matchId = ? AND runId = ?
            ORDER BY rankOrder ASC
        ");
        $stmtScores->execute([$matchId, $runId]);
        $mostLikelyScores = $stmtScores->fetchAll();
        
        // Determinar source
        $sourceVal = 'database_consensus';
        if (!empty($mostLikelyScores) && isset($mostLikelyScores[0]['source'])) {
            $sourceVal = $mostLikelyScores[0]['source'];
        }
        
        // Formatear scoreReview si existe
        $scoreReview = null;
        if (!empty($consensus['diagnosticsJson'])) {
            $diag = json_decode($consensus['diagnosticsJson'], true);
            if (isset($diag['scoreReview'])) {
                $scoreReview = $diag['scoreReview'];
            }
        }
        
        $predictedAt = date('d/m H:i', strtotime($consensus['createdAt']));
        
        $logData['success'] = true;
        $logData['run_id'] = $runId;
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
        
        echo json_encode([
            'match_id' => $matchId,
            'run_id' => $runId,
            'source' => $sourceVal,
            'home_team' => $match['teamA'],
            'away_team' => $match['teamB'],
            'match_date' => str_replace(' ', 'T', $match['date']),
            'predicted_at' => $predictedAt,
            'prediction' => [
                'probabilities' => [
                    'home' => floatval($consensus['probHome']),
                    'draw' => floatval($consensus['probDraw']),
                    'away' => floatval($consensus['probAway'])
                ],
                'pick' => $consensus['pick'],
                'confidence' => $consensus['confidence'] ?? 'medium',
                'modelAgreement' => $consensus['modelAgreement'] ?? 'moderate',
                'scoreReview' => $scoreReview,
                'score_review' => $scoreReview
            ],
            'agents' => $agents,
            'most_likely_scores' => $mostLikelyScores,
            'scoreReview' => $scoreReview,
            'score_review' => $scoreReview,
            'is_mock' => false
        ]);
        exit;
    } else {
        $logData['error'] = 'Pronóstico no disponible para este partido';
        file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
        echo json_encode(['error' => 'Pronóstico no disponible para este partido']);
        exit;
    }
} catch (Exception $e) {
    $logData['error'] = 'Exception: ' . $e->getMessage();
    file_put_contents($logFile, json_encode($logData) . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos al obtener el pronóstico', 'details' => $e->getMessage()]);
    exit;
}
