<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 94;

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
        foreach ($dbAgents as $da) {
            $da['probabilities'] = [
                'home' => $da['home'] !== null ? floatval($da['home']) : null,
                'draw' => $da['draw'] !== null ? floatval($da['draw']) : null,
                'away' => $da['away'] !== null ? floatval($da['away']) : null
            ];
            $agents[] = $da;
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
        echo json_encode(['error' => 'Pronóstico no disponible para este partido']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos al obtener el pronóstico', 'details' => $e->getMessage()]);
    exit;
}
