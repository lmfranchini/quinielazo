<?php
require_once 'config.php';
$user = requireLogin();
$db = getDB();

// Obtener todos los partidos de la Fase Final (IDs 73 a 104) ordenados cronológicamente
$matchesQuery = $db->query("SELECT * FROM `Match` WHERE id >= 73 ORDER BY date ASC, id ASC")->fetchAll();
$ffDisplayMap = [];
foreach ($matchesQuery as $index => $m) {
    $ffDisplayMap[$m['id']] = 73 + $index;
}
$ffMatches = [];
foreach ($matchesQuery as $m) {
    $ffMatches[$m['id']] = $m;
}

// Obtener pronósticos del usuario actual para la Fase Final
$predStmt = $db->prepare("SELECT * FROM `" . PREDICTION_TABLE . "` WHERE userId = ? AND matchId >= 73");
$predStmt->execute([$user['id']]);
$predictions = $predStmt->fetchAll();
$predMap = [];
foreach ($predictions as $p) {
    $predMap[$p['matchId']] = $p;
}

// Clasificación con puntos proyectados para el Leaderboard lateral
$allUsers = $db->query("SELECT id, username, pointsFaseFinal AS points, hasPaidFaseFinal AS hasPaid FROM `User` WHERE role != 'ADMIN' AND hasJoinedFaseFinal = 1 ORDER BY pointsFaseFinal DESC, username ASC")->fetchAll();

$allPreds = $db->query("
    SELECT p.userId, p.matchId, p.scoreA AS predA, p.scoreB AS predB, p.points AS confirmedPts,
           m.scoreA AS mScoreA, m.scoreB AS mScoreB, m.status, m.isFinished, m.winner, m.teamA, m.teamB
    FROM `" . PREDICTION_TABLE . "` p INNER JOIN `Match` m ON p.matchId = m.id
    WHERE m.id >= 73
")->fetchAll();

$userProjected = [];
foreach ($allPreds as $ap) {
    $uid = (int)$ap['userId'];
    if (!isset($userProjected[$uid])) $userProjected[$uid] = ['confirmed' => 0, 'projected' => 0];
    if ($ap['isFinished']) {
        $userProjected[$uid]['confirmed'] += (int)$ap['confirmedPts'];
    } elseif ($ap['status'] === 'LIVE' || $ap['status'] === 'HALFTIME') {
        $livePts = calculatePoints((int)$ap['predA'], (int)$ap['predB'], $ap['mScoreA'], $ap['mScoreB'], $ap['winner'] ?? null, $ap['teamA'], $ap['teamB']);
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
    if ($id >= 73 && $id <= 88) $dieciseisavos[] = $m;
    elseif ($id >= 89 && $id <= 96) $octavos[] = $m;
    elseif ($id >= 97 && $id <= 100) $cuartos[] = $m;
    elseif ($id >= 101 && $id <= 102) $semis[] = $m;
    elseif ($id === 103) $tercerLugar[] = $m;
    elseif ($id === 104) $finalMatch[] = $m;
    
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

// Determinar el partido del día actual o más próximo para auto-scrollear
$scrollToMatchId = null;
$todayYmd = (new DateTime('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');

foreach ($matchesQuery as $m) {
    $dt = new DateTime($m['date'], new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
    $matchYmd = $dt->format('Y-m-d');
    
    if ($matchYmd >= $todayYmd && !$m['isFinished']) {
        $scrollToMatchId = (int)$m['id'];
        break;
    }
}

if ($scrollToMatchId === null && !empty($matchesQuery)) {
    $lastMatch = end($matchesQuery);
    $scrollToMatchId = (int)$lastMatch['id'];
}

$paidCount = 0;
foreach ($leaderboard as $u) {
    if (!empty($u['hasPaid'])) {
        $paidCount++;
    }
}
$totalPrizePool = $paidCount * 500;

// Obtener todos los partidos en vivo para el leaderboard lateral (únicamente fase final)
$allDbMatches = $db->query("SELECT * FROM `Match` WHERE id >= 73")->fetchAll();
$liveMatches = [];
foreach ($allDbMatches as $m) {
    if ($m['status'] === 'LIVE' || $m['status'] === 'HALFTIME') {
        $liveMatches[$m['id']] = [
            'id'    => (int)$m['id'],
            'teamA' => $m['teamA'],
            'teamB' => $m['teamB'],
            'flagA' => getFlagUrl($m['teamA']),
            'flagB' => getFlagUrl($m['teamB']),
        ];
    }
}

$livePredsByUser = [];
foreach ($allUsers as $u) {
    $uid = (int)$u['id'];
    $livePredsByUser[$uid] = [];
    foreach ($liveMatches as $mid => $lm) {
        $pred = null;
        foreach ($allPreds as $ap) {
            if ((int)$ap['userId'] === $uid && (int)$ap['matchId'] === $mid) {
                $pred = $ap;
                break;
            }
        }
        $livePredsByUser[$uid][] = [
            'matchId' => $mid,
            'teamA'   => $lm['teamA'],
            'teamB'   => $lm['teamB'],
            'flagA'   => $lm['flagA'],
            'flagB'   => $lm['flagB'],
            'scoreA'  => $pred ? (int)$pred['predA'] : null,
            'scoreB'  => $pred ? (int)$pred['predB'] : null,
        ];
    }
}

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
    
    $teamNameRenderA = isPlaceholderTeam($teamA) ? '<span class="bracket-placeholder-team">' . htmlspecialchars($teamA) . '</span>' : htmlspecialchars($teamA);
    $teamNameRenderB = isPlaceholderTeam($teamB) ? '<span class="bracket-placeholder-team">' . htmlspecialchars($teamB) . '</span>' : htmlspecialchars($teamB);
    
    $flagRenderA = $flagA ? '<img src="' . $flagA . '" class="bracket-team-flag" />' : '<div class="bracket-team-flag-placeholder"></div>';
    $flagRenderB = $flagB ? '<img src="' . $flagB . '" class="bracket-team-flag" />' : '<div class="bracket-team-flag-placeholder"></div>';
    
    $shootoutA = $m['shootoutA'] ?? null;
    $shootoutB = $m['shootoutB'] ?? null;

    $scoreRenderA = is_numeric($scoreA) ? $scoreA : '–';
    if ($shootoutA !== null) {
        $scoreRenderA = "($shootoutA) $scoreRenderA";
    }
    
    $scoreRenderB = is_numeric($scoreB) ? $scoreB : '–';
    if ($shootoutB !== null) {
        $scoreRenderB = "($shootoutB) $scoreRenderB";
    }

    $shootoutData = json_decode($m['shootoutData'] ?? '[]', true);
    
    $shootoutDotsA = '';
    if (!empty($shootoutData['teamA'])) {
        $shootoutDotsA .= '<div class="shootout-dots" style="display:flex; gap:2px; margin-left: 5px; align-items:center;">';
        foreach ($shootoutData['teamA'] as $shot) {
            $color = $shot['didScore'] ? '#2ecc71' : '#e74c3c';
            $player = htmlspecialchars($shot['player']);
            $shootoutDotsA .= "<span title=\"$player\" style=\"width: 6px; height: 6px; border-radius: 50%; background-color: $color; display: inline-block;\"></span>";
        }
        $shootoutDotsA .= '</div>';
    }

    $shootoutDotsB = '';
    if (!empty($shootoutData['teamB'])) {
        $shootoutDotsB .= '<div class="shootout-dots" style="display:flex; gap:2px; margin-left: 5px; align-items:center;">';
        foreach ($shootoutData['teamB'] as $shot) {
            $color = $shot['didScore'] ? '#2ecc71' : '#e74c3c';
            $player = htmlspecialchars($shot['player']);
            $shootoutDotsB .= "<span title=\"$player\" style=\"width: 6px; height: 6px; border-radius: 50%; background-color: $color; display: inline-block;\"></span>";
        }
        $shootoutDotsB .= '</div>';
    }
    
    $liveTag = $isLive ? '<span class="bracket-match-live-tag">LIVE</span>' : '';
    
    ob_start();
    global $ffDisplayMap;
    $displayMatchId = isset($ffDisplayMap[$matchId]) ? $ffDisplayMap[$matchId] : $matchId;
    ?>
    <div class="<?= $cardClass ?>" data-match-id="<?= $matchId ?>" <?= $clickAttr ?>>
      <div class="bracket-match-number">
        <span>Partido #<?= $displayMatchId ?></span>
        <?= $liveTag ?>
      </div>
      
      <!-- Team A -->
      <div class="bracket-team-row <?= $classTeamA ?>">
        <div class="bracket-team-info" style="display:flex; align-items:center;">
          <?= $flagRenderA ?>
          <span class="bracket-team-name"><?= $teamNameRenderA ?></span>
          <?= $shootoutDotsA ?>
        </div>
        <span class="bracket-team-score <?= $scoreClassA ?>" id="scoreA-<?= $matchId ?>"><?= $scoreRenderA ?></span>
      </div>
      
      <!-- Team B -->
      <div class="bracket-team-row <?= $classTeamB ?>">
        <div class="bracket-team-info" style="display:flex; align-items:center;">
          <?= $flagRenderB ?>
          <span class="bracket-team-name"><?= $teamNameRenderB ?></span>
          <?= $shootoutDotsB ?>
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
  <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: '3.40' ?>" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="fade-in page-fase-final">

  <!-- Top Bar -->
  <div class="top-bar">
    <span>Hola, <strong><?= htmlspecialchars($user['username']) ?></strong></span>

    <?php if ($user['role'] === 'ADMIN'): ?>
      <a href="../admin.php" class="btn-admin">⚙️ Panel Admin</a>
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
      <!-- Sección de Reglas del Juego -->
      <div class="glass-panel rules-panel" style="margin-bottom: 2rem; padding: 1.5rem; border-color: rgba(255, 215, 0, 0.2);">
        <div class="rules-header" onclick="toggleRules()" style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;">
          <h3 style="margin: 0; font-size: 1.2rem; font-weight: 800; color: var(--accent-color); display: flex; align-items: center; gap: 0.6rem;">
            📜 Reglas del Juego
          </h3>
          <span id="rules-toggle-icon" style="font-size: 0.9rem; color: var(--text-secondary); transition: all 0.3s ease; background: rgba(255, 255, 255, 0.05); padding: 0.3rem 0.6rem; border-radius: 6px;">Mostrar ▼</span>
        </div>
        <div id="rules-content" style="max-height: 0px; overflow: hidden; transition: max-height 0.4s ease, opacity 0.4s ease, margin-top 0.4s ease; opacity: 0; margin-top: 0;">
          <div style="padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05); display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; font-size: 0.88rem; line-height: 1.5; color: var(--text-secondary);">
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">📈 Sistema de Puntuación</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🎯</span>
                  <div><strong>Marcador Exacto (+6 pts):</strong> Acierto del marcador exacto tras los 120 mins de juego. Ej. Pronóstico: 1-1 | Resultado: 1-1.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">✓</span>
                  <div><strong>Resultado Correcto / Avance (+3 pts):</strong> 
                    <ul style="padding-left: 1.1rem; margin-top: 0.2rem; display: flex; flex-direction: column; gap: 0.2rem;">
                      <li>Acierto de ganador o empate tras los 120 mins (ej. Pronóstico: 2-1 | Resultado: 1-0).</li>
                      <li><strong>Definición por Penales:</strong> Si pronosticaste que ganaba un equipo (ej. 2-1), el partido termina empatado (ej. 1-1) pero tu equipo gana en la tanda de penales y avanza, te llevas 3 puntos por acierto de tendencia.</li>
                    </ul>
                  </div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">✗</span>
                  <div><strong>Fallo (0 pts):</strong> Si no se acierta el ganador (en los 120 mins o penales) ni la tendencia de empate.</div>
                </li>
              </ul>
            </div>
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">⏰ Cierre y Sincronización</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🔒</span>
                  <div><strong>Cierre automático:</strong> Los pronósticos se bloquean automáticamente <strong>15 minutos antes</strong> del inicio oficial de cada partido.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">⚡</span>
                  <div><strong>Puntos en Vivo:</strong> Durante los partidos en vivo verás tus puntos proyectados. Se confirman oficialmente al terminar el encuentro.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🏆</span>
                  <div><strong>Ganador de la Quiniela:</strong> La quiniela abarca toda la Fase Final del certamen (un total de 32 partidos de eliminación directa), por lo que el ganador definitivo se determinará al concluir la Gran Final del Mundial.</div>
                </li>
              </ul>
            </div>
            <div>
              <h4 style="color: white; margin-bottom: 0.5rem; font-weight: 700;">💰 Bolsa de Premios</h4>
              <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.6rem;">
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">💵</span>
                  <div><strong>Competición por la Bolsa:</strong> Solo los participantes que aportaron la cuota de entrada ($500 pesos) compiten por la bolsa acumulada de la Fase Final. Están marcados con un signo de <strong>$ dorado</strong>.</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">🏆</span>
                  <div><strong>Solo Gana el 1º Lugar:</strong> La bolsa acumulada se entregará únicamente al participante mejor posicionado que tenga la marca dorada ($). No hay premios para el 2º ni 3º lugar (solo gana el primer lugar de los participantes con pago).</div>
                </li>
                <li style="display: flex; align-items: flex-start; gap: 0.5rem;">
                  <span style="font-size: 1.1rem; line-height: 1;">⚖️</span>
                  <div><strong>Empate de Puntos:</strong> Si dos o más participantes elegibles terminan empatados en el primer lugar con el mismo número de puntos al finalizar el torneo, la bolsa acumulada se repartirá en partes iguales entre ellos.</div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="section-header-tabs" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
        <div style="display: flex; gap: 1rem;">
          <button class="tab-btn active" id="btn-tab-ff-matches" onclick="switchFfTab('ff-matches')">⚽ Pronósticos Fase Final</button>
          <button class="tab-btn" id="btn-tab-bracket" onclick="switchFfTab('bracket')">🌳 Árbol de Encuentros</button>
          <button class="tab-btn" id="btn-tab-stats" onclick="switchFfTab('stats')">📊 Estadísticas</button>
        </div>
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
                    $isScrollTarget = ((int)$match['id'] === $scrollToMatchId);
                  ?>
                    <div class="match-card <?= $isLive ? 'match-card--live' : '' ?> <?= $hasStarted ? 'match-card--clickable' : '' ?>" 
                         data-match-id="<?= $match['id'] ?>"
                         <?= $isScrollTarget ? 'id="scroll-target" style="scroll-margin-top: 100px;"' : '' ?>
                         <?= $hasStarted ? 'onclick="openMatchDetails(' . $match['id'] . ')"' : '' ?>>
                      <span class="match-badge">Partido #<?= $ffDisplayMap[$match['id']] ?></span>

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
                              <?php if (isPlaceholderTeam($teamA)): ?>
                                <span class="bracket-placeholder-team"><?= htmlspecialchars($teamA) ?></span>
                              <?php else: ?>
                                <?= htmlspecialchars($teamA) ?>
                              <?php endif; ?>
                            </span>
                          </div>
                          <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                            <?php
                              $sA = $match['scoreA'] ?? 0;
                              if (isset($match['shootoutA'])) {
                                  $sA = "(" . $match['shootoutA'] . ") " . $sA;
                              }
                            ?>
                            <div class="team-realtime-score" id="scoreA-<?= $match['id'] ?>"><?= $sA ?></div>
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
                              <?php if (isPlaceholderTeam($teamB)): ?>
                                <span class="bracket-placeholder-team"><?= htmlspecialchars($teamB) ?></span>
                              <?php else: ?>
                                <?= htmlspecialchars($teamB) ?>
                              <?php endif; ?>
                            </span>
                          </div>
                          <?php if ($isLive || $isFinished || $status === 'HALFTIME'): ?>
                            <?php
                              $sB = $match['scoreB'] ?? 0;
                              if (isset($match['shootoutB'])) {
                                  $sB = "(" . $match['shootoutB'] . ") " . $sB;
                              }
                            ?>
                            <div class="team-realtime-score" id="scoreB-<?= $match['id'] ?>"><?= $sB ?></div>
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
                          <?php
                            $teamAIsPlaceholder = isPlaceholderTeam($teamA);
                            $teamBIsPlaceholder = isPlaceholderTeam($teamB);
                            if ($teamAIsPlaceholder || $teamBIsPlaceholder):
                          ?>
                          <div class="prediction-warning" style="
                            background: rgba(255,165,0,0.12);
                            border: 1px solid rgba(255,165,0,0.4);
                            border-radius: 8px;
                            padding: 0.45rem 0.65rem;
                            margin: 0.4rem 0 0.1rem;
                            font-size: 0.72rem;
                            color: #ffb347;
                            text-align: center;
                            line-height: 1.35;
                          ">
                            ⚠️ <?php
                              if ($teamAIsPlaceholder && $teamBIsPlaceholder)
                                echo 'Ambos equipos aún no están definidos.';
                              elseif ($teamAIsPlaceholder)
                                echo "El equipo local aún no está definido ({$match['teamA']}).";
                              else
                                echo "El equipo visitante aún no está definido ({$match['teamB']}).";
                            ?>
                            <br>Podrás actualizar tu pronóstico cuando se conozcan.
                          </div>
                          <?php endif; ?>
                          <button class="btn-save" data-match-id="<?= $match['id'] ?>">
                            <?= $pred ? 'Actualizar Pronóstico' : 'Guardar Pronóstico' ?>
                          </button>
                          <div class="save-status"></div>
                         <?php endif; ?>
                      </div>

                      <!-- Probabilidades de triunfo (Odds API via N8N) -->
                      <?php
                        $teamsAreKnown = !isPlaceholderTeam($teamA) && !isPlaceholderTeam($teamB);
                      ?>
                      <?php if ($match['status'] === 'SCHEDULED' && $teamsAreKnown && is_numeric($match['probHome']) && is_numeric($match['probDraw']) && is_numeric($match['probAway'])): ?>
                        <div class="match-probabilities" id="prob-container-<?= $match['id'] ?>">
                          <div class="prob-header">Probabilidades de triunfo</div>
                          <div class="prob-labels">
                            <span class="prob-label-val prob-val-home">L: <?= floatval($match['probHome']) ?>%</span>
                            <span class="prob-label-val prob-val-draw">E: <?= floatval($match['probDraw']) ?>%</span>
                            <span class="prob-label-val prob-val-away">V: <?= floatval($match['probAway']) ?>%</span>
                          </div>
                          <div class="prob-bar-track">
                            <div class="prob-bar-fill-home" style="width: <?= $match['probHome'] ?>%; background-color: <?= getTeamColor($teamA) ?>; background-image: none;"></div>
                            <div class="prob-bar-fill-draw" style="width: <?= $match['probDraw'] ?>%"></div>
                            <div class="prob-bar-fill-away" style="width: <?= $match['probAway'] ?>%; background-color: <?= getTeamColor($teamB) ?>; background-image: none;"></div>
                          </div>
                        </div>
                      <?php else: ?>
                        <div class="match-probabilities" id="prob-container-<?= $match['id'] ?>" style="display:none"></div>
                      <?php endif; ?>
                      <div class="match-likely-scores" id="likely-scores-container-<?= $match['id'] ?>" style="display:none"></div>
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
                <?= renderBracketMatch(75, $db) ?>
                <?= renderBracketMatch(78, $db) ?>
                <?= renderBracketMatch(73, $db) ?>
                <?= renderBracketMatch(76, $db) ?>
                <?= renderBracketMatch(84, $db) ?>
                <?= renderBracketMatch(83, $db) ?>
                <?= renderBracketMatch(82, $db) ?>
                <?= renderBracketMatch(81, $db) ?>
              </div>

              <!-- Columna 2: 8vos Izquierda (4 partidos) -->
              <div class="bracket-column" data-rounds="8vos">
                <div class="bracket-round-title">8vos de Final</div>
                <?= renderBracketMatch(89, $db) ?>
                <?= renderBracketMatch(90, $db) ?>
                <?= renderBracketMatch(93, $db) ?>
                <?= renderBracketMatch(94, $db) ?>
              </div>

              <!-- Columna 3: Cuartos Izquierda (2 partidos) -->
              <div class="bracket-column" data-rounds="cuartos">
                <div class="bracket-round-title">Cuartos de Final</div>
                <?= renderBracketMatch(97, $db) ?>
                <?= renderBracketMatch(98, $db) ?>
              </div>

              <!-- Columna 4: Semifinal Izquierda (1 partido) -->
              <div class="bracket-column" data-rounds="semis">
                <div class="bracket-round-title">Semifinal</div>
                <?= renderBracketMatch(101, $db) ?>
              </div>

              <!-- Columna 5: Final y Tercer Lugar (Centro) -->
              <div class="bracket-column bracket-column-center" data-rounds="final" style="position: relative;">
                
                <!-- Logo superior como en la imagen -->
                <div style="text-align: center; margin-bottom: 0.5rem; flex-shrink: 0;">
                  <span style="font-family: 'Inter', sans-serif; font-size: 1.4rem; font-weight: 900; letter-spacing: 2px; color: var(--fifa-cyan); text-shadow: 0 0 10px rgba(0, 240, 255, 0.4);">WE ARE 26</span>
                  <div style="font-size: 0.65rem; color: var(--text-secondary); font-weight: 800; letter-spacing: 4px; margin-top: 0.2rem;">POSIBLES</div>
                </div>

                <!-- Imagen del trofeo en el centro con resplandor -->
                <div style="text-align: center; margin: 1rem 0; position: relative; flex-shrink: 0;">
                  <img src="../images/fifa_trophy.png?v=<?= @filemtime(__DIR__ . '/../images/fifa_trophy.png') ?: '1' ?>" 
                       style="height: 180px; width: auto; filter: drop-shadow(0 0 15px rgba(255,215,0,0.3)); mix-blend-mode: screen;" 
                       alt="Copa del Mundo" />
                </div>

                <div class="bracket-center-section" style="flex-shrink: 0;">
                  <div class="bracket-round-title" style="border-bottom-color: var(--fifa-magenta); font-weight: 900;">🏆 Gran Final 🏆</div>
                  <?= renderBracketMatch(104, $db) ?>
                </div>

                <div class="bracket-center-section" style="margin-top: 1rem; flex-shrink: 0;">
                  <div class="bracket-sub-title-center">🥉 Tercer Lugar</div>
                  <?= renderBracketMatch(103, $db) ?>
                </div>

              </div>

              <!-- Columna 6: Semifinal Derecha (1 partido) -->
              <div class="bracket-column" data-rounds="semis">
                <div class="bracket-round-title">Semifinal</div>
                <?= renderBracketMatch(102, $db) ?>
              </div>

              <!-- Columna 7: Cuartos Derecha (2 partidos) -->
              <div class="bracket-column" data-rounds="cuartos">
                <div class="bracket-round-title">Cuartos de Final</div>
                <?= renderBracketMatch(99, $db) ?>
                <?= renderBracketMatch(100, $db) ?>
              </div>

              <!-- Columna 8: 8vos Derecha (4 partidos) -->
              <div class="bracket-column" data-rounds="8vos">
                <div class="bracket-round-title">8vos de Final</div>
                <?= renderBracketMatch(91, $db) ?>
                <?= renderBracketMatch(92, $db) ?>
                <?= renderBracketMatch(95, $db) ?>
                <?= renderBracketMatch(96, $db) ?>
              </div>

              <!-- Columna 9: 16vos Derecha (8 partidos) -->
              <div class="bracket-column active" data-rounds="16vos">
                <div class="bracket-round-title">16vos de Final (Derecha)</div>
                <?= renderBracketMatch(74, $db) ?>
                <?= renderBracketMatch(77, $db) ?>
                <?= renderBracketMatch(79, $db) ?>
                <?= renderBracketMatch(80, $db) ?>
                <?= renderBracketMatch(87, $db) ?>
                <?= renderBracketMatch(86, $db) ?>
                <?= renderBracketMatch(85, $db) ?>
                <?= renderBracketMatch(88, $db) ?>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- Tab: Estadísticas -->
      <div id="tab-stats" class="tab-pane" style="display: none;">
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
                          foreach ($yellowLeaders as $team => $info):
                        ?>
                          <tr class="team-card-row" onclick="toggleTeamCardDetails(this)">
                            <td>
                              <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
                                <?php if (!empty($info['flag'])): ?>
                                  <img src="<?= $info['flag'] ?>" alt="<?= htmlspecialchars($info['team']) ?>" style="width: 18px; height: auto; border-radius: 2px;" />
                                <?php endif; ?>
                                <span><?= htmlspecialchars($info['team']) ?></span>
                              </div>
                            </td>
                            <td class="num" style="font-weight:800; color:#ffaa00"><?= $info['count'] ?></td>
                          </tr>
                          <tr class="team-card-details-row" style="display: none;">
                            <td colspan="2" style="padding: 0.5rem 0.75rem; background: rgba(255,255,255,0.02); border-radius: 4px;">
                              <ul style="list-style: none; margin: 0; padding: 0; font-size: 0.75rem; color: var(--text-secondary); display: flex; flex-direction: column; gap: 0.25rem;">
                                <?php foreach ($info['details'] as $detail): ?>
                                  <li style="display: flex; align-items: center; gap: 0.3rem;">
                                    <span>🟨</span>
                                    <span><?= htmlspecialchars($detail) ?></span>
                                  </li>
                                <?php endforeach; ?>
                              </ul>
                            </td>
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
                          foreach ($redLeaders as $team => $info):
                        ?>
                          <tr class="team-card-row" onclick="toggleTeamCardDetails(this)">
                            <td>
                              <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700;">
                                <?php if (!empty($info['flag'])): ?>
                                  <img src="<?= $info['flag'] ?>" alt="<?= htmlspecialchars($info['team']) ?>" style="width: 18px; height: auto; border-radius: 2px;" />
                                <?php endif; ?>
                                <span><?= htmlspecialchars($info['team']) ?></span>
                              </div>
                            </td>
                            <td class="num" style="font-weight:800; color:#ff0055"><?= $info['count'] ?></td>
                          </tr>
                          <tr class="team-card-details-row" style="display: none;">
                            <td colspan="2" style="padding: 0.5rem 0.75rem; background: rgba(255,255,255,0.02); border-radius: 4px;">
                              <ul style="list-style: none; margin: 0; padding: 0; font-size: 0.75rem; color: var(--text-secondary); display: flex; flex-direction: column; gap: 0.25rem;">
                                <?php foreach ($info['details'] as $detail): ?>
                                  <li style="display: flex; align-items: center; gap: 0.3rem;">
                                    <span>🟥</span>
                                    <span><?= htmlspecialchars($detail) ?></span>
                                  </li>
                                <?php endforeach; ?>
                              </ul>
                            </td>
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
                <h4 class="group-title" style="color: var(--fifa-magenta)">📈 Resumen de Eliminación Directa</h4>
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

          <!-- Gráfico de Evolución de Puntos (Full Width) -->
          <div class="stats-card" style="margin-top: 2rem; padding: 1.5rem;">
            <h4 class="group-title" style="color: var(--fifa-cyan); display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid rgba(255,255,255,0.06); padding-bottom: 0.5rem;">
              📈 Evolución de Puntos
            </h4>
            <?php 
            $pointsHist = getPointsHistory($db); 
            if (empty($pointsHist['players'])):
            ?>
              <div style="text-align: center; color: var(--text-secondary); padding: 3rem 0; font-size: 0.9rem;">
                El gráfico estará disponible cuando finalice el primer partido de la Fase Final.
              </div>
            <?php else: ?>
              <?php 
              $chartHeight = max(320, count($pointsHist['players']) * 24); 
              ?>
              <div style="position: relative; height: <?= $chartHeight ?>px; width: 100%;">
                <canvas id="pointsHistoryChart"></canvas>
              </div>
              <script>
                window.pointsHistoryData = <?= json_encode($pointsHist) ?>;
                window.currentUsername = <?= json_encode($user['username']) ?>;
              </script>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Barra Lateral: Leaderboard -->
    <aside id="leaderboard-sidebar">
      <h2 class="section-title">🏆 Clasificación General</h2>

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
              <div>
                <?= htmlspecialchars($u['username']) ?>
                <?php if (!empty($u['hasPaid'])): ?>
                  <span class="paid-indicator" title="Participa por la bolsa de premios">$</span>
                <?php endif; ?>
                <?php if ((int)$u['id'] === (int)$user['id']): ?>
                  <span style="font-size:0.7rem; color:var(--accent-color)"> (tú)</span>
                <?php endif; ?>
              </div>
              <?php 
              $uLivePreds = $livePredsByUser[(int)$u['id']] ?? [];
              if (!empty($uLivePreds)):
              ?>
                <div class="lb-live-preds" style="font-size: 0.72rem; color: var(--text-secondary); display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin-top: 0.35rem; width: 100%;">
                  <span style="font-size: 0.62rem; text-transform: uppercase; color: var(--fifa-cyan); font-weight: 800; letter-spacing: 0.5px; opacity: 0.85;">En vivo:</span>
                  <?php foreach ($uLivePreds as $lp): ?>
                    <span style="display: inline-flex; align-items: center; gap: 0.2rem; background: rgba(255,255,255,0.04); padding: 0.1rem 0.35rem; border-radius: 4px; border: 1px solid rgba(255,255,255,0.03);" title="<?= htmlspecialchars($lp['teamA']) ?> vs <?= htmlspecialchars($lp['teamB']) ?>">
                      <?php if ($lp['flagA']): ?>
                        <img src="<?= $lp['flagA'] ?>" alt="<?= htmlspecialchars($lp['teamA']) ?>" style="width: 13px; height: auto; border-radius: 1px; display: block;" />
                      <?php endif; ?>
                      <?php if ($lp['scoreA'] !== null && $lp['scoreB'] !== null): ?>
                        <strong style="color: white; font-size: 0.72rem; font-family: 'Inter', sans-serif;">
                          <?= $lp['scoreA'] ?>-<?= $lp['scoreB'] ?>
                        </strong>
                      <?php else: ?>
                        <span style="color: var(--text-secondary); font-size: 0.65rem; font-weight: 600; font-family: 'Inter', sans-serif;">Sin pronóstico</span>
                      <?php endif; ?>
                      <?php if ($lp['flagB']): ?>
                        <img src="<?= $lp['flagB'] ?>" alt="<?= htmlspecialchars($lp['teamB']) ?>" style="width: 13px; height: auto; border-radius: 1px; display: block;" />
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                </div>
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
        <button class="modal-tab-btn" id="modal-btn-shootout" onclick="switchModalTab('shootout')" style="display:none;">🥅 Penales</button>
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
      
      <div id="modal-tab-shootout" class="modal-tab-pane">
        <div class="modal-subs-grid">
          <div class="subs-column">
            <h3 class="subs-team-title" id="shootout-title-a">Equipo A</h3>
            <ul class="subs-timeline" id="shootout-list-a"></ul>
          </div>
          <div class="subs-column">
            <h3 class="subs-team-title" id="shootout-title-b">Equipo B</h3>
            <ul class="subs-timeline" id="shootout-list-b"></ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Variable global para que app.js sepa si hay partidos en vivo
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
        icon.style.background = 'rgba(255, 215, 0, 0.1)';
      } else {
        content.style.maxHeight = '0px';
        content.style.opacity = '0';
        content.style.marginTop = '0';
        icon.textContent = 'Mostrar ▼';
        icon.style.color = 'var(--text-secondary)';
        icon.style.background = 'rgba(255,255,255,0.05)';
      }
    }

    // Conexiones de partidos en el árbol (quién alimenta a quién)
    const bracketConnections = [
      // Izquierda: 16vos -> 8vos
      { parentA: 75, parentB: 78, child: 89, side: 'left' },
      { parentA: 73, parentB: 76, child: 90, side: 'left' },
      { parentA: 84, parentB: 83, child: 93, side: 'left' },
      { parentA: 82, parentB: 81, child: 94, side: 'left' },

      // Izquierda: 8vos -> Cuartos
      { parentA: 89, parentB: 90, child: 97, side: 'left' },
      { parentA: 93, parentB: 94, child: 98, side: 'left' },

      // Izquierda: Cuartos -> Semis
      { parentA: 97, parentB: 98, child: 101, side: 'left' },

      // Izquierda: Semis -> Final
      { parentA: 101, child: 104, side: 'left-final' },

      // Derecha: 16vos -> 8vos
      { parentA: 74, parentB: 77, child: 91, side: 'right' },
      { parentA: 79, parentB: 80, child: 92, side: 'right' },
      { parentA: 87, parentB: 86, child: 95, side: 'right' },
      { parentA: 85, parentB: 88, child: 96, side: 'right' },

      // Derecha: 8vos -> Cuartos
      { parentA: 91, parentB: 92, child: 99, side: 'right' },
      { parentA: 95, parentB: 96, child: 100, side: 'right' },

      // Derecha: Cuartos -> Semis
      { parentA: 99, parentB: 100, child: 102, side: 'right' },

      // Derecha: Semis -> Final
      { parentA: 102, child: 104, side: 'right-final' }
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
      pathA.setAttribute('class', 'bracket-svg-path' + (activeA ? ' active' : ''));
      
      const pathB = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      pathB.setAttribute('d', `M ${xB} ${yB} H ${midX} V ${yC} H ${xC}`);
      pathB.setAttribute('class', 'bracket-svg-path' + (activeB ? ' active' : ''));

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
      path.setAttribute('class', 'bracket-svg-path' + (active ? ' active' : ''));
      svg.appendChild(path);
    }

    // Control de colapso de la tabla de posiciones lateral
    function toggleSidebar(forceState) {
      const container = document.querySelector('.main-container');
      if (!container) return;

      if (forceState !== undefined) {
        if (forceState) {
          container.classList.add('sidebar-hidden');
        } else {
          container.classList.remove('sidebar-hidden');
        }
      } else {
        container.classList.toggle('sidebar-hidden');
      }

      // Redibujar las líneas SVG del bracket ya que las coordenadas cambiaron
      setTimeout(drawBracketLines, 100);
    }

    // Dibuja todas las líneas de conexión basadas en la geometría actual
    function drawBracketLines() {
      const svg = document.getElementById('bracket-svg');
      if (!svg) return;
      
      svg.innerHTML = '';
      
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
        // Quitar la tabla de posiciones en el árbol
        toggleSidebar(true);
      } else {
        // Mostrar la tabla de posiciones en los pronósticos
        toggleSidebar(false);
      }
      
      if (tabName === 'stats' && typeof initPointsHistoryChart === 'function') {
        initPointsHistoryChart();
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

    // Lógica del botón flotante para móviles (FAB)
    function handleFabClick() {
      const fab = document.getElementById('mobile-fab');
      if (!fab) return;
      if (fab.dataset.action === 'top') {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        // Si el usuario está en la pestaña del árbol (bracket), cambiar a la de partidos primero para que la tabla esté visible
        const activeTabBtn = document.querySelector('.tab-btn.active');
        if (activeTabBtn && activeTabBtn.id === 'btn-tab-bracket') {
          if (typeof switchFfTab === 'function') {
            switchFfTab('ff-matches');
          }
        }
        
        setTimeout(() => {
          const el = document.getElementById('leaderboard-sidebar');
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }, 100);
      }
    }

    window.addEventListener('scroll', function() {
      const fab = document.getElementById('mobile-fab');
      if (!fab) return;
      // Si el scroll pasa de 500px, cambia de ir a Tabla a ir a Partidos (Arriba)
      if (window.scrollY > 500) {
        fab.innerHTML = '⚽ Partidos';
        fab.dataset.action = 'top';
      } else {
        fab.innerHTML = '🏆 Tabla';
        fab.dataset.action = 'leaderboard';
      }
    });

    document.addEventListener('DOMContentLoaded', () => {
      // Si por alguna razón la pestaña inicial de bracket estuviera activa
      if (document.getElementById('tab-bracket').style.display !== 'none') {
        setTimeout(drawBracketLines, 300);
      }

      // Configurar eventos para el botón flotante con soporte completo para iOS Safari
      const fab = document.getElementById('mobile-fab');
      if (fab) {
        fab.addEventListener('click', function(e) {
          e.preventDefault();
          handleFabClick();
        });
        fab.addEventListener('touchstart', function(e) {
          e.preventDefault();
          handleFabClick();
        }, { passive: false });
      }
    });
  </script>
  <!-- Botón Flotante para Móviles -->
  <button id="mobile-fab" class="mobile-fab" data-action="leaderboard">
    🏆 Tabla
  </button>
  <script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: '3.40' ?>"></script>
</body>
</html>
