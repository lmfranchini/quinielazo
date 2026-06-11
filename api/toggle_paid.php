<?php
ob_start();
require_once '../config.php';
requireAdmin();

$data = json_decode(file_get_contents('php://input'), true);
$userId  = (int)($data['userId'] ?? 0);
$hasPaid = (int)($data['hasPaid'] ?? 0);

if ($userId <= 0) {
    if (ob_get_length()) {
        @ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Usuario inválido']);
    exit;
}

try {
    $db = getDB();

    $stmt = $db->prepare("UPDATE `User` SET hasPaid = ?, updatedAt = NOW() WHERE id = ?");
    $stmt->execute([$hasPaid, $userId]);

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
