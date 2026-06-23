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
$count = $db->query("SELECT COUNT(*) FROM `Match` WHERE id >= 148")->fetchColumn();
if ($count > 0) {
    echo "Los partidos de la Fase Final ya existen en la base de datos (se encontraron $count partidos).\n";
} else {
    $matches = [
        // R32 (16vos)
        [148, '2A', '2B', '2026-06-28 17:00:00', 'Los Angeles Stadium'],
        [149, '1E', '3A/B/C/D/F', '2026-06-29 18:00:00', 'Boston Stadium'],
        [150, '1F', '2C', '2026-06-29 20:00:00', 'Estadio Monterrey'],
        [151, '1C', '2F', '2026-06-29 22:00:00', 'Houston Stadium'],
        [152, '1I', '3C/D/F/G/H', '2026-06-30 17:00:00', 'New York New Jersey Stadium'],
        [153, '2E', '2I', '2026-06-30 19:00:00', 'Dallas Stadium'],
        [154, '1A', '3C/E/F/H/I', '2026-06-30 21:00:00', 'Estadio Ciudad de México'],
        [155, '1L', '3E/H/I/J/K', '2026-07-01 17:00:00', 'Atlanta Stadium'],
        [156, '1D', '3B/E/F/I/J', '2026-07-02 18:00:00', 'San Francisco Bay Area Stadium'],
        [157, '1G', '3A/E/H/I/J', '2026-07-01 20:00:00', 'Seattle Stadium'],
        [158, '2K', '2L', '2026-07-02 20:00:00', 'Toronto Stadium'],
        [159, '1H', '2J', '2026-07-02 22:00:00', 'Los Angeles Stadium'],
        [160, '1B', '3E/F/G/I/J', '2026-07-02 17:00:00', 'BC Place Vancouver'],
        [161, '1J', '2H', '2026-07-03 18:00:00', 'Miami Stadium'],
        [162, '1K', '3D/E/I/J/L', '2026-07-03 20:00:00', 'Kansas City Stadium'],
        [163, '2D', '2G', '2026-07-03 22:00:00', 'Dallas Stadium'],

        // R16 (8vos)
        [164, 'Ganador 148', 'Ganador 150', '2026-07-04 17:00:00', 'Philadelphia Stadium'],
        [165, 'Ganador 149', 'Ganador 153', '2026-07-04 21:00:00', 'Houston Stadium'],
        [166, 'Ganador 151', 'Ganador 152', '2026-07-05 17:00:00', 'New York New Jersey Stadium'],
        [167, 'Ganador 154', 'Ganador 155', '2026-07-05 21:00:00', 'Estadio Ciudad de México'],
        [168, 'Ganador 159', 'Ganador 158', '2026-07-06 18:00:00', 'Dallas Stadium'],
        [169, 'Ganador 157', 'Ganador 156', '2026-07-06 21:00:00', 'Seattle Stadium'],
        [170, 'Ganador 163', 'Ganador 162', '2026-07-07 18:00:00', 'Atlanta Stadium'],
        [171, 'Ganador 160', 'Ganador 161', '2026-07-07 21:00:00', 'BC Place Vancouver'],

        // QF (4tos)
        [172, 'Ganador 164', 'Ganador 165', '2026-07-09 18:00:00', 'Boston Stadium'],
        [173, 'Ganador 168', 'Ganador 169', '2026-07-10 20:00:00', 'Los Angeles Stadium'],
        [174, 'Ganador 166', 'Ganador 167', '2026-07-11 18:00:00', 'Miami Stadium'],
        [175, 'Ganador 170', 'Ganador 171', '2026-07-11 21:00:00', 'Kansas City Stadium'],

        // SF
        [176, 'Ganador 172', 'Ganador 173', '2026-07-14 20:00:00', 'Dallas Stadium'],
        [177, 'Ganador 174', 'Ganador 175', '2026-07-15 20:00:00', 'Atlanta Stadium'],

        // 3rd Place
        [178, 'Perdedor 176', 'Perdedor 177', '2026-07-18 19:00:00', 'Miami Stadium'],

        // Final
        [179, 'Ganador 176', 'Ganador 177', '2026-07-19 19:00:00', 'New York New Jersey Stadium'],
    ];

    $stmt = $db->prepare("INSERT INTO `Match` (id, teamA, teamB, date, venue, status) VALUES (?, ?, ?, ?, ?, 'SCHEDULED')");
    $db->beginTransaction();
    foreach ($matches as $m) {
        $stmt->execute($m);
    }
    $db->commit();
    echo "Insertados 32 partidos de la Fase Final con éxito.\n";
}
