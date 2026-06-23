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

    // Determinar el ganador (winner) para partidos de fase final (IDs >= 148)
    $winner = null;
    if ($matchId >= 148) {
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

    // Calcular puntos para cada pronóstico de este partido
    $preds = $db->prepare("SELECT * FROM `Prediction` WHERE matchId = ?");
    $preds->execute([$matchId]);
    $predictions = $preds->fetchAll();

    $resultOutcome = ($scoreA > $scoreB) ? 'A' : (($scoreB > $scoreA) ? 'B' : 'DRAW');

    foreach ($predictions as $pred) {
        $pts = calculatePoints((int)$pred['scoreA'], (int)$pred['scoreB'], $scoreA, $scoreB);

        // Actualizar puntos del pronóstico
        $db->prepare("UPDATE `Prediction` SET points = ?, updatedAt = NOW() WHERE id = ?")
           ->execute([$pts, $pred['id']]);

        // Acumular puntos al usuario
        if ($pts > 0) {
            $db->prepare("UPDATE `User` SET points = points + ?, updatedAt = NOW() WHERE id = ?")
               ->execute([$pts, $pred['userId']]);
        }
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
