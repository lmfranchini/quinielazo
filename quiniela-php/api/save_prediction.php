<?php
ob_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}
$user = currentUser();
if ($user && $user['role'] === 'ADMIN') {
    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Los administradores no juegan en el torneo']);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
$matchId = (int)($data['matchId'] ?? 0);
$scoreA  = (int)($data['scoreA'] ?? 0);
$scoreB  = (int)($data['scoreB'] ?? 0);
$userId  = (int)$_SESSION['user_id'];

if ($matchId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Partido inválido']);
    exit;
}

try {
    $db = getDB();

    // Verificar que el partido exista y no esté terminado
    $stmt = $db->prepare("SELECT * FROM `Match` WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        echo json_encode(['success' => false, 'error' => 'Partido no encontrado']);
        exit;
    }
    if ($match['isFinished']) {
        echo json_encode(['success' => false, 'error' => 'El partido ya terminó']);
        exit;
    }

    // Verificar cierre de pronósticos (1 hora antes)
    if (isLocked($match['date'])) {
        echo json_encode(['success' => false, 'error' => 'Los pronósticos están cerrados para este partido']);
        exit;
    }

    // Upsert del pronóstico
    $check = $db->prepare("SELECT id FROM `Prediction` WHERE userId = ? AND matchId = ?");
    $check->execute([$userId, $matchId]);
    $existing = $check->fetch();

    if ($existing) {
        $upd = $db->prepare("UPDATE `Prediction` SET scoreA = ?, scoreB = ?, updatedAt = NOW() WHERE userId = ? AND matchId = ?");
        $upd->execute([$scoreA, $scoreB, $userId, $matchId]);
    } else {
        $ins = $db->prepare("INSERT INTO `Prediction` (userId, matchId, scoreA, scoreB, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $ins->execute([$userId, $matchId, $scoreA, $scoreB]);
    }

    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
