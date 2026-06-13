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

// Obtener partidos ordenados
$matches = $db->query("SELECT * FROM `Match` ORDER BY date ASC")->fetchAll();
// Obtener usuarios ordenados
$users = $db->query("SELECT id, username, points, hasPaid FROM `User` WHERE role != 'ADMIN' ORDER BY username ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin – Quiniela Mundial 2026</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/style.css?v=3.19" />
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

    <!-- Gestionar Participantes -->
    <div class="glass-panel" style="margin-top:2rem">
      <h2 class="admin-section-title">👥 Gestionar Participantes y Pagos</h2>
      <p style="color:var(--text-secondary); font-size:0.85rem; margin-bottom:1.5rem">
        Marca a los participantes que aportaron dinero a la bolsa de premios ($500 pesos).
        Los participantes marcados tendrán un signo de <strong>$ dorado</strong> en la clasificación general.
      </p>

      <div class="admin-users-list">
        <?php foreach ($users as $u): ?>
          <div class="admin-user-item">
            <div class="admin-user-info">
              <span class="admin-user-name"><?= htmlspecialchars($u['username']) ?></span>
              <span class="admin-user-pts"><?= $u['points'] ?> pts</span>
            </div>
            <div class="admin-user-actions">
              <label class="switch-container">
                <input type="checkbox" class="toggle-paid-checkbox" data-user-id="<?= $u['id'] ?>" <?= $u['hasPaid'] ? 'checked' : '' ?> />
                <span class="switch-slider"></span>
              </label>
              <span class="paid-status-label" id="status-label-<?= $u['id'] ?>" style="color: <?= $u['hasPaid'] ? 'var(--accent-color)' : 'var(--text-secondary)' ?>; font-size: 0.8rem; font-weight: 700; margin-left: 0.5rem; width: 65px; display: inline-block;">
                <?= $u['hasPaid'] ? 'Pagado' : 'Sin Pago' ?>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <p style="color:var(--text-secondary); text-align:center">No hay participantes registrados.</p>
        <?php endif; ?>
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
        const matchId = btn.dataset.matchId;
        const scoreA = document.querySelector(`.result-a-${matchId}`).value;
        const scoreB = document.querySelector(`.result-b-${matchId}`).value;

        if (scoreA === '' || scoreB === '') {
          alert('Ingresa ambos marcadores');
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Guardando...';

        try {
          const res = await fetch('api/set_result.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ matchId: parseInt(matchId), scoreA: parseInt(scoreA), scoreB: parseInt(scoreB) })
          });
          const data = await res.json();

          if (data.success) {
            const item = document.getElementById(`match-item-${matchId}`);
            // Reemplazar inputs por badge
            const inputs = item.querySelectorAll('.admin-score-input, .btn-set-result, span');
            inputs.forEach(el => el.remove());
            const badge = document.createElement('div');
            badge.className = 'finished-badge';
            badge.textContent = `✅ ${scoreA}–${scoreB}`;
            item.appendChild(badge);
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
        const hasPaid = chk.checked ? 1 : 0;
        const statusLabel = document.getElementById(`status-label-${userId}`);
        
        chk.disabled = true;
        if (statusLabel) {
          statusLabel.textContent = 'Guardando...';
          statusLabel.style.color = 'var(--text-secondary)';
        }

        try {
          const res = await fetch('api/toggle_paid.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ userId: parseInt(userId), hasPaid: hasPaid })
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
