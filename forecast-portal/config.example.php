<?php
// Configuración de sesión persistente (1 año)
$session_lifetime = 31536000;
ini_set('session.cookie_lifetime', $session_lifetime);
ini_set('session.gc_maxlifetime', $session_lifetime);

// Directorio de sesiones local en forecast-portal para aislamiento
$session_dir = __DIR__ . '/sessions';
if (!is_dir($session_dir)) {
    @mkdir($session_dir, 0700, true);
    @file_put_contents($session_dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
}
if (is_writable($session_dir)) {
    ini_set('session.save_path', $session_dir);
}

// Configurar parámetros de la cookie de sesión
if (function_exists('session_set_cookie_params')) {
    session_set_cookie_params($session_lifetime, '/');
}

session_start();

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'tu_base_datos');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');
define('REGISTRATION_CONTACT_WHATSAPP', '5525326749');

// Url del microservicio del pronosticador local
define('FORECAST_SERVICE_URL', 'http://127.0.0.1:8011');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function currentUser() {
    if (!isset($_SESSION['user_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM `User` WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireLogin() {
    $user = currentUser();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function requireAdmin() {
    $user = requireLogin();
    if ($user['role'] !== 'ADMIN') {
        header('Location: index.php');
        exit;
    }
    return $user;
}

function getFlagUrl($team) {
    $map = [
        'México' => 'mx', 'Sudáfrica' => 'za', 'República de Corea' => 'kr',
        'República Checa' => 'cz', 'Chequia' => 'cz', 'Canadá' => 'ca',
        'Bosnia y Herzegovina' => 'ba', 'Catar' => 'qa', 'Suiza' => 'ch',
        'Brasil' => 'br', 'Marruecos' => 'ma', 'Haití' => 'ht', 'Escocia' => 'gb-sct',
        'Estados Unidos' => 'us', 'EE. UU.' => 'us', 'Paraguay' => 'py',
        'Australia' => 'au', 'Turquía' => 'tr', 'Alemania' => 'de',
        'Curazao' => 'cw', 'Costa de Marfil' => 'ci', 'Ecuador' => 'ec',
        'Países Bajos' => 'nl', 'Japón' => 'jp', 'Suecia' => 'se', 'Túnez' => 'tn',
        'Bélgica' => 'be', 'Egipto' => 'eg', 'RI de Irán' => 'ir', 'Irán' => 'ir',
        'Nueva Zelanda' => 'nz', 'España' => 'es', 'Cabo Verde' => 'cv',
        'Islas de Cabo Verde' => 'cv', 'Arabia Saudí' => 'sa', 'Uruguay' => 'uy',
        'Francia' => 'fr', 'Senegal' => 'sn', 'Irak' => 'iq', 'Noruega' => 'no',
        'Argentina' => 'ar', 'Argelia' => 'dz', 'Austria' => 'at', 'Jordania' => 'jo',
        'Portugal' => 'pt', 'RD Congo' => 'cd', 'RD de Congo' => 'cd',
        'Uzbekistán' => 'uz', 'Colombia' => 'co', 'Inglaterra' => 'gb-eng',
        'Croacia' => 'hr', 'Ghana' => 'gh', 'Panamá' => 'pa',
    ];
    $code = $map[$team] ?? null;
    return $code ? "https://flagcdn.com/w80/{$code}.png" : '';
}

// Convertir UTC de la base de datos a Zona CDMX
function formatMatchDay($utcDateStr) {
    try {
        $dt = new DateTime($utcDateStr, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
        
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $days = [
            0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles',
            4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'
        ];
        
        $w = $days[(int)$dt->format('w')];
        $d = $dt->format('j');
        $m = $months[(int)$dt->format('n')];
        
        return "$w $d de $m";
    } catch (Exception $e) {
        return $utcDateStr;
    }
}

function formatMatchTime($utcDateStr) {
    try {
        $dt = new DateTime($utcDateStr, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
        return $dt->format('H:i') . ' hrs';
    } catch (Exception $e) {
        return '';
    }
}
