<?php
require_once 'config.php';
$user = requireLogin();

$db = getDB();

$kpiMatches = $db->query("
    SELECT m.id, m.scoreA, m.scoreB, fc.pick, fso.scoreA as predA, fso.scoreB as predB
    FROM `Match` m
    INNER JOIN (
        SELECT fc1.matchId, fc1.runId, fc1.pick
        FROM `ForecastConsensus` fc1
        INNER JOIN (
            SELECT fc_sub.matchId, MAX(fc_sub.runId) as max_runId
            FROM `ForecastConsensus` fc_sub
            INNER JOIN `Match` m_sub ON fc_sub.matchId = m_sub.id
            WHERE fc_sub.createdAt <= m_sub.date
              AND NOT (fc_sub.probHome = 34.24 AND fc_sub.probDraw = 31.51 AND fc_sub.probAway = 34.25)
            GROUP BY fc_sub.matchId
        ) fc2 ON fc1.matchId = fc2.matchId AND fc1.runId = fc2.max_runId
    ) fc ON m.id = fc.matchId
    LEFT JOIN `ForecastScoreOption` fso ON fc.matchId = fso.matchId AND fc.runId = fso.runId AND fso.rankOrder = 1
    WHERE m.isFinished = 1 OR m.status = 'FINISHED'
")->fetchAll();

$totalEvaluated = count($kpiMatches);
$exactHits = 0;
$approxHits = 0;
$totalHits = 0;

foreach ($kpiMatches as $km) {
    $realA = (int)$km['scoreA'];
    $realB = (int)$km['scoreB'];
    $realOutcome = 'draw';
    if ($realA > $realB) $realOutcome = 'home';
    elseif ($realB > $realA) $realOutcome = 'away';
    
    $predOutcome = $km['pick'];
    $isOutcomeCorrect = ($predOutcome === $realOutcome);
    
    $isExactCorrect = false;
    if ($km['predA'] !== null && $km['predB'] !== null) {
        $isExactCorrect = ((int)$km['predA'] === $realA && (int)$km['predB'] === $realB);
    }
    
    if ($isOutcomeCorrect) {
        $totalHits++;
        if ($isExactCorrect) {
            $exactHits++;
        } else {
            $approxHits++;
        }
    }
}

$pickAccuracy = $totalEvaluated > 0 ? ($totalHits / $totalEvaluated) * 100 : 0;
$exactAccuracy = $totalEvaluated > 0 ? ($exactHits / $totalEvaluated) * 100 : 0;
$approxAccuracy = $totalEvaluated > 0 ? ($approxHits / $totalEvaluated) * 100 : 0;

// Obtener todos los partidos ordenados por fecha con su respectivo pronóstico (pick) consolidado
$matches = $db->query("
    SELECT m.*, fc.pick, fc.confidence, fc.modelAgreement
    FROM `Match` m
    LEFT JOIN (
        SELECT fc1.*
        FROM `ForecastConsensus` fc1
        INNER JOIN (
            SELECT matchId, MAX(id) as max_id
            FROM `ForecastConsensus`
            GROUP BY matchId
        ) fc2 ON fc1.id = fc2.max_id
    ) fc ON m.id = fc.matchId
    ORDER BY m.date ASC
")->fetchAll();

// Agrupar partidos por día (Y-m-d) para tener una clave limpia
$grouped = [];
$dayNames = [];
foreach ($matches as $match) {
    try {
        $dt = new DateTime($match['date'], new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Mexico_City'));
        $ymd = $dt->format('Y-m-d');
    } catch (Exception $e) {
        $ymd = 'unknown';
    }
    
    if (!isset($grouped[$ymd])) {
        $grouped[$ymd] = [];
        $dayNames[$ymd] = formatMatchDay($match['date']);
    }
    $grouped[$ymd][] = $match;
}

// Calcular qué día debe ser el scroll-target (hoy, el partido futuro más cercano, o el último partido jugado)
$todayDt = new DateTime('now', new DateTimeZone('America/Mexico_City'));
$todayYmd = $todayDt->format('Y-m-d');

$scrollToKey = null;
$lastKey = null;
foreach (array_keys($grouped) as $ymd) {
    $lastKey = $ymd;
    if ($ymd === $todayYmd) {
        $scrollToKey = $ymd;
        break;
    }
    if ($ymd > $todayYmd && $scrollToKey === null) {
        $scrollToKey = $ymd;
    }
}
if ($scrollToKey === null) {
    $scrollToKey = $lastKey;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Portal de Pronósticos – Mundial 2026 🔮</title>
  <meta name="description" content="Visualizador oficial de pronósticos y momios de la Quiniela Mundial 2026." />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=1.1" />
</head>
<body class="fade-in">

  <!-- Top Bar -->
  <div class="top-bar">
    <span>Hola, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if ($user['role'] === 'ADMIN'): ?>
      <span class="admin-badge">🛡️ Administrador</span>
    <?php endif; ?>
    <a href="logout.php"><button class="btn-logout">Salir</button></a>
  </div>

  <!-- Header -->
  <header class="site-header">
    <div class="wc-badge">🔮</div>
    <h1 class="site-title">Pronósticos del Mundial</h1>
    <p class="site-subtitle">Visualiza la probabilidad estadística de los partidos en tiempo real</p>
  </header>

  <!-- Main Content -->
  <div class="main-container">

    <!-- KPI Section -->
    <section class="kpi-section" style="margin-bottom: 2rem;">
      <div class="kpi-grid">
        
        <div class="kpi-card glass-panel kpi-card--primary">
          <div class="kpi-icon">🔮</div>
          <div class="kpi-info">
            <span class="kpi-label">Efectividad General (1X2)</span>
            <span class="kpi-value"><?= number_format($pickAccuracy, 1) ?>%</span>
            <span class="kpi-subtext">Acertó ganador/empate en <strong><?= $totalHits ?></strong> de <strong><?= $totalEvaluated ?></strong> partidos</span>
          </div>
        </div>

        <div class="kpi-card glass-panel kpi-card--success">
          <div class="kpi-icon">🎯</div>
          <div class="kpi-info">
            <span class="kpi-label">Marcadores Exactos</span>
            <span class="kpi-value"><?= number_format($exactAccuracy, 1) ?>%</span>
            <span class="kpi-subtext">Clavó el marcador exacto en <strong><?= $exactHits ?></strong> partidos</span>
          </div>
        </div>

        <div class="kpi-card glass-panel kpi-card--warning">
          <div class="kpi-icon">✓</div>
          <div class="kpi-info">
            <span class="kpi-label">Resultados Aproximados</span>
            <span class="kpi-value"><?= number_format($approxAccuracy, 1) ?>%</span>
            <span class="kpi-subtext">Acertó tendencia pero no goles en <strong><?= $approxHits ?></strong> partidos</span>
          </div>
        </div>

      </div>
    </section>

    <!-- Admin Panel -->
    <?php if ($user['role'] === 'ADMIN'): ?>
      <section style="margin-bottom: 2.5rem;">
        <div class="glass-panel admin-panel">
          <h3 class="admin-title">⚙️ Control del Motor de Pronósticos</h3>
          <p class="admin-desc">
            El motor de pronósticos corre de forma automática todos los días a las 20:00 (hora CDMX). 
            Usa esta sección para forzar una corrida manual para los partidos de mañana.
          </p>
          <div class="admin-controls">
            <label class="switch-container">
              <input type="checkbox" id="dry-run-checkbox" checked />
              <span class="switch-slider"></span>
              <span class="switch-label">Simulación (dry run) - No escribe en base de datos</span>
            </label>
            <button id="btn-run-forecast" class="btn-primary">⚡ Ejecutar Pronósticos de Mañana</button>
          </div>
          <div id="console-log-container" style="display: none;">
            <div class="console-header">
              <span>Resultado de la corrida</span>
              <button class="console-close" onclick="closeConsole()">&times;</button>
            </div>
            <pre class="console-output" id="console-output">Esperando ejecución...</pre>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- Partidos Grid -->
    <section>
      <h2 class="section-title">⚽ Calendario de Partidos</h2>
      
      <div class="matches-container">
        <?php foreach ($grouped as $ymd => $dayMatches): 
          $dayName = $dayNames[$ymd] ?? $ymd;
          $isScrollTarget = ($ymd === $scrollToKey);
        ?>
          <div class="day-group" <?= $isScrollTarget ? 'id="scroll-target"' : '' ?>>
            <div class="day-header">
              <span><?= htmlspecialchars($dayName) ?></span>
            </div>
            <div class="match-grid">
              <?php foreach ($dayMatches as $match):
                $flagA = getFlagUrl($match['teamA']);
                $flagB = getFlagUrl($match['teamB']);
                $time = formatMatchTime($match['date']);
                $status = $match['status'] ?? 'SCHEDULED';
                $isLive = in_array($status, ['LIVE', 'HALFTIME']);
                $isFinished = (bool)$match['isFinished'];
                $hasForecast = !empty($match['pick']);
              ?>
                <div class="match-card match-card--clickable" onclick="openForecast(<?= $match['id'] ?>)">
                  <div class="match-card-header">
                    <span class="match-time">🕒 <?= $time ?></span>
                    <?php if ($isLive): ?>
                      <span class="badge badge--live">EN VIVO</span>
                    <?php elseif ($isFinished): ?>
                      <span class="badge badge--finished">FINALIZADO</span>
                    <?php else: ?>
                      <span class="badge badge--scheduled">PROGRAMADO</span>
                    <?php endif; ?>
                  </div>

                  <div class="match-teams-row">
                    <div class="team-block">
                      <?php if ($flagA): ?>
                        <img src="<?= $flagA ?>" alt="<?= htmlspecialchars($match['teamA']) ?>" class="flag" />
                      <?php endif; ?>
                      <span class="team-name" title="<?= htmlspecialchars($match['teamA']) ?>"><?= htmlspecialchars($match['teamA']) ?></span>
                    </div>
                    
                    <?php if ($isFinished || $isLive): ?>
                      <div class="vs-divider vs-divider--score">
                        <?= htmlspecialchars($match['scoreA']) ?> - <?= htmlspecialchars($match['scoreB']) ?>
                      </div>
                    <?php else: ?>
                      <div class="vs-divider">VS</div>
                    <?php endif; ?>

                    <div class="team-block">
                      <?php if ($flagB): ?>
                        <img src="<?= $flagB ?>" alt="<?= htmlspecialchars($match['teamB']) ?>" class="flag" />
                      <?php endif; ?>
                      <span class="team-name" title="<?= htmlspecialchars($match['teamB']) ?>"><?= htmlspecialchars($match['teamB']) ?></span>
                    </div>
                  </div>

                  <?php if (($isFinished || $isLive) && $hasForecast): ?>
                    <div class="match-forecast-summary">
                      <?php 
                        $pickText = [
                          'home' => 'Local',
                          'away' => 'Visitante',
                          'draw' => 'Empate'
                        ][$match['pick']] ?? strtoupper($match['pick']);
                        
                        $isCorrect = false;
                        if ($match['pick'] === 'home' && $match['scoreA'] > $match['scoreB']) $isCorrect = true;
                        elseif ($match['pick'] === 'away' && $match['scoreB'] > $match['scoreA']) $isCorrect = true;
                        elseif ($match['pick'] === 'draw' && $match['scoreA'] === $match['scoreB']) $isCorrect = true;
                      ?>
                      <?php if ($isFinished): ?>
                        <span class="forecast-result-badge <?= $isCorrect ? 'forecast-result-badge--hit' : 'forecast-result-badge--miss' ?>">
                          🔮 Pronóstico: <?= $pickText ?> <?= $isCorrect ? '✅ Acertado' : '❌ Fallado' ?>
                        </span>
                      <?php else: // live ?>
                        <span class="forecast-result-badge forecast-result-badge--live">
                          🔮 Pronóstico: <?= $pickText ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <div class="match-card-footer">
                    <span class="venue-text">📍 <?= htmlspecialchars($match['venue'] ?: 'Por definir') ?></span>
                    <button class="btn-view-forecast">Ver Pronóstico 🔮</button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  </div>

  <!-- Modal de Detalles del Pronóstico -->
  <div id="forecast-modal" class="modal">
    <div class="modal-content glass-panel">
      <span class="close-modal" onclick="closeModal()">&times;</span>
      
      <!-- Modal Header -->
      <div class="modal-match-header">
        <div class="modal-team">
          <img id="modal-flag-a" src="" alt="Bandera A" class="modal-flag" />
          <h2 id="modal-name-a">Equipo A</h2>
        </div>
        
        <div class="modal-vs">VS</div>
        
        <div class="modal-team">
          <img id="modal-flag-b" src="" alt="Bandera B" class="modal-flag" />
          <h2 id="modal-name-b">Equipo B</h2>
        </div>
      </div>

      <!-- Info del partido -->
      <div class="modal-match-info" id="modal-match-info-text">
        Cargando detalles...
      </div>

      <hr class="modal-divider" />

      <!-- Cargador -->
      <div id="modal-loader" class="modal-loader">
        <div class="spinner"></div>
        <p>Procesando momios y consensos...</p>
      </div>

      <!-- Error / Pendiente -->
      <div id="modal-error" style="display: none;" class="modal-error-block">
        <div class="error-icon">⚠️</div>
        <h3 id="modal-error-title">Pronóstico Pendiente</h3>
        <p id="modal-error-desc">El motor procesará este partido conforme se acerque la fecha de juego.</p>
      </div>

      <!-- Contenido del Pronóstico -->
      <div id="modal-forecast-content" style="display: none;">
        
        <!-- Predicción Consolidada -->
        <div class="forecast-section">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; flex-wrap: wrap; gap: 0.4rem;">
            <h3 class="section-subtitle" style="margin-bottom: 0;">🔮 Predicción Consolidada</h3>
            <span id="forecast-predicted-at" style="font-size: 0.72rem; color: var(--text-secondary); font-weight: 600; background: rgba(255,255,255,0.02); padding: 0.2rem 0.5rem; border-radius: 4px; border: 1px solid var(--border-color); display: none;"></span>
          </div>
          <div class="consensus-box">
            <div class="consensus-metric">
              <span class="consensus-label">Pick Recomendado</span>
              <span class="consensus-val" id="forecast-pick">LOCAL</span>
            </div>
            <div class="consensus-metric">
              <span class="consensus-label">Confianza</span>
              <span class="consensus-val" id="forecast-confidence">ALTA</span>
            </div>
            <div class="consensus-metric">
              <span class="consensus-label">Consenso de Modelos</span>
              <span class="consensus-val" id="forecast-agreement">FUERTE</span>
            </div>
          </div>

          <!-- Barra de Probabilidades -->
          <div class="prob-container-box">
            <div class="prob-labels">
              <span id="label-prob-home" class="prob-label-item">Local: 0%</span>
              <span id="label-prob-draw" class="prob-label-item">Empate: 0%</span>
              <span id="label-prob-away" class="prob-label-item">Visitante: 0%</span>
            </div>
            <div class="prob-bar-track">
              <div id="bar-fill-home" class="prob-bar-fill prob-bar-fill--home"></div>
              <div id="bar-fill-draw" class="prob-bar-fill prob-bar-fill--draw"></div>
              <div id="bar-fill-away" class="prob-bar-fill prob-bar-fill--away"></div>
            </div>
          </div>
        </div>

        <!-- Marcadores Más Probables -->
        <div class="forecast-section">
          <h3 class="section-subtitle">🔢 Marcadores Más Probables</h3>
          <div class="scores-list" id="forecast-scores-list">
            <!-- Cargado dinámicamente -->
          </div>
        </div>

        <!-- Revisión IA del Marcador -->
        <div class="forecast-section" id="score-review-section" style="display: none;">
          <h3 class="section-subtitle">🔍 Revisión IA del Marcador</h3>
          <div id="forecast-score-review-content">
            <!-- Cargado dinámicamente -->
          </div>
        </div>

        <!-- Comparativo de Agentes -->
        <div class="forecast-section">
          <h3 class="section-subtitle">🤖 Desglose de Agentes y Modelos</h3>
          <div class="agents-table-wrapper">
            <table class="agents-table">
              <thead>
                <tr>
                  <th>Agente / Modelo</th>
                  <th class="num">Local</th>
                  <th class="num">Empate</th>
                  <th class="num">Visitante</th>
                  <th class="num">Peso</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody id="forecast-agents-body">
                <!-- Cargado dinámicamente -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- Políticas y Nota Informativa -->
        <p class="forecast-note-footer">
          * Los agentes <strong>historical_internet</strong> y <strong>rf_lgbm_meta</strong> procesan conjuntos de datos de encuentros oficiales e históricos.
          El calibrador <strong>live_experts</strong> analiza señales externas de valor en vivo previo al arranque.
        </p>

      </div>
    </div>
  </div>

  <script src="js/app.js?v=<?= @filemtime(__DIR__ . '/js/app.js') ?: '1.22' ?>"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const target = document.getElementById('scroll-target');
      if (target) {
        setTimeout(() => {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 150);
      }
    });
  </script>
</body>
</html>
