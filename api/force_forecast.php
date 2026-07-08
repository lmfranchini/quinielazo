<?php
/**
 * force_forecast.php
 * 
 * Script administrativo para forzar la predicción de partidos específicos llamando 
 * al microservicio local de python y guardando las probabilidades directamente en la base de datos.
 * Bypassa N8N para resolver el problema de probabilidades vacías/stuck.
 * 
 * Uso: GET /api/force_forecast.php?token=qm26_probs_r3fr3sh_s3cr3t&id=77 (o sin id para procesar todos los pendientes)
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config.php';

// Token de seguridad
define('CRON_TOKEN', 'qm26_probs_r3fr3sh_s3cr3t');
$token = $_GET['token'] ?? '';
if ($token !== CRON_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$db = getDB();
$matchId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$matches = [];
if ($matchId > 0) {
    $stmt = $db->prepare("SELECT id, teamA, teamB, date FROM `Match` WHERE id = ?");
    $stmt->execute([$matchId]);
    $matches = $stmt->fetchAll();
} else {
    // Obtener todos los partidos de Fase Final pendientes con equipos reales (no placeholders)
    $stmt = $db->query("SELECT id, teamA, teamB, date FROM `Match` WHERE id >= 73 AND isFinished = 0 AND status = 'SCHEDULED' ORDER BY date ASC");
    $allMatches = $stmt->fetchAll();
    foreach ($allMatches as $m) {
        $isPlaceholder = preg_match('/^[12][A-L]$/', $m['teamA']) || 
                         preg_match('/^(Ganador|Perdedor)\s+\d+$/i', $m['teamA']) || 
                         preg_match('/^3[A-L\/]+$/', $m['teamA']) ||
                         preg_match('/^[12][A-L]$/', $m['teamB']) || 
                         preg_match('/^(Ganador|Perdedor)\s+\d+$/i', $m['teamB']) || 
                         preg_match('/^3[A-L\/]+$/', $m['teamB']);
        if (!$isPlaceholder) {
            $matches[] = $m;
        }
    }
}

$results = [];
$updated = 0;
$failed = 0;

foreach ($matches as $m) {
    $url = "http://127.0.0.1:8011/forecast/match/" . $m['id'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $success = false;
    $probs = null;
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['prediction']['probabilities'])) {
            $probs = $data['prediction']['probabilities'];
            if (isset($probs['home'], $probs['draw'], $probs['away'])) {
                $home = floatval($probs['home']);
                $draw = floatval($probs['draw']);
                $away = floatval($probs['away']);
                
                // Actualizar DB
                $upd = $db->prepare("UPDATE `Match` SET probHome = ?, probDraw = ?, probAway = ?, probLastUpdate = NOW(), updatedAt = NOW() WHERE id = ?");
                $upd->execute([$home, $draw, $away, $m['id']]);
                
                $success = true;
                $updated++;
                $results[] = [
                    'match_id' => $m['id'],
                    'teams' => "{$m['teamA']} vs {$m['teamB']}",
                    'status' => 'success',
                    'probs' => ['L' => $home, 'E' => $draw, 'V' => $away]
                ];
            }
        }
    }
    
    if (!$success) {
        $failed++;
        $results[] = [
            'match_id' => $m['id'],
            'teams' => "{$m['teamA']} vs {$m['teamB']}",
            'status' => 'failed',
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'raw_response_preview' => substr($response ?? '', 0, 150)
        ];
    }
}

echo json_encode([
    'status' => 'ok',
    'updated_count' => $updated,
    'failed_count' => $failed,
    'results' => $results,
    'timestamp' => date('Y-m-d H:i:s')
]);
