<?php
session_start();

define('DB_HOST', 'TU_HOST_DE_BD');
define('DB_PORT', '3306');
define('DB_NAME', 'TU_NOMBRE_DE_BD');
define('DB_USER', 'TU_USUARIO_DE_BD');
define('DB_PASS', 'TU_CONTRASENA_DE_BD');

// ── ESPN API pública (NO necesita registro ni API key) ──
define('ESPN_BASE', 'https://site.api.espn.com/apis/site/v2/sports/soccer');
define('ESPN_LEAGUE', 'fifa.world'); // FIFA World Cup

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

/**
 * Mapeo de nombres de equipos: ESPN (inglés) → nombre español en nuestra DB
 */
function getTeamNameMap() {
    return [
        'Mexico'               => 'México',
        'South Africa'         => 'Sudáfrica',
        'Korea Republic'       => 'República de Corea',
        'South Korea'          => 'República de Corea',
        'Czech Republic'       => 'República Checa',
        'Czechia'              => 'República Checa',
        'Canada'               => 'Canadá',
        'Bosnia-Herzegovina'   => 'Bosnia y Herzegovina',
        'Bosnia and Herzegovina' => 'Bosnia y Herzegovina',
        'Qatar'                => 'Catar',
        'Switzerland'          => 'Suiza',
        'Brazil'               => 'Brasil',
        'Morocco'              => 'Marruecos',
        'Haiti'                => 'Haití',
        'Scotland'             => 'Escocia',
        'United States'        => 'Estados Unidos',
        'USA'                  => 'Estados Unidos',
        'Paraguay'             => 'Paraguay',
        'Australia'            => 'Australia',
        'Turkey'               => 'Turquía',
        'Türkiye'              => 'Turquía',
        'Germany'              => 'Alemania',
        'Curaçao'              => 'Curazao',
        'Curacao'              => 'Curazao',
        'Ivory Coast'          => 'Costa de Marfil',
        "Côte d'Ivoire"        => 'Costa de Marfil',
        'Ecuador'              => 'Ecuador',
        'Netherlands'          => 'Países Bajos',
        'Japan'                => 'Japón',
        'Sweden'               => 'Suecia',
        'Tunisia'              => 'Túnez',
        'Belgium'              => 'Bélgica',
        'Egypt'                => 'Egipto',
        'Iran'                 => 'Irán',
        'IR Iran'              => 'Irán',
        'New Zealand'          => 'Nueva Zelanda',
        'Spain'                => 'España',
        'Cape Verde'           => 'Cabo Verde',
        'Cape Verde Islands'   => 'Cabo Verde',
        'Saudi Arabia'         => 'Arabia Saudí',
        'Uruguay'              => 'Uruguay',
        'France'               => 'Francia',
        'Senegal'              => 'Senegal',
        'Iraq'                 => 'Irak',
        'Norway'               => 'Noruega',
        'Argentina'            => 'Argentina',
        'Algeria'              => 'Argelia',
        'Austria'              => 'Austria',
        'Jordan'               => 'Jordania',
        'Portugal'             => 'Portugal',
        'DR Congo'             => 'RD de Congo',
        'Congo DR'             => 'RD de Congo',
        'Dem. Rep. Congo'      => 'RD de Congo',
        'Uzbekistan'           => 'Uzbekistán',
        'Colombia'             => 'Colombia',
        'England'              => 'Inglaterra',
        'Croatia'              => 'Croacia',
        'Ghana'                => 'Ghana',
        'Panama'               => 'Panamá',
    ];
}

function isLocked($matchDate) {
    $matchTime = strtotime($matchDate);
    $lockTime = $matchTime - 3600;
    return time() > $lockTime;
}

function formatMatchTime($matchDate) {
    $dt = new DateTime($matchDate, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
    return $dt->format('g:i a');
}

function formatMatchDay($matchDate) {
    $dt = new DateTime($matchDate, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
    $days = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $months = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $days[(int)$dt->format('w')] . ', ' . $dt->format('j') . ' de ' . $months[(int)$dt->format('n')-1] . ' ' . $dt->format('Y');
}

/**
 * Calcula los puntos de un pronóstico contra un marcador dado.
 * 6 pts = marcador exacto, 3 pts = resultado correcto (ganador o empate), 0 = fallo
 */
function calculatePoints($predA, $predB, $realA, $realB) {
    if ($realA === null || $realB === null) return 0;
    if ($predA === $realA && $predB === $realB) return 6;
    $predResult = ($predA > $predB) ? 1 : (($predA < $predB) ? -1 : 0);
    $realResult = ($realA > $realB) ? 1 : (($realA < $realB) ? -1 : 0);
    if ($predResult === $realResult) return 3;
    return 0;
}

/**
 * Mapea un status de ESPN a nuestro status interno
 */
function mapEspnStatus($espnState, $espnName = '') {
    // ESPN state: 'pre', 'in', 'post'
    // ESPN name: STATUS_SCHEDULED, STATUS_IN_PROGRESS, STATUS_HALFTIME, STATUS_FINAL, etc.
    if ($espnState === 'post') return 'FINISHED';
    if ($espnState === 'in') {
        if (stripos($espnName, 'HALFTIME') !== false || stripos($espnName, 'HALF_TIME') !== false) {
            return 'HALFTIME';
        }
        return 'LIVE';
    }
    return 'SCHEDULED';
}

/**
 * Consulta la API de ESPN y devuelve los eventos del Mundial para una fecha.
 * NO necesita API key.
 */
function fetchEspnScoreboard($date = '') {
    if (empty($date)) $date = gmdate('Ymd');
    
    $url = ESPN_BASE . '/' . ESPN_LEAGUE . '/scoreboard?dates=' . $date;
    
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header'  => "User-Agent: Mozilla/5.0\r\n",
            ]
        ]);
        $response = @file_get_contents($url, false, $ctx);
    }
    
    if (!$response) return null;
    
    return json_decode($response, true);
}

/**
 * Calcula dinámicamente la tabla de posiciones de los grupos del Mundial.
 */
function getGroupStandings($db) {
    $groupsDefinition = array(
        'Grupo A' => array('México', 'Sudáfrica', 'República de Corea', 'República Checa'),
        'Grupo B' => array('Canadá', 'Bosnia y Herzegovina', 'Catar', 'Suiza'),
        'Grupo C' => array('Brasil', 'Marruecos', 'Haití', 'Escocia'),
        'Grupo D' => array('Estados Unidos', 'Paraguay', 'Australia', 'Turquía'),
        'Grupo E' => array('Alemania', 'Curazao', 'Costa de Marfil', 'Ecuador'),
        'Grupo F' => array('Países Bajos', 'Japón', 'Suecia', 'Túnez'),
        'Grupo G' => array('España', 'Cabo Verde', 'Arabia Saudí', 'Uruguay'),
        'Grupo H' => array('Bélgica', 'Egipto', 'Irán', 'Nueva Zelanda'),
        'Grupo I' => array('Argentina', 'Argelia', 'Austria', 'Jordania'),
        'Grupo J' => array('Francia', 'Senegal', 'Irak', 'Noruega'),
        'Grupo K' => array('Portugal', 'RD de Congo', 'Uzbekistán', 'Colombia'),
        'Grupo L' => array('Inglaterra', 'Croacia', 'Ghana', 'Panamá')
    );
    
    $standings = array();
    foreach ($groupsDefinition as $groupName => $teams) {
        $standings[$groupName] = array();
        $seedIndex = 0;
        foreach ($teams as $team) {
            $standings[$groupName][$team] = array(
                'name' => $team,
                'flag' => getFlagUrl($team),
                'pj'   => 0,
                'pg'   => 0,
                'pe'   => 0,
                'pp'   => 0,
                'gf'   => 0,
                'gc'   => 0,
                'pts'  => 0,
                'seed' => $seedIndex++
            );
        }
    }
    
    $matches = $db->query("SELECT teamA, teamB, scoreA, scoreB, status, isFinished FROM `Match`")->fetchAll();
    
    foreach ($matches as $m) {
        $teamA = $m['teamA'];
        $teamB = $m['teamB'];
        $scoreA = $m['scoreA'];
        $scoreB = $m['scoreB'];
        
        if ($scoreA !== null && $scoreB !== null) {
            $grpA = null;
            $grpB = null;
            
            foreach ($groupsDefinition as $groupName => $teams) {
                if (in_array($teamA, $teams)) {
                    $grpA = $groupName;
                }
                if (in_array($teamB, $teams)) {
                    $grpB = $groupName;
                }
            }
            
            if ($grpA && isset($standings[$grpA][$teamA])) {
                $standings[$grpA][$teamA]['pj']++;
                $standings[$grpA][$teamA]['gf'] += (int)$scoreA;
                $standings[$grpA][$teamA]['gc'] += (int)$scoreB;
            }
            if ($grpB && isset($standings[$grpB][$teamB])) {
                $standings[$grpB][$teamB]['pj']++;
                $standings[$grpB][$teamB]['gf'] += (int)$scoreB;
                $standings[$grpB][$teamB]['gc'] += (int)$scoreA;
            }
            
            if ((int)$scoreA > (int)$scoreB) {
                if ($grpA && isset($standings[$grpA][$teamA])) {
                    $standings[$grpA][$teamA]['pg']++;
                    $standings[$grpA][$teamA]['pts'] += 3;
                }
                if ($grpB && isset($standings[$grpB][$teamB])) {
                    $standings[$grpB][$teamB]['pp']++;
                }
            } elseif ((int)$scoreA < (int)$scoreB) {
                if ($grpA && isset($standings[$grpA][$teamA])) {
                    $standings[$grpA][$teamA]['pp']++;
                }
                if ($grpB && isset($standings[$grpB][$teamB])) {
                    $standings[$grpB][$teamB]['pg']++;
                    $standings[$grpB][$teamB]['pts'] += 3;
                }
            } else {
                if ($grpA && isset($standings[$grpA][$teamA])) {
                    $standings[$grpA][$teamA]['pe']++;
                    $standings[$grpA][$teamA]['pts'] += 1;
                }
                if ($grpB && isset($standings[$grpB][$teamB])) {
                    $standings[$grpB][$teamB]['pe']++;
                    $standings[$grpB][$teamB]['pts'] += 1;
                }
            }
        }
    }
    
    foreach ($standings as $groupName => &$groupTeams) {
        usort($groupTeams, function($a, $b) {
            if ($b['pts'] !== $a['pts']) {
                return $b['pts'] - $a['pts'];
            }
            $dgA = $a['gf'] - $a['gc'];
            $dgB = $b['gf'] - $b['gc'];
            if ($dgB !== $dgA) {
                return $dgB - $dgA;
            }
            if ($b['gf'] !== $a['gf']) {
                return $b['gf'] - $a['gf'];
            }
            // Fallback al orden original de siembra (cabeza de serie primero)
            return $a['seed'] - $b['seed'];
        });
    }
    unset($groupTeams);
    
    return $standings;
}

/**
 * Obtiene los máximos goleadores individuales analizando scorersData.
 */
function getTopScorers($db) {
    $matches = $db->query("SELECT scorersData FROM `Match` WHERE scorersData IS NOT NULL")->fetchAll();
    $scorers = array();
    
    foreach ($matches as $m) {
        $data = json_decode($m['scorersData'], true);
        if (!$data) continue;
        
        $allScorers = array_merge(
            isset($data['teamA']) ? $data['teamA'] : array(),
            isset($data['teamB']) ? $data['teamB'] : array()
        );
        
        foreach ($allScorers as $sc) {
            // No contar autogoles para la tabla de goleo individual
            if (stripos($sc, '(ag.)') !== false) {
                continue;
            }
            
            // Extraer nombre del jugador (ej. Lionel Messi 12' -> Lionel Messi)
            $playerName = preg_replace('/\s+\d+.*$/', '', $sc);
            $playerName = trim($playerName);
            if (empty($playerName)) continue;
            
            if (!isset($scorers[$playerName])) {
                $scorers[$playerName] = 0;
            }
            $scorers[$playerName]++;
        }
    }
    
    // Ordenar de mayor a menor cantidad de goles
    arsort($scorers);
    
    // Devolver los 10 primeros
    return array_slice($scorers, 0, 10, true);
}

/**
 * Calcula estadísticas y récords acumulativos del torneo.
 */
function getTournamentStats($db) {
    $matches = $db->query("SELECT teamA, teamB, scoreA, scoreB, scorersData, cardsData, isFinished FROM `Match`")->fetchAll();
    
    $pj = 0;
    $totalGoals = 0;
    $penalties = 0;
    $ownGoals = 0;
    $totalYellows = 0;
    $totalReds = 0;
    
    $teamGoals = array();
    $teamConceded = array();
    
    $maxGoalsMatch = null;
    $maxGoalsValue = -1;
    
    foreach ($matches as $m) {
        $scoreA = $m['scoreA'];
        $scoreB = $m['scoreB'];
        $teamA = $m['teamA'];
        $teamB = $m['teamB'];
        
        if (!isset($teamGoals[$teamA])) $teamGoals[$teamA] = 0;
        if (!isset($teamGoals[$teamB])) $teamGoals[$teamB] = 0;
        if (!isset($teamConceded[$teamA])) $teamConceded[$teamA] = 0;
        if (!isset($teamConceded[$teamB])) $teamConceded[$teamB] = 0;
        
        if ($scoreA !== null && $scoreB !== null) {
            $pj++;
            $goals = (int)$scoreA + (int)$scoreB;
            $totalGoals += $goals;
            
            $teamGoals[$teamA] += (int)$scoreA;
            $teamGoals[$teamB] += (int)$scoreB;
            $teamConceded[$teamA] += (int)$scoreB;
            $teamConceded[$teamB] += (int)$scoreA;
            
            if ($goals > $maxGoalsValue) {
                $maxGoalsValue = $goals;
                $maxGoalsMatch = array(
                    'teamA'  => $teamA,
                    'teamB'  => $teamB,
                    'scoreA' => $scoreA,
                    'scoreB' => $scoreB
                );
            }
        }
        
        if ($m['scorersData']) {
            $data = json_decode($m['scorersData'], true);
            if ($data) {
                $allScorers = array_merge(
                    isset($data['teamA']) ? $data['teamA'] : array(),
                    isset($data['teamB']) ? $data['teamB'] : array()
                );
                foreach ($allScorers as $sc) {
                    if (stripos($sc, '(p.)') !== false) {
                        $penalties++;
                    }
                    if (stripos($sc, '(ag.)') !== false) {
                        $ownGoals++;
                    }
                }
            }
        }
        
        if (isset($m['cardsData']) && $m['cardsData']) {
            $cData = json_decode($m['cardsData'], true);
            if ($cData) {
                $yellowsA = isset($cData['teamA']['yellow']) ? $cData['teamA']['yellow'] : array();
                $redsA = isset($cData['teamA']['red']) ? $cData['teamA']['red'] : array();
                $yellowsB = isset($cData['teamB']['yellow']) ? $cData['teamB']['yellow'] : array();
                $redsB = isset($cData['teamB']['red']) ? $cData['teamB']['red'] : array();
                
                $totalYellows += count($yellowsA) + count($yellowsB);
                $totalReds += count($redsA) + count($redsB);
            }
        }
    }
    
    arsort($teamGoals);
    $bestAttackName = '';
    $bestAttackGoals = 0;
    if (!empty($teamGoals)) {
        $bestAttackName = key($teamGoals);
        $bestAttackGoals = current($teamGoals);
    }
    
    arsort($teamConceded);
    $worstDefenseName = '';
    $worstDefenseGoals = 0;
    if (!empty($teamConceded)) {
        $worstDefenseName = key($teamConceded);
        $worstDefenseGoals = current($teamConceded);
    }
    
    return array(
        'pj'                => $pj,
        'totalGoals'        => $totalGoals,
        'avgGoals'          => $pj > 0 ? round($totalGoals / $pj, 2) : 0,
        'penalties'         => $penalties,
        'ownGoals'          => $ownGoals,
        'totalYellows'      => $totalYellows,
        'totalReds'         => $totalReds,
        'bestAttackTeam'    => $bestAttackName,
        'bestAttackGoals'   => $bestAttackGoals,
        'worstDefenseTeam'  => $worstDefenseName,
        'worstDefenseGoals' => $worstDefenseGoals,
        'maxGoalsMatch'     => $maxGoalsMatch
    );
}

/**
 * Obtiene los líderes de tarjetas (amarillas y rojas) individuales.
 */
function getTopCards($db) {
    $matches = $db->query("SELECT cardsData FROM `Match` WHERE cardsData IS NOT NULL")->fetchAll();
    $yellows = array();
    $reds = array();
    
    foreach ($matches as $m) {
        $cData = json_decode($m['cardsData'], true);
        if (!$cData) continue;
        
        $allYellows = array_merge(
            isset($cData['teamA']['yellow']) ? $cData['teamA']['yellow'] : array(),
            isset($cData['teamB']['yellow']) ? $cData['teamB']['yellow'] : array()
        );
        $allReds = array_merge(
            isset($cData['teamA']['red']) ? $cData['teamA']['red'] : array(),
            isset($cData['teamB']['red']) ? $cData['teamB']['red'] : array()
        );
        
        foreach ($allYellows as $cardStr) {
            $pName = preg_replace('/\s+\d+.*$/', '', $cardStr);
            $pName = trim($pName);
            if ($pName) {
                $yellows[$pName] = ($yellows[$pName] ?? 0) + 1;
            }
        }
        
        foreach ($allReds as $cardStr) {
            $pName = preg_replace('/\s+\d+.*$/', '', $cardStr);
            $pName = trim($pName);
            if ($pName) {
                $reds[$pName] = ($reds[$pName] ?? 0) + 1;
            }
        }
    }
    
    arsort($yellows);
    arsort($reds);
    
    return array(
        'yellow' => array_slice($yellows, 0, 5, true),
        'red'    => array_slice($reds, 0, 5, true)
    );
}
