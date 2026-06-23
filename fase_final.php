<?php
require_once 'config.php';
$user = requireLogin();
$db = getDB();

// Obtener todos los partidos de la Fase Final (IDs 148 a 179)
$matchesQuery = $db->query("SELECT * FROM `Match` WHERE id >= 148 ORDER BY id ASC")->fetchAll();
$ffMatches = [];
foreach ($matchesQuery as $m) {
    $ffMatches[$m['id']] = $m;
}

// Obtener pronósticos del usuario actual para la Fase Final
$predStmt = $db->prepare("SELECT * FROM `Prediction` WHERE userId = ? AND matchId >= 148");
$predStmt->execute([$user['id']]);
$predictions = $predStmt->fetchAll();
$predMap = [];
foreach ($predictions as $p) {
    $predMap[$p['matchId']] = $p;
}

// Clasificación con puntos proyectados para el Leaderboard lateral (se mantiene igual a index.php para consistencia)
$allUsers = $db->query("SELECT id, username, points, hasPaid FROM `User` WHERE role != 'ADMIN' ORDER BY points DESC, username ASC")->fetchAll();

$allPreds = $db->query("
    SELECT p.userId, p.matchId, p.scoreA AS predA, p.scoreB AS predB, p.points AS confirmedPts,
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

// Agrupar partidos para la pestaña de lista de Pronósticos
$dieciseisavos = [];
$octavos = [];
$cuartos = [];
$semis = [];
$tercerLugar = [];
$finalMatch = [];
$hasLive = false;

foreach ($matchesQuery as $m) {
    $id = (int)$m['id'];
    if ($id >= 148 && $id <= 163) $dieciseisavos[] = $m;
    elseif ($id >= 164 && $id <= 171) $octavos[] = $m;
    elseif ($id >= 172 && $id <= 175) $cuartos[] = $m;
    elseif ($id >= 176 && $id <= 177) $semis[] = $m;
    elseif ($id === 178) $tercerLugar[] = $m;
    elseif ($id === 179) $finalMatch[] = $m;
    
    if (in_array($m['status'], ['LIVE', 'HALFTIME'])) $hasLive = true;
}

$groupedFf = [
    'Dieciseisavos de Final' => $dieciseisavos,
    'Octavos de Final' => $octavos,
    'Cuartos de Final' => $cuartos,
    'Semifinales' => $semis,
    'Tercer Lugar' => $tercerLugar,
    'Gran Final' => $finalMatch
];

$paidCount = 0;
foreach ($leaderboard as $u) {
    if (!empty($u['hasPaid'])) {
        $paidCount++;
    }
}
$totalPrizePool = $paidCount * 500;

// Función auxiliar para renderizar los partidos en el árbol visual
function renderBracketMatch($matchId, $db) {
    $stmt = $db->prepare("SELECT * FROM `Match` WHERE id = ?");
    $stmt->execute([$matchId]);
    $m = $stmt->fetch();
    if (!$m) return '';
    
    $flagA = '';
    $flagB = '';
    $teamA = resolvePlaceholderTeam($m['teamA'], $db, $flagA);
    $teamB = resolvePlaceholderTeam($m['teamB'], $db, $flagB);
    
    $scoreA = $m['scoreA'];
    $scoreB = $m['scoreB'];
    
    $isFinished = (bool)$m['isFinished'];
    $status = $m['status'] ?? 'SCHEDULED';
    $isLive = in_array($status, ['LIVE', 'HALFTIME']);
    
    $hasStarted = ($isLive || $isFinished);
    
    $classTeamA = '';
    $classTeamB = '';
    $scoreClassA = '';
    $scoreClassB = '';
    
    if ($isFinished && !empty($m['winner'])) {
        if ($m['winner'] === $teamA) {
            $classTeamA = 'winner-row';
            $scoreClassA = 'winner-score';
        } elseif ($m['winner'] === $teamB) {
            $classTeamB = 'winner-row';
            $scoreClassB = 'winner-score';
        }
    }
    
    $cardClass = 'bracket-match-card';
    if ($isLive) $cardClass .= ' bracket-match-card--live';
    if ($hasStarted) $cardClass .= ' clickable';
    
    $clickAttr = $hasStarted ? 'onclick="openMatchDetails(' . $matchId . ')"' : '';
    
    $teamNameRenderA = ($teamA === $m['teamA']) ? '<span class="bracket-placeholder-team">' . htmlspecialchars($teamA) . '</span>' : htmlspecialchars($teamA);
    $teamNameRenderB = ($teamB === $m['teamB']) ? '<span class="bracket-placeholder-team">' . htmlspecialchars($teamB) . '</span>' : htmlspecialchars($teamB);
    
    $flagRenderA = $flagA ? '<img src="' . $flagA . '" class="bracket-team-flag" />' : '<div class="bracket-team-flag-placeholder"></div>';
    $flagRenderB = $flagB ? '<img src="' . $flagB . '" class="bracket-team-flag" />' : '<div class="bracket-team-flag-placeholder"></div>';
    
    $scoreRenderA = is_numeric($scoreA) ? $scoreA : '–';
    $scoreRenderB = is_numeric($scoreB) ? $scoreB : '–';
    
    $liveTag = $isLive ? '<span class="bracket-match-live-tag">LIVE</span>' : '';
    
    ob_start();
    ?>
    <div class="<?= $cardClass ?>" data-match-id="<?= $matchId ?>" <?= $clickAttr ?>>
      <div class="bracket-match-number">
        <span>Partido #<?= $matchId ?></span>
        <?= $liveTag ?>
      </div>
      
      <!-- Team A -->
      <div class="bracket-team-row <?= $classTeamA ?>">
        <div class="bracket-team-info">
          <?= $flagRenderA ?>
          <span class="bracket-team-name"><?= $teamNameRenderA ?></span>
        </div>
        <span class="bracket-team-score <?= $scoreClassA ?>" id="scoreA-<?= $matchId ?>"><?= $scoreRenderA ?></span>
      </div>
      
      <!-- Team B -->
      <div class="bracket-team-row <?= $classTeamB ?>">
        <div class="bracket-team-info">
          <?= $flagRenderB ?>
          <span class="bracket-team-name"><?= $teamNameRenderB ?></span>
        </div>
        <span class="bracket-team-score <?= $scoreClassB ?>" id="scoreB-<?= $matchId ?>"><?= $scoreRenderB ?></span>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Fase Final - Quiniela Mundial 2026 🏆</title>
  <meta name="description" content="Eliminación directa de la Quiniela del Mundial 2026 – Bracket y Pronósticos." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=3.32" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="fade-in">

  <!-- Top Bar -->
  <div class="top-bar">
    <span>Hola, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <a href="index.php" class="btn-admin" style="background: var(--fifa-purple)">⬅️ Fase de Grupos</a>
    <?php if ($user['role'] === 'ADMIN'): ?>
      <a href="admin.php" class="btn-admin">⚙️ Panel Admin</a>
    <?php endif; ?>
    <a href="logout.php"><button class="btn-logout">Salir</button></a>
  </div>

  <!-- Header -->
  <header class="site-header">
    <div class="wc-badge">FASE FINAL</div>
    <h1 class="site-title">Eliminación Directa</h1>
    <p class="site-subtitle">¡La hora de la verdad! Llena tus pronósticos y sigue el árbol de encuentros.</p>
    <?php if ($hasLive): ?>
      <div class="live-banner">
        <span class="live-dot"></span> HAY PARTIDOS EN VIVO – Actualizando automáticamente
      </div>
    <?php endif; ?>
  </header>

  <!-- Main Container -->
  <div class="main-container">

    <!-- Secciones Principales -->
    <section>
      <!-- Tabs -->
      <div class="section-header-tabs">
        <button class="tab-btn active" id="btn-tab-ff-matches" onclick="switchFfTab('ff-matches')">⚽ Pronósticos Fase Final</button>
        <button class="tab-btn" id="btn-tab-bracket" onclick="switchFfTab('bracket')">🌳 Árbol de Encuentros</button>
      </div>

      <!-- Tab: Pronósticos Lista -->
      <div id="tab-ff-matches" class="tab-pane active">
        <div class="glass-panel">
          <?php foreach ($groupedFf as $roundName => $roundMatches): 
            if (empty($roundMatches)) continue;
          ?>
            <div class="day-group">
              <div class="day-header" style="cursor: default;">
                <span>🏆 <?= htmlspecialchars($roundName) ?></span>
              </div>
              <div class="day-content">
                <div class="match-grid">
                  <?php foreach ($roundMatches as $match):
                    $pred = $predMap[$match['id']] ?? null;
                    $locked = isLocked($match['date']);
                    
                    $flagA = '';
                    $flagB = '';
                    $teamA = resolvePlaceholderTeam($match['teamA'], $db, $flagA);
                    $teamB = resolvePlaceholderTeam($match['teamB'], $db, $flagB);
                    
                    $time = formatMatchTime($match['date']);
                    $status = $match['status'] ?? 'SCHEDULED';
                    $isLive = in_array($status, ['LIVE', 'HALFTIME']);
                    $isFinished = (bool)$match['isFinished'];
                    
                    $projPts = 0;
                    if ($pred && ($isLive || $isFinished) && is_numeric($match['scoreA'])) {
                        $projPts = calculatePoints((int)$pred['scoreA'], (int)$pred['scoreB'], (int)$match['scoreA'], (int)$match['scoreB']);
                    }
                    $hasStarted = ($isLive || $isFinished || $status === 'HALFTIME');
                  ?>
                    <div class="match-card <?= $isLive ? 'match-card--live' : '' ?> <?= $hasStarted ? 'match-card--clickable' : '' ?>" 
                         data-match-id="<?= $match['id'] ?>"
                         <?= $hasStarted ? 'onclick="openMatchDetails(' . $match['id'] . ')"' : '' ?>>
                      <span class="match-badge">Partido #<?= $match['id'] ?></span>

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
                          <div class="match-time"><?= $time ?> · <?= formatMatchDay($match['date']) ?></div>
                        <?php endif; ?>
                        <?php if ($match['venue']): ?>
                          <div class="match-venue">📍 <?= htmlspecialchars($match['venue']) ?></div>
                        <?php endif; ?>
                      </div>

                      <div class="teams">
                        <!-- Equipo A -->
                        <div class="team">
                          <?php if ($flagA): ?>
                            <img src="<?= $flagA ?>" alt="<?= htmlspecialchars($teamA) ?>" />
                          <?php else: ?>
                            <div class="team-flag-placeholder"></div>
                          <?php endif; ?>
                          <div class="team-name">
                            <span>
                              <?php if ($teamA === $match['teamA']): ?>
                                <span class="bracket-placeholder-team"><?= htmlspecialchars($teamA) ?></span>
                              <?php else: ?>
                                <?= htmlspecialchars($teamA) ?>
                              <?php endif; ?>
                            </span>
                          </div>
                          <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                            <div class="team-realtime-score" id="scoreA-<?= $match['id'] ?>"><?= $match['scoreA'] ?? 0 ?></div>
                          <?php else: ?>
                            <div class="team-realtime-score team-realtime-score--scheduled">–</div>
                          <?php endif; ?>
                        </div>

                        <!-- VS / Estado -->
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

                        <!-- Equipo B -->
                        <div class="team">
                          <?php if ($flagB): ?>
                            <img src="<?= $flagB ?>" alt="<?= htmlspecialchars($teamB) ?>" />
                          <?php else: ?>
                            <div class="team-flag-placeholder"></div>
                          <?php endif; ?>
                          <div class="team-name">
                            <span>
                              <?php if ($teamB === $match['teamB']): ?>
                                <span class="bracket-placeholder-team"><?= htmlspecialchars($teamB) ?></span>
                              <?php else: ?>
                                <?= htmlspecialchars($teamB) ?>
                              <?php endif; ?>
                            </span>
                          </div>
                          <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                            <div class="team-realtime-score" id="scoreB-<?= $match['id'] ?>"><?= $match['scoreB'] ?? 0 ?></div>
                          <?php else: ?>
                            <div class="team-realtime-score team-realtime-score--scheduled">–</div>
                          <?php endif; ?>
                        </div>
                      </div>

                      <!-- Área de Pronóstico -->
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
                                  <?= $projPts === 6 ? '🎯 +6 pts' : ($projPts === 3 ? '✓ +3 pts' : '✗ 0 pts') ?>
                                </div>
                              </div>
                            <?php else: ?>
                              <p class="pred-label" style="margin-top:0.5rem">No hiciste pronóstico</p>
                            <?php endif; ?>
                          </div>

                        <?php elseif ($isLive): ?>
                          <div class="result-final">
                            <?php if ($pred): ?>
                              <div class="pred-comparison">
                                <div class="pred-yours">
                                  <span class="pred-label">Tu pronóstico</span>
                                  <span class="pred-score"><?= $pred['scoreA'] ?> – <?= $pred['scoreB'] ?></span>
                                </div>
                                <div class="pred-points-live <?= $projPts === 6 ? 'pts-exact' : ($projPts === 3 ? 'pts-result' : 'pts-miss') ?>"
                                     id="projPts-<?= $match['id'] ?>">
                                  <?= $projPts === 6 ? '🎯 +6 pts' : ($projPts === 3 ? '✓ +3 pts' : '✗ 0 pts') ?>
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
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Tab: Árbol de Encuentros (Bracket) -->
      <div id="tab-bracket" class="tab-pane" style="display: none;">
        <!-- Selector de Ronda para Móviles -->
        <div class="round-selector-tabs">
          <button class="round-selector-btn active" data-round="16vos" onclick="switchBracketRound('16vos')">16vos</button>
          <button class="round-selector-btn" data-round="8vos" onclick="switchBracketRound('8vos')">8vos</button>
          <button class="round-selector-btn" data-round="cuartos" onclick="switchBracketRound('cuartos')">Cuartos</button>
          <button class="round-selector-btn" data-round="semis" onclick="switchBracketRound('semis')">Semis</button>
          <button class="round-selector-btn" data-round="final" onclick="switchBracketRound('final')">Final</button>
        </div>

        <div class="glass-panel" style="padding: 1.5rem 1rem;">
          <div class="bracket-wrapper">
            <div class="bracket-container">
              <!-- SVG para dibujar las líneas de conexión -->
              <svg id="bracket-svg" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 0;"></svg>
              
              <!-- Columna 1: 16vos Izquierda (8 partidos) -->
              <div class="bracket-column active" data-rounds="16vos">
                <div class="bracket-round-title">16vos de Final (Izquierda)</div>
                <?= renderBracketMatch(148, $db) ?>
                <?= renderBracketMatch(150, $db) ?>
                <?= renderBracketMatch(149, $db) ?>
                <?= renderBracketMatch(153, $db) ?>
                <?= renderBracketMatch(159, $db) ?>
                <?= renderBracketMatch(158, $db) ?>
                <?= renderBracketMatch(157, $db) ?>
                <?= renderBracketMatch(156, $db) ?>
              </div>

              <!-- Columna 2: 8vos Izquierda (4 partidos) -->
              <div class="bracket-column" data-rounds="8vos">
                <div class="bracket-round-title">8vos de Final</div>
                <?= renderBracketMatch(164, $db) ?>
                <?= renderBracketMatch(165, $db) ?>
                <?= renderBracketMatch(168, $db) ?>
                <?= renderBracketMatch(169, $db) ?>
              </div>

              <!-- Columna 3: Cuartos Izquierda (2 partidos) -->
              <div class="bracket-column" data-rounds="cuartos">
                <div class="bracket-round-title">Cuartos de Final</div>
                <?= renderBracketMatch(172, $db) ?>
                <?= renderBracketMatch(173, $db) ?>
              </div>

              <!-- Columna 4: Semifinal Izquierda (1 partido) -->
              <div class="bracket-column" data-rounds="semis">
                <div class="bracket-round-title">Semifinal</div>
                <?= renderBracketMatch(176, $db) ?>
              </div>

              <!-- Columna 5: Final y Tercer Lugar (Centro) -->
              <div class="bracket-column bracket-column-center" data-rounds="final">
                
                <div class="bracket-center-section">
                  <div class="bracket-round-title" style="border-bottom-color: var(--fifa-magenta);">🏆 Gran Final 🏆</div>
                  <?= renderBracketMatch(179, $db) ?>
                </div>

                <div class="bracket-center-section">
                  <div class="bracket-sub-title-center">🥉 Tercer Lugar</div>
                  <?= renderBracketMatch(178, $db) ?>
                </div>

              </div>

              <!-- Columna 6: Semifinal Derecha (1 partido) -->
              <div class="bracket-column" data-rounds="semis">
                <div class="bracket-round-title">Semifinal</div>
                <?= renderBracketMatch(177, $db) ?>
              </div>

              <!-- Columna 7: Cuartos Derecha (2 partidos) -->
              <div class="bracket-column" data-rounds="cuartos">
                <div class="bracket-round-title">Cuartos de Final</div>
                <?= renderBracketMatch(174, $db) ?>
                <?= renderBracketMatch(175, $db) ?>
              </div>

              <!-- Columna 8: 8vos Derecha (4 partidos) -->
              <div class="bracket-column" data-rounds="8vos">
                <div class="bracket-round-title">8vos de Final</div>
                <?= renderBracketMatch(166, $db) ?>
                <?= renderBracketMatch(167, $db) ?>
                <?= renderBracketMatch(170, $db) ?>
                <?= renderBracketMatch(171, $db) ?>
              </div>

              <!-- Columna 9: 16vos Derecha (8 partidos) -->
              <div class="bracket-column active" data-rounds="16vos">
                <div class="bracket-round-title">16vos de Final (Derecha)</div>
                <?= renderBracketMatch(151, $db) ?>
                <?= renderBracketMatch(152, $db) ?>
                <?= renderBracketMatch(154, $db) ?>
                <?= renderBracketMatch(155, $db) ?>
                <?= renderBracketMatch(163, $db) ?>
                <?= renderBracketMatch(162, $db) ?>
                <?= renderBracketMatch(160, $db) ?>
                <?= renderBracketMatch(161, $db) ?>
              </div>

            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Barra Lateral: Leaderboard -->
    <aside>
      <div class="glass-panel" style="margin-bottom: 2rem; border-color: rgba(255, 0, 85, 0.2);">
        <h3 style="margin-top:0; font-size:1.15rem; font-weight:800; letter-spacing:0.5px; display:flex; align-items:center; gap:0.5rem; color:var(--fifa-magenta)">
          💰 BOLSA ACUMULADA
        </h3>
        <div style="font-size: 2.2rem; font-weight: 900; color: white; text-shadow: 0 0 15px rgba(255, 0, 85, 0.4); margin: 0.5rem 0;">
          $<?= number_format($totalPrizePool, 0) ?> MXN
        </div>
        <p style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4; margin: 0;">
          Entradas confirmadas: <strong><?= $paidCount ?></strong> de $500 c/u.
        </p>
      </div>

      <h2 class="section-title">🏆 Clasificación General</h2>
      <div class="leaderboard">
        <?php foreach ($leaderboard as $idx => $player): 
          $rank = $idx + 1;
          $rowClass = ($rank == 1) ? 'top-1' : (($rank == 2) ? 'top-2' : (($rank == 3) ? 'top-3' : ''));
          $isSelf = ($player['id'] == $user['id']);
          $displayName = htmlspecialchars($player['username']);
          if ($isSelf) $displayName .= ' <span style="color:var(--fifa-cyan); font-weight:800">(Tú)</span>';
          if (!empty($player['hasPaid'])) {
              $displayName .= ' <span class="paid-marker" title="Entrada pagada">$</span>';
          }
        ?>
          <div class="leaderboard-row <?= $rowClass ?> <?= $isSelf ? 'leaderboard-row--self' : '' ?>">
            <span class="lb-rank">#<?= $rank ?></span>
            <span class="lb-name"><?= $displayName ?></span>
            <div class="lb-score-block">
              <span class="lb-points" id="lb-pts-<?= $player['id'] ?>"><?= $player['total'] ?></span>
              <div class="lb-pts-label">
                <span id="lb-conf-<?= $player['id'] ?>"><?= $player['confirmed'] ?></span> conf + 
                <span id="lb-proj-<?= $player['id'] ?>"><?= $player['projected'] ?></span> proj
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>

  </div>

  <!-- Modal de Detalles del Partido (Live Stats & Lineups) -->
  <div id="match-details-modal" class="modal">
    <div class="modal-content glass-panel">
      <span class="close-modal" onclick="closeMatchDetails()">&times;</span>
      
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
      
      <div class="modal-match-info">
        <span id="modal-info-venue">📍 Sede</span>
        <span id="modal-info-referee">👤 Árbitro: –</span>
        <span id="modal-info-attendance">👥 Asistencia: –</span>
      </div>
      
      <div class="modal-tabs">
        <button class="modal-tab-btn active" id="modal-btn-stats" onclick="switchModalTab('stats')">📊 Estadísticas</button>
        <button class="modal-tab-btn" id="modal-btn-lineups" onclick="switchModalTab('lineups')">📋 Alineaciones</button>
        <button class="modal-tab-btn" id="modal-btn-subs" onclick="switchModalTab('subs')">🔄 Cambios</button>
      </div>
      
      <div id="modal-tab-stats" class="modal-tab-pane active">
        <div class="modal-stats-list" id="modal-stats-container"></div>
      </div>
      
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

  <!-- Botón Flotante para Móviles -->
  <button id="mobile-fab" class="mobile-fab" data-action="leaderboard" onclick="handleFabClick()">
    🏆 Tabla
  </button>

  <script>
    // Variable global para que app.js sepa si hay partidos en vivo
    window.HAS_LIVE = <?= $hasLive ? 'true' : 'false' ?>;

    // Conexiones de partidos en el árbol (quién alimenta a quién)
    const bracketConnections = [
      // Izquierda: 16vos -> 8vos
      { parentA: 148, parentB: 150, child: 164, side: 'left' },
      { parentA: 149, parentB: 153, child: 165, side: 'left' },
      { parentA: 159, parentB: 158, child: 168, side: 'left' },
      { parentA: 157, parentB: 156, child: 169, side: 'left' },

      // Izquierda: 8vos -> Cuartos
      { parentA: 164, parentB: 165, child: 172, side: 'left' },
      { parentA: 168, parentB: 169, child: 173, side: 'left' },

      // Izquierda: Cuartos -> Semis
      { parentA: 172, parentB: 173, child: 176, side: 'left' },

      // Izquierda: Semis -> Final
      { parentA: 176, child: 179, side: 'left-final' },

      // Derecha: 16vos -> 8vos
      { parentA: 151, parentB: 152, child: 166, side: 'right' },
      { parentA: 154, parentB: 155, child: 167, side: 'right' },
      { parentA: 163, parentB: 162, child: 170, side: 'right' },
      { parentA: 160, parentB: 161, child: 171, side: 'right' },

      // Derecha: 8vos -> Cuartos
      { parentA: 166, parentB: 167, child: 174, side: 'right' },
      { parentA: 170, parentB: 171, child: 175, side: 'right' },

      // Derecha: Cuartos -> Semis
      { parentA: 174, parentB: 175, child: 177, side: 'right' },

      // Derecha: Semis -> Final
      { parentA: 177, child: 179, side: 'right-final' }
    ];

    // Verifica si la ruta entre un partido padre y su hijo está activa (es decir, el ganador del padre está en el hijo)
    function isPathActive(parentCard, childCard) {
      const winnerRow = parentCard.querySelector('.winner-row');
      if (!winnerRow) return false;
      
      const winnerNameEl = winnerRow.querySelector('.bracket-team-name');
      if (!winnerNameEl) return false;
      
      const winnerName = winnerNameEl.textContent.trim().toLowerCase();
      if (!winnerName || winnerName.includes('ganador') || winnerName.includes('perdedor') || winnerName.includes('3a') || winnerName.includes('3b')) {
        return false;
      }
      
      const childTeamNames = Array.from(childCard.querySelectorAll('.bracket-team-name'))
        .map(el => el.textContent.trim().toLowerCase());
        
      return childTeamNames.includes(winnerName);
    }

    // Dibuja una bifurcación ortogonal entre dos padres y un hijo
    function drawOrthogonalBranch(svg, xA, yA, xB, yB, xC, yC, midX, activeA, activeB) {
      const pathA = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      pathA.setAttribute('d', `M ${xA} ${yA} H ${midX} V ${yC} H ${xC}`);
      pathA.setAttribute('fill', 'none');
      if (activeA) {
        pathA.setAttribute('stroke', 'var(--accent-color)');
        pathA.setAttribute('stroke-width', '3');
        pathA.setAttribute('style', 'filter: drop-shadow(0 0 5px var(--accent-color));');
        pathA.setAttribute('stroke-linecap', 'round');
        pathA.setAttribute('stroke-linejoin', 'round');
      } else {
        pathA.setAttribute('stroke', 'rgba(255, 255, 255, 0.12)');
        pathA.setAttribute('stroke-width', '2');
        pathA.setAttribute('stroke-linecap', 'round');
        pathA.setAttribute('stroke-linejoin', 'round');
      }
      
      const pathB = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      pathB.setAttribute('d', `M ${xB} ${yB} H ${midX} V ${yC} H ${xC}`);
      pathB.setAttribute('fill', 'none');
      if (activeB) {
        pathB.setAttribute('stroke', 'var(--accent-color)');
        pathB.setAttribute('stroke-width', '3');
        pathB.setAttribute('style', 'filter: drop-shadow(0 0 5px var(--accent-color));');
        pathB.setAttribute('stroke-linecap', 'round');
        pathB.setAttribute('stroke-linejoin', 'round');
      } else {
        pathB.setAttribute('stroke', 'rgba(255, 255, 255, 0.12)');
        pathB.setAttribute('stroke-width', '2');
        pathB.setAttribute('stroke-linecap', 'round');
        pathB.setAttribute('stroke-linejoin', 'round');
      }

      // Añadir la inactiva primero para que la activa quede arriba
      if (!activeA && !activeB) {
        svg.appendChild(pathA);
        svg.appendChild(pathB);
      } else if (activeA) {
        svg.appendChild(pathB);
        svg.appendChild(pathA);
      } else {
        svg.appendChild(pathA);
        svg.appendChild(pathB);
      }
    }

    // Dibuja una línea única (Semifinal a Final)
    function drawSingleLine(svg, xA, yA, xC, yC, midX, active) {
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', `M ${xA} ${yA} H ${midX} V ${yC} H ${xC}`);
      path.setAttribute('fill', 'none');
      if (active) {
        path.setAttribute('stroke', 'var(--accent-color)');
        path.setAttribute('stroke-width', '3');
        path.setAttribute('style', 'filter: drop-shadow(0 0 5px var(--accent-color));');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
      } else {
        path.setAttribute('stroke', 'rgba(255, 255, 255, 0.12)');
        path.setAttribute('stroke-width', '2');
        path.setAttribute('stroke-linecap', 'round');
        path.setAttribute('stroke-linejoin', 'round');
      }
      svg.appendChild(path);
    }

    // Dibuja todas las líneas de conexión basadas en la geometría actual
    function drawBracketLines() {
      const svg = document.getElementById('bracket-svg');
      if (!svg) return;
      
      svg.innerHTML = '';
      
      // Ocultar líneas en móvil para evitar desalineación (las columnas se apilan)
      if (window.innerWidth <= 1000) {
        return;
      }
      
      const container = document.querySelector('.bracket-container');
      if (!container || container.offsetWidth === 0) return;
      
      const containerRect = container.getBoundingClientRect();
      
      bracketConnections.forEach(conn => {
        const cardC = document.querySelector(`.bracket-match-card[data-match-id="${conn.child}"]`);
        if (!cardC) return;
        const rectC = cardC.getBoundingClientRect();
        
        if (conn.parentB) {
          const cardA = document.querySelector(`.bracket-match-card[data-match-id="${conn.parentA}"]`);
          const cardB = document.querySelector(`.bracket-match-card[data-match-id="${conn.parentB}"]`);
          if (!cardA || !cardB) return;
          
          const rectA = cardA.getBoundingClientRect();
          const rectB = cardB.getBoundingClientRect();
          
          let xA, yA, xB, yB, xC, yC;
          
          if (conn.side === 'left') {
            xA = rectA.right - containerRect.left;
            yA = rectA.top + rectA.height / 2 - containerRect.top;
            xB = rectB.right - containerRect.left;
            yB = rectB.top + rectB.height / 2 - containerRect.top;
            xC = rectC.left - containerRect.left;
            yC = rectC.top + rectC.height / 2 - containerRect.top;
          } else {
            xA = rectA.left - containerRect.left;
            yA = rectA.top + rectA.height / 2 - containerRect.top;
            xB = rectB.left - containerRect.left;
            yB = rectB.top + rectB.height / 2 - containerRect.top;
            xC = rectC.right - containerRect.left;
            yC = rectC.top + rectC.height / 2 - containerRect.top;
          }
          
          const midX = (xA + xC) / 2;
          const activeA = isPathActive(cardA, cardC);
          const activeB = isPathActive(cardB, cardC);
          
          drawOrthogonalBranch(svg, xA, yA, xB, yB, xC, yC, midX, activeA, activeB);
        } else {
          const cardA = document.querySelector(`.bracket-match-card[data-match-id="${conn.parentA}"]`);
          if (!cardA) return;
          
          const rectA = cardA.getBoundingClientRect();
          
          let xA, yA, xC, yC;
          
          if (conn.side === 'left-final') {
            xA = rectA.right - containerRect.left;
            yA = rectA.top + rectA.height / 2 - containerRect.top;
            xC = rectC.left - containerRect.left;
            // Desplazar levemente el puerto de entrada izquierdo en la final
            yC = rectC.top + rectC.height * 0.35 - containerRect.top;
          } else {
            xA = rectA.left - containerRect.left;
            yA = rectA.top + rectA.height / 2 - containerRect.top;
            xC = rectC.right - containerRect.left;
            // Desplazar levemente el puerto de entrada derecho en la final
            yC = rectC.top + rectC.height * 0.65 - containerRect.top;
          }
          
          const midX = (xA + xC) / 2;
          const active = isPathActive(cardA, cardC);
          
          drawSingleLine(svg, xA, yA, xC, yC, midX, active);
        }
      });
    }

    // Exportar para acceso global
    window.drawBracketLines = drawBracketLines;

    // Función para cambiar de pestañas principales (Pronósticos vs Árbol)
    function switchFfTab(tabName) {
      document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.id === 'btn-tab-' + tabName || (tabName === 'ff-matches' && btn.id === 'btn-tab-ff-matches'));
      });
      document.querySelectorAll('.tab-pane').forEach(pane => {
        if (pane.id === 'tab-' + tabName) {
          pane.style.display = 'block';
        } else {
          pane.style.display = 'none';
        }
      });
      if (tabName === 'bracket') {
        setTimeout(drawBracketLines, 100);
      }
    }

    // Cambiar de rondas en el Árbol de Encuentros (móviles)
    function switchBracketRound(round) {
      document.querySelectorAll('.round-selector-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.round === round);
      });
      document.querySelectorAll('.bracket-column').forEach(col => {
        const colRounds = col.dataset.rounds ? col.dataset.rounds.split(',') : [];
        if (colRounds.includes(round)) {
          col.classList.add('active');
        } else {
          col.classList.remove('active');
        }
      });
    }

    // Redibujar al cambiar el tamaño de ventana o cargar
    window.addEventListener('resize', () => {
      if (document.getElementById('tab-bracket').style.display !== 'none') {
        drawBracketLines();
      }
    });

    document.addEventListener('DOMContentLoaded', () => {
      // Si por alguna razón la pestaña inicial de bracket estuviera activa
      if (document.getElementById('tab-bracket').style.display !== 'none') {
        setTimeout(drawBracketLines, 300);
      }
    });
  </script>
  <script src="js/app.js?v=3.32"></script>
</body>
</html>
