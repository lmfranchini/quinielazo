<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

function fetchContextAgentFromN8N($matchId) {
    $url = 'https://n8n.mantisa.com.mx/webhook/quiniela/match-probabilities';
    $payload = json_encode(array('match_id' => intval($matchId)));
    
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }
    }
    return null;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
if ($matchId <= 0) {
    echo json_encode(['error' => 'ID de partido inválido']);
    exit;
}

$url = FORECAST_SERVICE_URL . "/forecast/match/" . $matchId;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Si la API responde correctamente con datos válidos
if ($response !== false && $httpCode === 200) {
    $data = json_decode($response, true);
    error_log(json_encode($data));
    error_log('UPSTREAM_KEYS=' . json_encode(array_keys($data ?? [])));
    error_log('UPSTREAM_PREDICTION_KEYS=' . json_encode(array_keys($data['prediction'] ?? [])));
    error_log('UPSTREAM_SCORE_REVIEW=' . json_encode(
        $data['scoreReview']
        ?? $data['score_review']
        ?? ($data['prediction']['scoreReview'] ?? null)
        ?? ($data['prediction']['score_review'] ?? null)
    ));
    if ($data && !isset($data['error'])) {
        $microserviceScoreReview = $data['scoreReview']
            ?? $data['score_review']
            ?? ($data['prediction']['scoreReview'] ?? null)
            ?? ($data['prediction']['score_review'] ?? null)
            ?? ($data['data']['scoreReview'] ?? null)
            ?? ($data['data']['score_review'] ?? null)
            ?? ($data['data']['prediction']['scoreReview'] ?? null)
            ?? ($data['data']['prediction']['score_review'] ?? null);

        $root = $data;

        if (isset($data['data']) && is_array($data['data'])) {
            $root = $data['data'];
        } elseif (isset($data['forecast']) && is_array($data['forecast'])) {
            $root = $data['forecast'];
        } elseif (isset($data['result']) && is_array($data['result'])) {
            $root = $data['result'];
        }

        $scoreKeys = [
            'most_likely_scores',
            'mostLikelyScores',
            'scores',
            'score_options',
            'scoreOptions',
            'top_scores',
            'topScores'
        ];

        $scores = [];

        foreach ($scoreKeys as $key) {
            if (isset($root[$key]) && is_array($root[$key]) && count($root[$key]) > 0) {
                $scores = $root[$key];
                break;
            }
        }

        if (empty($scores) && isset($root['prediction']) && is_array($root['prediction'])) {
            foreach ($scoreKeys as $key) {
                if (isset($root['prediction'][$key]) && is_array($root['prediction'][$key]) && count($root['prediction'][$key]) > 0) {
                    $scores = $root['prediction'][$key];
                    break;
                }
            }
        }

        if ($root !== $data) {
            foreach ($root as $k => $v) {
                if (!isset($data[$k])) {
                    $data[$k] = $v;
                }
            }
        }
        $data['most_likely_scores'] = $scores;
        $data['mostLikelyScores'] = $scores;
        $data['scores'] = $scores;

        if (!isset($data['prediction']) || !is_array($data['prediction'])) {
            $data['prediction'] = [];
        }

        $data['prediction']['most_likely_scores'] = $scores;
        $data['prediction']['mostLikelyScores'] = $scores;
        $data['prediction']['scores'] = $scores;

        // Normalizar scoreReview desde el microservicio
        $reviewFound = null;
        if (isset($root['scoreReview'])) {
            $reviewFound = $root['scoreReview'];
        } elseif (isset($root['score_review'])) {
            $reviewFound = $root['score_review'];
        } elseif (isset($root['prediction']['scoreReview'])) {
            $reviewFound = $root['prediction']['scoreReview'];
        } elseif (isset($root['prediction']['score_review'])) {
            $reviewFound = $root['prediction']['score_review'];
        }
        if ($reviewFound !== null) {
            $data['scoreReview'] = $reviewFound;
            $data['score_review'] = $reviewFound;
            $data['prediction']['scoreReview'] = $reviewFound;
            $data['prediction']['score_review'] = $reviewFound;
        }

        // No mezclar ni sobreescribir con datos de /probabilities de N8N
        // Construir la estructura prediction.probabilities esperada por el frontend
        if (!isset($data['prediction']) || !is_array($data['prediction']) || empty($data['prediction'])) {
            $data['prediction'] = [
                'probabilities' => [
                    'home' => isset($data['probHome']) ? floatval($data['probHome']) : null,
                    'draw' => isset($data['probDraw']) ? floatval($data['probDraw']) : null,
                    'away' => isset($data['probAway']) ? floatval($data['probAway']) : null
                ],
                'pick' => $data['pick'] ?? null,
                'confidence' => $data['confidence'] ?? null,
                'modelAgreement' => $data['modelAgreement'] ?? null
            ];
        }

        // Obtener listado de agentes directamente de la base de datos (fuente única de verdad para el desglose)
        if (!isset($data['agents']) || !is_array($data['agents']) || count($data['agents']) === 0) {
            try {
                $db = getDB();
                $runIdVal = $data['runId'] ?? $data['run_id'] ?? null;
                if ($runIdVal) {
                    $stmtAgents = $db->prepare("
                        SELECT agentKey as agent, status, probHome as home, probDraw as draw, probAway as away, weightApplied, payloadJson 
                        FROM `ForecastAgentPrediction` 
                        WHERE matchId = ? AND runId = ?
                    ");
                    $stmtAgents->execute([$matchId, $runIdVal]);
                    $dbAgents = $stmtAgents->fetchAll();
                    
                    $data['agents'] = [];
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
                        $data['agents'][] = $da;
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
                            $data['agents'] = array_filter($data['agents'], function($a) {
                                return $a['agent'] !== 'lineup_impact';
                            });
                            $data['agents'][] = $backupLineup;
                            $data['agents'] = array_values($data['agents']);
                        }
                    }
                }
            } catch (Exception $eAgents) {
                // Ignorar
            }
        }

        if (isset($data['createdAt']) && !isset($data['predicted_at'])) {
            $data['predicted_at'] = date('d/m H:i', strtotime($data['createdAt']));
        }
        if (isset($data['runId']) && !isset($data['run_id'])) {
            $data['run_id'] = $data['runId'];
        }
        $finalResponse = &$data;
        $scoreReview = $data['scoreReview']
            ?? $data['score_review']
            ?? ($data['prediction']['scoreReview'] ?? null)
            ?? ($data['prediction']['score_review'] ?? null)
            ?? $microserviceScoreReview
            ?? null;

        $finalResponse['scoreReview'] = $scoreReview;
        $finalResponse['score_review'] = $scoreReview;

        if (!isset($finalResponse['prediction']) || !is_array($finalResponse['prediction'])) {
            $finalResponse['prediction'] = [];
        }

        $finalResponse['prediction']['scoreReview'] = $scoreReview;
        $finalResponse['prediction']['score_review'] = $scoreReview;

        error_log('SCORE_REVIEW_FINAL=' . json_encode($scoreReview));

        echo json_encode($data);
        exit;
    }
}

// Fallback: Generación de Mock dinámico si el servicio no está disponible o el partido es futuro
// Esto permite probar el portal en entornos de desarrollo local de forma totalmente funcional.
$db = getDB();
$stmt = $db->prepare("SELECT id, teamA, teamB, date FROM `Match` WHERE id = ?");
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    echo json_encode(['error' => 'Partido no encontrado']);
    exit;
}

// Intentar obtener el pronóstico real de la base de datos local
$stmtConsensus = $db->prepare("SELECT * FROM `ForecastConsensus` WHERE matchId = ? ORDER BY runId DESC LIMIT 1");
$stmtConsensus->execute([$matchId]);
$consensus = $stmtConsensus->fetch();

if ($consensus) {
    // Si encontramos un pronóstico real en la base de datos, lo cargamos
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
    
    // Obtener los marcadores
    $stmtScores = $db->prepare("
        SELECT rankOrder as rank, CONCAT(scoreA, '-', scoreB) as score, scoreA, scoreB, probability, source, sampleSize 
        FROM `ForecastScoreOption` 
        WHERE matchId = ? AND runId = ?
        ORDER BY rankOrder ASC
    ");
    $stmtScores->execute([$matchId, $runId]);
    $mostLikelyScores = $stmtScores->fetchAll();
    
    // Formatear scoreReview (si existe en los diagnósticos o metadatos)
    $scoreReview = null;
    if (!empty($consensus['diagnosticsJson'])) {
        $diag = json_decode($consensus['diagnosticsJson'], true);
        if (isset($diag['scoreReview'])) {
            $scoreReview = $diag['scoreReview'];
        }
    }
    
    $predictedAt = date('d/m H:i', strtotime($consensus['createdAt']));
    
    $sourceVal = 'database_consensus';
    if (!empty($mostLikelyScores) && isset($mostLikelyScores[0]['source'])) {
        $sourceVal = $mostLikelyScores[0]['source'];
    }
    
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
}

// Si no hay datos en el microservicio ni en la BD, retornar error
echo json_encode(['error' => 'Pronóstico no disponible para este partido']);
exit;
