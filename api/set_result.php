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

    // Actualizar resultado del partido
    $stmt = $db->prepare("UPDATE `Match` SET scoreA = ?, scoreB = ?, isFinished = 1, updatedAt = NOW() WHERE id = ?");
    $stmt->execute([$scoreA, $scoreB, $matchId]);

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
