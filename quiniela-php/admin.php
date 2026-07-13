<?php
require_once 'config.php';
$user = requireAdmin();

$db = getDB();
$msg = '';
$msgType = '';

// Crear partido nuevo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $teamA = trim($_POST['teamA'] ?? '');
    $teamB = trim($_POST['teamB'] ?? '');
    $date  = trim($_POST['date'] ?? '');
    $venue = trim($_POST['venue'] ?? '');

    if ($teamA && $teamB && $date) {
        $dt = new DateTime($date, new DateTimeZone('America/Mexico_City'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $utcDate = $dt->format('Y-m-d H:i:s');

        $db->prepare("INSERT INTO `Match` (teamA, teamB, flagA, flagB, date, venue, isFinished, createdAt, updatedAt)
                      VALUES (?, ?, '', '', ?, ?, 0, NOW(), NOW())")
           ->execute([$teamA, $teamB, $utcDate, $venue]);
        $msg = "✅ Partido $teamA vs $teamB creado.";
        $msgType = 'success';
    } else {
        $msg = 'Completa todos los campos requeridos.';
        $msgType = 'error';
    }
}

// Auto-calcular llaves de fase final (Grupos -> 16vos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autocalc') {
    function assignThirdPlaces($qualifiedThirds) {
        $combinations = require 'fifa_combinations.php';
        
        $letters = [];
        foreach ($qualifiedThirds as $t) {
            $letters[] = $t['groupLetter'];
        }
        sort($letters);
        $key = implode(',', $letters);
        
        $assigned = [];
        if (isset($combinations[$key])) {
            $matchups = $combinations[$key];
            
            $mapping = [
                79 => 0, // 1A
                85 => 1, // 1B
                81 => 2, // 1D
                75 => 3, // 1E
                82 => 4, // 1G
                78 => 5, // 1I
                88 => 6, // 1K
                80 => 7  // 1L
            ];
            
            foreach ($mapping as $matchId => $idx) {
                if (isset($matchups[$idx])) {
                    $targetGroupLetter = substr($matchups[$idx], 1);
                    foreach ($qualifiedThirds as $t) {
                        if ($t['groupLetter'] === $targetGroupLetter) {
                            $assigned[$matchId] = $t['name'];
                            break;
                        }
                    }
                }
            }
        }
        return $assigned;
    }

    function resolvePlaceholderForAutocalc($matchId, $isTeamB, $placeholder, $standings, $assignedThirds, $db) {
        if (preg_match('/^([12])([A-L])$/', $placeholder, $matches)) {
            $pos = (int)$matches[1];
            $groupLetter = $matches[2];
            $groupName = 'Grupo ' . $groupLetter;
            if (isset($standings[$groupName][$pos - 1])) {
                return $standings[$groupName][$pos - 1]['name'];
            }
        }
        if (strpos($placeholder, '3') === 0) {
            if (isset($assignedThirds[$matchId])) {
                return $assignedThirds[$matchId];
            }
        }
        if (preg_match('/^(Ganador|Perdedor)\s+(\d+)$/', $placeholder, $matches)) {
            $type = $matches[1];
            $prevId = (int)$matches[2];
            $stmt = $db->prepare("SELECT teamA, teamB, winner FROM `Match` WHERE id = ?");
            $stmt->execute([$prevId]);
            $m = $stmt->fetch();
            if ($m && !empty($m['winner'])) {
                if ($type === 'Ganador') {
                    return $m['winner'];
                } else {
                    return ($m['winner'] === $m['teamA']) ? $m['teamB'] : $m['teamA'];
                }
            }
        }
        return $placeholder;
    }

    try {
        $originalKnockoutPlaceholders = [
            73 => ['teamA' => '2A', 'teamB' => '2B'],
            74 => ['teamA' => '1C', 'teamB' => '2F'],
            75 => ['teamA' => '1E', 'teamB' => '3A/B/C/D/F'],
            76 => ['teamA' => '1F', 'teamB' => '2C'],
            77 => ['teamA' => '2E', 'teamB' => '2I'],
            78 => ['teamA' => '1I', 'teamB' => '3C/D/F/G/H'],
            79 => ['teamA' => '1A', 'teamB' => '3C/E/F/H/I'],
            80 => ['teamA' => '1L', 'teamB' => '3E/H/I/J/K'],
            81 => ['teamA' => '1D', 'teamB' => '3B/E/F/I/J'],
            82 => ['teamA' => '1G', 'teamB' => '3A/E/H/I/J'],
            83 => ['teamA' => '1H', 'teamB' => '2J'],
            84 => ['teamA' => '2K', 'teamB' => '2L'],
            85 => ['teamA' => '1B', 'teamB' => '3E/F/G/I/J'],
            86 => ['teamA' => '1J', 'teamB' => '2H'],
            87 => ['teamA' => '2D', 'teamB' => '2G'],
            88 => ['teamA' => '1K', 'teamB' => '3D/E/I/J/L'],
            
            // 8vos (Round of 16)
            89 => ['teamA' => 'Ganador 75', 'teamB' => 'Ganador 78'],
            90 => ['teamA' => 'Ganador 73', 'teamB' => 'Ganador 76'],
            91 => ['teamA' => 'Ganador 74', 'teamB' => 'Ganador 77'],
            92 => ['teamA' => 'Ganador 79', 'teamB' => 'Ganador 80'],
            93 => ['teamA' => 'Ganador 84', 'teamB' => 'Ganador 83'],
            94 => ['teamA' => 'Ganador 82', 'teamB' => 'Ganador 81'],
            95 => ['teamA' => 'Ganador 87', 'teamB' => 'Ganador 86'],
            96 => ['teamA' => 'Ganador 85', 'teamB' => 'Ganador 88'],
            
            // Cuartos (Quarter-finals)
            97 => ['teamA' => 'Ganador 89', 'teamB' => 'Ganador 90'],
            98 => ['teamA' => 'Ganador 93', 'teamB' => 'Ganador 94'],
            99 => ['teamA' => 'Ganador 91', 'teamB' => 'Ganador 92'],
            100 => ['teamA' => 'Ganador 95', 'teamB' => 'Ganador 96'],
            
            // Semifinales
            101 => ['teamA' => 'Ganador 97', 'teamB' => 'Ganador 98'],
            102 => ['teamA' => 'Ganador 99', 'teamB' => 'Ganador 100'],
            
            // Tercer Lugar
            103 => ['teamA' => 'Perdedor 101', 'teamB' => 'Perdedor 102'],
            
            // Final
            104 => ['teamA' => 'Ganador 101', 'teamB' => 'Ganador 102']
        ];

        $standings = getGroupStandings($db);

        // Calcular mejores terceros
        $thirdPlaces = [];
        foreach ($standings as $groupName => $teams) {
            $groupLetter = substr($groupName, -1);
            if (isset($teams[2])) {
                $third = $teams[2];
                $thirdPlaces[] = [
                    'name' => $third['name'],
                    'groupLetter' => $groupLetter,
                    'pts' => $third['pts'],
                    'gf' => $third['gf'],
                    'gc' => $third['gc'],
                    'gd' => $third['gf'] - $third['gc'],
                    'seed' => $third['seed']
                ];
            }
        }
        usort($thirdPlaces, function($a, $b) {
            if ($b['pts'] !== $a['pts']) return $b['pts'] - $a['pts'];
            if ($b['gd'] !== $a['gd']) return $b['gd'] - $a['gd'];
            if ($b['gf'] !== $a['gf']) return $b['gf'] - $a['gf'];
            return $a['seed'] - $b['seed'];
        });
        $qualifiedThirds = array_slice($thirdPlaces, 0, 8);

        $assignedThirds = assignThirdPlaces($qualifiedThirds);

        $updatedCount = 0;
        foreach ($originalKnockoutPlaceholders as $matchId => $p) {
            $resolvedA = resolvePlaceholderForAutocalc($matchId, false, $p['teamA'], $standings, $assignedThirds, $db);
            $resolvedB = resolvePlaceholderForAutocalc($matchId, true, $p['teamB'], $standings, $assignedThirds, $db);
            $flagA = getFlagUrl($resolvedA);
            $flagB = getFlagUrl($resolvedB);

            $stmt = $db->prepare("UPDATE `Match` SET teamA = ?, teamB = ?, flagA = ?, flagB = ? WHERE id = ?");
            $stmt->execute([$resolvedA, $resolvedB, $flagA, $flagB, $matchId]);
            $updatedCount++;
        }

        $msg = "✅ Auto-cálculo masivo completado. Se actualizaron $updatedCount partidos de la fase final en la base de datos.";
        $msgType = 'success';
    } catch (Exception $e) {
        $msg = "❌ Error al auto-calcular: " . $e->getMessage();
        $msgType = 'error';
    }
}

// Obtener partidos de fase de grupos únicamente
$matches = $db->query("SELECT * FROM `Match` WHERE id < 73 ORDER BY date ASC")->fetchAll();
// Obtener usuarios ordenados - Quiniela Principal
$usersMain = $db->query("SELECT id, username, points, hasPaid FROM `User` WHERE role != 'ADMIN' AND origin = 'MAIN' ORDER BY username ASC")->fetchAll();
// Obtener usuarios ordenados - Quiniela Fase Final
$usersFF = $db->query("SELECT id, username, pointsFaseFinal, hasPaidFaseFinal FROM `User` WHERE role != 'ADMIN' AND hasJoinedFaseFinal = 1 ORDER BY username ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin – Quiniela Mundial 2026</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: '3.40' ?>" />
</head>
<body class="fade-in">

  <div class="top-bar">
    <span>Admin: <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <a href="index.php" class="btn-admin" style="background:var(--fifa-purple)">← Ver Quiniela</a>
    <a href="logout.php"><button class="btn-logout">Salir</button></a>
  </div>

  <header class="site-header">
    <div class="wc-badge">⚙️</div>
    <h1 class="site-title">Panel de Administración</h1>
    <p class="site-subtitle">Gestiona partidos y resultados</p>
  </header>

  <div class="admin-container">
    <?php if ($msg): ?>
      <div class="alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="admin-grid">

      <!-- Crear Partido -->
      <div class="glass-panel">
        <h2 class="admin-section-title">➕ Crear Nuevo Partido</h2>
        <form method="POST" action="admin.php">
          <input type="hidden" name="action" value="create" />
          <div class="form-group">
            <label>Equipo A *</label>
            <input type="text" name="teamA" class="form-input" placeholder="Ej. México" required />
          </div>
          <div class="form-group">
            <label>Equipo B *</label>
            <input type="text" name="teamB" class="form-input" placeholder="Ej. Argentina" required />
          </div>
          <div class="form-group">
            <label>Fecha y Hora (Hora México) *</label>
            <input type="datetime-local" name="date" class="form-input" required />
          </div>
          <div class="form-group">
            <label>Estadio / Ciudad</label>
            <input type="text" name="venue" class="form-input" placeholder="Ej. Estadio Azteca" />
          </div>
          <button type="submit" class="btn-login" style="margin-top:1rem">Crear Partido</button>
        </form>
      </div>

      <!-- Registrar Resultados -->
      <div class="glass-panel">
        <h2 class="admin-section-title">📋 Registrar Resultados</h2>
        <div class="admin-match-list" id="match-list">
          <?php foreach ($matches as $m):
            $time = formatMatchTime($m['date']);
            $day  = formatMatchDay($m['date']);
          ?>
            <div class="admin-match-item" id="match-item-<?= $m['id'] ?>">
              <div style="flex:1">
                <div class="admin-match-name">
                  <?= htmlspecialchars($m['teamA']) ?> vs <?= htmlspecialchars($m['teamB']) ?>
                </div>
                <div class="admin-match-date"><?= $day ?> · <?= $time ?></div>
              </div>
              <?php if ($m['isFinished']): ?>
                <div class="finished-badge">✅ <?= $m['scoreA'] ?>–<?= $m['scoreB'] ?></div>
              <?php else: ?>
                <input type="number" min="0" max="20" class="admin-score-input result-a-<?= $m['id'] ?>"
                       placeholder="0" value="" />
                <span style="color:var(--text-secondary); font-weight:900">–</span>
                <input type="number" min="0" max="20" class="admin-score-input result-b-<?= $m['id'] ?>"
                       placeholder="0" value="" />
                <button class="btn-set-result" data-match-id="<?= $m['id'] ?>">Guardar</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (empty($matches)): ?>
            <p style="color:var(--text-secondary); text-align:center">No hay partidos creados.</p>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Gestión de Fase Final -->
    <div class="glass-panel" style="margin-top:2rem; border-color: rgba(0, 240, 255, 0.2);">
      <h2 class="admin-section-title">🏆 Gestión de Fase Final</h2>
      <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1.5rem">
        Calcula automáticamente los clasificados a la ronda de 16vos basándote en las posiciones actuales de los grupos, o registra resultados de eliminación directa.
      </p>

      <form method="POST" action="admin.php" style="margin-bottom: 2rem;">
        <input type="hidden" name="action" value="autocalc" />
        <button type="submit" class="btn-login" style="width:auto; padding:0.8rem 2rem; background: linear-gradient(135deg, var(--fifa-purple), var(--fifa-magenta)); color: white; border: none; box-shadow: 0 4px 15px rgba(255, 0, 85, 0.3);">
          ⚡ Auto-calcular Equipos Clasificados (Grupos → 16vos)
        </button>
      </form>
      
      <!-- Subsección para elegir ganadores en caso de empates en la fase final -->
      <div style="border-top:1px solid rgba(255,255,255,0.05); padding-top:1.5rem">
        <h3 style="font-size:1rem; font-weight:700; color:white; margin-bottom:0.8rem">Resumen de Partidos de Fase Final</h3>
        <p style="color:var(--text-secondary); font-size:0.8rem; margin-bottom:1rem">
          Si un partido de eliminación directa termina empatado (ej. 1–1), el sistema requiere registrar qué equipo avanzó en la tanda de penaltis o prórroga.
        </p>
        <div class="admin-match-list">
          <?php 
          $ffMatchesForAdmin = $db->query("SELECT * FROM `Match` WHERE id >= 73 ORDER BY date ASC, id ASC")->fetchAll();
          $ffDisplayMap = [];
          foreach ($ffMatchesForAdmin as $index => $m) {
              $ffDisplayMap[$m['id']] = 73 + $index;
          }
          $hasAnyFf = false;
          foreach ($ffMatchesForAdmin as $m):
            $hasAnyFf = true;
            $time = formatMatchTime($m['date']);
            $day  = formatMatchDay($m['date']);
            
            $flagA = ''; $flagB = '';
            $teamNameA = resolvePlaceholderTeam($m['teamA'], $db, $flagA);
            $teamNameB = resolvePlaceholderTeam($m['teamB'], $db, $flagB);
          ?>
            <div class="admin-match-item" id="match-item-<?= $m['id'] ?>">
              <div style="flex:1; min-width: 250px;">
                <div class="admin-match-name">
                  <span>M#<?= $ffDisplayMap[$m['id']] ?>:</span> 
                  <?= htmlspecialchars($teamNameA) ?> vs <?= htmlspecialchars($teamNameB) ?>
                </div>
                <div class="admin-match-date"><?= $day ?> · <?= $time ?></div>
                <?php if ($m['isFinished'] && !empty($m['winner'])): ?>
                  <div style="font-size: 0.78rem; color: var(--accent-color); font-weight: 700; margin-top: 0.2rem;">
                    Avanzó: <?= htmlspecialchars($m['winner']) ?>
                  </div>
                <?php endif; ?>
              </div>
              <?php if ($m['isFinished']): ?>
                <div class="finished-badge">✅ <?= $m['scoreA'] ?>–<?= $m['scoreB'] ?></div>
              <?php else: ?>
                <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <input type="number" min="0" max="20" class="admin-score-input result-a-<?= $m['id'] ?>"
                         placeholder="0" style="margin-right:0.2rem" />
                  <span style="color:var(--text-secondary); font-weight:900">–</span>
                  <input type="number" min="0" max="20" class="admin-score-input result-b-<?= $m['id'] ?>"
                         placeholder="0" style="margin-left:0.2rem" />
                  
                  <div class="winner-selector-container-<?= $m['id'] ?>" style="display:none; margin-top:0.5rem; width:100%;">
                    <label style="font-size:0.75rem; color:var(--text-secondary)">Ganador Desempate/Penales:</label>
                    <select class="admin-winner-select-<?= $m['id'] ?>" style="background:#000; color:#fff; border:1px solid var(--panel-border); padding:0.3rem; border-radius:4px; margin-left:0.5rem;">
                      <option value="">-- Seleccionar --</option>
                      <option value="<?= htmlspecialchars($teamNameA) ?>"><?= htmlspecialchars($teamNameA) ?></option>
                      <option value="<?= htmlspecialchars($teamNameB) ?>"><?= htmlspecialchars($teamNameB) ?></option>
                    </select>
                  </div>
                  
                  <button class="btn-set-result" data-match-id="<?= $m['id'] ?>">Guardar</button>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$hasAnyFf): ?>
            <p style="color:var(--text-secondary); text-align:center">No hay partidos de fase final registrados.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Gestionar Participantes -->
    <div class="glass-panel" style="margin-top:2rem">
      <h2 class="admin-section-title">👥 Gestionar Participantes y Pagos</h2>
      <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1.5rem">
        Marca a los participantes que aportaron dinero a la bolsa de premios ($500 pesos) en cada uno de los torneos correspondientes.
      </p>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin-top: 1rem;">
        <!-- Torneo Principal -->
        <div>
          <h3 style="color: var(--fifa-purple); font-size: 1.1rem; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            ⚽ Quiniela Principal (Grupos)
          </h3>
          <div class="admin-users-list">
            <?php foreach ($usersMain as $u): ?>
              <div class="admin-user-item">
                <div class="admin-user-info">
                  <span class="admin-user-name"><?= htmlspecialchars($u['username']) ?></span>
                  <span class="admin-user-pts"><?= $u['points'] ?> pts</span>
                </div>
                <div class="admin-user-actions">
                  <label class="switch-container">
                    <input type="checkbox" class="toggle-paid-checkbox" data-user-id="<?= $u['id'] ?>" data-field="hasPaid" <?= $u['hasPaid'] ? 'checked' : '' ?> />
                    <span class="switch-slider"></span>
                  </label>
                  <span class="paid-status-label" id="status-label-main-<?= $u['id'] ?>" style="color: <?= $u['hasPaid'] ? 'var(--accent-color)' : 'var(--text-secondary)' ?>; font-size: 0.8rem; font-weight: 700; margin-left: 0.5rem; width: 65px; display: inline-block;">
                    <?= $u['hasPaid'] ? 'Pagado' : 'Sin Pago' ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($usersMain)): ?>
              <p style="color:var(--text-secondary); text-align:center">No hay participantes registrados.</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Torneo Fase Final -->
        <div>
          <h3 style="color: var(--fifa-cyan); font-size: 1.1rem; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 0.5rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
            🏆 Quiniela Fase Final
          </h3>
          <div class="admin-users-list">
            <?php foreach ($usersFF as $u): ?>
              <div class="admin-user-item">
                <div class="admin-user-info">
                  <span class="admin-user-name"><?= htmlspecialchars($u['username']) ?></span>
                  <span class="admin-user-pts"><?= $u['pointsFaseFinal'] ?> pts</span>
                </div>
                <div class="admin-user-actions">
                  <label class="switch-container">
                    <input type="checkbox" class="toggle-paid-checkbox" data-user-id="<?= $u['id'] ?>" data-field="hasPaidFaseFinal" <?= $u['hasPaidFaseFinal'] ? 'checked' : '' ?> />
                    <span class="switch-slider"></span>
                  </label>
                  <span class="paid-status-label" id="status-label-ff-<?= $u['id'] ?>" style="color: <?= $u['hasPaidFaseFinal'] ? 'var(--accent-color)' : 'var(--text-secondary)' ?>; font-size: 0.8rem; font-weight: 700; margin-left: 0.5rem; width: 65px; display: inline-block;">
                    <?= $u['hasPaidFaseFinal'] ? 'Pagado' : 'Sin Pago' ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($usersFF)): ?>
              <p style="color:var(--text-secondary); text-align:center; padding: 1rem 0;">Ningún participante se ha firmado en la quiniela nueva.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- API Sync Panel -->
    <div class="glass-panel" style="margin-top:2rem">
      <h2 class="admin-section-title">📡 Resultados en Vivo</h2>
      <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1rem">
        Consulta automática de resultados del Mundial desde ESPN.
        <br><span style="color:var(--accent-color)">✅ No necesita API key ni registro – funciona automáticamente.</span>
      </p>

      <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center">
        <button id="btn-fetch-api" class="btn-login" style="width:auto; padding:0.8rem 2rem; margin:0">
          🔄 Actualizar Resultados Ahora
        </button>
        <div id="api-status" style="color:var(--text-secondary); font-size:0.85rem"></div>
      </div>

      <div id="api-log" style="margin-top:1rem; font-family:monospace; font-size:0.8rem; color:var(--text-secondary); max-height:200px; overflow-y:auto; background:rgba(0,0,0,0.2); border-radius:8px; padding:1rem; display:none"></div>

      <!-- Status de partidos en vivo -->
      <?php
      $liveMatches = array_filter($matches, function($m) {
          return in_array(isset($m['status']) ? $m['status'] : '', ['LIVE', 'HALFTIME']);
      });
      if (!empty($liveMatches)):
      ?>
        <div style="margin-top:1.5rem">
          <h3 style="font-size:1rem; font-weight:700; color:#ff6b9d; margin-bottom:0.8rem">
            <span class="live-dot" style="margin-right:0.4rem"></span> Partidos en Vivo
          </h3>
          <?php foreach ($liveMatches as $lm): ?>
            <div class="admin-match-item" style="border-color:rgba(255,0,85,0.3)">
              <div style="flex:1">
                <div class="admin-match-name"><?= htmlspecialchars($lm['teamA']) ?> vs <?= htmlspecialchars($lm['teamB']) ?></div>
                <div class="admin-match-date">
                  <?= $lm['status'] === 'HALFTIME' ? 'Medio Tiempo' : "Minuto {$lm['matchMinute']}'" ?>
                  · Última actualización: <?= $lm['lastApiUpdate'] ?? 'N/A' ?>
                </div>
              </div>
              <div style="font-size:1.8rem; font-weight:900; color:#ff6b9d">
                <?= $lm['scoreA'] ?? 0 ?> – <?= $lm['scoreB'] ?? 0 ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <p style="margin-top:1.5rem; font-size:0.75rem; color:var(--text-secondary)">
        💡 Para actualizaciones automáticas, configura un cron job en cPanel:<br>
        <code style="background:rgba(0,0,0,0.3); padding:0.3rem 0.6rem; border-radius:4px; font-size:0.7rem">
          */5 * * * * php /home/maplemx/public_html/api/sync.php
        </code>
      </p>
    </div>
  </div>

  <script>
    document.querySelectorAll('.btn-set-result').forEach(btn => {
      btn.addEventListener('click', async () => {
        const matchId = parseInt(btn.dataset.matchId);
        const scoreA = document.querySelector(`.result-a-${matchId}`).value;
        const scoreB = document.querySelector(`.result-b-${matchId}`).value;

        if (scoreA === '' || scoreB === '') {
          alert('Ingresa ambos marcadores');
          return;
        }

        let winner = '';
        if (matchId >= 73 && parseInt(scoreA) === parseInt(scoreB)) {
          const container = document.querySelector(`.winner-selector-container-${matchId}`);
          const select = document.querySelector(`.admin-winner-select-${matchId}`);
          
          if (container && select) {
            if (container.style.display === 'none') {
              container.style.display = 'block';
              alert('Los partidos de eliminación directa no pueden terminar en empate sin un ganador. Por favor, selecciona el equipo que avanza (ganador de penales o prórroga) en el menú desplegable.');
              return;
            }
            winner = select.value;
            if (!winner) {
              alert('Por favor, selecciona al ganador que avanza.');
              return;
            }
          }
        }

        btn.disabled = true;
        btn.textContent = 'Guardando...';

        try {
          const res = await fetch('api/set_result.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ matchId: matchId, scoreA: parseInt(scoreA), scoreB: parseInt(scoreB), winner: winner })
          });
          const data = await res.json();

          if (data.success) {
            const item = document.getElementById(`match-item-${matchId}`);
            // Reemplazar inputs por badge
            const inputs = item.querySelectorAll('.admin-score-input, .btn-set-result, span, select, label, div[class^="winner-selector-container-"]');
            inputs.forEach(el => el.remove());
            const badge = document.createElement('div');
            badge.className = 'finished-badge';
            badge.textContent = `✅ ${scoreA}–${scoreB}`;
            item.appendChild(badge);
            
            if (winner) {
              const info = document.createElement('div');
              info.style.cssText = 'font-size: 0.78rem; color: var(--accent-color); font-weight: 700; margin-top: 0.2rem;';
              info.textContent = 'Avanzó: ' + winner;
              item.querySelector('div').appendChild(info);
            }
          } else {
            alert('Error: ' + (data.error || 'No se pudo guardar'));
            btn.disabled = false;
            btn.textContent = 'Guardar';
          }
        } catch (e) {
          alert('Error de conexión');
          btn.disabled = false;
          btn.textContent = 'Guardar';
        }
      });
    });

    // Mostrar/ocultar selector de ganador en vivo según el marcador ingresado
    document.querySelectorAll('.admin-score-input').forEach(input => {
      input.addEventListener('input', () => {
        const item = input.closest('.admin-match-item');
        if (!item) return;
        const btn = item.querySelector('.btn-set-result');
        if (!btn) return;
        const matchId = parseInt(btn.dataset.matchId);
        if (matchId < 73) return;
        
        const scoreAVal = item.querySelector(`.result-a-${matchId}`).value;
        const scoreBVal = item.querySelector(`.result-b-${matchId}`).value;
        const container = item.querySelector(`.winner-selector-container-${matchId}`);
        
        if (container) {
          if (scoreAVal !== '' && scoreBVal !== '' && parseInt(scoreAVal) === parseInt(scoreBVal)) {
            container.style.display = 'block';
          } else {
            container.style.display = 'none';
          }
        }
      });
    });

    // Fetch from API button
    const fetchBtn = document.getElementById('btn-fetch-api');
    if (fetchBtn && !fetchBtn.disabled) {
      fetchBtn.addEventListener('click', async () => {
        const statusEl = document.getElementById('api-status');
        const logEl = document.getElementById('api-log');
        fetchBtn.disabled = true;
        fetchBtn.textContent = '🔄 Consultando API...';
        statusEl.textContent = '';
        logEl.style.display = 'none';

        try {
          const res = await fetch('api/sync.php');
          const text = await res.text();
          
          if (!res.ok) {
            logEl.style.display = 'block';
            const cleanText = text.replace(/<[^>]*>/g, '').substring(0, 500);
            logEl.innerHTML = `<div style="color:#ff6b9d; font-weight:bold">Respuesta del servidor (${res.status} ${res.statusText}):</div><pre style="white-space:pre-wrap; margin-top:0.5rem; background:rgba(0,0,0,0.5); padding:0.5rem">${cleanText}</pre>`;
            throw new Error(`HTTP ${res.status} ${res.statusText}`);
          }
          
          let data;
          try {
            data = JSON.parse(text);
          } catch(err) {
            logEl.style.display = 'block';
            const cleanText = text.replace(/<[^>]*>/g, '').substring(0, 300);
            logEl.innerHTML = `<div style="color:#ff6b9d; font-weight:bold">Respuesta del servidor (No es JSON):</div><pre style="white-space:pre-wrap; margin-top:0.5rem; background:rgba(0,0,0,0.5); padding:0.5rem">${cleanText}</pre>`;
            throw new Error('La respuesta del servidor no es válida.');
          }
          
          if (data.log) {
            logEl.style.display = 'block';
            logEl.innerHTML = data.log.map(l => `<div>${l}</div>`).join('');
          }
          statusEl.innerHTML = '<span style="color:var(--accent-color)">✅ Actualización completada</span>';
          // Recargar después de 2s para ver los cambios
          setTimeout(() => location.reload(), 2000);
        } catch(e) {
          statusEl.innerHTML = `<span style="color:#ff6b9d">❌ Error: ${e.message}</span>`;
        } finally {
          fetchBtn.disabled = false;
          fetchBtn.textContent = '🔄 Actualizar Resultados Ahora';
        }
      });
    }

    // Toggle user payment status
    document.querySelectorAll('.toggle-paid-checkbox').forEach(chk => {
      chk.addEventListener('change', async () => {
        const userId = chk.dataset.userId;
        const field = chk.dataset.field || 'hasPaid';
        const labelPrefix = (field === 'hasPaid') ? 'main-' : 'ff-';
        const hasPaid = chk.checked ? 1 : 0;
        const statusLabel = document.getElementById(`status-label-${labelPrefix}${userId}`);
        
        chk.disabled = true;
        if (statusLabel) {
          statusLabel.textContent = 'Guardando...';
          statusLabel.style.color = 'var(--text-secondary)';
        }

        try {
          const res = await fetch('api/toggle_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userId: parseInt(userId), hasPaid: hasPaid, field: field })
          });
          const data = await res.json();

          if (data.success) {
            if (statusLabel) {
              statusLabel.textContent = hasPaid ? 'Pagado' : 'Sin Pago';
              statusLabel.style.color = hasPaid ? 'var(--accent-color)' : 'var(--text-secondary)';
            }
          } else {
            alert('Error: ' + (data.error || 'No se pudo actualizar'));
            chk.checked = !chk.checked; // Revertir
            if (statusLabel) {
              statusLabel.textContent = !hasPaid ? 'Pagado' : 'Sin Pago';
              statusLabel.style.color = !hasPaid ? 'var(--accent-color)' : 'var(--text-secondary)';
            }
          }
        } catch (e) {
          alert('Error de conexión');
          chk.checked = !chk.checked; // Revertir
          if (statusLabel) {
            statusLabel.textContent = !hasPaid ? 'Pagado' : 'Sin Pago';
            statusLabel.style.color = !hasPaid ? 'var(--accent-color)' : 'var(--text-secondary)';
          }
        } finally {
          chk.disabled = false;
        }
      });
    });
  </script>
</body>
</html>
