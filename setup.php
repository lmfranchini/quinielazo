<?php
/**
 * Script de instalación – Ejecutar UNA SOLA VEZ desde SSH o
 * temporalmente habilitarlo en .htaccess.
 * 
 * Crea las tablas y datos iniciales en la base de datos.
 * 
 * IMPORTANTE: Después de ejecutar, ELIMINA este archivo del servidor
 * o asegúrate de que .htaccess lo bloquee.
 */

require_once 'config.php';

echo "<pre style='font-family:monospace; background:#111; color:#0f8; padding:2rem'>";
echo "═══════════════════════════════════════\n";
echo "  Quiniela Mundial 2026 – Instalación\n";
echo "═══════════════════════════════════════\n\n";

try {
    $db = getDB();
    echo "✅ Conexión a la base de datos exitosa.\n\n";

    // Leer y ejecutar el esquema SQL
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    
    // Separar por punto y coma para ejecutar cada statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($s) {
            return !empty($s) && substr($s, 0, 2) !== '--';
        }
    );

    $count = 0;
    foreach ($statements as $stmt) {
        if (empty(trim($stmt))) continue;
        // Saltar comentarios puros
        $clean = preg_replace('/--.*$/m', '', $stmt);
        $clean = trim($clean);
        if (empty($clean)) continue;
        
        try {
            $db->exec($stmt);
            $count++;
            // Determinar qué se hizo
            if (stripos($stmt, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE.*?`(\w+)`/i', $stmt, $m);
                echo "📋 Tabla '{$m[1]}' creada.\n";
            } elseif (stripos($stmt, 'INSERT INTO') !== false) {
                preg_match('/INSERT INTO.*?`(\w+)`/i', $stmt, $m);
                $rowCount = $db->query("SELECT ROW_COUNT()")->fetchColumn();
                echo "📥 Datos insertados en '{$m[1]}' ($rowCount filas).\n";
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '42S01') {
                // Table already exists
                echo "ℹ️  Tabla ya existía, saltando...\n";
            } else {
                echo "⚠️  " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n── Total de operaciones: $count ──\n";

    // Migración de base de datos para la versión en vivo
    echo "\n🔄 Verificando y agregando columnas en vivo en 'Match'...\n";
    $liveColumns = array(
        "status" => "ALTER TABLE `Match` ADD COLUMN `status` VARCHAR(20) DEFAULT 'SCHEDULED'",
        "matchMinute" => "ALTER TABLE `Match` ADD COLUMN `matchMinute` VARCHAR(10) DEFAULT NULL",
        "externalId" => "ALTER TABLE `Match` ADD COLUMN `externalId` INT DEFAULT NULL",
        "lastApiUpdate" => "ALTER TABLE `Match` ADD COLUMN `lastApiUpdate` DATETIME DEFAULT NULL",
        "scorersData" => "ALTER TABLE `Match` ADD COLUMN `scorersData` TEXT DEFAULT NULL",
        "cardsData" => "ALTER TABLE `Match` ADD COLUMN `cardsData` TEXT DEFAULT NULL"
    );
    foreach ($liveColumns as $col => $sqlCmd) {
        try {
            $check = $db->query("SHOW COLUMNS FROM `Match` LIKE '$col'")->fetch();
            if (!$check) {
                $db->exec($sqlCmd);
                echo "   ➕ Columna '$col' agregada a 'Match'.\n";
            } else {
                echo "   ✓ Columna '$col' ya existe.\n";
            }
        } catch (PDOException $ex) {
            echo "   ⚠️ No se pudo verificar/agregar '$col': " . $ex->getMessage() . "\n";
        }
    }

    echo "\n🔄 Verificando y agregando columnas en vivo en 'User'...\n";
    try {
        $check = $db->query("SHOW COLUMNS FROM `User` LIKE 'hasPaid'")->fetch();
        if (!$check) {
            $db->exec("ALTER TABLE `User` ADD COLUMN `hasPaid` TINYINT(1) NOT NULL DEFAULT 0");
            echo "   ➕ Columna 'hasPaid' agregada a 'User'.\n";
        } else {
            echo "   ✓ Columna 'hasPaid' ya existe.\n";
        }
    } catch (PDOException $ex) {
        echo "   ⚠️ No se pudo verificar/agregar 'hasPaid' en 'User': " . $ex->getMessage() . "\n";
    }

    // Verificar tablas
    echo "\n📊 Verificando tablas...\n";
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $rows = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "   → $t: $rows registros\n";
    }

    echo "\n✅ Instalación completa.\n";
    echo "\n⚠️  IMPORTANTE: Elimina este archivo (setup.php) del servidor.\n";
    echo "   O accede por SSH: rm setup.php\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
