const API = window.TI_NEO_API_BASE || '../api/v1';
const REFRESH_TOKEN_KEY = 'ti_neo_refresh_token';
const REFRESH_TOKEN_KEYS = ['ti_neo_refresh_token', 'neo_portal_refresh_token', 'gs_neo_refresh_token'];
const RECALL_LOCK_MS = 8000;

const state = {
  token: localStorage.getItem('ti_neo_token') || '',
  refreshToken: REFRESH_TOKEN_KEYS.map((key) => localStorage.getItem(key) || '').find(Boolean) || '',
  user: readStoredUser(),
  window: 0,
  windowModeLabel: '',
  autoCall: false,
  current: null,
  currentId: 0,
  relatedSelected: new Set(),
  paused: localStorage.getItem('ti_neo_turnero_paused') === '1',
  pauseAfter: false,
  loadingWindow: false,
  callSubmitting: false,
  stateSubmitting: false,
  recallLockedUntil: 0,
  recallLockTimer: null,
  recallSubmitting: false,
  pollTimer: null,
  sessionWarningTimer: null,
  refreshPromise: null,
};

const $ = (id) => document.getElementById(id);

function readStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('ti_neo_user') || 'null');
  } catch (error) {
    return null;
  }
}

async function apiFetch(path, options = {}) {
  const timeoutMs = Number(options.timeoutMs || 25000);
  const controller = new AbortController();
  const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {}),
  };

  if (state.token) {
    headers.Authorization = `Bearer ${state.token}`;
    headers['X-Auth-Token'] = state.token;
  }

  const fetchOptions = { ...options };
  delete fetchOptions.timeoutMs;
  delete fetchOptions.retryingAuth;

  try {
    const response = await fetch(`${API}/${path}`, {
      ...fetchOptions,
      signal: options.signal || controller.signal,
      headers,
    });
    const payload = await response.json().catch(() => ({}));

    if (response.status === 401) {
      if (!options.retryingAuth && await refreshSession()) {
        return apiFetch(path, { ...options, retryingAuth: true });
      }
      clearSession();
      throw new Error('Sesion vencida. Inicia sesion en Tickets HSJ.');
    }

    if (!response.ok || payload.success === false) {
      throw new Error(payload.message || 'No se pudo completar la operacion.');
    }

    return payload.data || {};
  } catch (error) {
    if (error.name === 'AbortError') {
      throw new Error('El servidor demoro demasiado en responder. Actualiza el mini turnero.');
    }

    throw error;
  } finally {
    window.clearTimeout(timeout);
  }
}

async function refreshSession() {
  if (!state.refreshToken) {
    syncStoredSession();
  }

  if (!state.refreshToken) {
    return false;
  }

  if (state.refreshPromise) {
    return state.refreshPromise;
  }

  state.refreshPromise = fetch(`${API}/refresh-token.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refresh_token: state.refreshToken }),
  })
    .then(async (response) => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.success === false || !payload.data?.access_token) {
        return false;
      }

      state.token = payload.data.access_token;
      state.user = payload.data.user || state.user;
      state.refreshToken = payload.data.refresh_token || state.refreshToken;
      localStorage.setItem('ti_neo_token', state.token);
      localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
      if (state.refreshToken) {
        localStorage.setItem(REFRESH_TOKEN_KEY, state.refreshToken);
      }
      scheduleSessionWarning();
      return true;
    })
    .catch(() => false)
    .finally(() => {
      state.refreshPromise = null;
    });

  return state.refreshPromise;
}

function decodeJwtPayload(sessionToken) {
  try {
    const payload = sessionToken.split('.')[1] || '';
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    return JSON.parse(decodeURIComponent(escape(atob(padded))));
  } catch (error) {
    return null;
  }
}

function sessionSecondsLeft() {
  const payload = decodeJwtPayload(state.token || '');
  return Number(payload?.exp || 0) - Math.floor(Date.now() / 1000);
}

function scheduleSessionWarning() {
  window.clearTimeout(state.sessionWarningTimer);
  hideSessionWarning();
  const secondsLeft = sessionSecondsLeft();
  if (secondsLeft <= 0) {
    return;
  }
  state.sessionWarningTimer = window.setTimeout(showSessionWarning, Math.max(0, (secondsLeft - 300) * 1000));
}

function sessionWarningElement() {
  let element = document.getElementById('sessionWarning');
  if (element) {
    return element;
  }

  element = document.createElement('aside');
  element.id = 'sessionWarning';
  element.className = 'session-warning hidden';
  element.innerHTML = `
    <strong>Sesion por vencer</strong>
    <button type="button" id="sessionRenewButton">Renovar</button>
  `;
  document.body.prepend(element);
  document.getElementById('sessionRenewButton').addEventListener('click', async () => {
    element.classList.add('is-loading');
    const renewed = await refreshSession();
    element.classList.remove('is-loading');
    if (renewed) {
      hideSessionWarning();
      scheduleSessionWarning();
      return;
    }
    clearSession();
    render();
  });
  return element;
}

function showSessionWarning() {
  if (!state.token) {
    return;
  }
  sessionWarningElement().classList.remove('hidden');
}

function hideSessionWarning() {
  const element = document.getElementById('sessionWarning');
  if (element) {
    element.classList.add('hidden');
  }
}

function syncStoredSession() {
  state.token = localStorage.getItem('ti_neo_token') || state.token || '';
  state.refreshToken = REFRESH_TOKEN_KEYS.map((key) => localStorage.getItem(key) || '').find(Boolean) || state.refreshToken || '';
  state.user = readStoredUser() || state.user;
  scheduleSessionWarning();
}

function clearSession() {
  state.token = '';
  state.refreshToken = '';
  state.user = null;
  localStorage.removeItem('ti_neo_token');
  localStorage.removeItem(REFRESH_TOKEN_KEY);
  localStorage.removeItem('ti_neo_user');
  hideSessionWarning();
}

function todayInputValue() {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
}

function windowMode(windowNumber = state.window) {
  if ([1, 2].includes(Number(windowNumber))) {
    return 'Preferencial';
  }
  if ([3, 4, 5, 6].includes(Number(windowNumber))) {
    return 'Normal proporcional';
  }
  if ([7, 8, 9].includes(Number(windowNumber))) {
    return 'Manual';
  }
  return 'Sin ventanilla';
}

function stateLabel(value) {
  const labels = {
    en_cola: 'En cola',
    llamando: 'Llamando',
    atendiendo: 'Atendiendo',
    atendido: 'Atendido',
    ausente: 'Ausente',
  };

  return labels[value] || value || 'Disponible';
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));
}

function renderRelatedTickets(current) {
  const container = $('operatorRelatedTickets');
  if (!container) {
    return;
  }

  const related = Array.isArray(current?.tickets_relacionados)
    ? current.tickets_relacionados
    : [];

  container.hidden = related.length === 0;
  if (related.length === 0) {
    container.innerHTML = '';
    return;
  }

  const items = related.slice(0, 6).map((ticket) => {
    const stateText = stateLabel(ticket.cola_estado || ticket.estado);
    const id = Number(ticket.id || 0);
    const checked = state.relatedSelected.has(id);
    const documentText = `${ticket.tipo_documento || current?.tipo_documento || 'DNI'} ${ticket.numero_documento || current?.numero_documento || '-'}`;
    return `
      <li class="${checked ? 'is-consumed' : ''}">
        <label class="related-check">
          <input type="checkbox" data-related-id="${id}" ${checked ? 'checked' : ''}>
          <span>
            <strong>${escapeHtml(ticket.codigo || '-')} <small>${escapeHtml(documentText)}</small></strong>
            <em>${escapeHtml(ticket.especialidad || '-')}</em>
          </span>
        </label>
        <small>${checked ? '&#10003; Confirmado para cerrar' : escapeHtml(stateText)}</small>
      </li>
    `;
  }).join('');

  const total = Number(current?.tickets_relacionados_total || related.length);
  const extra = total > related.length ? `<p>+${total - related.length} mas</p>` : '';
  container.innerHTML = `
    <b>Tickets pendientes del documento</b>
    <ul>${items}</ul>
    ${extra}
  `;
}

function setCurrentTicket(ticket) {
  const nextId = Number(ticket?.id || 0);
  if (nextId !== state.currentId) {
    state.relatedSelected = new Set();
    state.currentId = nextId;
  }
  state.current = ticket || null;
  const validRelatedIds = new Set((state.current?.tickets_relacionados || []).map((item) => Number(item.id || 0)));
  state.relatedSelected = new Set([...state.relatedSelected].filter((id) => validRelatedIds.has(id)));
}

function render() {
  const hasSession = Boolean(state.token && state.user);
  const hasWindow = state.window >= 1 && state.window <= 9;
  const current = state.current;
  const hasCurrent = Boolean(current?.id);
  const isCalling = current?.cola_estado === 'llamando';
  const isServing = current?.cola_estado === 'atendiendo';

  $('operatorSession').textContent = hasSession
    ? (state.user?.nombre || state.user?.email || 'Sesion activa')
    : 'Inicia sesion en la pagina principal';
  $('operatorAvatar').textContent = initials(state.user);
  $('operatorRole').textContent = hasWindow
    ? `${state.windowModeLabel || windowMode()} - Ventanilla ${state.window}`
    : 'Sin ventanilla asignada';
  $('operatorWindowTitle').textContent = hasWindow ? `Ventanilla ${state.window}` : 'Sin asignar';
  $('operatorWindowMode').textContent = hasWindow
    ? `${state.windowModeLabel || windowMode()} - ${state.paused ? 'Pausado' : hasCurrent ? stateLabel(current.cola_estado) : 'Disponible'}`
    : 'Solicita asignacion al administrador';
  $('operatorWindowPicker').hidden = true;
  $('operatorCurrentCode').textContent = current?.codigo || '---';
  $('operatorCurrentDetail').textContent = current
    ? `${current.tipo_documento || 'DNI'} ${current.numero_documento || '-'} - ${current.especialidad || '-'} - ${current.prioridad_atencion || current.tipo_ticket} - Ventanilla ${current.ventanilla_numero || state.window}`
    : hasWindow ? 'Sin turno asignado' : 'Primero selecciona ventanilla';
  renderRelatedTickets(current);

  $('operatorCallButton').hidden = !hasSession || !hasWindow || state.paused || hasCurrent;
  $('operatorCallButton').disabled = state.callSubmitting || state.stateSubmitting;
  $('operatorCallButton').textContent = [7, 8, 9].includes(state.window) ? 'Jalar ticket' : 'Llamar siguiente';
  $('operatorAttendButton').hidden = !hasCurrent || !isCalling;
  $('operatorAttendButton').disabled = state.stateSubmitting || state.callSubmitting;
  const recallButton = operatorRecallButton;
  const recallSeconds = recallSecondsLeft();
  recallButton.hidden = !hasCurrent || !isCalling;
  recallButton.disabled = state.recallSubmitting || state.stateSubmitting || state.callSubmitting || recallSeconds > 0;
  recallButton.classList.toggle('recall-waiting', recallSeconds > 0);
  recallButton.textContent = recallButtonText(recallSeconds, state.recallSubmitting);
  $('operatorFinishButton').hidden = !hasCurrent || !isServing;
  $('operatorFinishButton').disabled = state.stateSubmitting || state.callSubmitting;
  $('operatorAbsentButton').hidden = !hasCurrent || (!isCalling && !isServing);
  $('operatorAbsentButton').disabled = state.stateSubmitting || state.callSubmitting;
  $('operatorPauseAfterButton').hidden = !hasCurrent || !isServing;
  $('operatorPauseAfterButton').textContent = state.pauseAfter ? 'Pausa programada' : 'Pausar al finalizar';
  $('operatorResumeButton').hidden = !hasWindow || !state.paused;
  $('operatorChangeWindowButton').hidden = !hasWindow || hasCurrent;
}

function initials(user) {
  const source = String(user?.nombre || user?.email || 'TU').trim();
  const parts = source.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
  }
  return source.slice(0, 2).toUpperCase() || 'TU';
}

function notifyQueueChanged() {
  localStorage.setItem('ti_neo_queue_changed_at', String(Date.now()));
}

function shouldAutoCall() {
  return state.autoCall
    && !state.paused
    && !state.current?.id;
}

function setWindow(windowNumber) {
  state.window = Number(windowNumber);
  state.windowModeLabel = windowMode();
  state.autoCall = state.window >= 1 && state.window <= 6;
  setCurrentTicket(null);
  state.paused = false;
  state.pauseAfter = false;
  localStorage.setItem('ti_neo_turnero_window', String(state.window));
  localStorage.removeItem('ti_neo_turnero_paused');
  render();
}

async function loadAssignedWindow(options = {}) {
  const quiet = options.quiet === true;
  if (!state.token) {
    render();
    return;
  }

  if (state.loadingWindow) {
    return;
  }

  state.loadingWindow = true;

  try {
    const data = await apiFetch('tickets.php?action=mi-ventanilla');
    state.window = Number(data.ventanilla?.numero || 0);
    if (state.window) {
      state.windowModeLabel = data.ventanilla?.modo_cola_label || data.ventanilla?.modo || windowMode();
      state.autoCall = Boolean(data.ventanilla?.automatico);
      setCurrentTicket(data.ventanilla?.ticket || null);
      localStorage.setItem('ti_neo_turnero_window', String(state.window));
      render();
      if (shouldAutoCall()) {
        await callNext({ quiet: true });
      }
      return;
    }

    localStorage.removeItem('ti_neo_turnero_window');
    if (!quiet) {
      $('operatorMessage').textContent = 'No tienes ventanilla asignada. Solicitala al administrador.';
    }
    render();
  } catch (error) {
    if (!quiet) {
      $('operatorMessage').textContent = error.message;
    }
    render();
  } finally {
    state.loadingWindow = false;
  }
}

async function callNext(options = {}) {
  const quiet = options.quiet === true;
  if (state.callSubmitting || state.stateSubmitting) {
    return;
  }

  if (state.window < 1) {
    if (!quiet) {
      $('operatorMessage').textContent = 'Selecciona ventanilla.';
    }
    return;
  }

  if (!quiet) {
    $('operatorMessage').textContent = '';
  }
  state.callSubmitting = true;
  render();

  try {
    const data = await apiFetch('tickets.php?action=llamar-siguiente', {
      method: 'POST',
      body: JSON.stringify({ fecha: todayInputValue(), ventanilla: state.window }),
    });
    setCurrentTicket(data.ticket || null);
    state.paused = false;
    localStorage.removeItem('ti_neo_turnero_paused');
    notifyQueueChanged();
    if (!state.current && !quiet) {
      $('operatorMessage').textContent = 'No hay tickets en cola para llamar.';
    }
    render();
  } catch (error) {
    if (!quiet) {
      $('operatorMessage').textContent = error.message;
    }
  } finally {
    state.callSubmitting = false;
    render();
  }
}

async function setCurrentState(nextState) {
  if (!state.current?.id || state.stateSubmitting || state.callSubmitting) {
    return;
  }

  $('operatorMessage').textContent = '';
  state.stateSubmitting = true;
  render();

  try {
    const data = await apiFetch('tickets.php?action=actualizar-cola', {
      method: 'POST',
      body: JSON.stringify({
        id: state.current.id,
        estado: nextState,
        relacionados_atendidos: [...state.relatedSelected],
      }),
    });
    setCurrentTicket(data.ticket || { ...state.current, cola_estado: nextState });

    if (['atendido', 'ausente'].includes(nextState)) {
      setCurrentTicket(null);
      if (state.pauseAfter) {
        state.paused = true;
        state.pauseAfter = false;
        localStorage.setItem('ti_neo_turnero_paused', '1');
      } else if (![7, 8, 9].includes(Number(state.window))) {
        render();
        await callNext();
        return;
      }
    }

    render();
    notifyQueueChanged();
  } catch (error) {
    $('operatorMessage').textContent = error.message;
  } finally {
    state.stateSubmitting = false;
    render();
  }
}

async function recallCurrent() {
  if (!state.current?.id || state.window < 1) {
    return;
  }
  const secondsLeft = recallSecondsLeft();
  if (secondsLeft > 0) {
    $('operatorMessage').textContent = `Ya se envio el llamado. Espera ${secondsLeft}s para volver a llamar.`;
    render();
    return;
  }
  if (state.recallSubmitting || state.stateSubmitting || state.callSubmitting) {
    $('operatorMessage').textContent = 'Espera a que termine la accion actual.';
    return;
  }

  $('operatorMessage').textContent = '';
  state.recallSubmitting = true;
  lockRecallButton();

  try {
    const data = await apiFetch('tickets.php?action=rellamar', {
      method: 'POST',
      body: JSON.stringify({ id: state.current.id, ventanilla: state.window }),
    });
    setCurrentTicket(data.ticket || { ...state.current, cola_estado: 'llamando' });
    render();
    notifyQueueChanged();
  } catch (error) {
    $('operatorMessage').textContent = error.message;
  } finally {
    state.recallSubmitting = false;
    render();
  }
}

function recallSecondsLeft() {
  return Math.max(0, Math.ceil((state.recallLockedUntil - Date.now()) / 1000));
}

function recallButtonText(secondsLeft, submitting) {
  if (submitting) {
    return 'Re-llamando...';
  }

  return secondsLeft > 0 ? `Espera ${secondsLeft}s` : 'Re-llamar';
}

function lockRecallButton() {
  state.recallLockedUntil = Date.now() + RECALL_LOCK_MS;
  if (state.recallLockTimer) {
    window.clearInterval(state.recallLockTimer);
  }
  render();
  state.recallLockTimer = window.setInterval(function () {
    if (Date.now() >= state.recallLockedUntil) {
      state.recallLockedUntil = 0;
      window.clearInterval(state.recallLockTimer);
      state.recallLockTimer = null;
    }
    render();
  }, 500);
}

function startPolling() {
  stopPolling();
  state.pollTimer = window.setInterval(() => {
    if (!document.hidden) {
      loadAssignedWindow({ quiet: true }).catch(() => {});
    }
  }, 1800);
}

function stopPolling() {
  if (state.pollTimer) {
    window.clearInterval(state.pollTimer);
    state.pollTimer = null;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  $('operatorWindowPicker').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-window]');
    if (button) {
      setWindow(button.dataset.window);
    }
  });
  $('operatorRelatedTickets')?.addEventListener('change', (event) => {
    const input = event.target.closest('input[data-related-id]');
    if (!input) {
      return;
    }
    const id = Number(input.dataset.relatedId || 0);
    if (id < 1) {
      return;
    }
    if (input.checked) {
      state.relatedSelected.add(id);
    } else {
      state.relatedSelected.delete(id);
    }
    render();
  });
  $('operatorChangeWindowButton').addEventListener('click', () => {
    $('operatorMessage').textContent = 'El cambio de ventanilla lo realiza el administrador.';
  });
  $('operatorRefreshButton').addEventListener('click', () => loadAssignedWindow());
  $('operatorCallButton').addEventListener('click', callNext);
  $('operatorAttendButton').addEventListener('click', () => setCurrentState('atendiendo'));
  $('operatorRecallButton').addEventListener('click', recallCurrent);
  $('operatorFinishButton').addEventListener('click', () => setCurrentState('atendido'));
  $('operatorAbsentButton').addEventListener('click', () => setCurrentState('ausente'));
  $('operatorPauseAfterButton').addEventListener('click', () => {
    state.pauseAfter = true;
    render();
  });
  $('operatorResumeButton').addEventListener('click', () => {
    state.paused = false;
    localStorage.removeItem('ti_neo_turnero_paused');
    render();
  });

  render();
  loadAssignedWindow();
  scheduleSessionWarning();
  startPolling();
  window.addEventListener('storage', (event) => {
    if (event.key === 'ti_neo_queue_changed_at') {
      loadAssignedWindow({ quiet: true }).catch(() => {});
      return;
    }

    if (['ti_neo_token', 'ti_neo_refresh_token', 'neo_portal_refresh_token', 'gs_neo_refresh_token', 'ti_neo_user'].includes(event.key || '')) {
      syncStoredSession();
      render();
      loadAssignedWindow({ quiet: true }).catch(() => {});
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      loadAssignedWindow({ quiet: true }).catch(() => {});
    }
  });
});



