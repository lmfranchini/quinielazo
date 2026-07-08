<?php
ob_start();
require_once '../config.php';
requireAdmin();

$data = json_decode(file_get_contents('php://input'), true);
$matchId = (int)($data['matchId'] ?? 0);
$scoreA  = (int)($data['scoreA'] ?? 0);
$scoreB  = (int)($data['scoreB'] ?? 0);

if ($matchId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Partido inválido']);
    exit;
}

try {
    $db = getDB();

    // Determinar el ganador (winner) para partidos de fase final (IDs >= 73)
    $winner = null;
    if ($matchId >= 73) {
        $stmtMatch = $db->prepare("SELECT teamA, teamB FROM `Match` WHERE id = ?");
        $stmtMatch->execute([$matchId]);
        $mInfo = $stmtMatch->fetch();
        if ($mInfo) {
            $flagA = ''; $flagB = '';
            $resolvedA = resolvePlaceholderTeam($mInfo['teamA'], $db, $flagA);
            $resolvedB = resolvePlaceholderTeam($mInfo['teamB'], $db, $flagB);
            
            if ($scoreA > $scoreB) {
                $winner = $resolvedA;
            } elseif ($scoreB > $scoreA) {
                $winner = $resolvedB;
            } else {
                // Empate en tiempo regular/extras: requiere que el admin envíe el ganador de los penaltis
                $winner = trim($data['winner'] ?? '');
                if ($winner !== $resolvedA && $winner !== $resolvedB) {
                    if (ob_get_length()) {
                        @ob_end_clean();
                    }
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => "Para empates en eliminación directa, debes especificar quién avanzó: '$resolvedA' o '$resolvedB'"]);
                    exit;
                }
            }
        }
    }

    // Actualizar resultado del partido (e incluir la columna winner)
    $stmt = $db->prepare("UPDATE `Match` SET scoreA = ?, scoreB = ?, winner = ?, isFinished = 1, updatedAt = NOW() WHERE id = ?");
    $stmt->execute([$scoreA, $scoreB, $winner, $matchId]);

    // Obtener nombres reales de los equipos para la función de puntuación
    $teamA = '';
    $teamB = '';
    if ($mInfo) {
        $flagA = ''; $flagB = '';
        $teamA = resolvePlaceholderTeam($mInfo['teamA'], $db, $flagA);
        $teamB = resolvePlaceholderTeam($mInfo['teamB'], $db, $flagB);
    }

    // Calcular puntos para cada pronóstico de este partido
    $preds = $db->prepare("SELECT * FROM `Prediction` WHERE matchId = ?");
    $preds->execute([$matchId]);
    $predictions = $preds->fetchAll();

    $resultOutcome = ($scoreA > $scoreB) ? 'A' : (($scoreB > $scoreA) ? 'B' : 'DRAW');

    foreach ($predictions as $pred) {
        $pts = calculatePoints((int)$pred['scoreA'], (int)$pred['scoreB'], $scoreA, $scoreB, $winner, $teamA, $teamB);

        // Actualizar puntos del pronóstico
        $db->prepare("UPDATE `Prediction` SET points = ?, updatedAt = NOW() WHERE id = ?")
           ->execute([$pts, $pred['id']]);

        // Acumular puntos al usuario
        if ($pts > 0) {
            $db->prepare("UPDATE `User` SET points = points + ?, updatedAt = NOW() WHERE id = ?")
               ->execute([$pts, $pred['userId']]);
        }
    }

    // Calcular puntos de forma paralela para la quiniela de fase final (ID >= 73)
    if ($matchId >= 73) {
        $predsFF = $db->prepare("SELECT * FROM `PredictionFaseFinal` WHERE matchId = ?");
        $predsFF->execute([$matchId]);
        $predictionsFF = $predsFF->fetchAll();

        foreach ($predictionsFF as $predFF) {
            $ptsFF = calculatePoints((int)$predFF['scoreA'], (int)$predFF['scoreB'], $scoreA, $scoreB, $winner, $teamA, $teamB);

            // Actualizar puntos del pronóstico alterno
            $db->prepare("UPDATE `PredictionFaseFinal` SET points = ?, updatedAt = NOW() WHERE id = ?")
               ->execute([$ptsFF, $predFF['id']]);
        }

        // Recalcular el puntaje total acumulado en pointsFaseFinal para todos los usuarios
        $db->exec("UPDATE `User` u SET pointsFaseFinal = (
            SELECT COALESCE(SUM(p.points), 0)
            FROM `PredictionFaseFinal` p
            INNER JOIN `Match` m ON p.matchId = m.id
            WHERE p.userId = u.id AND m.isFinished = 1
        )");
    }

    // Auto-recalcular los clasificados de las siguientes llaves en la base de datos
    try {
        resolveAndSaveAllPlaceholders($db);
    } catch (Exception $eResolve) {
        // Ignorar si falla para no interrumpir el flujo principal
    }

    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'predictions_updated' => count($predictions)]);
} catch (Exception $e) {
    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
