<?php
require_once __DIR__ . '/../config.php';

// Solo permitir ejecución en consola CLI o si es administrador autenticado
if (php_sapi_name() !== 'cli') {
    $user = currentUser();
    if (!$user || $user['role'] !== 'ADMIN') {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
}

$db = getDB();

// 1. Agregar columna winner si no existe
try {
    $db->exec("ALTER TABLE `Match` ADD COLUMN `winner` VARCHAR(100) DEFAULT NULL");
    echo "Columna 'winner' agregada o verificada con éxito.\n";
} catch (PDOException $e) {
    // Si ya existe, MySQL podría tirar error o no, capturamos por si acaso
    echo "Nota de columna 'winner': " . $e->getMessage() . "\n";
}

// 2. Insertar partidos de Fase Final si no están insertados
$count = $db->query("SELECT COUNT(*) FROM `Match` WHERE id >= 73")->fetchColumn();
if ($count > 0) {
    echo "Los partidos de la Fase Final ya existen en la base de datos (se encontraron $count partidos).\n";
} else {
    $matches = [
        // R32 (16vos)
        [73, '2A', '2B', '2026-06-28 17:00:00', 'Los Angeles Stadium'],
        [74, '1E', '3A/B/C/D/F', '2026-06-29 18:00:00', 'Boston Stadium'],
        [75, '1F', '2C', '2026-06-29 20:00:00', 'Estadio Monterrey'],
        [76, '1C', '2F', '2026-06-29 22:00:00', 'Houston Stadium'],
        [77, '1I', '3C/D/F/G/H', '2026-06-30 17:00:00', 'New York New Jersey Stadium'],
        [78, '2E', '2I', '2026-06-30 19:00:00', 'Dallas Stadium'],
        [79, '1A', '3C/E/F/H/I', '2026-06-30 21:00:00', 'Estadio Ciudad de México'],
        [80, '1L', '3E/H/I/J/K', '2026-07-01 17:00:00', 'Atlanta Stadium'],
        [81, '1D', '3B/E/F/I/J', '2026-07-02 18:00:00', 'San Francisco Bay Area Stadium'],
        [82, '1G', '3A/E/H/I/J', '2026-07-01 20:00:00', 'Seattle Stadium'],
        [83, '2K', '2L', '2026-07-02 20:00:00', 'Toronto Stadium'],
        [84, '1H', '2J', '2026-07-02 22:00:00', 'Los Angeles Stadium'],
        [85, '1B', '3E/F/G/I/J', '2026-07-02 17:00:00', 'BC Place Vancouver'],
        [86, '1J', '2H', '2026-07-03 18:00:00', 'Miami Stadium'],
        [87, '1K', '3D/E/I/J/L', '2026-07-03 20:00:00', 'Kansas City Stadium'],
        [88, '2D', '2G', '2026-07-03 22:00:00', 'Dallas Stadium'],

        // R16 (8vos)
        [89, 'Ganador 73', 'Ganador 75', '2026-07-04 17:00:00', 'Philadelphia Stadium'],
        [90, 'Ganador 74', 'Ganador 78', '2026-07-04 21:00:00', 'Houston Stadium'],
        [91, 'Ganador 76', 'Ganador 77', '2026-07-05 17:00:00', 'New York New Jersey Stadium'],
        [92, 'Ganador 79', 'Ganador 80', '2026-07-05 21:00:00', 'Estadio Ciudad de México'],
        [93, 'Ganador 84', 'Ganador 83', '2026-07-06 18:00:00', 'Dallas Stadium'],
        [94, 'Ganador 82', 'Ganador 81', '2026-07-06 21:00:00', 'Seattle Stadium'],
        [95, 'Ganador 88', 'Ganador 87', '2026-07-07 18:00:00', 'Atlanta Stadium'],
        [96, 'Ganador 85', 'Ganador 86', '2026-07-07 21:00:00', 'BC Place Vancouver'],

        // QF (4tos)
        [97, 'Ganador 89', 'Ganador 90', '2026-07-09 18:00:00', 'Boston Stadium'],
        [98, 'Ganador 93', 'Ganador 94', '2026-07-10 20:00:00', 'Los Angeles Stadium'],
        [99, 'Ganador 91', 'Ganador 92', '2026-07-11 18:00:00', 'Miami Stadium'],
        [100, 'Ganador 95', 'Ganador 96', '2026-07-11 21:00:00', 'Kansas City Stadium'],

        // SF
        [101, 'Ganador 97', 'Ganador 98', '2026-07-14 20:00:00', 'Dallas Stadium'],
        [102, 'Ganador 99', 'Ganador 100', '2026-07-15 20:00:00', 'Atlanta Stadium'],

        // 3rd Place
        [103, 'Perdedor 101', 'Perdedor 102', '2026-07-18 19:00:00', 'Miami Stadium'],

        // Final
        [104, 'Ganador 101', 'Ganador 102', '2026-07-19 19:00:00', 'New York New Jersey Stadium'],
    ];

    $stmt = $db->prepare("INSERT INTO `Match` (id, teamA, teamB, date, venue, status) VALUES (?, ?, ?, ?, ?, 'SCHEDULED')");
    $db->beginTransaction();
    foreach ($matches as $m) {
        $stmt->execute($m);
    }
    $db->commit();
    echo "Insertados 32 partidos de la Fase Final con éxito.\n";
}
