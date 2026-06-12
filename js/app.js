// ── Guardar pronóstico vía AJAX ──
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.btn-save').forEach(btn => {
    btn.addEventListener('click', async () => {
      const card = btn.closest('.match-card');
      const matchId = btn.dataset.matchId;
      const scoreA = card.querySelector('.input-a').value;
      const scoreB = card.querySelector('.input-b').value;
      const statusEl = card.querySelector('.save-status');

      if (scoreA === '' || scoreB === '') {
        statusEl.style.color = '#ff6b9d';
        statusEl.textContent = 'Ingresa ambos marcadores';
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Guardando...';
      statusEl.textContent = '';

      try {
        const res = await fetch('api/save_prediction.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ matchId: parseInt(matchId), scoreA: parseInt(scoreA), scoreB: parseInt(scoreB) })
        });
        const data = await res.json();

        if (data.success) {
          statusEl.style.color = 'var(--accent-color)';
          statusEl.textContent = '✅ Guardado';
          btn.textContent = 'Actualizar Pronóstico';
        } else {
          statusEl.style.color = '#ff6b9d';
          statusEl.textContent = '❌ ' + (data.error || 'Error al guardar');
          btn.textContent = 'Guardar Pronóstico';
        }
      } catch (e) {
        statusEl.style.color = '#ff6b9d';
        statusEl.textContent = '❌ Error de conexión';
        btn.textContent = 'Guardar Pronóstico';
      } finally {
        btn.disabled = false;
      }
    });
  });

  // ── Polling de datos en vivo ──
  initLivePolling();
});

// ── Sistema de actualización en vivo ──
let pollInterval = null;
let pollSpeed = 30000; // 30 segundos por defecto

function initLivePolling() {
  // Siempre iniciar el polling (cada 60s normal, cada 20s si hay partidos en vivo)
  if (window.HAS_LIVE) {
    pollSpeed = 20000; // 20 segundos cuando hay partidos en vivo
  } else {
    pollSpeed = 60000; // 60 segundos cuando no hay en vivo
  }

  // Primera consulta después de 5 segundos
  setTimeout(fetchLiveData, 5000);
  pollInterval = setInterval(fetchLiveData, pollSpeed);
}

async function fetchLiveData() {
  try {
    const res = await fetch('api/live_data.php?t=' + Date.now());
    const data = await res.json();
    if (data.error) return;

    updateMatches(data.matches);
    updateLeaderboard(data.leaderboard);
    if (data.standings) {
      updateStandings(data.standings);
    }
    if (data.topScorers) {
      updateTopScorers(data.topScorers);
    }
    if (data.topCards) {
      updateTopCards(data.topCards);
    }
    if (data.tStats) {
      updateTournamentStats(data.tStats);
    }

    // Ajustar velocidad de polling
    if (data.hasLive && pollSpeed !== 20000) {
      clearInterval(pollInterval);
      pollSpeed = 20000;
      pollInterval = setInterval(fetchLiveData, pollSpeed);
    } else if (!data.hasLive && pollSpeed !== 60000) {
      clearInterval(pollInterval);
      pollSpeed = 60000;
      pollInterval = setInterval(fetchLiveData, pollSpeed);
    }
  } catch (e) {
    // Silenciar errores de red
  }
}

function updateMatches(matches) {
  matches.forEach(m => {
    const card = document.querySelector(`.match-card[data-match-id="${m.id}"]`);
    if (!card) return;

    // Actualizar marcador en vivo
    const scoreAEl = document.getElementById(`scoreA-${m.id}`);
    const scoreBEl = document.getElementById(`scoreB-${m.id}`);

    if (m.status === 'LIVE' || m.status === 'HALFTIME' || m.isFinished) {
      if (scoreAEl && scoreBEl) {
        const oldA = parseInt(scoreAEl.textContent);
        const oldB = parseInt(scoreBEl.textContent);
        const newA = m.scoreA ?? 0;
        const newB = m.scoreB ?? 0;

        if (oldA !== newA) {
          scoreAEl.textContent = newA;
          scoreAEl.classList.add('score-changed');
          setTimeout(() => scoreAEl.classList.remove('score-changed'), 2000);
        }
        if (oldB !== newB) {
          scoreBEl.textContent = newB;
          scoreBEl.classList.add('score-changed');
          setTimeout(() => scoreBEl.classList.remove('score-changed'), 2000);
        }
      }

      // Actualizar minuto, estado y badges de en vivo
      let liveInd = card.querySelector('.live-indicator');
      if (m.status === 'LIVE') {
        if (!liveInd) {
          liveInd = document.createElement('div');
          liveInd.className = 'live-indicator';
          card.insertBefore(liveInd, card.querySelector('.match-header'));
        } else {
          liveInd.className = 'live-indicator';
        }
        liveInd.innerHTML = `<span class="live-dot"></span><span>EN VIVO ${m.minute ? "· " + m.minute + "'" : ''}</span>`;
      } else if (m.status === 'HALFTIME') {
        if (!liveInd) {
          liveInd = document.createElement('div');
          liveInd.className = 'live-indicator live-indicator--ht';
          card.insertBefore(liveInd, card.querySelector('.match-header'));
        } else {
          liveInd.className = 'live-indicator live-indicator--ht';
        }
        liveInd.innerHTML = '<span>MEDIO TIEMPO</span>';
      } else {
        if (liveInd) {
          liveInd.remove();
        }
      }

      // Actualizar la parte central de estado (Minuto / VS / FINAL)
      const centerStatus = card.querySelector('.match-center-status');
      if (centerStatus) {
        if (m.status === 'LIVE') {
          const minText = m.minute ? `Min ${m.minute}'` : 'EN VIVO';
          centerStatus.innerHTML = `
            <div class="live-dot" style="margin-bottom:0.2rem"></div>
            <div class="match-time-live" id="minute-${m.id}">${minText}</div>
          `;
        } else if (m.status === 'HALFTIME') {
          centerStatus.innerHTML = `<div class="match-time-ht">MEDIO TIEMPO</div>`;
        } else if (m.isFinished || m.status === 'FINISHED') {
          centerStatus.innerHTML = `<div class="match-time-final">FINAL</div>`;
        } else {
          centerStatus.innerHTML = `<div class="vs">VS</div>`;
        }
      }

      // Show de medio tiempo dinámico
      let htShow = card.querySelector('.halftime-show');
      if (m.status === 'HALFTIME') {
        if (!htShow) {
          htShow = document.createElement('div');
          htShow.className = 'halftime-show';
          const animals = ['🐱', '🐶', '🐹', '🐮', '🐷', '🐣', '🦆', '🦛', '🐭', '🐼', '🐨', '🐰', '🐻', '🦊', '🦁'];
          const dances = ['dance-bounce', 'dance-swing', 'dance-wobble', 'dance-jump'];
          const randAnimal = animals[Math.floor(Math.random() * animals.length)];
          const randDance = dances[Math.floor(Math.random() * dances.length)];
          htShow.innerHTML = `
            <div class="halftime-bubble">Show de medio tiempo</div>
            <div class="halftime-character ${randDance}">${randAnimal}</div>
          `;
          card.appendChild(htShow);
          playHalftimeChime();
        }
      } else {
        if (htShow) {
          htShow.remove();
        }
      }

      // Actualizar goleadores en tiempo real
      const teams = card.querySelectorAll('.team');
      const scorersAEl = teams[0] ? teams[0].querySelector('.team-scorers') : null;
      const scorersBEl = teams[1] ? teams[1].querySelector('.team-scorers') : null;
      if (scorersAEl && scorersBEl) {
        if (m.scorers) {
          scorersAEl.innerHTML = (m.scorers.teamA || []).map(sc => `<div class="scorer-item"><span class="event-icon">⚽</span>${escapeHtml(sc)}</div>`).join('');
          scorersBEl.innerHTML = (m.scorers.teamB || []).map(sc => `<div class="scorer-item"><span class="event-icon">⚽</span>${escapeHtml(sc)}</div>`).join('');
        } else {
          scorersAEl.innerHTML = '';
          scorersBEl.innerHTML = '';
        }
      }

      // Actualizar tarjetas en tiempo real
      const cardsAEl = document.getElementById(`cardsA-${m.id}`);
      const cardsBEl = document.getElementById(`cardsB-${m.id}`);
      if (cardsAEl && cardsBEl) {
        if (m.cards) {
          let htmlA = '';
          (m.cards.teamA.yellow || []).forEach(y => htmlA += `<div class="card-item-yellow"><span class="event-icon">🟨</span>${escapeHtml(y)}</div>`);
          (m.cards.teamA.red || []).forEach(r => htmlA += `<div class="card-item-red"><span class="event-icon">🟥</span>${escapeHtml(r)}</div>`);
          cardsAEl.innerHTML = htmlA;

          let htmlB = '';
          (m.cards.teamB.yellow || []).forEach(y => htmlB += `<div class="card-item-yellow"><span class="event-icon">🟨</span>${escapeHtml(y)}</div>`);
          (m.cards.teamB.red || []).forEach(r => htmlB += `<div class="card-item-red"><span class="event-icon">🟥</span>${escapeHtml(r)}</div>`);
          cardsBEl.innerHTML = htmlB;
        } else {
          cardsAEl.innerHTML = '';
          cardsBEl.innerHTML = '';
        }
      }

      // Actualizar puntos proyectados
      const projEl = document.getElementById(`projPts-${m.id}`);
      if (projEl) {
        const cls = m.projectedPts === 6 ? 'pts-exact' : (m.projectedPts === 3 ? 'pts-result' : 'pts-miss');
        projEl.className = `pred-points-live ${cls}`;
        const text = m.projectedPts === 6 ? '🎯 +6 pts' : (m.projectedPts === 3 ? '✓ +3 pts' : '✗ 0 pts');
        projEl.innerHTML = `${text} <span class="pts-live-tag">en vivo</span>`;
      }
    }

    // Si un partido cambió de estado (ej. pasó a LIVE), recargar la página
    const wasLive = card.classList.contains('match-card--live');
    const isNowLive = (m.status === 'LIVE' || m.status === 'HALFTIME');
    if (!wasLive && isNowLive) {
      // Un partido acaba de empezar – recargar para actualizar la UI completa
      location.reload();
    }
    if (m.isFinished && card.querySelector('.score-inputs')) {
      // Un partido acaba de terminar y todavía mostramos inputs – recargar
      location.reload();
    }
  });
}

function updateLeaderboard(leaderboard) {
  const container = document.getElementById('leaderboard');
  if (!container || !leaderboard.length) return;

  // Recalcular bolsa acumulada en vivo
  const paidCount = leaderboard.filter(u => u.hasPaid).length;
  const totalPrize = paidCount * 500;
  
  const amountEl = document.getElementById('prize-pool-amount');
  const participantsEl = document.getElementById('prize-pool-participants');
  if (amountEl) {
    amountEl.textContent = `$${totalPrize.toLocaleString()} MXN`;
  }
  if (participantsEl) {
    participantsEl.textContent = `${paidCount} ${paidCount === 1 ? 'participante' : 'participantes'} de $500 pesos`;
  }

  const medals = ['🥇'];

  container.innerHTML = leaderboard.map((u, i) => {
    const topClass = i < 3 ? `top-${i + 1}` : '';
    const youTag = u.isYou ? '<span style="font-size:0.7rem; color:var(--accent-color)"> (tú)</span>' : '';
    const paidTag = u.hasPaid ? ' <span class="paid-indicator" title="Participa por la bolsa de premios">$</span>' : '';
    const projectedTag = u.projected > 0
      ? `<div class="lb-projected">+${u.projected} en vivo</div>`
      : '';

    return `
      <div class="leaderboard-row ${topClass}">
        <div class="lb-rank">${medals[i] ?? (i + 1)}</div>
        <div class="lb-name">${escapeHtml(u.username)}${paidTag}${youTag}</div>
        <div class="lb-score-block">
          <div class="lb-points">${u.total}</div>
          <div class="lb-pts-label">pts</div>
          ${projectedTag}
        </div>
      </div>
    `;
  }).join('');
}

function updateStandings(standings) {
  for (const groupName in standings) {
    const teams = standings[groupName];
    const tbodyId = `group-body-${groupName.replace(/\s+/g, '-')}`;
    const tbody = document.getElementById(tbodyId);
    if (!tbody) continue;

    let html = '';
    teams.forEach((team, index) => {
      const pos = index + 1;
      const dg = team.gf - team.gc;
      const dgStr = dg > 0 ? `+${dg}` : dg;
      const isQualifier = pos <= 2;
      const topClass = isQualifier ? 'top-two' : '';

      const flagHtml = team.flag ? `<img src="${team.flag}" alt="${escapeHtml(team.name)}" />` : '';

      html += `
        <tr class="${topClass}">
          <td class="num pos">${pos}</td>
          <td class="team-cell">
            ${flagHtml}
            <span class="team-name-abbrev" title="${escapeHtml(team.name)}">
              ${escapeHtml(team.name)}
            </span>
          </td>
          <td class="num">${team.pj}</td>
          <td class="num">${team.pg}</td>
          <td class="num">${team.pe}</td>
          <td class="num">${team.pp}</td>
          <td class="num">${team.gf}</td>
          <td class="num">${team.gc}</td>
          <td class="num">${dgStr}</td>
          <td class="num" style="font-weight: 800; color:var(--accent-color)">${team.pts}</td>
        </tr>
      `;
    });
    tbody.innerHTML = html;
  }
}

function updateTopScorers(topScorers) {
  const tbody = document.getElementById('top-scorers-body');
  if (!tbody) return;

  const entries = Object.entries(topScorers);
  if (entries.length === 0) {
    tbody.innerHTML = `
      <tr>
        <td colspan="3" style="text-align: center; color: var(--text-secondary); padding: 2rem;">
          No hay goles registrados aún
        </td>
      </tr>
    `;
    return;
  }

  let html = '';
  let pos = 1;
  entries.forEach(([player, info]) => {
    const flagHtml = info.flag ? `<img src="${info.flag}" alt="${escapeHtml(info.team)}" style="width: 16px; height: auto; border-radius: 2px;" />` : '';
    html += `
      <tr>
        <td class="num pos">${pos++}</td>
        <td>
          <div style="font-weight: 700">${escapeHtml(player)}</div>
          <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.2rem;">
            ${flagHtml}
            <span>${escapeHtml(info.team)}</span>
          </div>
        </td>
        <td class="num" style="font-weight: 800; color: var(--accent-color); font-size: 1.1rem">${info.goals}</td>
      </tr>
    `;
  });
  tbody.innerHTML = html;
}

function updateTournamentStats(stats) {
  const pjEl = document.getElementById('stat-pj');
  const totalGoalsEl = document.getElementById('stat-totalGoals');
  const avgGoalsEl = document.getElementById('stat-avgGoals');
  const penaltiesEl = document.getElementById('stat-penalties');
  const ownGoalsEl = document.getElementById('stat-ownGoals');
  const yellowsEl = document.getElementById('stat-yellows');
  const redsEl = document.getElementById('stat-reds');
  const bestAttackEl = document.getElementById('record-best-attack');
  const worstDefenseEl = document.getElementById('record-worst-defense');
  const maxGoalsEl = document.getElementById('record-max-goals');

  if (pjEl) pjEl.textContent = stats.pj;
  if (totalGoalsEl) totalGoalsEl.textContent = stats.totalGoals;
  if (avgGoalsEl) avgGoalsEl.textContent = stats.avgGoals;
  if (penaltiesEl) penaltiesEl.textContent = stats.penalties;
  if (ownGoalsEl) ownGoalsEl.textContent = stats.ownGoals;
  if (yellowsEl) yellowsEl.innerHTML = `🟨 ${stats.totalYellows}`;
  if (redsEl) redsEl.innerHTML = `🟥 ${stats.totalReds}`;

  if (bestAttackEl) {
    bestAttackEl.textContent = stats.bestAttackTeam 
      ? `${stats.bestAttackTeam} (${stats.bestAttackGoals} Goles)` 
      : '–';
  }
  if (worstDefenseEl) {
    worstDefenseEl.textContent = stats.worstDefenseTeam 
      ? `${stats.worstDefenseTeam} (${stats.worstDefenseGoals} Goles)` 
      : '–';
  }
  if (maxGoalsEl) {
    if (stats.maxGoalsMatch) {
      maxGoalsEl.textContent = `${stats.maxGoalsMatch.teamA} ${stats.maxGoalsMatch.scoreA} – ${stats.maxGoalsMatch.scoreB} ${stats.maxGoalsMatch.teamB}`;
    } else {
      maxGoalsEl.textContent = '–';
    }
  }
}

function updateTopCards(topCards) {
  const tbodyYellow = document.getElementById('top-yellows-body');
  const tbodyRed = document.getElementById('top-reds-body');

  if (tbodyYellow) {
    const yellows = Object.entries(topCards.yellow || {});
    if (yellows.length === 0) {
      tbodyYellow.innerHTML = '<tr><td style="color:var(--text-secondary); text-align:center; padding:1rem;">Ninguna</td></tr>';
    } else {
      tbodyYellow.innerHTML = yellows.map(([player, info]) => {
        const flagHtml = info.flag ? `<img src="${info.flag}" alt="${escapeHtml(info.team)}" style="width: 14px; height: auto; border-radius: 1px;" />` : '';
        return `
          <tr>
            <td>
              <div style="font-weight:700">${escapeHtml(player)}</div>
              <div style="font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.1rem;">
                ${flagHtml}
                <span>${escapeHtml(info.team)}</span>
              </div>
            </td>
            <td class="num" style="font-weight:800; color:#ffaa00">${info.count}</td>
          </tr>
        `;
      }).join('');
    }
  }

  if (tbodyRed) {
    const reds = Object.entries(topCards.red || {});
    if (reds.length === 0) {
      tbodyRed.innerHTML = '<tr><td style="color:var(--text-secondary); text-align:center; padding:1rem;">Ninguna</td></tr>';
    } else {
      tbodyRed.innerHTML = reds.map(([player, info]) => {
        const flagHtml = info.flag ? `<img src="${info.flag}" alt="${escapeHtml(info.team)}" style="width: 14px; height: auto; border-radius: 1px;" />` : '';
        return `
          <tr>
            <td>
              <div style="font-weight:700">${escapeHtml(player)}</div>
              <div style="font-size: 0.7rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.3rem; margin-top: 0.1rem;">
                ${flagHtml}
                <span>${escapeHtml(info.team)}</span>
              </div>
            </td>
            <td class="num" style="font-weight:800; color:#ff0055">${info.count}</td>
          </tr>
        `;
      }).join('');
    }
  }
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// ── Modal de Detalles del Partido (Alineaciones y Estadísticas) ──
let activeModalMatchId = null;

async function openMatchDetails(matchId) {
  activeModalMatchId = matchId;
  const modal = document.getElementById('match-details-modal');
  if (!modal) return;
  
  // Mostrar modal y transición
  modal.classList.add('active');
  
  // Limpiar/Loader
  document.getElementById('modal-score-value').textContent = '–';
  document.getElementById('modal-status-badge').textContent = 'Cargando...';
  document.getElementById('modal-status-badge').className = '';
  document.getElementById('modal-stats-container').innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-secondary)">Cargando estadísticas...</div>';
  document.getElementById('lineup-starters-a').innerHTML = '';
  document.getElementById('lineup-bench-a').innerHTML = '';
  document.getElementById('lineup-starters-b').innerHTML = '';
  document.getElementById('lineup-bench-b').innerHTML = '';
  document.getElementById('subs-timeline-a').innerHTML = '';
  document.getElementById('subs-timeline-b').innerHTML = '';
  
  // Reset tabs
  switchModalTab('stats');
  
  try {
    const res = await fetch(`api/match_details.php?id=${matchId}`);
    const data = await res.json();
    
    if (data.error) {
      document.getElementById('modal-status-badge').textContent = 'Error';
      document.getElementById('modal-stats-container').innerHTML = `<div style="text-align:center; padding:2rem; color:#ff6b9d">${escapeHtml(data.error)}</div>`;
      return;
    }
    
    // Set Header
    document.getElementById('modal-name-a').textContent = data.teamA;
    document.getElementById('modal-name-b').textContent = data.teamB;
    document.getElementById('modal-flag-a').src = data.flagA || '';
    document.getElementById('modal-flag-b').src = data.flagB || '';
    document.getElementById('lineup-title-a').textContent = data.teamA;
    document.getElementById('lineup-title-b').textContent = data.teamB;
    document.getElementById('subs-title-a').textContent = data.teamA;
    document.getElementById('subs-title-b').textContent = data.teamB;
    
    const scoreA = data.scoreA !== null ? data.scoreA : 0;
    const scoreB = data.scoreB !== null ? data.scoreB : 0;
    document.getElementById('modal-score-value').textContent = `${scoreA} – ${scoreB}`;
    
    // Info adicional
    document.getElementById('modal-info-venue').innerHTML = `📍 ${escapeHtml(data.venue || 'Sede no disponible')}`;
    document.getElementById('modal-info-referee').innerHTML = `👤 Árbitro: ${escapeHtml(data.referee || '–')}`;
    document.getElementById('modal-info-attendance').innerHTML = `👥 Asistencia: ${data.attendance ? data.attendance.toLocaleString() : '–'}`;
    
    // Status Badge
    const badge = document.getElementById('modal-status-badge');
    badge.textContent = data.status === 'LIVE' ? `EN VIVO ${data.minute ? "· " + data.minute + "'" : ''}` : (data.status === 'HALFTIME' ? 'MEDIO TIEMPO' : (data.status === 'FINISHED' ? 'FINAL' : 'PROGRAMADO'));
    badge.className = data.status === 'LIVE' ? 'live-indicator' : (data.status === 'HALFTIME' ? 'live-indicator live-indicator--ht' : '');
    
    // Render Stats
    renderModalStats(data.stats);
    
    // Render Lineups
    renderModalRoster(data.rosters, data.scorers, data.cards);
    
    // Render Subs
    renderModalSubs(data.substitutions);
    
  } catch (e) {
    document.getElementById('modal-status-badge').textContent = 'Error';
    document.getElementById('modal-stats-container').innerHTML = '<div style="text-align:center; padding:2rem; color:#ff6b9d">Error de conexión al cargar detalles</div>';
  }
}

function closeMatchDetails() {
  const modal = document.getElementById('match-details-modal');
  if (modal) {
    modal.classList.remove('active');
  }
  activeModalMatchId = null;
}

// Cerrar modal haciendo clic afuera
window.addEventListener('click', (e) => {
  const modal = document.getElementById('match-details-modal');
  if (e.target === modal) {
    closeMatchDetails();
  }
});

function switchModalTab(tabId) {
  document.querySelectorAll('.modal-tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.modal-tab-pane').forEach(pane => pane.classList.remove('active'));
  
  const activeBtn = document.getElementById(`modal-btn-${tabId}`);
  const activePane = document.getElementById(`modal-tab-${tabId}`);
  
  if (activeBtn) activeBtn.classList.add('active');
  if (activePane) activePane.classList.add('active');
}

function renderModalStats(stats) {
  const container = document.getElementById('modal-stats-container');
  if (!container) return;
  
  const statLabelsMap = {
    'possessionPct': 'Posesión de Balón',
    'totalShots': 'Tiros Totales',
    'shotsOnTarget': 'Tiros a Portería',
    'wonCorners': 'Tiros de Esquina',
    'foulsCommitted': 'Faltas',
    'saves': 'Atajadas del Portero',
    'offsides': 'Fueras de Juego'
  };
  
  let html = '';
  
  for (const key in statLabelsMap) {
    if (!stats[key]) continue;
    
    const label = statLabelsMap[key];
    const valAStr = stats[key].teamA || '0';
    const valBStr = stats[key].teamB || '0';
    
    // Calcular porcentaje para las barras
    let pctA = 50;
    let pctB = 50;
    
    const valA = parseFloat(valAStr.replace('%', ''));
    const valB = parseFloat(valBStr.replace('%', ''));
    
    if (valA + valB > 0) {
      pctA = (valA / (valA + valB)) * 100;
      pctB = (valB / (valA + valB)) * 100;
    } else if (valA === 0 && valB === 0) {
      pctA = 0;
      pctB = 0;
    }
    
    html += `
      <div class="stat-row">
        <div class="stat-labels">
          <span class="stat-val-a">${escapeHtml(valAStr)}</span>
          <span class="stat-name">${escapeHtml(label)}</span>
          <span class="stat-val-b">${escapeHtml(valBStr)}</span>
        </div>
        <div class="stat-bar-track">
          <div class="stat-bar-fill-a" style="width: ${pctA}%"></div>
          <div class="stat-bar-fill-b" style="width: ${pctB}%"></div>
        </div>
      </div>
    `;
  }
  
  if (!html) {
    container.innerHTML = '<div style="text-align:center; padding:2rem; color:var(--text-secondary)">No hay estadísticas disponibles para este partido aún</div>';
  } else {
    container.innerHTML = html;
  }
}

function renderModalRoster(rosters, scorers, cards) {
  const renderList = (players, ulId, teamKey) => {
    const ul = document.getElementById(ulId);
    if (!ul) return;
    ul.innerHTML = '';
    
    if (!players || players.length === 0) {
      ul.innerHTML = '<li class="lineup-player-item" style="color:var(--text-secondary)">No disponible</li>';
      return;
    }
    
    players.forEach(p => {
      const li = document.createElement('li');
      li.className = 'lineup-player-item';
      
      const numSpan = document.createElement('span');
      numSpan.className = 'player-number';
      numSpan.textContent = p.jersey || '–';
      
      const nameSpan = document.createElement('span');
      nameSpan.className = 'player-name-text';
      nameSpan.textContent = p.name;
      
      if (p.position && p.position !== 'Unknown') {
        const posTag = document.createElement('span');
        posTag.className = 'player-pos-tag';
        posTag.textContent = p.position;
        nameSpan.appendChild(posTag);
      }
      
      // Contenedor de eventos (goles, tarjetas, cambios)
      const eventsDiv = document.createElement('div');
      eventsDiv.className = 'player-events';
      
      // Si el jugador fue sustituido
      if (p.subbedOut) {
        const subOut = document.createElement('span');
        subOut.title = 'Sustituido';
        subOut.textContent = '🔄';
        eventsDiv.appendChild(subOut);
      }
      if (p.subbedIn) {
        const subIn = document.createElement('span');
        subIn.title = 'Entró de cambio';
        subIn.textContent = '📥';
        eventsDiv.appendChild(subIn);
      }
      
      // Buscar si el jugador anotó gol o tiene tarjeta en nuestros datos locales
      const card = document.querySelector(`.match-card[data-match-id="${activeModalMatchId}"]`);
      if (card) {
        const teamIndex = teamKey === 'teamA' ? 0 : 1;
        const scorersInCard = card.querySelectorAll(`.team:nth-child(${teamIndex === 0 ? 1 : 3}) .team-scorers .scorer-item`);
        scorersInCard.forEach(sc => {
          if (isPlayerMatch(p.name, sc.textContent)) {
            const goalSpan = document.createElement('span');
            goalSpan.textContent = '⚽';
            goalSpan.title = sc.textContent;
            eventsDiv.appendChild(goalSpan);
          }
        });
        
        const cardsInCard = card.querySelectorAll(`#cards${teamKey === 'teamA' ? 'A' : 'B'}-${activeModalMatchId} div`);
        cardsInCard.forEach(c => {
          if (isPlayerMatch(p.name, c.textContent)) {
            const cardSpan = document.createElement('span');
            cardSpan.textContent = c.textContent.includes('🟨') ? '🟨' : '🟥';
            cardSpan.title = c.textContent;
            eventsDiv.appendChild(cardSpan);
          }
        });
      }
      
      li.appendChild(numSpan);
      li.appendChild(nameSpan);
      li.appendChild(eventsDiv);
      ul.appendChild(li);
    });
  };
  
  renderList(rosters.teamA.starters, 'lineup-starters-a', 'teamA');
  renderList(rosters.teamA.bench, 'lineup-bench-a', 'teamA');
  renderList(rosters.teamB.starters, 'lineup-starters-b', 'teamB');
  renderList(rosters.teamB.bench, 'lineup-bench-b', 'teamB');
}

function renderModalSubs(subs) {
  const renderSubsColumn = (teamSubs, containerId) => {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    
    if (!teamSubs || teamSubs.length === 0) {
      container.innerHTML = '<div style="color:var(--text-secondary); font-size:0.8rem; padding:1rem; text-align:center">Sin sustituciones registradas</div>';
      return;
    }
    
    teamSubs.sort((a, b) => parseInt(a.minute) - parseInt(b.minute));
    
    teamSubs.forEach(s => {
      const item = document.createElement('div');
      item.className = 'subs-timeline-item';
      
      const minSpan = document.createElement('span');
      minSpan.className = 'sub-minute';
      minSpan.textContent = s.minute || '–';
      
      const details = document.createElement('div');
      details.className = 'sub-details';
      
      const inSpan = document.createElement('span');
      inSpan.className = 'sub-in';
      inSpan.textContent = `🟢 ${s.in}`;
      
      const outSpan = document.createElement('span');
      outSpan.className = 'sub-out';
      outSpan.textContent = `🔴 ${s.out}`;
      
      details.appendChild(inSpan);
      details.appendChild(outSpan);
      
      item.appendChild(minSpan);
      item.appendChild(details);
      
      container.appendChild(item);
    });
  };
  
  renderSubsColumn(subs.teamA, 'subs-timeline-a');
  renderSubsColumn(subs.teamB, 'subs-timeline-b');
}

function escapeHtml(str) {
  if (!str) return '';
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function getCleanEventPlayerName(text) {
  // Remove emojis
  let name = text.replace(/[⚽🟨🟥]/g, '');
  // Remove minute (e.g. " 45'", " 90+2'")
  name = name.replace(/\s+\d+(?:\+\d+)?'/, '');
  // Remove suffixes like (p.) or (ag.)
  name = name.replace(/\s*\(p\.\)/gi, '');
  name = name.replace(/\s*\(ag\.\)/gi, '');
  return name.trim().toLowerCase();
}

function isPlayerMatch(rosterName, eventText) {
  const cleanRoster = rosterName.toLowerCase().trim();
  const cleanEvent = getCleanEventPlayerName(eventText).replace(/\./g, '').trim(); // Remove dots from abbreviations
  
  if (cleanRoster === cleanEvent) return true;
  
  const rosterWords = cleanRoster.split(/\s+/);
  const eventWords = cleanEvent.split(/\s+/);
  
  if (eventWords.length === 0) return false;
  
  // If event name has only 1 word, it must be present in rosterWords
  if (eventWords.length === 1) {
    const singleWord = eventWords[0];
    if (rosterWords.includes(singleWord)) {
      // Prevent false positives on common first names if it is the first word and the roster has a last name
      const commonFirstNames = new Set([
        "raúl", "raul", "josé", "jose", "juan", "luis", "carlos", "diego", "david", 
        "javier", "alejandro", "roberto", "fernando", "daniel", "mateo", "santiago", 
        "sebastian", "gabriel", "lucas", "nicolás", "nicolas", "pedro", "jorge", 
        "miguel", "ángel", "angel", "antonio", "manuel", "francisco", "césar", "cesar",
        "hugo", "arturo", "sergio", "rodrigo", "marcos", "christian", "cristian"
      ]);
      if (rosterWords.indexOf(singleWord) === 0 && rosterWords.length > 1 && commonFirstNames.has(singleWord)) {
        return false;
      }
      return true;
    }
    return false;
  }
  
  // For multi-word event names (e.g. "r jimenez" or "raul jimenez"), all words must match
  return eventWords.every(ew => {
    if (ew.length === 1) {
      // Abbreviation: must match starting letter of at least one roster word
      return rosterWords.some(rw => rw.startsWith(ew));
    } else {
      // Full word: must exist in rosterWords
      return rosterWords.includes(ew);
    }
  });
}

let sharedAudioCtx = null;

function initAudioOnFirstClick() {
  const unlock = () => {
    try {
      const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
      if (AudioCtxClass) {
        sharedAudioCtx = new AudioCtxClass();
        if (sharedAudioCtx.state === 'suspended') {
          sharedAudioCtx.resume();
        }
        
        // Si hay algún show de medio tiempo visible al momento del clic de desbloqueo, haz sonar el chime
        if (document.querySelector('.halftime-show')) {
          playHalftimeChime();
        }
      }
    } catch (e) {
      // ignore
    }
    document.removeEventListener('click', unlock);
    document.removeEventListener('touchstart', unlock);
  };
  document.addEventListener('click', unlock);
  document.addEventListener('touchstart', unlock);
}

// Inicializar el escuchador de desbloqueo
initAudioOnFirstClick();

function playHalftimeChime() {
  try {
    if (!sharedAudioCtx) {
      const AudioCtxClass = window.AudioContext || window.webkitAudioContext;
      if (AudioCtxClass) {
        sharedAudioCtx = new AudioCtxClass();
      }
    }
    if (!sharedAudioCtx) return;
    
    if (sharedAudioCtx.state === 'suspended') {
      sharedAudioCtx.resume();
    }
    
    // Melodía tierna ascendente: C5, E5, G5, C6 (Do, Mi, Sol, Do)
    const notes = [523.25, 659.25, 783.99, 1046.50];
    const duration = 0.15; // Duración de cada nota en segundos
    const gap = 0.12;      // Retraso entre cada nota
    
    notes.forEach((freq, index) => {
      const osc = sharedAudioCtx.createOscillator();
      const gain = sharedAudioCtx.createGain();
      
      osc.type = 'sine'; // Onda senoidal para un tono suave y tierno
      osc.frequency.setValueAtTime(freq, sharedAudioCtx.currentTime + index * gap);
      
      gain.gain.setValueAtTime(0.12, sharedAudioCtx.currentTime + index * gap); // Volumen controlado (12%)
      gain.gain.exponentialRampToValueAtTime(0.001, sharedAudioCtx.currentTime + index * gap + duration);
      
      osc.connect(gain);
      gain.connect(sharedAudioCtx.destination);
      
      osc.start(sharedAudioCtx.currentTime + index * gap);
      osc.stop(sharedAudioCtx.currentTime + index * gap + duration);
    });
  } catch (e) {
    // Silenciar si el navegador bloquea la reproducción por políticas de interacción del usuario (autoplay)
  }
}
