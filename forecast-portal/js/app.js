// ── Lógica Frontend - Portal de Pronósticos 🔮 ──

document.addEventListener('DOMContentLoaded', () => {
  const btnRun = document.getElementById('btn-run-forecast');
  if (btnRun) {
    btnRun.addEventListener('click', runForecastEngine);
  }
});

// ── Abrir modal de pronóstico y cargar datos ──
async function openForecast(matchId) {
  const modal = document.getElementById('forecast-modal');
  const loader = document.getElementById('modal-loader');
  const errorBlock = document.getElementById('modal-error');
  const content = document.getElementById('modal-forecast-content');
  
  // Resetear interfaz y limpiar datos anteriores para evitar parpadeos
  loader.style.display = 'block';
  errorBlock.style.display = 'none';
  content.style.display = 'none';
  modal.style.display = 'block';
  
  document.getElementById('forecast-pick').textContent = '...';
  document.getElementById('forecast-confidence').textContent = '...';
  document.getElementById('forecast-agreement').textContent = '...';
  document.getElementById('label-prob-home').textContent = 'Local: 0%';
  document.getElementById('label-prob-draw').textContent = 'Empate: 0%';
  document.getElementById('label-prob-away').textContent = 'Visitante: 0%';
  document.getElementById('bar-fill-home').style.width = '0%';
  document.getElementById('bar-fill-draw').style.width = '0%';
  document.getElementById('bar-fill-away').style.width = '0%';
  document.getElementById('forecast-scores-list').innerHTML = '';
  document.getElementById('forecast-agents-body').innerHTML = '';
  const reviewSection = document.getElementById('score-review-section');
  if (reviewSection) reviewSection.style.display = 'none';
  const reviewContent = document.getElementById('forecast-score-review-content');
  if (reviewContent) reviewContent.innerHTML = '';
  const predictedAtEl = document.getElementById('forecast-predicted-at');
  if (predictedAtEl) {
    predictedAtEl.textContent = '';
    predictedAtEl.style.display = 'none';
  }
  
  // Buscar datos del partido desde la tarjeta en el DOM para pre-poblar cabecera
  const card = document.querySelector(`.match-card[onclick="openForecast(${matchId})"]`);
  if (card) {
    const teamA = card.querySelector('.team-block:first-child .team-name').textContent;
    const teamB = card.querySelector('.team-block:last-child .team-name').textContent;
    const flagA = card.querySelector('.team-block:first-child .flag').src;
    const flagB = card.querySelector('.team-block:last-child .flag').src;
    
    document.getElementById('modal-name-a').textContent = teamA;
    document.getElementById('modal-name-b').textContent = teamB;
    document.getElementById('modal-flag-a').src = flagA;
    document.getElementById('modal-flag-b').src = flagB;
    document.getElementById('modal-match-info-text').textContent = card.querySelector('.match-time').textContent + ' | ' + card.querySelector('.venue-text').textContent;
  }
  
  try {
    const isLive = card && card.querySelector('.badge--live') !== null;
    const endpoint = isLive ? 'api/get_live_forecast.php' : 'api/get_forecast.php';
    const fetchOptions = isLive ? { method: 'POST' } : { method: 'GET' };
    
    const res = await fetch(`${endpoint}?match_id=${matchId}&t=` + Date.now(), fetchOptions);
    const data = await res.json();
    
    if (data.error) {
      showModalError('Pronóstico Pendiente', data.error);
      return;
    }

    const forecastRoot =
      data?.data ||
      data?.forecast ||
      data?.result ||
      data?.match ||
      data;

    const scoreReview =
      forecastRoot?.scoreReview ||
      forecastRoot?.score_review ||
      forecastRoot?.prediction?.scoreReview ||
      forecastRoot?.prediction?.score_review ||
      data?.scoreReview ||
      data?.score_review ||
      data?.prediction?.scoreReview ||
      data?.prediction?.score_review ||
      null;

    console.log('FORECAST RAW DATA', data);
    console.log('data.run_id:', data.run_id ?? data.runId);
    console.log('data.source:', data.source);
    console.log('data.availability:', data.availability);
    console.log('data.agents:', data.agents);
    console.log('data.most_likely_scores:', data.most_likely_scores ?? data.mostLikelyScores ?? data.scores);
    console.log('FORECAST ROOT', forecastRoot);
    console.log('scoreReview recibido:', scoreReview);
    
    // Cargar Predicción Consolidada
    const pickMap = { 'home': 'LOCAL', 'away': 'VISITANTE', 'draw': 'EMPATE' };
    const confMap = { 'high': 'ALTA 🟢', 'medium': 'MEDIA 🟡', 'low': 'BAJA 🔴' };
    const agreementMap = { 'strong': 'FUERTE 💪', 'moderate': 'MODERADO 🤝', 'weak': 'DEBIL ❓' };
    
    document.getElementById('forecast-pick').textContent = pickMap[data.prediction.pick] || data.prediction.pick.toUpperCase();
    document.getElementById('forecast-confidence').textContent = confMap[data.prediction.confidence] || data.prediction.confidence.toUpperCase();
    document.getElementById('forecast-agreement').textContent = agreementMap[data.prediction.modelAgreement] || data.prediction.modelAgreement.toUpperCase();
    
    // Mostrar fecha y hora de la predicción (predicted_at)
    const predictedAtEl = document.getElementById('forecast-predicted-at');
    if (predictedAtEl) {
      if (data.predicted_at) {
        predictedAtEl.textContent = `Corrida: ${data.predicted_at}`;
        predictedAtEl.style.display = 'inline-block';
      } else {
        predictedAtEl.style.display = 'none';
      }
    }
    
    // Asignar colores según pick
    const pickEl = document.getElementById('forecast-pick');
    pickEl.style.color = data.prediction.pick === 'home' ? 'var(--accent-color)' : (data.prediction.pick === 'away' ? 'var(--fifa-cyan)' : 'var(--text-secondary)');
    
    // 6. En consola imprimir antes de renderizar
    console.log('FORECAST RAW', data.match_id, data.run_id, data.home_team, data.away_team, data.prediction?.probabilities, data.agents);

    // Cargar porcentajes y barra tripartita
    let pHome = (data.prediction?.probabilities?.home !== undefined && data.prediction?.probabilities?.home !== null) ? parseFloat(data.prediction.probabilities.home) : 0;
    let pAway = (data.prediction?.probabilities?.away !== undefined && data.prediction?.probabilities?.away !== null) ? parseFloat(data.prediction.probabilities.away) : 0;
    let pDraw;
    if (data.prediction?.probabilities?.draw !== undefined && data.prediction?.probabilities?.draw !== null) {
      pDraw = parseFloat(data.prediction.probabilities.draw);
    } else {
      pDraw = 100 - pHome - pAway;
    }

    // Asegurar valores no negativos
    pHome = Math.max(0, pHome);
    pDraw = Math.max(0, pDraw);
    pAway = Math.max(0, pAway);

    // Guard: si la suma no está entre 99.5 y 100.5, normalizar proporcionalmente
    const sum = pHome + pDraw + pAway;
    if (sum > 0 && (sum < 99.5 || sum > 100.5)) {
      pHome = parseFloat(((pHome / sum) * 100).toFixed(2));
      pDraw = parseFloat(((pDraw / sum) * 100).toFixed(2));
      pAway = parseFloat(((pAway / sum) * 100).toFixed(2));
    } else {
      pHome = parseFloat(pHome.toFixed(2));
      pDraw = parseFloat(pDraw.toFixed(2));
      pAway = parseFloat(pAway.toFixed(2));
    }
    
    document.getElementById('label-prob-home').textContent = `Local: ${pHome}%`;
    document.getElementById('label-prob-draw').textContent = `Empate: ${pDraw}%`;
    document.getElementById('label-prob-away').textContent = `Visitante: ${pAway}%`;
    
    document.getElementById('bar-fill-home').style.width = `${pHome}%`;
    document.getElementById('bar-fill-draw').style.width = `${pDraw}%`;
    document.getElementById('bar-fill-away').style.width = `${pAway}%`;
    
    // Cargar marcadores más probables
    const scoresContainer = document.getElementById('forecast-scores-list');
    scoresContainer.innerHTML = '';
    const root = data?.data || data?.forecast || data?.result || data;

    const scores =
      root?.most_likely_scores ||
      root?.mostLikelyScores ||
      root?.scores ||
      root?.score_options ||
      root?.scoreOptions ||
      root?.top_scores ||
      root?.topScores ||
      root?.prediction?.most_likely_scores ||
      root?.prediction?.mostLikelyScores ||
      root?.prediction?.scores ||
      root?.prediction?.score_options ||
      root?.prediction?.scoreOptions ||
      root?.prediction?.top_scores ||
      root?.prediction?.topScores ||
      [];

    console.log('FORECAST RAW DATA', data);
    console.log('FORECAST ROOT', root);
    console.log('SCORES RESOLVED', Array.isArray(scores), scores.length, scores);
    const visibleScores = scores
      .filter(s => {
        if (!s.source) return true;
        const src = String(s.source).toLowerCase();
        return src.startsWith('scoreline_engine') || src === 'quiniela_users';
      })
      .slice(0, 5);
      
    if (visibleScores.length > 0) {
      visibleScores.forEach(s => {
        const scoreText = s.score || `${s.scoreA}-${s.scoreB}`;
        const probability = Number(s.probability || 0);
        const scoreCard = document.createElement('div');
        scoreCard.className = 'score-option-card';
        scoreCard.innerHTML = `
          <span class="score-rank-badge">Rango #${s.rank || ''}</span>
          <span class="score-text-val">${scoreText}</span>
          <span class="score-prob-badge">${probability}% prob.</span>
        `;
        scoresContainer.appendChild(scoreCard);
      });
    } else {
      scoresContainer.innerHTML = '<p style="color:var(--text-secondary); font-size:0.85rem;">No hay marcadores disponibles.</p>';
    }

    // Cargar revisión de IA del marcador
    const reviewSection = document.getElementById('score-review-section');
    const reviewContent = document.getElementById('forecast-score-review-content');
    
    if (scoreReview && scoreReview.llmStatus === 'ok' && reviewSection && reviewContent) {
      const decision = String(scoreReview.decision || '').toUpperCase();
      let decisionColor = '#fff';
      if (decision.includes('APPROV') || decision.includes('APROB') || decision.includes('OK')) {
        decisionColor = '#4caf50';
      } else if (decision.includes('OVERRID') || decision.includes('CORREG') || decision.includes('CAMBIO')) {
        decisionColor = '#ff9800';
      } else if (decision.includes('REJECT') || decision.includes('RECHAZ')) {
        decisionColor = '#f44336';
      }

      reviewContent.innerHTML = `
        <div class="score-review-card" style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; padding: 16px; margin-top: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);">
          <!-- Cabecera con Decisión y Confianza -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
            <div>
              <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 2px;">Decisión</span>
              <span class="decision-badge" style="font-size: 0.85rem; font-weight: 700; color: ${decisionColor};">${scoreReview.decision || '–'}</span>
            </div>
            <div style="text-align: right;">
              <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 2px;">Confianza</span>
              <span style="font-size: 0.85rem; font-weight: 700; color: var(--fifa-cyan);">${scoreReview.confidence || '–'}</span>
            </div>
          </div>

          <!-- Marcadores Recomendados y Alternativos -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; background: rgba(255, 255, 255, 0.02); border-radius: 6px; padding: 10px; margin-bottom: 12px; border: 1px dashed rgba(255, 255, 255, 0.05); text-align: center;">
            <div style="border-right: 1px dashed rgba(255,255,255,0.1); padding-right: 8px;">
              <span style="font-size: 0.65rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Recomendado</span>
              <span style="font-size: 1.15rem; font-weight: 700; color: var(--accent-color); display: block; margin-top: 2px;">${scoreReview.recommendedScore || '–'}</span>
            </div>
            <div style="padding-left: 8px;">
              <span style="font-size: 0.65rem; color: var(--text-secondary); display: block; text-transform: uppercase;">Alternativo</span>
              <span style="font-size: 1.15rem; font-weight: 700; color: var(--fifa-cyan); display: block; margin-top: 2px;">${scoreReview.alternativeScore || '–'}</span>
            </div>
          </div>

          <!-- Veredicto -->
          <div style="margin-bottom: 10px;">
            <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 2px;">Veredicto</span>
            <p style="font-size: 0.82rem; margin: 0; color: #fff; line-height: 1.4; font-weight: 500;">${scoreReview.verdict || '–'}</p>
          </div>

          <!-- Razonamiento -->
          <div style="margin-bottom: 10px; border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 10px;">
            <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 2px;">Razonamiento</span>
            <div style="font-size: 0.78rem; margin: 0; color: var(--text-secondary); line-height: 1.4; text-align: justify;">${renderList(scoreReview.reasoning) || '–'}</div>
          </div>

          <!-- Contraargumento -->
          <div style="margin-bottom: 10px; border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 10px;">
            <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 2px;">Caso de Contraparte (Counter-Case)</span>
            <p style="font-size: 0.78rem; margin: 0; color: var(--text-secondary); line-height: 1.4; text-align: justify; font-style: italic;">${scoreReview.counterCase || '–'}</p>
          </div>

          <!-- Factores de Riesgo -->
          <div style="border-top: 1px solid rgba(255, 255, 255, 0.05); padding-top: 10px;">
            <span style="font-size: 0.72rem; text-transform: uppercase; color: var(--text-secondary); display: block; margin-bottom: 4px;">Factores de Riesgo</span>
            <div style="font-size: 0.78rem; margin: 0; color: #ff5252; line-height: 1.4;">${renderList(scoreReview.riskFactors) || '–'}</div>
          </div>
        </div>
      `;
      reviewSection.style.display = 'block';
    } else {
      if (reviewSection) {
        reviewSection.style.display = 'none';
      }
    }

    // Cargar tabla de agentes
    const agentsBody = document.getElementById('forecast-agents-body');
    agentsBody.innerHTML = '';
    
    const agentNameMap = {
      'market': 'Mercado (The Odds API)',
      'quiniela_users': 'Consenso de Usuarios',
      'historical_internet': 'Histórico Internacional',
      'random_forest': 'Random Forest',
      'lightgbm': 'LightGBM',
      'rf_lgbm_meta': 'RF/LGBM Meta-consenso',
      'live_experts': 'Expertos en Vivo',
      'machine_stats': 'Machine Stats',
      'lineup_impact': 'Alineación Titular',
      'live_match_state': 'Estado en Vivo',
      'context': 'Contexto & Entorno',
      'Contexto & Entorno': 'Contexto & Entorno',
      'context_environment': 'Contexto & Entorno',
      'context_entorno': 'Contexto & Entorno',
      'contexto_entorno': 'Contexto & Entorno',
      'environment_context': 'Contexto & Entorno'
    };
    
    const statusMap = {
      'ok': 'Completo',
      'rules_shadow': 'Completo',
      'unavailable': 'No Disponible',
      'pending': 'Pendiente'
    };
    
    // Lista de alias correspondientes a Machine Stats para deduplicación y mapeo
    const machineStatsAliases = [
      'machine_stats',
      'Machine Stats',
      'Machine Stats (Reserva)',
      'machine_stats_reserva',
      'machine_stats_reserve',
      'machineStats'
    ];

    // Filtrar y normalizar los agentes
    const contextKeys = ['Contexto & Entorno', 'context_environment', 'context_entorno', 'contexto_entorno', 'environment_context', 'context'];
    const isReviewer = (name) => name === 'score_reviewer' || name === 'scoreReviewer' || name === 'score_review' || name === 'scoreReview';
    
    let rawAgents = Array.isArray(data.agents) ? data.agents : Object.values(data.agents || {});
    
    // Si la API no trae lineup_impact o live_match_state, agregar sus objetos locales fallback correspondientes
    const hasLineup = rawAgents.some(a => a.agent === 'lineup_impact' || a.agentKey === 'lineup_impact');
    const hasLiveMatchState = rawAgents.some(a => a.agent === 'live_match_state' || a.agentKey === 'live_match_state');
    
    if (!hasLineup) {
      rawAgents.push({
        agent: 'lineup_impact',
        status: 'pending',
        probabilities: null,
        weightApplied: null
      });
    }
    
    if (!hasLiveMatchState) {
      const card = document.querySelector(`.match-card[onclick="openForecast(${matchId})"]`);
      rawAgents.push({
        agent: 'live_match_state',
        status: card && card.querySelector('.badge--finished') !== null ? 'unavailable' : 'pending',
        probabilities: null,
        weightApplied: null
      });
    }
    
    // Regla de consolidación: Si está presente 'rf_lgbm_meta', no mostrar 'random_forest' ni 'lightgbm'
    const hasMetaConsensus = rawAgents.some(a => a.agent === 'rf_lgbm_meta');
    
    let agentsToRender = [];
    
    rawAgents.forEach(a => {
      if (isReviewer(a.agent)) return;
      
      // Ocultar componentes si ya existe el meta-consenso
      if (hasMetaConsensus && (a.agent === 'random_forest' || a.agent === 'lightgbm')) {
        return;
      }
      
      // Normalizar nombre del agente
      let normalizedAgent = a.agent;
      if (machineStatsAliases.includes(a.agent)) {
        normalizedAgent = 'machine_stats';
      } else if (contextKeys.includes(a.agent)) {
        normalizedAgent = 'context';
      }
      
      // Ocultar Expertos en Vivo si no está disponible para no interrumpir la lectura
      if (normalizedAgent === 'live_experts' && a.status === 'unavailable') {
        return;
      }
      
      // Evitar duplicados (por ejemplo, si vienen alias repetidos)
      const exists = agentsToRender.some(existing => existing.agent === normalizedAgent);
      if (exists) return;
      
      // Intentar parsear el payloadJson si existe y es una cadena
      let parsedPayload = null;
      if (a.payloadJson) {
        try {
          parsedPayload = typeof a.payloadJson === 'string' ? JSON.parse(a.payloadJson) : a.payloadJson;
        } catch (e) {
          console.warn("Error parsing payloadJson for agent " + a.agent, e);
        }
      }
      
      agentsToRender.push({
        ...a,
        agent: normalizedAgent,
        payload: parsedPayload || a.payload || null
      });
    });

    // Ordenar por ponderación (peso aplicado descendente, luego por estatus ok antes que pending)
    agentsToRender.sort((a, b) => {
      const getWeight = (x) => {
        const w = x.weightApplied !== null && x.weightApplied !== undefined ? x.weightApplied : x.weight;
        return w !== null && w !== undefined ? parseFloat(w) : -1;
      };
      
      const wA = getWeight(a);
      const wB = getWeight(b);
      
      if (wB !== wA) {
        return wB - wA;
      }
      
      const statusOrder = { 'ok': 2, 'rules_shadow': 2, 'pending': 1, 'unavailable': 0 };
      const statusA = statusOrder[a.status] || 0;
      const statusB = statusOrder[b.status] || 0;
      return statusB - statusA;
    });
    
    if (agentsToRender.length > 0) {
      agentsToRender.forEach(a => {
        const tr = document.createElement('tr');
        
        // Extraer probabilidades soportando formato plano y formato anidado
        let apHome = null;
        if (a.probabilities && a.probabilities.home !== undefined && a.probabilities.home !== null) {
          apHome = parseFloat(a.probabilities.home);
        } else if (a.home !== undefined && a.home !== null) {
          apHome = parseFloat(a.home);
        }

        let apAway = null;
        if (a.probabilities && a.probabilities.away !== undefined && a.probabilities.away !== null) {
          apAway = parseFloat(a.probabilities.away);
        } else if (a.away !== undefined && a.away !== null) {
          apAway = parseFloat(a.away);
        }

        let apDraw = null;
        if (a.probabilities && a.probabilities.draw !== undefined && a.probabilities.draw !== null) {
          apDraw = parseFloat(a.probabilities.draw);
        } else if (a.draw !== undefined && a.draw !== null) {
          apDraw = parseFloat(a.draw);
        } else if (apHome !== null && apAway !== null) {
          apDraw = 100 - apHome - apAway;
        }

        const hasProbs = apHome !== null && apHome !== undefined;
        
        let homeVal = '–';
        let drawVal = '–';
        let awayVal = '–';

        if (hasProbs) {
          // Asegurar no negativos
          apHome = Math.max(0, apHome);
          apDraw = Math.max(0, apDraw);
          apAway = Math.max(0, apAway);

          // Guard: si la suma no está entre 99.5 y 100.5, normalizar proporcionalmente
          const aSum = apHome + apDraw + apAway;
          if (aSum > 0 && (aSum < 99.5 || aSum > 100.5)) {
            apHome = parseFloat(((apHome / aSum) * 100).toFixed(2));
            apDraw = parseFloat(((apDraw / aSum) * 100).toFixed(2));
            apAway = parseFloat(((apAway / aSum) * 100).toFixed(2));
          } else {
            apHome = parseFloat(apHome.toFixed(2));
            apDraw = parseFloat(apDraw.toFixed(2));
            apAway = parseFloat(apAway.toFixed(2));
          }

          homeVal = `${apHome}%`;
          drawVal = `${apDraw}%`;
          awayVal = `${apAway}%`;
        }
        
        // Extraer peso asignado (weightApplied o weight)
        const weight = a.weightApplied !== null && a.weightApplied !== undefined ? a.weightApplied : a.weight;
        let weightVal = '–';
        if (weight !== null && weight !== undefined) {
          let weightPercent = weight * 100;
          if (weightPercent % 1 !== 0) {
            weightPercent = parseFloat(weightPercent.toFixed(2));
          }
          weightVal = `${weightPercent}%`;
        }
        
        // Estatus: si no tiene probabilidades, forzar a "pending" (Pendiente) si estaba en "ok" o "rules_shadow"
        let displayStatus = a.status;
        if (displayStatus === 'rules_shadow') {
          displayStatus = 'ok';
        }
        if (!hasProbs && displayStatus === 'ok') {
          displayStatus = 'pending';
        }
        
        const nameLabel = agentNameMap[a.agent] || a.agent;
        let nameCellContent = nameLabel;
        
        // Agregar metadatos adicionales para lineup_impact
        if (a.agent === 'lineup_impact') {
          const lineupSummary = a.lineupSummary || a.displayDetails || a.detailsText || null;
          const lineupImpact = a.payload || null;
          
          if (lineupSummary) {
            nameCellContent += `
              <div class="agent-meta-details" style="color: var(--text-secondary); margin-top: 4px; line-height: 1.4; font-style: italic; border-left: 2px dashed rgba(255,255,255,0.2); padding-left: 6px; padding-top: 2px; padding-bottom: 2px; font-size: 0.72rem; font-weight: normal;">
                ${escapeHtml(lineupSummary)}
              </div>
            `;
          } else if (lineupImpact) {
            const parts = [];
            const home = lineupImpact.home || {};
            const away = lineupImpact.away || {};
            
            if (home.starterCount !== undefined || away.starterCount !== undefined) {
              parts.push(`Titulares: ${home.starterCount ?? '–'} vs ${away.starterCount ?? '–'}`);
            }
            
            if (home.fidelity !== undefined || away.fidelity !== undefined) {
              let fHomeVal = home.fidelity;
              if (fHomeVal !== undefined && fHomeVal !== null) {
                fHomeVal = fHomeVal <= 1 ? (fHomeVal * 100).toFixed(0) + '%' : fHomeVal + '%';
              } else {
                fHomeVal = '–';
              }
              let fAwayVal = away.fidelity;
              if (fAwayVal !== undefined && fAwayVal !== null) {
                fAwayVal = fAwayVal <= 1 ? (fAwayVal * 100).toFixed(0) + '%' : fAwayVal + '%';
              } else {
                fAwayVal = '–';
              }
              parts.push(`Fidelidad: ${fHomeVal} vs ${fAwayVal}`);
            }
            
            const homeMissing = home.missingStars || [];
            const awayMissing = away.missingStars || [];
            if (homeMissing.length > 0 || awayMissing.length > 0) {
              const mHome = homeMissing.map(p => p.name || p.displayName || p.id || 'Jugador').join(', ');
              const mAway = awayMissing.map(p => p.name || p.displayName || p.id || 'Jugador').join(', ');
              let missingText = 'Estrellas ausentes: ';
              if (mHome) missingText += `Local: ${mHome}`;
              if (mAway) {
                if (mHome) missingText += ' | ';
                missingText += `Visitante: ${mAway}`;
              }
              parts.push(missingText);
            }
            
            if (lineupImpact.absenceSignals && lineupImpact.absenceSignals.length > 0) {
              const absText = lineupImpact.absenceSignals.map(s => {
                if (typeof s === 'object' && s !== null) {
                  return s.player || s.headline || s.description || s.source || 'Baja detectada';
                }
                return s;
              }).join(', ');
              parts.push(`Bajas: ${absText}`);
            }
            
            if (lineupImpact.scoreAdjustments) {
              let adjText = '';
              if (typeof lineupImpact.scoreAdjustments === 'object') {
                const adjParts = [];
                for (const [k, v] of Object.entries(lineupImpact.scoreAdjustments)) {
                  const label = k.includes('home') ? 'Local' : (k.includes('away') ? 'Visitante' : k);
                  const n = Number(v);
                  if (Number.isFinite(n)) {
                    const pct = (n - 1) * 100;
                    const sign = pct > 0 ? '+' : '';
                    adjParts.push(`${label} ${sign}${pct.toFixed(1)}%`);
                  } else {
                    adjParts.push(`${label} ${v}`);
                  }
                }
                adjText = adjParts.join(', ');
              } else {
                adjText = lineupImpact.scoreAdjustments;
              }
              parts.push(`Ajuste Goles: ${adjText}`);
            }
            
            if (parts.length > 0) {
              nameCellContent += `
                <details style="margin-top: 4px; font-weight: normal; font-size: 0.72rem;">
                  <summary style="cursor: pointer; color: var(--accent-color); outline: none; font-style: italic; user-select: none; display: list-item;">
                    Ver detalles de alineación
                  </summary>
                  <div class="agent-meta-details" style="color: var(--text-secondary); margin-top: 4px; line-height: 1.4; font-style: italic; border-left: 2px dashed rgba(255,255,255,0.2); padding-left: 6px; padding-top: 2px; padding-bottom: 2px;">
                    ${parts.join('<br/>')}
                  </div>
                </details>
              `;
            }
          }
        }
        
        // Agregar metadatos adicionales para live_match_state
        if (a.agent === 'live_match_state') {
          const state = a.state || (a.payload && a.payload.state) || a.payload || null;
          if (state && (state.home || state.away)) {
            const home = state.home || {};
            const away = state.away || {};
            
            const cardsA = `${home.yellowCards ?? home.yellow_cards ?? 0}A/${home.redCards ?? home.red_cards ?? 0}R`;
            const cardsB = `${away.yellowCards ?? away.yellow_cards ?? 0}A/${away.redCards ?? away.red_cards ?? 0}R`;
            
            const metaInfo = [
              `Posesión: ${home.possessionPct ?? '–'}% vs ${away.possessionPct ?? '–'}%`,
              `Tiros: ${home.totalShots ?? '–'} vs ${away.totalShots ?? '–'}`,
              `A puerta: ${home.shotsOnTarget ?? '–'} vs ${away.shotsOnTarget ?? '–'}`,
              `Corners: ${home.corners ?? '–'} vs ${away.corners ?? '–'}`,
              `Tarjetas: ${cardsA} vs ${cardsB}`
            ].join('<br/>');
            
            nameCellContent += `
              <div class="agent-meta-details" style="color: var(--text-secondary); margin-top: 4px; line-height: 1.4; font-style: italic; border-left: 2px dashed rgba(255,255,255,0.2); padding-left: 6px; padding-top: 2px; padding-bottom: 2px; font-size: 0.72rem; font-weight: normal;">
                ${metaInfo}
              </div>
            `;
          }
        }
        
        tr.innerHTML = `
          <td class="agent-name-cell">${nameCellContent}</td>
          <td class="num">${homeVal}</td>
          <td class="num">${drawVal}</td>
          <td class="num">${awayVal}</td>
          <td class="num" style="font-weight: 700;">${weightVal}</td>
          <td>
            <span class="agent-badge-status agent-badge-status--${displayStatus}">
              ${statusMap[displayStatus] || displayStatus}
            </span>
          </td>
        `;
        agentsBody.appendChild(tr);
      });
    }
    
    // Mostrar contenido principal
    loader.style.display = 'none';
    content.style.display = 'block';
    
  } catch (e) {
    showModalError('Error de conexión', 'No se pudieron recuperar los pronósticos del servidor.');
  }
}

function showModalError(title, desc) {
  document.getElementById('modal-loader').style.display = 'none';
  document.getElementById('modal-forecast-content').style.display = 'none';
  
  const errBlock = document.getElementById('modal-error');
  document.getElementById('modal-error-title').textContent = title;
  document.getElementById('modal-error-desc').textContent = desc;
  errBlock.style.display = 'block';
}

function closeModal() {
  document.getElementById('forecast-modal').style.display = 'none';
}

// Cerrar modal al dar clic fuera del panel
window.addEventListener('click', (e) => {
  const modal = document.getElementById('forecast-modal');
  if (e.target === modal) {
    closeModal();
  }
});

// ── Ejecución manual del motor (Admin) ──
async function runForecastEngine() {
  const btn = document.getElementById('btn-run-forecast');
  const dryRun = document.getElementById('dry-run-checkbox').checked;
  const consoleContainer = document.getElementById('console-log-container');
  const consoleEl = document.getElementById('console-output');
  
  btn.disabled = true;
  btn.textContent = 'Procesando corrida...';
  consoleContainer.style.display = 'block';
  consoleEl.textContent = 'Enviando petición de ejecución manual al motor...\n';
  
  try {
    const res = await fetch(`api/run_forecast.php?dry_run=${dryRun}&t=` + Date.now(), { method: 'POST' });
    const data = await res.json();
    
    if (data.error) {
      consoleEl.textContent += `❌ Error: ${data.error}\n`;
    } else {
      consoleEl.textContent += `✅ Servidor respondió con estatus: ${data.status.toUpperCase()}\n`;
      consoleEl.textContent += `Mensaje: ${data.message}\n`;
      consoleEl.textContent += `ID Corrida: ${data.run_id} | Partidos procesados: ${data.matches_processed}\n\n`;
      consoleEl.textContent += JSON.stringify(data, null, 2);
    }
  } catch (e) {
    consoleEl.textContent += '❌ Error: Fallo de conexión con la API del motor de pronósticos.\n';
  } finally {
    btn.disabled = false;
    btn.textContent = '⚡ Ejecutar Pronósticos de Mañana';
  }
}

function closeConsole() {
  document.getElementById('console-log-container').style.display = 'none';
}

// Utilidades
function escapeHtml(str) {
  if (!str) return '';
  return str
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function renderList(value) {
  if (!value) return '';
  const isArr = Array.isArray(value);
  const items = isArr ? value : String(value).split(/,\s*/);
  if (items.length <= 1 && !isArr) {
    return escapeHtml(value);
  }
  return `<ul style="margin: 4px 0 0 16px; padding: 0; list-style-type: disc;">${items.map(item => `<li style="margin-bottom: 2px;">${escapeHtml(item)}</li>`).join('')}</ul>`;
}
