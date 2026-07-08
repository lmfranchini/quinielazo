<?php
/**
 * refresh_probs.php
 * 
 * Endpoint dedicado para actualizar probabilidades de triunfo de partidos pendientes.
 * Diseñado para ser invocado por un cron job 4 veces al día.
 * 
 * Protegido con token secreto para evitar abuso.
 * Uso: GET /api/refresh_probs.php?token=TU_TOKEN
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config.php';

// --- Token de seguridad ---
// Debe coincidir con el token configurado en el cron job de cPanel
define('CRON_TOKEN', 'qm26_probs_r3fr3sh_s3cr3t');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if ($token !== CRON_TOKEN) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado', 'hint' => 'Token inválido o ausente']);
    exit;
}

$startTime = microtime(true);
$results   = [];
$updated   = 0;
$failed    = 0;

try {
    $db = getDB();

    // Obtener todos los partidos futuros o del día actual sin probabilidades recientes
    // Límite de 16 para no saturar la API de N8N en cada corrida
    $stmt = $db->query("
        SELECT id, teamA, teamB, date
        FROM `Match`
        WHERE isFinished = 0
          AND status = 'SCHEDULED'
          AND date > NOW()
          AND (
            probLastUpdate IS NULL
            OR probLastUpdate < DATE_SUB(NOW(), INTERVAL 6 HOUR)
          )
        ORDER BY date ASC
        LIMIT 16
    ");
    $matches = $stmt->fetchAll();

    if (empty($matches)) {
        echo json_encode([
            'status'    => 'ok',
            'message'   => 'No hay partidos que requieran actualización en este momento.',
            'updated'   => 0,
            'failed'    => 0,
            'elapsed_s' => round(microtime(true) - $startTime, 2),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }

    foreach ($matches as $m) {
        $probData = fetchMatchProbabilitiesFromApi($m['id'], $m['teamA'], $m['teamB'], $m['date']);

        // Normalizar: N8N devuelve probabilidades dentro de prediction.probabilities
        $rawProbs = null;
        if ($probData) {
            if (isset($probData['probabilities']) && is_array($probData['probabilities'])) {
                $rawProbs = $probData['probabilities'];
            } elseif (isset($probData['prediction']['probabilities']) && is_array($probData['prediction']['probabilities'])) {
                $rawProbs = $probData['prediction']['probabilities'];
            }
        }

        if ($rawProbs && isset($rawProbs['home'], $rawProbs['draw'], $rawProbs['away'])) {
            $homeProb = floatval($rawProbs['home']);
            $drawProb = floatval($rawProbs['draw']);
            $awayProb = floatval($rawProbs['away']);

            $upd = $db->prepare("
                UPDATE `Match`
                SET probHome = ?, probDraw = ?, probAway = ?,
                    probLastUpdate = NOW(), updatedAt = NOW()
                WHERE id = ?
            ");
            $upd->execute([$homeProb, $drawProb, $awayProb, $m['id']]);

            $results[] = [
                'match_id' => (int)$m['id'],
                'match'    => "{$m['teamA']} vs {$m['teamB']}",
                'status'   => 'updated',
                'probs'    => ['home' => $homeProb, 'draw' => $drawProb, 'away' => $awayProb],
            ];
            $updated++;
        } else {
            // No actualizamos probLastUpdate para permitir reintentar de inmediato en la siguiente ejecucion
            $results[] = [
                'match_id' => (int)$m['id'],
                'match'    => "{$m['teamA']} vs {$m['teamB']}",
                'status'   => 'n8n_unavailable',
                'probs'    => null,
            ];
            $failed++;
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'     => 'Error interno: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    exit;
}

echo json_encode([
    'status'    => 'ok',
    'message'   => "Corrida completada: $updated actualizados, $failed sin datos.",
    'updated'   => $updated,
    'failed'    => $failed,
    'matches'   => $results,
    'elapsed_s' => round(microtime(true) - $startTime, 2),
    'timestamp' => date('Y-m-d H:i:s'),
]);
