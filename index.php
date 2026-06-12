<?php
require_once 'config.php';
$user = requireLogin();

$db = getDB();

// Obtener todos los partidos ordenados por fecha
$matches = $db->query("SELECT * FROM `Match` ORDER BY date ASC")->fetchAll();

// Obtener pronósticos del usuario actual
$predStmt = $db->prepare("SELECT * FROM `Prediction` WHERE userId = ?");
$predStmt->execute([$user['id']]);
$predictions = $predStmt->fetchAll();
$predMap = [];
foreach ($predictions as $p) {
    $predMap[$p['matchId']] = $p;
}

// Clasificación con puntos proyectados
$allUsers = $db->query("SELECT id, username, points, hasPaid FROM `User` WHERE role != 'ADMIN' ORDER BY points DESC, username ASC")->fetchAll();

// Calcular puntos proyectados en vivo
$allPreds = $db->query("
    SELECT p.userId, p.scoreA AS predA, p.scoreB AS predB, p.points AS confirmedPts,
           m.scoreA AS mScoreA, m.scoreB AS mScoreB, m.status, m.isFinished
    FROM `Prediction` p INNER JOIN `Match` m ON p.matchId = m.id
")->fetchAll();

$userProjected = [];
foreach ($allPreds as $ap) {
    $uid = (int)$ap['userId'];
    if (!isset($userProjected[$uid])) $userProjected[$uid] = ['confirmed' => 0, 'projected' => 0];
    if ($ap['isFinished']) {
        $userProjected[$uid]['confirmed'] += (int)$ap['confirmedPts'];
    } elseif ($ap['status'] === 'LIVE' || $ap['status'] === 'HALFTIME') {
        $livePts = calculatePoints((int)$ap['predA'], (int)$ap['predB'], $ap['mScoreA'], $ap['mScoreB']);
        $userProjected[$uid]['projected'] += $livePts;
    }
}

// Construir leaderboard con totales
$leaderboard = [];
foreach ($allUsers as $u) {
    $uid = (int)$u['id'];
    $pts = $userProjected[$uid] ?? ['confirmed' => 0, 'projected' => 0];
    $leaderboard[] = array_merge($u, [
        'confirmed' => $pts['confirmed'],
        'projected' => $pts['projected'],
        'total' => $pts['confirmed'] + $pts['projected'],
    ]);
}
usort($leaderboard, function($a, $b) {
    return $b['total'] - $a['total'];
});

// Agrupar partidos por día
$grouped = [];
$hasLive = false;
foreach ($matches as $match) {
    $dayKey = formatMatchDay($match['date']);
    $grouped[$dayKey][] = $match;
    if (in_array($match['status'], ['LIVE', 'HALFTIME'])) $hasLive = true;
}

// Calcular bolsa acumulada
$paidCount = 0;
foreach ($leaderboard as $u) {
    if (!empty($u['hasPaid'])) {
        $paidCount++;
    }
}
$totalPrizePool = $paidCount * 500;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Quiniela Mundial 2026 ⚽</title>
  <meta name="description" content="Quiniela del Mundial de Fútbol 2026 – Compite con tus amigos." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=3.4" />
</head>
<body class="fade-in">

  <!-- Top Bar -->
  <div class="top-bar">
    <span>Hola, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if ($user['role'] === 'ADMIN'): ?>
      <a href="admin.php" class="btn-admin">⚙️ Panel Admin</a>
    <?php endif; ?>
    <a href="logout.php"><button class="btn-logout">Salir</button></a>
  </div>

  <!-- Header -->
  <header class="site-header">
    <div class="wc-badge">26</div>
    <h1 class="site-title">Quiniela Mundialista</h1>
    <p class="site-subtitle">¡Vive la pasión del fútbol y compite con tus amigos!</p>
    <?php if ($hasLive): ?>
      <div class="live-banner">
        <span class="live-dot"></span> HAY PARTIDOS EN VIVO – Actualizando automáticamente
      </div>
    <?php endif; ?>
  </header>

  <!-- Main Content -->
  <div class="main-container">

    <!-- Contenido Principal (Partidos / Tablas de Grupos) -->
    <section>
      <!-- Sección de Reglas del Juego -->
      <div class="glass-panel rules-panel" style="margin-bottom: 2rem; padding: 1.5rem; border-color: rgba(0, 255, 136, 0.2);">
        <div class="rules-header" onclick="toggleRules()" style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;">
          <h3 style="margin: 0; font-size: 1.2rem; font-weight: 800; color: var(--accent-color); display: flex; align-items: center; gap: 0.6rem;">
            📜 Reglas del Juego
          </h3>
          <span id="rules-toggle-icon" style="font-size: 0.9rem; color: var(--accent-color); transition: all 0.3s ease; background: rgba(0, 255, 136, 0.1); padding: 0.3rem 0.6rem; border-radius: 6px;">Ocultar ▲</span>
        </div>
        <div id="rules-content" style="max-height: 1000px; overflow: hidden; transition: max-height 0.4s ease, opacity 0.4s ease, margin-top 0.4s ease; opacity: 1; margin-top: 1rem;">
          <div style="padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05); display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; font-size: 0.88rem; line-height: 1.5; color: var(--text-secondary);">
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">📈 Sistema de Puntuación</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🎯</span>
                  <div><strong>Marcador Exacto (+6 pts):</strong> Acierto del marcador exacto del partido. Ej. Pronóstico: 2-1 | Resultado: 2-1.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">✓</span>
                  <div><strong>Resultado Correcto (+3 pts):</strong> Acierto de ganador o empate, pero no del marcador exacto. Ej. Pronóstico: 2-1 | Resultado: 1-0.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">✗</span>
                  <div><strong>Fallo (0 pts):</strong> Si no se acierte el ganador ni el empate.</div>
                </li>
              </ul>
            </div>
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">⏰ Cierre y Sincronización</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🔒</span>
                  <div><strong>Cierre automático:</strong> Los pronósticos se bloquean automáticamente <strong>1 hora antes</strong> del inicio oficial de cada partido.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">⚡</span>
                  <div><strong>Puntos en Vivo:</strong> Durante los partidos en vivo verás tus puntos proyectados. Se confirman oficialmente al terminar el encuentro.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🏆</span>
                  <div><strong>Ganador de la Quiniela:</strong> La quiniela abarca todo el certamen (un total de 104 partidos), por lo que el ganador definitivo se determinará al concluir la Gran Final del Mundial.</div>
                </li>
              </ul>
            </div>
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">💰 Bolsa de Premios</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">💵</span>
                  <div><strong>Competición por la Bolsa:</strong> Solo los participantes que aportaron la cuota de entrada ($500 pesos) compiten por la bolsa acumulada. Están marcados con un signo de <strong>$ dorado</strong>.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🏆</span>
                  <div><strong>Solo Gana el 1º Lugar:</strong> La bolsa acumulada se entregará únicamente al participante mejor posicionado que tenga la marca dorada ($). No hay premios para el 2º ni 3º lugar (solo gana el primer lugar de los participantes con pago).</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">⚖️</span>
                  <div><strong>Empate de Puntos:</strong> Si dos o más participantes elegibles terminan empatados en el primer lugar con el mismo número de puntos al finalizar la quiniela, la bolsa acumulada se repartirá en partes iguales entre ellos.</div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="section-header-tabs">
        <button class="tab-btn active" id="btn-tab-matches" onclick="switchTab('matches')">⚽ Partidos y Pronósticos</button>
        <button class="tab-btn" id="btn-tab-groups" onclick="switchTab('groups')">📊 Grupos del Mundial</button>
        <button class="tab-btn" id="btn-tab-stats" onclick="switchTab('stats')">📈 Estadísticas</button>
      </div>

      <!-- Tab: Partidos -->
      <div id="tab-matches" class="tab-pane active">
        <div class="glass-panel">
        <?php if (empty($grouped)): ?>
          <p style="text-align:center; color:var(--text-secondary)">No hay partidos programados aún.</p>
        <?php else: ?>
          <?php $matchNumber = 1; ?>
          <?php foreach ($grouped as $day => $dayMatches): ?>
            <div class="day-group">
              <h3 class="day-header"><?= htmlspecialchars($day) ?></h3>
              <div class="match-grid">
                <?php foreach ($dayMatches as $match):
                  $pred = $predMap[$match['id']] ?? null;
                  $locked = isLocked($match['date']);
                  $flagA = getFlagUrl($match['teamA']);
                  $flagB = getFlagUrl($match['teamB']);
                  $time = formatMatchTime($match['date']);
                  $status = $match['status'] ?? 'SCHEDULED';
                  $isLive = in_array($status, ['LIVE', 'HALFTIME']);
                  $isFinished = (bool)$match['isFinished'];
                  
                  // Puntos proyectados para este partido
                  $projPts = 0;
                  if ($pred && ($isLive || $isFinished) && is_numeric($match['scoreA'])) {
                      $projPts = calculatePoints((int)$pred['scoreA'], (int)$pred['scoreB'], (int)$match['scoreA'], (int)$match['scoreB']);
                  }
                  $hasStarted = ($isLive || $isFinished || $status === 'HALFTIME');
                ?>
                <div class="match-card <?= $isLive ? 'match-card--live' : '' ?> <?= $hasStarted ? 'match-card--clickable' : '' ?>" 
                     data-match-id="<?= $match['id'] ?>"
                     <?= $hasStarted ? 'onclick="openMatchDetails(' . $match['id'] . ')"' : '' ?>>
                  <span class="match-badge">Partido #<?= $matchNumber++ ?></span>

                  <!-- Status badge en vivo -->
                  <?php if ($isLive): ?>
                    <div class="live-indicator">
                      <span class="live-dot"></span>
                      <span>EN VIVO <?= $match['matchMinute'] ? "· {$match['matchMinute']}'" : '' ?></span>
                    </div>
                  <?php elseif ($status === 'HALFTIME'): ?>
                    <div class="live-indicator live-indicator--ht">
                      <span>MEDIO TIEMPO</span>
                    </div>
                  <?php endif; ?>

                  <div class="match-header">
                    <?php if (!$isLive && !$isFinished): ?>
                      <div class="match-time"><?= $time ?></div>
                    <?php endif; ?>
                    <?php if ($match['venue']): ?>
                      <div class="match-venue">📍 <?= htmlspecialchars($match['venue']) ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="teams">
                    <div class="team">
                      <?php if ($flagA): ?>
                        <img src="<?= $flagA ?>" alt="<?= htmlspecialchars($match['teamA']) ?>" />
                      <?php else: ?><div class="team-flag-placeholder"></div><?php endif; ?>
                      <div class="team-name"><?= htmlspecialchars($match['teamA']) ?></div>
                      <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                        <div class="team-realtime-score" id="scoreA-<?= $match['id'] ?>"><?= $match['scoreA'] ?? 0 ?></div>
                      <?php else: ?>
                        <div class="team-realtime-score team-realtime-score--scheduled">–</div>
                      <?php endif; ?>
                      
                      <!-- Eventos del Equipo A (Goles y Tarjetas alineados) -->
                      <div class="team-events">
                        <!-- Goleadores Equipo A -->
                        <div class="team-scorers">
                          <?php 
                          $scorers = isset($match['scorersData']) ? json_decode($match['scorersData'], true) : null;
                          $scorersA = isset($scorers['teamA']) ? $scorers['teamA'] : array();
                          foreach ($scorersA as $sc):
                          ?>
                            <div class="scorer-item"><span class="event-icon">⚽</span><?= htmlspecialchars($sc) ?></div>
                          <?php endforeach; ?>
                        </div>

                        <!-- Tarjetas Equipo A -->
                        <div class="team-cards" id="cardsA-<?= $match['id'] ?>">
                          <?php 
                          $cards = isset($match['cardsData']) ? json_decode($match['cardsData'], true) : null;
                          $yellowsA = isset($cards['teamA']['yellow']) ? $cards['teamA']['yellow'] : array();
                          $redsA = isset($cards['teamA']['red']) ? $cards['teamA']['red'] : array();
                          foreach ($yellowsA as $cardStr):
                          ?>
                            <div class="card-item-yellow"><span class="event-icon">🟨</span><?= htmlspecialchars($cardStr) ?></div>
                          <?php endforeach; ?>
                          <?php foreach ($redsA as $cardStr): ?>
                            <div class="card-item-red"><span class="event-icon">🟥</span><?= htmlspecialchars($cardStr) ?></div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>

                    <!-- Centro: Minuto / VS / FINAL -->
                    <div class="match-center-status">
                      <?php if ($isLive): ?>
                        <div class="live-dot" style="margin-bottom:0.2rem"></div>
                        <div class="match-time-live" id="minute-<?= $match['id'] ?>">
                          <?= $match['matchMinute'] ? "Min {$match['matchMinute']}'" : 'EN VIVO' ?>
                        </div>
                      <?php elseif ($status === 'HALFTIME'): ?>
                        <div class="match-time-ht">MEDIO TIEMPO</div>
                      <?php elseif ($isFinished): ?>
                        <div class="match-time-final">FINAL</div>
                      <?php else: ?>
                        <div class="vs">VS</div>
                      <?php endif; ?>
                    </div>

                    <div class="team">
                      <?php if ($flagB): ?>
                        <img src="<?= $flagB ?>" alt="<?= htmlspecialchars($match['teamB']) ?>" />
                      <?php else: ?><div class="team-flag-placeholder"></div><?php endif; ?>
                      <div class="team-name"><?= htmlspecialchars($match['teamB']) ?></div>
                      <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                        <div class="team-realtime-score" id="scoreB-<?= $match['id'] ?>"><?= $match['scoreB'] ?? 0 ?></div>
                      <?php else: ?>
                        <div class="team-realtime-score team-realtime-score--scheduled">–</div>
                      <?php endif; ?>
                      
                      <!-- Eventos del Equipo B (Goles y Tarjetas alineados) -->
                      <div class="team-events">
                        <!-- Goleadores Equipo B -->
                        <div class="team-scorers">
                          <?php 
                          $scorers = isset($match['scorersData']) ? json_decode($match['scorersData'], true) : null;
                          $scorersB = isset($scorers['teamB']) ? $scorers['teamB'] : array();
                          foreach ($scorersB as $sc):
                          ?>
                            <div class="scorer-item"><span class="event-icon">⚽</span><?= htmlspecialchars($sc) ?></div>
                          <?php endforeach; ?>
                        </div>

                        <!-- Tarjetas Equipo B -->
                        <div class="team-cards" id="cardsB-<?= $match['id'] ?>">
                          <?php 
                          $cards = isset($match['cardsData']) ? json_decode($match['cardsData'], true) : null;
                          $yellowsB = isset($cards['teamB']['yellow']) ? $cards['teamB']['yellow'] : array();
                          $redsB = isset($cards['teamB']['red']) ? $cards['teamB']['red'] : array();
                          foreach ($yellowsB as $cardStr):
                          ?>
                            <div class="card-item-yellow"><span class="event-icon">🟨</span><?= htmlspecialchars($cardStr) ?></div>
                          <?php endforeach; ?>
                          <?php foreach ($redsB as $cardStr): ?>
                            <div class="card-item-red"><span class="event-icon">🟥</span><?= htmlspecialchars($cardStr) ?></div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Área de pronóstico -->
                  <div class="prediction-area">
                    <?php if ($isFinished): ?>
                      <div class="result-final">
                        <p class="result-label">Resultado Final</p>
                        <?php if ($pred): ?>
                          <div class="pred-comparison">
                            <div class="pred-yours">
                              <span class="pred-label">Tu pronóstico</span>
                              <span class="pred-score"><?= $pred['scoreA'] ?> – <?= $pred['scoreB'] ?></span>
                            </div>
                            <div class="pred-points <?= $projPts === 6 ? 'pts-exact' : ($projPts === 3 ? 'pts-result' : 'pts-miss') ?>">
                              <?php if ($projPts === 6): ?>
                                🎯 +6 pts
                              <?php elseif ($projPts === 3): ?>
                                ✓ +3 pts
                              <?php else: ?>
                                ✗ 0 pts
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php else: ?>
                          <p class="pred-label" style="margin-top:0.5rem">No hiciste pronóstico</p>
                        <?php endif; ?>
                      </div>

                    <?php elseif ($isLive): ?>
                      <!-- Partido en vivo: mostrar pronóstico y puntos proyectados -->
                      <div class="result-final">
                        <?php if ($pred): ?>
                          <div class="pred-comparison">
                            <div class="pred-yours">
                              <span class="pred-label">Tu pronóstico</span>
                              <span class="pred-score"><?= $pred['scoreA'] ?> – <?= $pred['scoreB'] ?></span>
                            </div>
                            <div class="pred-points-live <?= $projPts === 6 ? 'pts-exact' : ($projPts === 3 ? 'pts-result' : 'pts-miss') ?>"
                                 id="projPts-<?= $match['id'] ?>">
                              <?php if ($projPts === 6): ?>
                                🎯 +6 pts
                              <?php elseif ($projPts === 3): ?>
                                ✓ +3 pts
                              <?php else: ?>
                                ✗ 0 pts
                              <?php endif; ?>
                              <span class="pts-live-tag">en vivo</span>
                            </div>
                          </div>
                        <?php else: ?>
                          <p class="pred-label">No hiciste pronóstico</p>
                        <?php endif; ?>
                      </div>

                    <?php elseif ($locked): ?>
                      <div class="result-final">
                        <p class="locked-label">🔒 Pronósticos Cerrados</p>
                        <div class="locked-score">
                          <?= $pred ? $pred['scoreA'] : '-' ?> – <?= $pred ? $pred['scoreB'] : '-' ?>
                        </div>
                        <p class="locked-sub">El partido está por comenzar</p>
                      </div>

                    <?php elseif ($user['role'] === 'ADMIN'): ?>
                      <div class="result-final">
                        <p class="locked-label" style="color:var(--accent-color)">🔒 Administrador</p>
                        <p class="locked-sub" style="margin-top:0.2rem">Vista de lectura</p>
                      </div>
                    <?php else: ?>
                      <div class="score-inputs">
                        <input type="number" min="0" max="20"
                               class="score-input input-a"
                               value="<?= $pred ? $pred['scoreA'] : '' ?>"
                               placeholder="–" />
                        <span style="color:var(--text-secondary); font-weight:900">–</span>
                        <input type="number" min="0" max="20"
                               class="score-input input-b"
                               value="<?= $pred ? $pred['scoreB'] : '' ?>"
                               placeholder="–" />
                      </div>
                      <button class="btn-save" data-match-id="<?= $match['id'] ?>">
                        <?= $pred ? 'Actualizar Pronóstico' : 'Guardar Pronóstico' ?>
                      </button>
                      <div class="save-status"></div>
                    <?php endif; ?>
                  </div>

                  <?php if ($status === 'HALFTIME'): 
                      $animals = array('🐱', '🐶', '🐹', '🐮', '🐷', '🐣', '🦆', '🦛', '🐭', '🐼', '🐨', '🐰', '🐻', '🦊', '🦁');
                      $dances = array('dance-bounce', 'dance-swing', 'dance-wobble', 'dance-jump');
                      $randAnimal = $animals[array_rand($animals)];
                      $randDance = $dances[array_rand($dances)];
                  ?>
                    <div class="halftime-show">
                      <div class="halftime-bubble">Show de medio tiempo</div>
                      <div class="halftime-character <?= $randDance ?>"><?= $randAnimal ?></div>
                    </div>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
      </div>

      <!-- Tab: Grupos -->
      <div id="tab-groups" class="tab-pane">
        <div class="glass-panel" style="padding: 1.5rem;">
          <div class="groups-grid">
            <?php 
            $standings = getGroupStandings($db);
            foreach ($standings as $groupName => $teams):
            ?>
              <div class="group-table-card">
                <h4 class="group-title"><?= htmlspecialchars($groupName) ?></h4>
                <table class="group-table">
                  <thead>
                    <tr>
                      <th class="num" style="width: 1.2rem">#</th>
                      <th>Equipo</th>
                      <th class="num" title="Partidos Jugados">PJ</th>
                      <th class="num" title="Ganados">G</th>
                      <th class="num" title="Empatados">E</th>
                      <th class="num" title="Perdidos">P</th>
                      <th class="num" title="Goles a Favor">GF</th>
                      <th class="num" title="Goles en Contra">GC</th>
                      <th class="num" title="Diferencia de Goles">DG</th>
                      <th class="num" title="Puntos" style="color:var(--accent-color)">PTS</th>
                    </tr>
                  </thead>
                  <tbody id="group-body-<?= htmlspecialchars(str_replace(' ', '-', $groupName)) ?>">
                    <?php 
                    $pos = 1;
                    foreach ($teams as $team):
                      $dg = $team['gf'] - $team['gc'];
                      $dgStr = $dg > 0 ? "+$dg" : $dg;
                      $isQualifier = $pos <= 2;
                    ?>
                      <tr class="<?= $isQualifier ? 'top-two' : '' ?>">
                        <td class="num pos"><?= $pos++ ?></td>
                        <td class="team-cell">
                          <?php if ($team['flag']): ?>
                            <img src="<?= $team['flag'] ?>" alt="<?= htmlspecialchars($team['name']) ?>" />
                          <?php endif; ?>
                          <span class="team-name-abbrev" title="<?= htmlspecialchars($team['name']) ?>">
                            <?= htmlspecialchars($team['name']) ?>
                          </span>
                        </td>
                        <td class="num"><?= $team['pj'] ?></td>
                        <td class="num"><?= $team['pg'] ?></td>
                        <td class="num"><?= $team['pe'] ?></td>
                        <td class="num"><?= $team['pp'] ?></td>
                        <td class="num"><?= $team['gf'] ?></td>
                        <td class="num"><?= $team['gc'] ?></td>
                        <td class="num"><?= $dgStr ?></td>
                        <td class="num" style="font-weight: 800; color:var(--accent-color)"><?= $team['pts'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Tab: Estadísticas -->
      <div id="tab-stats" class="tab-pane">
        <div class="glass-panel" style="padding: 1.5rem;">
          <div class="stats-tab-grid">
            <!-- Columna Goleadores y Tarjetas -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
              <!-- Tarjeta Goleadores -->
              <div class="stats-card">
                <h4 class="group-title" style="color: var(--accent-color)">⚽ Líderes de Goleo</h4>
                <table class="group-table" style="font-size: 0.9rem">
                  <thead>
                    <tr>
                      <th class="num" style="width: 2rem">Pos</th>
                      <th>Jugador</th>
                      <th class="num" style="color: var(--accent-color)">Goles</th>
                    </tr>
                  </thead>
                  <tbody id="top-scorers-body">
                    <?php 
                    $topScorers = getTopScorers($db);
                    if (empty($topScorers)):
                    ?>
                      <tr>
                        <td colspan="3" style="text-align: center; color: var(--text-secondary); padding: 2rem;">
                          No hay goles registrados aún
                        </td>
                      </tr>
                    <?php 
                    else:
                      $pos = 1;
                      foreach ($topScorers as $player => $info):
                    ?>
                      <tr>
                        <td class="num pos"><?= $pos++ ?></td>
                        <td>
                          <div style="font-weight: 700"><?= htmlspecialchars($player) ?></div>
                          <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.2rem;">
                            <?php if (!empty($info['flag'])): ?>
                              <img src="<?= $info['flag'] ?>" alt="<?= htmlspecialchars($info['team']) ?>" style="width: 16px; height: auto; border-radius: 2px;" />
                            <?php endif; ?>
                            <span><?= htmlspecialchars($info['team']) ?></span>
                          </div>
                        </td>
                        <td class="num" style="font-weight: 800; color: var(--accent-color); font-size: 1.1rem">
                          <?= $info['goals'] ?>
                        </td>
                      </tr>
                    <?php 
                      endforeach;
                    endif;
                    ?>
                  </tbody>
                </table>
              </div>

              <!-- Tarjeta Líderes de Tarjetas -->
              <div class="stats-card">
                <h4 class="group-title" style="color: #ffaa00">🟨 Líderes de Tarjetas</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                  <!-- Amarillas -->
                  <div>
                    <h5 style="color: #ffaa00; margin-bottom: 0.5rem; font-size: 0.8rem; text-transform: uppercase;">Amarillas 🟨</h5>
                    <table class="group-table" style="font-size: 0.8rem">
                      <tbody id="top-yellows-body">
                        <?php 
                        $topCards = getTopCards($db);
                        $yellowLeaders = isset($topCards['yellow']) ? $topCards['yellow'] : array();
                        if (empty($yellowLeaders)):
                        ?>
                          <tr><td style="color:var(--text-secondary); text-align:center; padding:1rem;">Ninguna</td></tr>
                        <?php 
                        else:
                          foreach ($yellowLeaders as $player => $info):
                        ?>
                          <tr>
                            <td>
                              <div style="font-weight:700"><?= htmlspecialchars($player) ?></div>
                              <div style="font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.1rem;">
                                <?php if (!empty($info['flag'])): ?>
                                  <img src="<?= $info['flag'] ?>" alt="<?= htmlspecialchars($info['team']) ?>" style="width: 14px; height: auto; border-radius: 1px;" />
                                <?php endif; ?>
                                <span><?= htmlspecialchars($info['team']) ?></span>
                              </div>
                            </td>
                            <td class="num" style="font-weight:800; color:#ffaa00"><?= $info['count'] ?></td>
                          </tr>
                        <?php 
                          endforeach;
                        endif;
                        ?>
                      </tbody>
                    </table>
                  </div>
                  <!-- Rojas -->
                  <div>
                    <h5 style="color: #ff0055; margin-bottom: 0.5rem; font-size: 0.8rem; text-transform: uppercase;">Rojas 🟥</h5>
                    <table class="group-table" style="font-size: 0.8rem">
                      <tbody id="top-reds-body">
                        <?php 
                        $redLeaders = isset($topCards['red']) ? $topCards['red'] : array();
                        if (empty($redLeaders)):
                        ?>
                          <tr><td style="color:var(--text-secondary); text-align:center; padding:1rem;">Ninguna</td></tr>
                        <?php 
                        else:
                          foreach ($redLeaders as $player => $info):
                        ?>
                          <tr>
                            <td>
                              <div style="font-weight:700"><?= htmlspecialchars($player) ?></div>
                              <div style="font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.1rem;">
                                <?php if (!empty($info['flag'])): ?>
                                  <img src="<?= $info['flag'] ?>" alt="<?= htmlspecialchars($info['team']) ?>" style="width: 14px; height: auto; border-radius: 1px;" />
                                <?php endif; ?>
                                <span><?= htmlspecialchars($info['team']) ?></span>
                              </div>
                            </td>
                            <td class="num" style="font-weight:800; color:#ff0055"><?= $info['count'] ?></td>
                          </tr>
                        <?php 
                          endforeach;
                        endif;
                        ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <!-- Columna Datos del Torneo -->
            <div class="stats-summary-column">
              <div class="stats-card">
                <h4 class="group-title" style="color: var(--fifa-magenta)">📈 Resumen del Mundial</h4>
                
                <?php $tStats = getTournamentStats($db); ?>
                <div class="stats-metric-grid" style="grid-template-columns: repeat(3, 1fr);">
                  <div class="metric-card">
                    <span class="metric-value" id="stat-pj"><?= $tStats['pj'] ?></span>
                    <span class="metric-label">Partidos</span>
                  </div>
                  <div class="metric-card">
                    <span class="metric-value" id="stat-totalGoals" style="color: var(--fifa-cyan)"><?= $tStats['totalGoals'] ?></span>
                    <span class="metric-label">Goles</span>
                  </div>
                  <div class="metric-card">
                    <span class="metric-value" id="stat-avgGoals"><?= $tStats['avgGoals'] ?></span>
                    <span class="metric-label">Promedio</span>
                  </div>
                  <div class="metric-card">
                    <span class="metric-value" id="stat-penalties" style="color: #ffaa00"><?= $tStats['penalties'] ?></span>
                    <span class="metric-label">Penales</span>
                  </div>
                  <div class="metric-card">
                    <span class="metric-value" id="stat-yellows" style="color: #ffe600">🟨 <?= $tStats['totalYellows'] ?></span>
                    <span class="metric-label">Amarillas</span>
                  </div>
                  <div class="metric-card">
                    <span class="metric-value" id="stat-reds" style="color: #ff0055">🟥 <?= $tStats['totalReds'] ?></span>
                    <span class="metric-label">Rojas</span>
                  </div>
                </div>
              </div>

              <!-- Records del Torneo -->
              <div class="stats-card" style="margin-top: 1.5rem">
                <h4 class="group-title" style="color: var(--fifa-cyan)">🏆 Récords de Equipos</h4>
                <div class="record-item">
                  <span class="record-title">⚽ Delantera Más Goleadora</span>
                  <span class="record-detail" id="record-best-attack">
                    <?= $tStats['bestAttackTeam'] ? htmlspecialchars($tStats['bestAttackTeam']) . " (" . $tStats['bestAttackGoals'] . " goles)" : "–" ?>
                  </span>
                </div>
                <div class="record-item">
                  <span class="record-title">🛡️ Defensa Más Vencida</span>
                  <span class="record-detail" id="record-worst-defense">
                    <?= $tStats['worstDefenseTeam'] ? htmlspecialchars($tStats['worstDefenseTeam']) . " (" . $tStats['worstDefenseGoals'] . " goles)" : "–" ?>
                  </span>
                </div>
                <div class="record-item">
                  <span class="record-title">🔥 Partido con Más Goles</span>
                  <span class="record-detail" id="record-max-goals">
                    <?php 
                    if ($tStats['maxGoalsMatch']) {
                      echo htmlspecialchars($tStats['maxGoalsMatch']['teamA']) . " " . $tStats['maxGoalsMatch']['scoreA'] . " – " . $tStats['maxGoalsMatch']['scoreB'] . " " . htmlspecialchars($tStats['maxGoalsMatch']['teamB']);
                    } else {
                      echo "–";
                    }
                    ?>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Leaderboard -->
    <section>
      <h2 class="section-title">🏆 Clasificación</h2>

      <!-- Card Bolsa Acumulada -->
      <div class="glass-panel prize-pool-card" style="margin-bottom: 1.5rem; padding: 1.2rem; border-color: rgba(255, 215, 0, 0.25); background: linear-gradient(135deg, rgba(20, 25, 35, 0.95), rgba(255, 215, 0, 0.03));">
        <div style="display: flex; align-items: center; gap: 1rem;">
          <div style="font-size: 2.2rem; line-height: 1; filter: drop-shadow(0 0 10px rgba(255, 215, 0, 0.3));">💰</div>
          <div>
            <div style="font-size: 0.72rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; font-weight: 700;">Bolsa Acumulada</div>
            <div style="font-size: 1.6rem; font-weight: 900; color: #ffd700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.2);" id="prize-pool-amount">
              $<?= number_format($totalPrizePool) ?> MXN
            </div>
            <div style="font-size: 0.72rem; color: var(--text-secondary);" id="prize-pool-participants">
              <?= $paidCount ?> <?= $paidCount === 1 ? 'participante' : 'participantes' ?> de $500 pesos
            </div>
          </div>
        </div>
      </div>

      <div class="leaderboard" id="leaderboard">
        <?php $medals = ['🥇']; ?>
        <?php foreach ($leaderboard as $i => $u): ?>
          <div class="leaderboard-row <?= $i < 3 ? 'top-'.($i+1) : '' ?>">
            <div class="lb-rank"><?= $medals[$i] ?? ($i + 1) ?></div>
            <div class="lb-name">
              <?= htmlspecialchars($u['username']) ?>
              <?php if (!empty($u['hasPaid'])): ?>
                <span class="paid-indicator" title="Participa por la bolsa de premios">$</span>
              <?php endif; ?>
              <?php if ((int)$u['id'] === (int)$user['id']): ?>
                <span style="font-size:0.7rem; color:var(--accent-color)"> (tú)</span>
              <?php endif; ?>
            </div>
            <div class="lb-score-block">
              <div class="lb-points"><?= $u['total'] ?></div>
              <div class="lb-pts-label">pts</div>
              <?php if ($u['projected'] > 0): ?>
                <div class="lb-projected">+<?= $u['projected'] ?> en vivo</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($leaderboard)): ?>
          <div style="padding:2rem; text-align:center; color:var(--text-secondary)">
            Aún no hay participantes
          </div>
        <?php endif; ?>
      </div>
    </section>

  </div>

  <script>
    // Indicar al JS si hay partidos en vivo para activar el polling
    window.HAS_LIVE = <?= $hasLive ? 'true' : 'false' ?>;

    // Control de la sección de reglas
    function toggleRules() {
      const content = document.getElementById('rules-content');
      const icon = document.getElementById('rules-toggle-icon');
      if (content.style.maxHeight === '0px' || content.style.maxHeight === '') {
        content.style.maxHeight = '1000px';
        content.style.opacity = '1';
        content.style.marginTop = '1rem';
        icon.textContent = 'Ocultar ▲';
        icon.style.color = 'var(--accent-color)';
        icon.style.background = 'rgba(0, 255, 136, 0.1)';
      } else {
        content.style.maxHeight = '0px';
        content.style.opacity = '0';
        content.style.marginTop = '0';
        icon.textContent = 'Mostrar ▼';
        icon.style.color = 'var(--text-secondary)';
        icon.style.background = 'rgba(255,255,255,0.05)';
      }
    }

    // Control de pestañas (Partidos / Grupos)
    function switchTab(tabId) {
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
      
      if (tabId === 'matches') {
        document.getElementById('btn-tab-matches').classList.add('active');
        document.getElementById('tab-matches').classList.add('active');
      } else if (tabId === 'groups') {
        document.getElementById('btn-tab-groups').classList.add('active');
        document.getElementById('tab-groups').classList.add('active');
      } else if (tabId === 'stats') {
        document.getElementById('btn-tab-stats').classList.add('active');
        document.getElementById('tab-stats').classList.add('active');
      }
    }
  </script>
  <!-- Modal de Detalles del Partido (Live Stats & Lineups) -->
  <div id="match-details-modal" class="modal">
    <div class="modal-content glass-panel">
      <span class="close-modal" onclick="closeMatchDetails()">&times;</span>
      
      <!-- Modal Header: Teams & Score -->
      <div class="modal-match-header">
        <div class="modal-team modal-team-a">
          <img id="modal-flag-a" src="" alt="Bandera A" />
          <h2 id="modal-name-a">Equipo A</h2>
        </div>
        
        <div class="modal-score-center">
          <div id="modal-score-value">0 – 0</div>
          <div id="modal-status-badge">PROGRAMADO</div>
        </div>
        
        <div class="modal-team modal-team-b">
          <img id="modal-flag-b" src="" alt="Bandera B" />
          <h2 id="modal-name-b">Equipo B</h2>
        </div>
      </div>
      
      <!-- Info adicional (Estadio, Árbitro, Asistencia) -->
      <div class="modal-match-info">
        <span id="modal-info-venue">📍 Sede</span>
        <span id="modal-info-referee">👤 Árbitro: –</span>
        <span id="modal-info-attendance">👥 Asistencia: –</span>
      </div>
      
      <!-- Tabs del Modal -->
      <div class="modal-tabs">
        <button class="modal-tab-btn active" id="modal-btn-stats" onclick="switchModalTab('stats')">📊 Estadísticas</button>
        <button class="modal-tab-btn" id="modal-btn-lineups" onclick="switchModalTab('lineups')">📋 Alineaciones</button>
        <button class="modal-tab-btn" id="modal-btn-subs" onclick="switchModalTab('subs')">🔄 Cambios</button>
      </div>
      
      <!-- Tab Content: Stats -->
      <div id="modal-tab-stats" class="modal-tab-pane active">
        <div class="modal-stats-list" id="modal-stats-container">
          <!-- Las barras de estadísticas se insertarán aquí dinámicamente -->
        </div>
      </div>
      
      <!-- Tab Content: Lineups -->
      <div id="modal-tab-lineups" class="modal-tab-pane">
        <div class="modal-lineups-grid">
          <div class="lineup-column">
            <h3 class="lineup-team-title" id="lineup-title-a">Equipo A</h3>
            <div class="lineup-section">
              <h4>Titulares</h4>
              <ul id="lineup-starters-a"></ul>
            </div>
            <div class="lineup-section" style="margin-top: 1.2rem;">
              <h4>Suplentes</h4>
              <ul id="lineup-bench-a"></ul>
            </div>
          </div>
          <div class="lineup-column">
            <h3 class="lineup-team-title" id="lineup-title-b">Equipo B</h3>
            <div class="lineup-section">
              <h4>Titulares</h4>
              <ul id="lineup-starters-b"></ul>
            </div>
            <div class="lineup-section" style="margin-top: 1.2rem;">
              <h4>Suplentes</h4>
              <ul id="lineup-bench-b"></ul>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Tab Content: Subs -->
      <div id="modal-tab-subs" class="modal-tab-pane">
        <div class="modal-subs-grid">
          <div class="subs-column">
            <h3 class="subs-team-title" id="subs-title-a">Equipo A</h3>
            <div class="subs-timeline" id="subs-timeline-a"></div>
          </div>
          <div class="subs-column">
            <h3 class="subs-team-title" id="subs-title-b">Equipo B</h3>
            <div class="subs-timeline" id="subs-timeline-b"></div>
          </div>
        </div>
      </div>
      
    </div>
  </div>

  <script src="js/app.js?v=3.5"></script>
</body>
</html>
