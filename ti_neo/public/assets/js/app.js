const API = window.TI_NEO_API_BASE || '../api/v1';
const PRESET_AVATARS = Array.isArray(window.TI_NEO_PRESET_AVATARS) ? window.TI_NEO_PRESET_AVATARS : [];
const PRESET_BACKGROUNDS = Array.isArray(window.TI_NEO_PRESET_BACKGROUNDS) ? window.TI_NEO_PRESET_BACKGROUNDS : [];
const TURNERO_RECALL_LOCK_MS = 8000;
const PORTAL_TOKEN_KEY = 'neo_portal_token';
const PORTAL_TOKEN_KEYS = ['neo_portal_token', 'gs_neo_token'];
const REFRESH_TOKEN_KEY = 'ti_neo_refresh_token';
const REFRESH_TOKEN_KEYS = ['ti_neo_refresh_token', 'neo_portal_refresh_token', 'gs_neo_refresh_token'];
const TEST_DNI_NUMBERS = ['72402757'];
const TICKET_TYPE_BY_KEY = {
  1: 'Preferencial',
  2: 'Normal',
  3: 'Procedimiento',
};
const VIEW_PERMISSION_ALIASES = {};
let documentAdvanceTimer = null;

const DEFAULT_PRINT_LAYOUT = {
  papel_mm: 80,
  contenido_mm: 80,
  padding_mm: 5,
  logo_mm: 16,
  header_px: 15,
  codigo_px: 54,
  documento_px: 19,
  especialidad_px: 19,
  fecha_px: 31,
  codigo_margen_mm: 0,
  linea_margen_mm: 0,
  fecha_margen_mm: 0,
  offset_x_mm: 0,
  offset_y_mm: 0,
  hospital_x_mm: 0,
  hospital_y_mm: 0,
  logo_left_x_mm: 0,
  logo_left_y_mm: 0,
  logo_right_x_mm: 0,
  logo_right_y_mm: 0,
  code_x_mm: 0,
  code_y_mm: 0,
  doc_x_mm: 0,
  doc_y_mm: 0,
  specialty_x_mm: 0,
  specialty_y_mm: 0,
  date_x_mm: 0,
  date_y_mm: 0,
};

const DEFAULT_TICKET_FORMAT = {
  titulo: 'HOSPITAL SAN\nJOSE DEL CALLAO',
  logo_izquierdo_url: 'https://thumbs4.imagebam.com/a4/33/1a/ME1CROZK_t.png',
  logo_derecho_url: 'https://thumbs4.imagebam.com/bd/2a/44/ME1CROVG_t.png',
};

function readStoredUser() {
  try {
    return JSON.parse(localStorage.getItem('ti_neo_user') || 'null');
  } catch (error) {
    localStorage.removeItem('ti_neo_user');
    localStorage.removeItem('ti_neo_token');
    return null;
  }
}

function decodeJwtPayload(token) {
  try {
    const payload = token.split('.')[1] || '';
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    return JSON.parse(decodeURIComponent(escape(atob(padded))));
  } catch (error) {
    return null;
  }
}

function applySessionToken(sessionToken) {
  const payload = decodeJwtPayload(sessionToken);
  const user = payload?.user;
  if (!user || Number(user.id || 0) <= 0 || Number(payload?.exp || 0) <= Math.floor(Date.now() / 1000)) {
    return false;
  }

  state.token = sessionToken;
  state.user = {
    id: Number(user.id || 0),
    dni: user.dni || '',
    email: user.email || '',
    nombre: user.nombre || `${user.nombres || ''} ${user.apellidos || ''}`.trim() || user.email || user.dni || 'Usuario',
    nombres: user.nombres || '',
    apellidos: user.apellidos || '',
    rol_id: Number(user.rol_id || 0),
    rol: user.rol || user.rol_nombre || '',
    perfil: user.perfil || {},
  };
  localStorage.setItem('ti_neo_token', state.token);
  localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
  return true;
}

function forgetRejectedToken(rejectedToken = '') {
  if (!rejectedToken) {
    return;
  }

  ['ti_neo_token', ...PORTAL_TOKEN_KEYS].forEach((key) => {
    if (localStorage.getItem(key) === rejectedToken) {
      localStorage.removeItem(key);
    }
  });

  if (state.token === rejectedToken) {
    state.token = '';
    state.user = null;
    localStorage.removeItem('ti_neo_user');
  }
}

function portalTokenCandidates(urlToken = '') {
  const candidates = [
    urlToken,
    ...PORTAL_TOKEN_KEYS.map((key) => localStorage.getItem(key) || ''),
    localStorage.getItem(PORTAL_TOKEN_KEY) || '',
    state.token || '',
  ].filter(Boolean);

  return [...new Set(candidates)];
}

function consumePortalSession(excludeToken = '') {
  const params = new URLSearchParams(window.location.search);
  const hasSsoParam = params.has('sso_token');
  const urlToken = params.get('sso_token') || '';
  let consumed = false;

  for (const candidate of portalTokenCandidates(urlToken)) {
    if (candidate === excludeToken) {
      continue;
    }
    if (applySessionToken(candidate)) {
      consumed = true;
      break;
    }
  }

  if (hasSsoParam) {
    params.delete('sso_token');
    const cleanQuery = params.toString();
    window.history.replaceState({}, document.title, `${window.location.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${window.location.hash}`);
  }

  return consumed;
}

const state = {
  token: localStorage.getItem('ti_neo_token') || '',
  refreshToken: REFRESH_TOKEN_KEYS.map((key) => localStorage.getItem(key) || '').find(Boolean) || '',
  user: readStoredUser(),
  summary: null,
  printSettings: { automatica: 'SI', retraso_ms: 120, layout: { ...DEFAULT_PRINT_LAYOUT } },
  ticketFormat: { ...DEFAULT_TICKET_FORMAT },
  specialties: [],
  citasDisponibles: new Map(),
  citasDisponiblesMeta: {},
  availabilityTab: '',
  emitMode: localStorage.getItem('ti_neo_emit_mode') === 'solo_impresion' ? 'solo_impresion' : 'normal',
  documentTypeManual: false,
  specialtyShortcutIds: [],
  frequentSpecialties: [],
  lastTicket: null,
  queue: { activos: [], disponibles: [], resumen: {} },
  windowsAdmin: { ventanillas: [], usuarios: [] },
  screenDevices: [],
  screenTicker: '',
  screenDeviceKeys: {},
  screenDeviceEditingId: null,
  turnero: {
    window: Number(localStorage.getItem('ti_neo_turnero_window') || 0),
    current: null,
    paused: localStorage.getItem('ti_neo_turnero_paused') === '1',
    pauseAfter: false,
    callSubmitting: false,
    stateSubmitting: false,
    recallLockedUntil: 0,
    recallLockTimer: null,
    recallSubmitting: false,
  },
  screenTimer: null,
  screenLoading: false,
  screenEtag: '',
  screenLastCode: '',
  screenLastState: '',
  screenLastUpdatedAt: '',
  screenLastChangeAt: 0,
  queueTimer: null,
  queueLoading: false,
  queueRefreshPending: false,
  queueLastChangeAt: 0,
  windowsAdminLoading: false,
  historyPage: 1,
  reportPage: 1,
  profile: null,
  permissions: { views: {}, actions: {} },
  sessionWarningTimer: null,
  refreshPromise: null,
};

const rangeState = {
  monthCursor: new Date(),
  calendar: {},
  selectedDate: new Date().toISOString().slice(0, 10),
  batchInProgress: false,
  cancelBatch: false,
};

const $ = (id) => document.getElementById(id);

function apiFetch(path, options = {}) {
  const timeoutMs = Number(options.timeoutMs || 25000);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);
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

  return fetch(`${API}/${path}`, {
    ...fetchOptions,
    signal: options.signal || controller.signal,
    headers,
  }).then(async (response) => {
    const payload = await response.json().catch(() => ({}));
    if (response.status === 401) {
      const rejectedToken = state.token;
      if (!options.retryingAuth && await refreshSession()) {
        return apiFetch(path, { ...options, retryingAuth: true });
      }
      forgetRejectedToken(rejectedToken);
      if (!options.retryingAuth && consumePortalSession(rejectedToken)) {
        return apiFetch(path, { ...options, retryingAuth: true });
      }
      stopScreenRefresh();
      stopQueueRefresh();
      logout();
      throw new Error('Sesion vencida. Ingresa nuevamente.');
    }

    if (!response.ok || payload.success === false) {
      throw new Error(payload.message || 'No se pudo completar la operacion.');
    }

    return payload.data || {};
  }).catch((error) => {
    if (error.name === 'AbortError') {
      throw new Error('El servidor demoro demasiado en responder. Revisa si la operacion se completo y actualiza.');
    }

    throw error;
  }).finally(() => {
    clearTimeout(timeout);
  });
}

async function refreshSession() {
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
      localStorage.setItem('ti_neo_token', state.token);
      localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
      scheduleSessionWarning();
      return true;
    })
    .catch(() => false)
    .finally(() => {
      state.refreshPromise = null;
    });

  return state.refreshPromise;
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
    <span>Renueva para continuar trabajando sin volver a ingresar.</span>
    <button type="button" id="sessionRenewButton">Renovar sesion</button>
  `;
  document.body.appendChild(element);
  document.getElementById('sessionRenewButton').addEventListener('click', async () => {
    element.classList.add('is-loading');
    const renewed = await refreshSession();
    element.classList.remove('is-loading');
    if (renewed) {
      hideSessionWarning();
      scheduleSessionWarning();
      return;
    }
    logout();
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

function showLogin() {
  stopScreenRefresh();
  stopQueueRefresh();
  hideSessionWarning();
  $('loginScreen').classList.remove('hidden');
  $('loginScreen').classList.remove('login-pending');
  $('appShell').classList.add('hidden');
}

function showApp() {
  $('loginScreen').classList.add('hidden');
  $('loginScreen').classList.remove('login-pending');
  $('appShell').classList.remove('hidden');
  applyGlobalProfile();
  refreshGlobalProfile().catch(() => {});
  scheduleSessionWarning();
  focusDocumentScanner();
}

function currentProfile() {
  return state.user?.perfil || {};
}

function applyGlobalProfile() {
  const profile = currentProfile();
  const loginName = displayLogin(state.user);
  const displayName = profile.nombre_visible || state.user?.nombre || loginName || 'Usuario';
  const accent = normalizeHexColor(profile.color_principal || profile.color_acento);
  const initials = profile.iniciales || profileInitials(displayName);
  const avatarUrl = String(profile.avatar_url || '').trim();

  document.documentElement.style.setProperty('--accent', accent);
  document.documentElement.style.setProperty('--personal-accent', accent);
  applyProfileBackground('', 0);
  $('currentUser').textContent = loginName || displayName;
  $('profileName').textContent = displayName;
  $('profileInitials').textContent = '...';
  $('profileInitials').style.backgroundImage = '';
  $('profileInitials').classList.remove('with-image');
  updateTopbarProfileSummary(displayName, profile.cargo_area || 'Equipo de atencion', accent, avatarUrl, initials);
}

function displayLogin(user) {
  const email = String(user?.email || '').trim();
  if (email !== '' && !email.toLowerCase().endsWith('@dni.local')) {
    return email;
  }

  return String(user?.dni || '').trim();
}

function profileInitials(value) {
  const parts = String(value || 'Usuario')
    .replace(/@.*/, '')
    .split(/\s+|[._-]+/)
    .filter(Boolean);
  return (parts[0]?.[0] || 'U') + (parts[1]?.[0] || '');
}

function setLoading(visible, text = 'Generando ticket...') {
  $('loadingOverlay').classList.toggle('hidden', !visible);
  $('loadingOverlay').querySelector('small').textContent = text;
}

function goEmitStep(step) {
  const stepNumber = Number(step);

  document.querySelectorAll('[data-step-panel]').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.stepPanel === String(stepNumber));
  });

  document.querySelectorAll('[data-step-dot]').forEach((dot) => {
    dot.classList.toggle('active', dot.dataset.stepDot === String(stepNumber));
    dot.classList.toggle('done', Number(dot.dataset.stepDot) < stepNumber);
  });

  if (stepNumber === 1) {
    syncDocumentTypeFromNumber();
    focusDocumentScanner();
  }

  if (stepNumber === 2) {
    setTimeout(() => $('specialtySearch').focus(), 50);
  }

  if (stepNumber === 3) {
    syncDocumentTypeFromNumber();
    updateSelectedSummary();
    const firstButton = document.querySelector('.ticket-button:not([disabled])');
    firstButton?.focus();
  }
}

function ticketTypeBadgeClass(type) {
  const normalized = String(type || '').toLowerCase();
  return normalized === 'emergencia' ? 'procedimiento' : normalized;
}

function ticketTypeIcon(type) {
  const normalized = ticketTypeBadgeClass(type);
  const icon = normalized === 'preferencial'
    ? 'ticket-preferencial.svg'
    : normalized === 'procedimiento'
      ? 'ticket-procedimiento.svg'
      : 'ticket-normal.svg';

  return `
    <img class="ticket-svg-icon" src="assets/icons/${icon}" alt="Ticket">
    <span class="ticket-icon-fallback">TICKET</span>
  `;
}

function isTicketTypeStepActive() {
  const stepPanel = document.querySelector('[data-step-panel="3"]');
  const emissionPanel = document.querySelector('[data-view-panel="emision"]');
  return Boolean(
    stepPanel?.classList.contains('active')
    && emissionPanel?.classList.contains('active')
    && !$('appShell').classList.contains('hidden')
  );
}

function handleTicketTypeShortcut(event) {
  if (!isTicketTypeStepActive() || event.ctrlKey || event.altKey || event.metaKey) {
    return;
  }

  if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
    return;
  }

  const type = TICKET_TYPE_BY_KEY[event.key];
  if (!type) {
    return;
  }

  const button = document.querySelector(`.ticket-button[data-type="${type}"]:not([disabled])`);
  if (!button) {
    return;
  }

  event.preventDefault();
  button.click();
}

function focusDocumentScanner(delay = 50) {
  window.setTimeout(() => {
    const appVisible = !$('appShell').classList.contains('hidden');
    const emissionVisible = document.querySelector('[data-view-panel="emision"]')?.classList.contains('active');
    const stepOneVisible = document.querySelector('[data-step-panel="1"]')?.classList.contains('active');

    if (appVisible && emissionVisible && stepOneVisible) {
      $('documentNumber').focus();
      $('documentNumber').select();
    }
  }, delay);
}

function setDocType(type, focusField = true, manual = true) {
  $('documentType').value = type;
  state.documentTypeManual = manual;
  document.querySelectorAll('.doc-type-button').forEach((button) => {
    button.classList.toggle('active', button.dataset.docType === type);
  });

  if (focusField) {
    focusDocumentScanner(0);
  }
}

function detectDocumentType(number) {
  const normalized = String(number || '').trim().toUpperCase();

  if (/^\d{8}$/.test(normalized) || isTestDni(normalized)) {
    return 'DNI';
  }

  if (/^[A-Z][A-Z0-9]{5,14}$/.test(normalized) && normalized.length > 12) {
    return 'PASAPORTE';
  }

  if (normalized.length > 8) {
    return 'CE';
  }

  return $('documentType').value || 'DNI';
}

function syncDocumentTypeFromNumber() {
  if (state.documentTypeManual) {
    return;
  }

  const detectedType = detectDocumentType($('documentNumber').value);
  if ($('documentType').value !== detectedType) {
    setDocType(detectedType, false, false);
  }
}

function isTestDni(number) {
  return TEST_DNI_NUMBERS.includes(number);
}

function canAutoAdvanceDocument() {
  syncDocumentTypeFromNumber();

  const type = $('documentType').value;
  const number = $('documentNumber').value.trim();

  if (state.documentTypeManual && type !== 'DNI') {
    return false;
  }

  if (type === 'DNI') {
    return isTestDni(number) || /^\d{8}$/.test(number);
  }

  if (type === 'CE') {
    return /^[A-Za-z0-9]{6,12}$/.test(number);
  }

  return type === 'PASAPORTE' && /^[A-Za-z0-9]{6,15}$/.test(number);
}

function validateDocumentStep() {
  syncDocumentTypeFromNumber();

  const type = $('documentType').value;
  const number = $('documentNumber').value.trim().toUpperCase();

  if (!number) {
    $('emitMessage').textContent = 'Escanea o escribe el documento.';
    $('documentNumber').focus();
    return false;
  }

  if (type === 'DNI' && !isTestDni(number) && !/^\d{8}$/.test(number)) {
    $('emitMessage').textContent = 'El DNI debe tener 8 digitos.';
    $('documentNumber').focus();
    return false;
  }

  if (type === 'CE' && !/^[A-Za-z0-9]{6,12}$/.test(number)) {
    $('emitMessage').textContent = 'El carne debe tener entre 6 y 12 caracteres.';
    $('documentNumber').focus();
    return false;
  }

  if (type === 'PASAPORTE' && !/^[A-Za-z0-9]{6,15}$/.test(number)) {
    $('emitMessage').textContent = 'El pasaporte debe tener entre 6 y 15 caracteres.';
    $('documentNumber').focus();
    return false;
  }

  $('emitMessage').textContent = '';
  return true;
}

function scheduleDocumentAutoAdvance() {
  clearTimeout(documentAdvanceTimer);
  syncDocumentTypeFromNumber();

  if (!canAutoAdvanceDocument()) {
    return;
  }

  const delay = $('documentType').value === 'DNI' ? 180 : 360;

  documentAdvanceTimer = setTimeout(() => {
    const activeStep = document.querySelector('[data-step-panel].active')?.dataset.stepPanel;
    if (activeStep === '1' && validateDocumentStep()) {
      goEmitStep(2);
      $('specialtySearch').focus();
    }
  }, delay);
}

function validateSpecialtyStep() {
  if (!$('specialtySelect').value) {
    $('emitMessage').textContent = 'Selecciona la especialidad.';
    $('specialtySearch').focus();
    return false;
  }

  $('emitMessage').textContent = '';
  return true;
}

function updateSelectedSummary() {
  const type = $('documentType').value;
  const documentNumber = $('documentNumber').value.trim();
  const specialty = selectedSpecialtyName();
  const modeLabel = state.emitMode === 'solo_impresion' ? ' - Solo impresion' : '';
  $('selectedSummary').textContent = `${type} ${documentNumber} - ${specialty}${modeLabel}`;
}

function setEmitMode(mode) {
  const requestedMode = mode === 'solo_impresion' ? 'solo_impresion' : 'normal';
  if (state.permissions && !canUseEmitMode(requestedMode)) {
    state.emitMode = canUseEmitMode('normal') ? 'normal' : 'solo_impresion';
  } else {
    state.emitMode = requestedMode;
  }
  localStorage.setItem('ti_neo_emit_mode', state.emitMode);
  document.querySelectorAll('[data-emit-mode]').forEach((button) => {
    button.classList.toggle('active', button.dataset.emitMode === state.emitMode);
  });
  if ($('emitModeNotice')) {
    $('emitModeNotice').hidden = state.emitMode !== 'solo_impresion';
  }
  if ($('selectedSummary')) {
    updateSelectedSummary();
  }
}

function resetEmitFlow() {
  $('documentNumber').value = '';
  $('specialtySelect').value = '';
  $('specialtySearch').value = '';
  renderSpecialties();
  setDocType('DNI', false, false);
  goEmitStep(1);
  focusDocumentScanner();
}

function setEmitBusy(visible, message = '') {
  setTicketButtons(!visible);
  $('goSpecialtyButton').disabled = visible;
  $('goTicketTypeButton').disabled = visible;
  document.querySelectorAll('.doc-type-button, [data-go-step], [data-emit-mode]').forEach((button) => {
    button.disabled = visible;
  });

  if (message) {
    $('emitMessage').textContent = message;
  }
}

async function login(event) {
  event.preventDefault();
  $('loginMessage').textContent = '';
  $('loginButton').disabled = true;

  try {
    const data = await apiFetch('login.php', {
      method: 'POST',
      body: JSON.stringify({
        login: $('loginEmail').value.trim(),
        password: $('loginPassword').value,
      }),
    });

    state.token = data.access_token;
    state.refreshToken = data.refresh_token || '';
    state.user = data.user;
    localStorage.setItem('ti_neo_token', state.token);
    localStorage.setItem(REFRESH_TOKEN_KEY, state.refreshToken);
    localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
    await bootApp();
    showApp();
  } catch (error) {
    $('loginMessage').textContent = error.message;
  } finally {
    $('loginButton').disabled = false;
  }
}

function logout() {
  const refreshToken = state.refreshToken;
  if (refreshToken) {
    fetch(`${API}/logout.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    }).catch(() => {});
  }
  state.token = '';
  state.refreshToken = '';
  state.user = null;
  localStorage.removeItem('ti_neo_token');
  localStorage.removeItem(REFRESH_TOKEN_KEY);
  localStorage.removeItem('ti_neo_user');
  showLogin();
}

async function bootApp() {
  await loadPermissions();
  applyPermissionsToNavigation();

  const tasks = [];
  if (canView('emision')) {
    tasks.push(loadSpecialties(), loadSummary());
  } else if (canView('turnero')) {
    tasks.push(loadQueue());
  } else if (canView('pantalla')) {
    tasks.push(loadScreen());
  } else if (canView('rangos')) {
    tasks.push(loadRangeWorkspace());
  } else if (canView('reportes')) {
    tasks.push(loadReport());
  }
  if (canView('historial')) {
    tasks.push(loadHistory());
  }
  await Promise.all(tasks);

  const initialView = screenKioskRequested() && canView('pantalla')
    ? 'pantalla'
    : canView('emision') ? 'emision' : firstAllowedView();
  if (!initialView) {
    throw new Error('No tienes permisos activos para usar Tickets.');
  }
  showView(initialView);
}

async function loadPermissions() {
  state.permissions = await apiFetch('tickets.php?action=permisos');
}

function canView(view) {
  return Boolean(state.permissions?.views?.[VIEW_PERMISSION_ALIASES[view] || view]);
}

function canAction(action) {
  return Boolean(state.permissions?.actions?.[action]);
}

function canEmitNormal() {
  return canAction('emitir_normal') || canAction('emitir');
}

function canEmitPrintOnly() {
  return canAction('emitir_solo_impresion');
}

function canUseEmitMode(mode) {
  return mode === 'solo_impresion' ? canEmitPrintOnly() : canEmitNormal();
}

function firstAllowedView() {
  return ['emision', 'turnero', 'pantalla', 'rangos', 'historial', 'reportes', 'perfil', 'ventanillas', 'pantallas', 'impresion'].find(canView) || '';
}

function activeViewName() {
  return document.querySelector('[data-view-panel].active')?.dataset.viewPanel || firstAllowedView() || 'emision';
}

function isScreenOnlyUser() {
  const views = state.permissions?.views || {};
  return Boolean(views.pantalla)
    && !['emision', 'turnero', 'rangos', 'historial', 'reportes', 'perfil', 'ventanillas', 'pantallas', 'impresion', 'configuracion'].some((view) => Boolean(views[view]));
}

function screenKioskRequested() {
  const params = new URLSearchParams(window.location.search);
  return params.get('tv') === '1' || params.get('pantalla') === '1' || window.location.hash === '#pantalla-tv';
}

function applyPermissionsToNavigation() {
  document.querySelectorAll('[data-view]').forEach((link) => {
    const allowed = canView(link.dataset.view);
    if (!allowed && document.activeElement === link) {
      link.blur();
    }
    link.hidden = !allowed;
  });

  document.querySelectorAll('[data-view-panel]').forEach((panel) => {
    panel.hidden = !canView(panel.dataset.viewPanel);
  });

  const scanButton = $('topbarScanButton');
  if (scanButton) {
    scanButton.hidden = !canView('emision');
  }

  const dailyReprintPanel = $('dailyReprintPanel');
  if (dailyReprintPanel) {
    dailyReprintPanel.hidden = !canAction('reimprimir_dia');
  }

  const operatorButton = $('openOperatorButton');
  if (operatorButton) {
    operatorButton.hidden = !canAction('turnero');
  }

  const savePrintLayoutButton = $('savePrintLayoutButton');
  if (savePrintLayoutButton) {
    savePrintLayoutButton.hidden = !canAction('impresion_admin');
  }

  applyEmitModePermissions();

  const globalSearch = $('globalSearchInput');
  const globalSearchWrap = globalSearch?.closest('.topbar-search');
  if (globalSearch && globalSearchWrap) {
    const allowed = canView('historial');
    if (!allowed && document.activeElement === globalSearch) {
      globalSearch.blur();
    }
    globalSearch.value = allowed ? globalSearch.value : '';
    globalSearch.disabled = !allowed;
    globalSearchWrap.hidden = !allowed;
  }
}

function applyEmitModePermissions() {
  const modeWrap = document.querySelector('.emit-mode');
  const normalButton = $('emitModeNormal');
  const printOnlyButton = $('emitModePrintOnly');
  const normalAllowed = canEmitNormal();
  const printOnlyAllowed = canEmitPrintOnly();

  if (normalButton) {
    normalButton.hidden = !normalAllowed;
    normalButton.disabled = !normalAllowed;
  }

  if (printOnlyButton) {
    printOnlyButton.hidden = !printOnlyAllowed;
    printOnlyButton.disabled = !printOnlyAllowed;
  }

  if (modeWrap) {
    modeWrap.hidden = !(normalAllowed && printOnlyAllowed);
  }

  if (normalAllowed && !printOnlyAllowed) {
    setEmitMode('normal');
    return;
  }

  if (!normalAllowed && printOnlyAllowed) {
    setEmitMode('solo_impresion');
    return;
  }

  if (!normalAllowed && !printOnlyAllowed) {
    document.querySelectorAll('.ticket-button').forEach((button) => {
      button.disabled = true;
    });
    if ($('emitModeNotice')) {
      $('emitModeNotice').hidden = true;
    }
    return;
  }

  setEmitMode(state.emitMode);
}

function setSidebarOpen(open) {
  $('sidebar')?.classList.toggle('open', open);
  $('sidebarScrim')?.classList.toggle('visible', open);
  $('menuToggle')?.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function closeSidebar() {
  setSidebarOpen(false);
}

function runGlobalSearch() {
  const query = ($('globalSearchInput')?.value || '').trim();
  if (!query) {
    return;
  }

  if (!canView('historial')) {
    const messageTarget = $(`${activeViewName()}Message`) || $('emitMessage');
    if (messageTarget) {
      messageTarget.textContent = 'No tienes permiso para consultar historial.';
    }
    return;
  }

  $('historySearchFilter').value = query;
  $('historyDateFilter').value = '';
  $('historyTypeFilter').value = '';
  $('historyStatusFilter').value = '';
  state.historyPage = 1;
  showView('historial');
  loadHistory().catch((error) => {
    $('emitMessage').textContent = error.message;
  });
  closeSidebar();
}

function goQuickEmission() {
  if (!canView('emision')) {
    return;
  }

  showView('emision');
  focusDocumentScanner();
  closeSidebar();
}

function openOperatorWindow() {
  if (!canAction('turnero')) {
    $('emitMessage').textContent = 'No tienes permiso para abrir el turnero.';
    return;
  }

  const features = [
    'popup=yes',
    'width=390',
    'height=620',
    'left=80',
    'top=80',
    'resizable=yes',
    'scrollbars=no',
  ].join(',');
  const operatorWindow = window.open('operador.php', 'ti_neo_operador', features);
  if (operatorWindow) {
    operatorWindow.focus();
  }
}

function refreshActiveView() {
  const view = activeViewName();
  const tasks = [loadSummary()];

  if (view === 'emision') {
    tasks.push(loadSpecialties());
  } else
  if (view === 'historial') {
    tasks.push(loadHistory());
  } else if (view === 'turnero') {
    tasks.push(loadQueue());
  } else if (view === 'pantalla') {
    tasks.push(loadScreen());
  } else if (view === 'reportes') {
    tasks.push(loadReport());
  } else if (view === 'rangos') {
    tasks.push(loadRangeWorkspace());
  } else if (view === 'perfil') {
    tasks.push(loadProfile());
  } else if (view === 'ventanillas') {
    tasks.push(loadWindowsAdmin());
  } else if (view === 'pantallas') {
    tasks.push(loadScreenDevices());
  } else if (view === 'impresion') {
    applyPrintLayout(state.printSettings.layout);
  }

  Promise.all(tasks).catch((error) => {
    const target = view === 'reportes' ? 'reportMessage' : view === 'rangos' ? 'rangeMessage' : 'emitMessage';
    if ($(target)) {
      $(target).textContent = error.message;
    }
  });
}

function refreshAfterQueueSignal() {
  const view = activeViewName();

  if (view === 'emision') {
    refreshOperationalData().catch(() => {});
    return;
  }

  if (view === 'rangos') {
    refreshOperationalData({ ranges: true }).catch(() => {});
    return;
  }

  if (view === 'turnero') {
    state.queueLastChangeAt = Date.now();
    loadQueue().catch(() => {});
    return;
  }

  if (view === 'pantalla') {
    state.screenLastChangeAt = Date.now();
    loadScreen().catch(() => {});
  }
}

async function loadSpecialties() {
  const [data, disponibilidad] = await Promise.all([
    apiFetch('tickets.php?action=especialidades'),
    apiFetch('tickets.php?action=citas-sigh').catch(() => ({ especialidades: [] })),
  ]);
  state.specialties = data.especialidades || [];
  state.citasDisponiblesMeta = {
    periodo: disponibilidad.periodo || '',
    fechaInicio: disponibilidad.fecha_inicio || '',
    fechaFin: disponibilidad.fecha_fin || '',
    fechaOperativaInicio: disponibilidad.fecha_operativa_inicio || '',
    fechaOperativaFin: disponibilidad.fecha_operativa_fin || '',
    totalSigh: Number(disponibilidad.total_sigh || 0),
    totalMapeadas: Number(disponibilidad.total_mapeadas || 0),
  };
  state.citasDisponibles = new Map(
    (disponibilidad.especialidades || [])
      .filter((item) => item.core_especialidad_id)
      .map((item) => [Number(item.core_especialidad_id), item])
  );
  renderSpecialties();
  renderFrequentSpecialties();
  renderAvailabilityPanel();
}

function refreshMonthlyAvailability() {
  return loadSpecialties().catch(() => {});
}

async function refreshOperationalData(options = {}) {
  const settings = {
    summary: true,
    specialties: true,
    history: false,
    ranges: false,
    queue: false,
    screen: false,
    ...options,
  };
  const tasks = [];

  if (settings.summary) {
    tasks.push(loadSummary().catch(() => {}));
  }
  if (settings.specialties) {
    tasks.push(loadSpecialties().catch(() => {}));
  }
  if (settings.history && canView('historial')) {
    tasks.push(loadHistory().catch(() => {}));
  }
  if (settings.ranges && canView('rangos')) {
    tasks.push(loadRangeWorkspace().catch(() => {}));
  }
  if (settings.queue && canView('turnero')) {
    tasks.push(loadQueue().catch(() => {}));
  }
  if (settings.screen && canView('pantalla')) {
    tasks.push(loadScreen().catch(() => {}));
  }

  await Promise.all(tasks);
}

async function loadSummary() {
  const data = await apiFetch('tickets.php?action=summary');
  state.summary = data;
  state.printSettings = {
    ...state.printSettings,
    ...(data.impresion || {}),
    layout: {
      ...DEFAULT_PRINT_LAYOUT,
      ...((data.impresion || {}).layout || {}),
    },
  };
  state.ticketFormat = {
    ...DEFAULT_TICKET_FORMAT,
    titulo: data.impresion?.titulo || DEFAULT_TICKET_FORMAT.titulo,
    logo_izquierdo_url: data.impresion?.logo_izquierdo_url || DEFAULT_TICKET_FORMAT.logo_izquierdo_url,
    logo_derecho_url: data.impresion?.logo_derecho_url || DEFAULT_TICKET_FORMAT.logo_derecho_url,
  };
  applyPrintLayout(state.printSettings.layout);
  applyTicketFormat();

  $('operationalDate').textContent = `Fecha operativa: ${data.fecha}`;
  $('rangeDate').value = data.fecha;
  $('statTotal').textContent = data.total_hoy || 0;
  $('statPreferencial').textContent = data.por_tipo?.Preferencial || 0;
  $('statNormal').textContent = data.por_tipo?.Normal || 0;
  $('statProcedimiento').textContent =
    (data.por_tipo?.Procedimiento || 0) + (data.por_tipo?.Emergencia || 0);
  $('statLast').textContent = data.ultimo?.codigo || '-';
  if ($('rangeStatus')) {
    const activeRanges = (data.rangos || []).filter((range) => String(range.activo || '').toUpperCase() === 'SI').length;
    $('rangeStatus').textContent = `${activeRanges}/3 rangos activos`;
  }
  renderRanges(data.rangos || []);
  state.frequentSpecialties = data.especialidades_frecuentes || [];
  renderFrequentSpecialties();
}

function selectedSpecialtyName() {
  const id = Number($('specialtySelect').value || 0);
  return state.specialties.find((specialty) => Number(specialty.id) === id)?.nombre || '';
}

function specialtyAvailability(id) {
  return state.citasDisponibles.get(Number(id)) || null;
}

function specialtyAvailabilityBadge(specialty) {
  const id = Number(specialty?.id || 0);
  const availability = specialtyAvailability(id);
  const isMappedToSigh = Boolean(Number(specialty?.sigh_especialidad_id || 0));
  if (!availability && !isMappedToSigh) {
    return '';
  }

  const cuposMes = Number(availability?.citas_disponibles || 0);
  const cuposOperativos = Number(availability?.citas_operativas || cuposMes || 0);
  const cuposProximos = Math.max(0, cuposOperativos - cuposMes);
  const primeraFecha = availability?.primera_fecha_disponible ? formatShortDate(availability.primera_fecha_disponible) : '';
  if (cuposMes > 0) {
    if (cuposProximos > 0) {
      return `<small class="slots-ok">${cuposMes} mes / ${cuposProximos} desde ${escapeHtml(primeraFecha || 'prox.')}</small>`;
    }
    return `<small class="slots-ok">${cuposMes} cupos mes</small>`;
  }

  if (cuposOperativos > 0) {
    return `<small class="slots-next">0 mes / ${cuposOperativos} desde ${escapeHtml(primeraFecha || 'prox.')}</small>`;
  }

  return '<small class="slots-empty">0 cupos</small>';
}

function selectSpecialty(id) {
  $('specialtySelect').value = String(id);
  const specialtyName = selectedSpecialtyName();
  $('specialtySearch').value = specialtyName;
  renderSpecialties();
  renderFrequentSpecialties();
  $('emitMessage').textContent = '';
  goEmitStep(3);
}

function renderSpecialties() {
  const container = $('specialtyList');
  if (!container) {
    return;
  }

  const query = ($('specialtySearch')?.value || '').trim().toLowerCase();
  const selectedId = Number($('specialtySelect').value || 0);
  const specialties = state.specialties
    .filter((specialty) => specialty.nombre.toLowerCase().includes(query))
    .slice(0, 9);

  state.specialtyShortcutIds = specialties.map((specialty) => Number(specialty.id));

  container.innerHTML = '';

  if (!query && !selectedId) {
    state.specialtyShortcutIds = [];
    container.innerHTML = '<span class="specialty-empty">Escribe para ver coincidencias. Usa 1-9 para seleccionar.</span>';
    return;
  }

  if (!specialties.length) {
    state.specialtyShortcutIds = [];
    container.innerHTML = '<span class="specialty-empty">Sin coincidencias.</span>';
    return;
  }

  specialties.forEach((specialty, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `specialty-choice tone-${(index % 4) + 1}`;
    button.classList.toggle('active', Number(specialty.id) === selectedId);
    button.innerHTML = `<kbd class="shortcut-badge">${index + 1}</kbd><span>${escapeHtml(specialty.nombre)}</span>${specialtyAvailabilityBadge(specialty)}`;
    button.addEventListener('click', () => selectSpecialty(specialty.id));
    container.appendChild(button);
  });
}

function renderAvailabilityPanel() {
  const container = $('availabilityPanelList');
  if (!container) {
    return;
  }

  const period = $('availabilityPeriod');
  const meta = state.citasDisponiblesMeta || {};
  if (period) {
    period.textContent = meta.fechaInicio && meta.fechaFin
      ? `${formatShortDate(meta.fechaInicio)} - ${formatShortDate(meta.fechaFin)}`
      : 'Mes actual';
  }

  const allRows = Array.from(state.citasDisponibles.values()).sort(compareAvailabilityByName);
  const grouped = {
    disponibles: allRows.filter((item) => Number(item.citas_disponibles || 0) > 0).sort(compareAvailabilityByStatus),
    proximas: allRows.filter((item) => Number(item.citas_disponibles || 0) < 1 && Number(item.citas_operativas || 0) > 0).sort(compareAvailabilityByStatus),
    agotadas: allRows.filter((item) => Number(item.citas_disponibles || 0) < 1 && Number(item.citas_operativas || 0) < 1),
    reservadas: allRows.filter((item) => Number(item.reservas_operativas_ti_neo || item.reservas_activas_ti_neo || 0) > 0),
  };

  if (!allRows.length) {
    renderAvailabilityTabs(grouped);
    container.innerHTML = '<span class="availability-empty">Sin disponibilidad SIGH mapeada para este periodo.</span>';
    return;
  }

  if (!['disponibles', 'proximas', 'agotadas', 'reservadas'].includes(state.availabilityTab)) {
    state.availabilityTab = grouped.disponibles.length
      ? 'disponibles'
      : (grouped.proximas.length ? 'proximas' : (grouped.agotadas.length ? 'agotadas' : 'reservadas'));
  }

  renderAvailabilityTabs(grouped);

  const rows = grouped[state.availabilityTab] || [];
  if (!rows.length) {
    container.innerHTML = `<span class="availability-empty">${availabilityEmptyMessage(state.availabilityTab)}</span>`;
    return;
  }

  container.innerHTML = rows.map((item) => {
    const availableMonth = Number(item.citas_disponibles || 0);
    const availableOperational = Number(item.citas_operativas || 0);
    const sighAvailableMonth = Number(item.citas_disponibles_sigh || 0);
    const reservedMonth = Number(item.reservas_activas_ti_neo || 0);
    const reservedOperational = Number(item.reservas_operativas_ti_neo || reservedMonth || 0);
    const firstDate = item.primera_fecha_disponible ? formatShortDate(item.primera_fecha_disponible) : '';
    const title = item.core_nombre || item.sigh_nombre || 'Especialidad';
    const isNextOnly = availableMonth < 1 && availableOperational > 0;
    const visibleCount = isNextOnly ? availableOperational : availableMonth;
    const subtitle = isNextOnly
      ? `Sin cupos este mes${firstDate ? ` - proximo desde ${firstDate}` : ''}`
      : `${sighAvailableMonth} cupos SIGH este mes`;
    const detail = isNextOnly
      ? `Este mes: 0 / Proximos: ${availableOperational} / Reservados: ${reservedOperational}`
      : `Este mes: ${availableMonth} / SIGH mes: ${sighAvailableMonth} / Reservados: ${reservedMonth}`;

    return `
      <div class="availability-row ${isNextOnly ? 'next' : (availableMonth > 0 ? 'available' : 'empty')}">
        <div>
          <strong>${escapeHtml(title)}</strong>
          <small>SIGH ${escapeHtml(item.sigh_nombre || title)}</small>
          <em>${escapeHtml(subtitle)}</em>
        </div>
        <div class="availability-counts">
          <span>${visibleCount}</span>
          <small>${isNextOnly ? 'proximos' : 'este mes'}</small>
        </div>
        <small class="availability-detail">${escapeHtml(detail)}</small>
      </div>
    `;
  }).join('');
}
function renderAvailabilityTabs(grouped) {
  const tabs = $('availabilityTabs');
  if (!tabs) {
    return;
  }

  const options = [
    ['disponibles', 'Disponibles', grouped.disponibles.length],
    ['proximas', 'Proximas', grouped.proximas.length],
    ['agotadas', 'Agotadas', grouped.agotadas.length],
    ['reservadas', 'Reservadas NEO', grouped.reservadas.length],
  ];

  tabs.innerHTML = options.map(([key, label, count]) => `
    <button type="button" class="${state.availabilityTab === key ? 'active' : ''}" data-availability-tab="${key}" role="tab" aria-selected="${state.availabilityTab === key ? 'true' : 'false'}">
      <span>${label}</span>
      <strong>${count}</strong>
    </button>
  `).join('');

  tabs.querySelectorAll('[data-availability-tab]').forEach((button) => {
    button.addEventListener('click', () => {
      state.availabilityTab = button.dataset.availabilityTab;
      renderAvailabilityPanel();
    });
  });
}
function compareAvailabilityByName(a, b) {
  return String(a.core_nombre || a.sigh_nombre || '')
    .localeCompare(String(b.core_nombre || b.sigh_nombre || ''), 'es', { sensitivity: 'base' });
}

function compareAvailabilityByStatus(a, b) {
  const aMonth = Number(a.citas_disponibles || 0);
  const bMonth = Number(b.citas_disponibles || 0);
  const aNext = Number(a.citas_operativas || 0);
  const bNext = Number(b.citas_operativas || 0);
  const aRank = aMonth > 0 ? 0 : (aNext > 0 ? 1 : 2);
  const bRank = bMonth > 0 ? 0 : (bNext > 0 ? 1 : 2);

  if (aRank !== bRank) {
    return aRank - bRank;
  }

  if (aRank === 0 && aMonth !== bMonth) {
    return bMonth - aMonth;
  }

  if (aRank === 1 && aNext !== bNext) {
    return bNext - aNext;
  }

  return compareAvailabilityByName(a, b);
}

function availabilityEmptyMessage(tab) {
  if (tab === 'agotadas') {
    return 'No hay especialidades agotadas sin proximos cupos.';
  }
  if (tab === 'reservadas') {
    return 'No hay reservas temporales activas.';
  }
  if (tab === 'proximas') {
    return 'No hay especialidades con cupos proximos.';
  }

  return 'No hay cupos disponibles este mes.';
}
function formatShortDate(value) {
  const [year, month, day] = String(value || '').split('-');
  if (!year || !month || !day) {
    return String(value || '');
  }

  return `${day}/${month}`;
}

function renderFrequentSpecialties() {
  const container = $('frequentSpecialtyList');
  if (!container) {
    return;
  }

  const selectedId = Number($('specialtySelect').value || 0);
  const frequent = state.frequentSpecialties
    .filter((specialty) => Number(specialty.total || 0) > 0)
    .slice(0, 3);

  container.innerHTML = '';

  if (!frequent.length) {
    container.innerHTML = '<span class="specialty-empty">Se llenara con las mas usadas de hoy.</span>';
    return;
  }

  frequent.forEach((specialty, index) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `specialty-choice frequent tone-${(index % 4) + 1}`;
    button.classList.toggle('active', Number(specialty.id) === selectedId);
    button.innerHTML = `<span>${escapeHtml(specialty.nombre)}</span>${specialtyAvailabilityBadge(specialty) || `<small>${Number(specialty.total)}</small>`}`;
    button.addEventListener('click', () => selectSpecialty(specialty.id));
    container.appendChild(button);
  });
}

function historyDocumentText(ticket) {
  const documentNumber = String(ticket.numero_documento || '').trim();
  const specialty = String(ticket.especialidad || '').trim().toUpperCase();

  if (!documentNumber && specialty === 'LOTE / REIMPRESION') {
    return 'Lote';
  }

  return `${ticket.tipo_documento || ''} ${ticket.numero_documento || ''}`.trim() || '-';
}

function historySpecialtyText(ticket) {
  const specialty = String(ticket.especialidad || '').trim();
  if (specialty.toUpperCase() === 'LOTE / REIMPRESION') {
    return '-';
  }

  return specialty || '-';
}

async function loadProfile() {
  if (!canView('perfil') || !$('profileDisplayName')) {
    return;
  }

  $('profileMessage').textContent = 'Cargando perfil...';
  const data = await apiFetch('profile.php?action=ver');
  state.profile = data;
  renderProfile(data);
  $('profileMessage').textContent = '';
}

async function saveProfile() {
  if (!canView('perfil')) {
    return;
  }

  $('saveProfileButton').disabled = true;
  $('profileMessage').textContent = 'Guardando perfil...';

  try {
    const data = await apiFetch('profile.php?action=guardar', {
      method: 'POST',
      body: JSON.stringify(readProfileForm()),
    });
    state.profile = data;
    renderProfile(data);
    $('profileMessage').textContent = 'Perfil guardado.';
  } catch (error) {
    $('profileMessage').textContent = error.message;
  } finally {
    $('saveProfileButton').disabled = false;
  }
}

function readProfileForm() {
  const payload = {
    nombre_visible: $('profileDisplayName')?.value.trim() || '',
    color_acento: normalizeHexColor($('profileAccent')?.value),
    avatar_url: $('profileAvatarUrl')?.value.trim() || '',
  };
  if ($('profileBackgroundUrl')) {
    payload.background_url = $('profileBackgroundUrl')?.value.trim() || '';
  }
  if ($('profileBackgroundOpacity')) {
    payload.background_opacity = normalizeBackgroundOpacity($('profileBackgroundOpacity')?.value || 12);
  }
  if ($('profileDailyGoal')) {
    payload.meta_diaria = Number($('profileDailyGoal')?.value || 60);
  }
  return payload;
}

function normalizeHexColor(value, fallback = '#7c3aed') {
  const raw = String(value || '').trim();
  const withHash = raw.startsWith('#') ? raw : `#${raw}`;
  if (!/^#[0-9a-f]{6}$/i.test(withHash)) return fallback;
  return withHash.toLowerCase();
}

function hueToHex(hue) {
  const h = Math.max(0, Math.min(360, Number(hue) || 0));
  const canvas = document.createElement('canvas');
  canvas.width = 1;
  canvas.height = 1;
  const context = canvas.getContext('2d');
  context.fillStyle = `hsl(${h} 72% 48%)`;
  context.fillRect(0, 0, 1, 1);
  return normalizeHexColor(context.fillStyle);
}

function hexToHue(hex) {
  const value = normalizeHexColor(hex).slice(1);
  const r = parseInt(value.slice(0, 2), 16) / 255;
  const g = parseInt(value.slice(2, 4), 16) / 255;
  const b = parseInt(value.slice(4, 6), 16) / 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const delta = max - min;
  if (delta === 0) return 0;
  let hue = max === r ? ((g - b) / delta) % 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
  return Math.round(hue * 60 < 0 ? hue * 60 + 360 : hue * 60);
}

const profileColorState = { h: 262, s: 72, v: 48 };

function clampColor(value, min = 0, max = 100) {
  return Math.max(min, Math.min(max, Number(value) || 0));
}

function hexToRgb(hex) {
  const value = normalizeHexColor(hex).slice(1);
  return {
    r: parseInt(value.slice(0, 2), 16),
    g: parseInt(value.slice(2, 4), 16),
    b: parseInt(value.slice(4, 6), 16),
  };
}

function rgbToHex(r, g, b) {
  return `#${[r, g, b].map((part) => Math.round(clampColor(part, 0, 255)).toString(16).padStart(2, '0')).join('')}`;
}

function hexToHsv(hex) {
  const { r, g, b } = hexToRgb(hex);
  const nr = r / 255;
  const ng = g / 255;
  const nb = b / 255;
  const max = Math.max(nr, ng, nb);
  const min = Math.min(nr, ng, nb);
  const delta = max - min;
  let h = 0;

  if (delta !== 0) {
    h = max === nr ? ((ng - nb) / delta) % 6 : max === ng ? (nb - nr) / delta + 2 : (nr - ng) / delta + 4;
    h = Math.round(h * 60);
    if (h < 0) h += 360;
  }

  return {
    h,
    s: max === 0 ? 0 : Math.round((delta / max) * 100),
    v: Math.round(max * 100),
  };
}

function hsvToHex(h, s, v) {
  const hue = ((Number(h) || 0) % 360 + 360) % 360;
  const saturation = clampColor(s) / 100;
  const value = clampColor(v) / 100;
  const chroma = value * saturation;
  const x = chroma * (1 - Math.abs((hue / 60) % 2 - 1));
  const match = value - chroma;
  const [r1, g1, b1] = hue < 60 ? [chroma, x, 0]
    : hue < 120 ? [x, chroma, 0]
    : hue < 180 ? [0, chroma, x]
    : hue < 240 ? [0, x, chroma]
    : hue < 300 ? [x, 0, chroma]
    : [chroma, 0, x];

  return rgbToHex((r1 + match) * 255, (g1 + match) * 255, (b1 + match) * 255);
}

function syncColorPicker(accent) {
  const hsv = hexToHsv(accent);
  profileColorState.h = hsv.h;
  profileColorState.s = hsv.s;
  profileColorState.v = hsv.v;

  const picker = $('profileColorPicker');
  if (picker) {
    picker.style.setProperty('--picker-hue-color', hsvToHex(profileColorState.h, 100, 100));
    picker.style.setProperty('--picker-saturation', String(profileColorState.s));
    picker.style.setProperty('--picker-value', String(profileColorState.v));
  }
}

function setColorFromMapEvent(event) {
  const map = $('profileColorMap');
  if (!map) return;

  const rect = map.getBoundingClientRect();
  const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
  const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
  profileColorState.s = Math.round((x / rect.width) * 100);
  profileColorState.v = Math.round((1 - (y / rect.height)) * 100);
  applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
}

function bindColorPicker() {
  const map = $('profileColorMap');
  if (!map) return;

  let dragging = false;
  map.addEventListener('pointerdown', (event) => {
    dragging = true;
    map.setPointerCapture(event.pointerId);
    setColorFromMapEvent(event);
  });
  map.addEventListener('pointermove', (event) => {
    if (dragging) setColorFromMapEvent(event);
  });
  map.addEventListener('pointerup', () => {
    dragging = false;
  });
  map.addEventListener('pointercancel', () => {
    dragging = false;
  });
  map.addEventListener('keydown', (event) => {
    const step = event.shiftKey ? 10 : 2;
    if (event.key === 'ArrowLeft') profileColorState.s = clampColor(profileColorState.s - step);
    else if (event.key === 'ArrowRight') profileColorState.s = clampColor(profileColorState.s + step);
    else if (event.key === 'ArrowUp') profileColorState.v = clampColor(profileColorState.v + step);
    else if (event.key === 'ArrowDown') profileColorState.v = clampColor(profileColorState.v - step);
    else return;

    event.preventDefault();
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
  });
}

function applyProfileAccent(value) {
  const accent = normalizeHexColor(value);
  $('profileAccent').value = accent.slice(1);
  $('profileAccentPreview').style.background = accent;
  if ($('profileColorSummary')) $('profileColorSummary').textContent = accent;
  $('profileHue').value = String(hexToHue(accent));
  syncColorPicker(accent);
  if ($('topbarProfileColor')) {
    $('topbarProfileColor').textContent = accent;
  }
  document.documentElement.style.setProperty('--accent', accent);
  document.documentElement.style.setProperty('--personal-accent', accent);
  return accent;
}

function updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl, initials) {
  $('topbarProfileName').textContent = displayName || 'Usuario';
  $('topbarProfileRole').textContent = roleArea || 'Equipo de atencion';
  if ($('topbarProfileColor')) {
    $('topbarProfileColor').textContent = normalizeHexColor(accent);
  }
  $('topbarProfileAvatar').textContent = initials || profileInitials(displayName || 'Usuario');
  $('topbarProfileAvatar').style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
  $('topbarProfileAvatar').classList.toggle('with-image', Boolean(avatarUrl));
}

async function refreshGlobalProfile() {
  const data = await apiFetch('profile.php?action=ver');
  const profile = data.perfil || {};
  state.user = {
    ...state.user,
    perfil: {
      ...(state.user?.perfil || {}),
      nombre_visible: profile.nombre_visible || '',
      cargo_area: profile.cargo_area || '',
      color_principal: profile.color_acento || profile.color_principal || '#7c3aed',
      avatar_url: profile.avatar_url || '',
      background_url: profile.background_url || '',
      background_opacity: normalizeBackgroundOpacity(profile.background_opacity),
      iniciales: profile.iniciales || '',
    },
  };
  localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
  applyGlobalProfile();
}

async function requestPasswordReset() {
  $('profileMessage').textContent = 'Enviando solicitud...';
  try {
    await apiFetch('profile.php?action=solicitar_reinicio_password', { method: 'POST' });
    $('profileMessage').textContent = 'Solicitud enviada. GS NEO debe aprobar el reinicio.';
  } catch (error) {
    $('profileMessage').textContent = error.message;
  }
}

function renderPasswordResetStatus(status = {}) {
  $('passwordResetPanel')?.classList.toggle('hidden', !status.habilitado);
}

async function changeProfilePassword() {
  const password = $('newProfilePassword')?.value || '';
  const confirm = $('confirmProfilePassword')?.value || '';
  $('changeProfilePasswordMessage').textContent = 'Actualizando contrasena...';
  try {
    await apiFetch('profile.php?action=cambiar_password', {
      method: 'POST',
      body: JSON.stringify({ password, confirm_password: confirm }),
    });
    $('newProfilePassword').value = '';
    $('confirmProfilePassword').value = '';
    $('passwordResetPanel').classList.add('hidden');
    $('changeProfilePasswordMessage').textContent = 'Contrasena actualizada.';
  } catch (error) {
    $('changeProfilePasswordMessage').textContent = error.message;
  }
}

function togglePasswordInput(id) {
  const input = $(id);
  if (!input) {
    return;
  }
  input.type = input.type === 'password' ? 'text' : 'password';
}

function renderProfile(data) {
  const profile = data.perfil || {};
  const widgets = data.widgets || {};
  const accent = normalizeHexColor(profile.color_acento);
  const initials = profile.iniciales || profileInitials(profile.nombre_visible);
  const avatarUrl = String(profile.avatar_url || '').trim();

  $('profileDisplayName').value = profile.nombre_visible || '';
  $('profileRoleArea').value = profile.cargo_area || '';
  $('profileEmail').value = profile.correo || '';
  applyProfileAccent(accent);
  if ($('profileDailyGoal')) $('profileDailyGoal').value = Number(profile.meta_diaria || 60);
  $('profileAvatarUrl').value = avatarUrl;
  applyProfileBackground('', 0);

  state.user = {
    ...state.user,
    nombre: profile.nombre_visible || state.user?.nombre,
    perfil: {
      nombre_visible: profile.nombre_visible || '',
      cargo_area: profile.cargo_area || '',
      color_principal: accent,
      avatar_url: avatarUrl,
      iniciales: initials,
      meta_diaria_tickets: Number(profile.meta_diaria || 60),
    },
  };
  localStorage.setItem('ti_neo_user', JSON.stringify(state.user));
  $('profileHeroName').textContent = profile.nombre_visible || 'Usuario';
  $('profileHeroRole').textContent = profile.cargo_area || 'Equipo de atencion';
  if ($('profileColorSummary')) $('profileColorSummary').textContent = accent;
  $('profileName').textContent = profile.nombre_visible || 'Usuario';
  $('currentUser').textContent = profile.correo || displayLogin(state.user) || 'Usuario';
  $('profileInitials').textContent = '...';
  $('profileInitials').style.backgroundImage = '';
  $('profileInitials').classList.remove('with-image');
  updateTopbarProfileSummary(profile.nombre_visible || 'Usuario', profile.cargo_area || 'Equipo de atencion', accent, avatarUrl, initials);
  renderProfileAvatar(avatarUrl, initials);
  renderProfileWidgets(widgets);
  renderProfileList('profileRecognitions', data.reconocimientos || []);
  renderProfileList('profileAlerts', data.alertas || []);
  renderPasswordResetStatus(data.password_reset || {});
}

function renderProfileAvatar(avatarUrl, initials) {
  const avatar = $('profileAvatarPreview');
  avatar.textContent = initials;
  avatar.style.backgroundImage = '';
  avatar.classList.toggle('with-image', Boolean(avatarUrl));

  if (avatarUrl) {
    avatar.style.backgroundImage = `url("${avatarUrl.replaceAll('"', '%22')}")`;
  }

  renderAvatarPresets(avatarUrl);
}

function normalizeAvatarForCompare(value) {
  return String(value || '').trim().replace(/^https?:\/\/[^/]+/i, '');
}

function renderAvatarPresets(selectedUrl = '') {
  const grid = $('profileAvatarPresets');
  if (!grid) {
    return;
  }

  grid.innerHTML = PRESET_AVATARS.map((url, index) => {
    const active = normalizeAvatarForCompare(url) === normalizeAvatarForCompare(selectedUrl) ? ' active' : '';
    return `
      <button class="avatar-preset-button${active}" type="button" data-avatar-url="${url}" aria-label="Seleccionar avatar ${index + 1}">
        <img src="${url}" alt="">
      </button>
    `;
  }).join('');
}

function normalizeBackgroundOpacity(value) {
  const raw = Number(value);
  if (!Number.isFinite(raw)) return 0.12;
  return Math.max(0, Math.min(0.35, raw > 1 ? raw / 100 : raw));
}

function applyProfileBackground(backgroundUrl = '', opacity = 0.12) {
  const url = String(backgroundUrl || '').trim();
  const normalizedOpacity = normalizeBackgroundOpacity(opacity);
  document.documentElement.style.setProperty('--profile-bg-image', url ? `url("${url.replaceAll('"', '%22')}")` : 'none');
  document.documentElement.style.setProperty('--profile-bg-opacity', String(normalizedOpacity));
  if ($('profileBackgroundUrl')) $('profileBackgroundUrl').value = url;
  if ($('profileBackgroundOpacity')) $('profileBackgroundOpacity').value = String(Math.round(normalizedOpacity * 100));
  if ($('profileBackgroundOpacityValue')) $('profileBackgroundOpacityValue').textContent = `${Math.round(normalizedOpacity * 100)}%`;
  renderBackgroundPresets(url);
}

function renderBackgroundPresets(selectedUrl = '') {
  const grid = $('profileBackgroundPresets');
  if (!grid) return;
  const selected = normalizeAvatarForCompare(selectedUrl);
  grid.innerHTML = [
    '<button class="background-preset-button clear" type="button" data-background-url="" aria-label="Quitar fondo">Sin fondo</button>',
    ...PRESET_BACKGROUNDS.map((url, index) => {
      const active = normalizeAvatarForCompare(url) === selected ? ' active' : '';
      return `<button class="background-preset-button${active}" type="button" data-background-url="${url}" aria-label="Seleccionar fondo ${index + 1}"><span style="background-image:url('${url}')"></span></button>`;
    }),
  ].join('');
}

function renderProfileWidgets(widgets) {
  if (!$('profileTodayCount')) return;
  const today = Number(widgets.hoy || 0);
  const yesterday = Number(widgets.ayer || 0);
  const goal = Number(widgets.meta_diaria || 0);
  const progress = Math.min(100, Number(widgets.meta_porcentaje || 0));
  const delta = Number(widgets.diferencia || 0);
  const bestHour = widgets.mejor_hora || {};
  const bestDay = widgets.mejor_dia || {};

  $('profileDate').textContent = widgets.fecha || 'Hoy';
  $('profileTodayCount').textContent = today;
  $('profileYesterdayCount').textContent = yesterday;
  $('profileDelta').textContent = delta === 0 ? 'igual que ayer' : `${delta > 0 ? '+' : ''}${delta} vs ayer`;
  $('profileGoalSummary').textContent = `${today} / ${goal}`;
  $('profileGoalBar').style.width = `${progress}%`;
  $('profileGoalText').textContent = Number(widgets.faltan_meta || 0) > 0
    ? `Faltan ${widgets.faltan_meta} para completar la meta`
    : 'Meta completada';
  $('profileBestHour').textContent = bestHour.hora || '-';
  $('profileBestHourText').textContent = bestHour.total ? `${bestHour.total} tickets` : 'sin datos';
  $('profileBestDay').textContent = bestDay.fecha || '-';
  $('profileBestDayText').textContent = bestDay.total ? `${bestDay.total} tickets` : 'sin datos';
}

function renderProfileList(id, items) {
  const container = $(id);
  if (!container) return;
  container.innerHTML = '';
  const values = items.length ? items : ['Sin datos por ahora.'];

  values.forEach((item) => {
    const row = document.createElement('span');
    row.textContent = item;
    container.appendChild(row);
  });
}

function syncProfilePreview() {
  if (!$('profileDisplayName')) {
    return;
  }

  const displayName = $('profileDisplayName').value.trim() || 'Usuario';
  const roleArea = $('profileRoleArea').value.trim() || 'Equipo de atencion';
  const accent = normalizeHexColor($('profileAccent').value);
  const avatarUrl = $('profileAvatarUrl').value.trim();
  const initials = profileInitials(displayName);

  applyProfileAccent(accent);
  $('profileHeroName').textContent = displayName;
  $('profileHeroRole').textContent = roleArea;
  $('profileInitials').textContent = '...';
  renderProfileAvatar(avatarUrl, initials);
  applyProfileBackground('', 0);
  updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl, initials);
}

function applyPrintLayout(layout) {
  const normalized = { ...DEFAULT_PRINT_LAYOUT, ...(layout || {}) };
  state.printSettings.layout = normalized;

  const root = document.documentElement;
  root.style.setProperty('--ticket-paper-mm', `${normalized.papel_mm}mm`);
  root.style.setProperty('--ticket-content-mm', `${normalized.contenido_mm}mm`);
  const padMm = `${normalized.padding_mm}mm`;
  root.style.setProperty('--ticket-padding-mm', padMm);
  root.style.setProperty('--ticket-padding', padMm);
  root.style.setProperty('--ticket-logo-mm', `${normalized.logo_mm}mm`);
  root.style.setProperty('--ticket-header-px', `${normalized.header_px}px`);
  root.style.setProperty('--ticket-code-px', `${normalized.codigo_px}px`);
  root.style.setProperty('--ticket-doc-px', `${normalized.documento_px}px`);
  root.style.setProperty('--ticket-specialty-px', `${normalized.especialidad_px}px`);
  root.style.setProperty('--ticket-date-px', `${normalized.fecha_px}px`);
  root.style.setProperty('--ticket-code-margin-mm', `${normalized.codigo_margen_mm}mm`);
  root.style.setProperty('--ticket-line-margin-mm', `${normalized.linea_margen_mm}mm`);
  root.style.setProperty('--ticket-date-margin-mm', `${normalized.fecha_margen_mm}mm`);
  root.style.setProperty('--ticket-offset-x-mm', `${normalized.offset_x_mm}mm`);
  root.style.setProperty('--ticket-offset-y-mm', `${normalized.offset_y_mm}mm`);
  root.style.setProperty('--ticket-hospital-x-mm', `${normalized.hospital_x_mm}mm`);
  root.style.setProperty('--ticket-hospital-y-mm', `${normalized.hospital_y_mm}mm`);
  root.style.setProperty('--ticket-logo-left-x-mm', `${normalized.logo_left_x_mm}mm`);
  root.style.setProperty('--ticket-logo-left-y-mm', `${normalized.logo_left_y_mm}mm`);
  root.style.setProperty('--ticket-logo-right-x-mm', `${normalized.logo_right_x_mm}mm`);
  root.style.setProperty('--ticket-logo-right-y-mm', `${normalized.logo_right_y_mm}mm`);
  root.style.setProperty('--ticket-code-x-mm', `${normalized.code_x_mm}mm`);
  root.style.setProperty('--ticket-code-y-mm', `${normalized.code_y_mm}mm`);
  root.style.setProperty('--ticket-doc-x-mm', `${normalized.doc_x_mm}mm`);
  root.style.setProperty('--ticket-doc-y-mm', `${normalized.doc_y_mm}mm`);
  root.style.setProperty('--ticket-specialty-x-mm', `${normalized.specialty_x_mm}mm`);
  root.style.setProperty('--ticket-specialty-y-mm', `${normalized.specialty_y_mm}mm`);
  root.style.setProperty('--ticket-date-x-mm', `${normalized.date_x_mm}mm`);
  root.style.setProperty('--ticket-date-y-mm', `${normalized.date_y_mm}mm`);
  root.style.setProperty('--ticket-width', `${normalized.papel_mm}mm`);
  root.style.setProperty('--ticket-code-size', `${normalized.codigo_px}px`);
  root.style.setProperty('--ticket-date-size', `${normalized.fecha_px}px`);
  document.querySelectorAll('[data-print-setting]').forEach((input) => {
    input.value = normalized[input.dataset.printSetting] ?? '';
  });
}

function applyTicketFormat() {
  document.querySelectorAll('[data-ticket-title]').forEach((title) => {
    title.textContent = state.ticketFormat.titulo;
  });

  document.querySelectorAll('[data-logo-left]').forEach((logo) => {
    logo.src = state.ticketFormat.logo_izquierdo_url;
  });

  document.querySelectorAll('[data-logo-right]').forEach((logo) => {
    logo.src = state.ticketFormat.logo_derecho_url;
  });
}

function readPrintLayoutForm() {
  const layout = {};
  document.querySelectorAll('[data-print-setting]').forEach((input) => {
    layout[input.dataset.printSetting] = Number(input.value);
  });
  return layout;
}

async function savePrintLayout() {
  $('printLayoutMessage').textContent = '';
  $('savePrintLayoutButton').disabled = true;

  try {
    const layout = readPrintLayoutForm();
    const data = await apiFetch('tickets.php?action=guardar-diseno-impresion', {
      method: 'POST',
      body: JSON.stringify(layout),
    });

    state.printSettings = data.impresion || state.printSettings;
    applyPrintLayout(state.printSettings.layout);
    $('printLayoutMessage').textContent = 'Diseno de impresion guardado.';
  } catch (error) {
    $('printLayoutMessage').textContent = error.message;
  } finally {
    $('savePrintLayoutButton').disabled = false;
  }
}

async function saveRange() {
  $('rangeMessage').textContent = '';
  $('saveRangeButton').disabled = true;

  try {
    await apiFetch('tickets.php?action=guardar-rango', {
      method: 'POST',
      body: JSON.stringify({
        fecha: $('rangeDate').value,
        tipo_ticket: $('rangeType').value,
        desde: Number($('rangeFrom').value),
        hasta: Number($('rangeTo').value),
      }),
    });

    $('rangeMessage').textContent = 'Rango guardado correctamente.';
    await loadSummary();
  } catch (error) {
    $('rangeMessage').textContent = error.message;
  } finally {
    $('saveRangeButton').disabled = false;
  }
}

async function loadHistory() {
  if (!canView('historial')) {
    return;
  }

  const data = await apiFetch(historyQueryString());
  const rows = data.tickets || [];
  const body = $('historyBody');
  const total = Number(data.total || rows.length);
  const page = Number(data.pagina || state.historyPage);
  const totalPages = Number(data.total_paginas || 1);
  body.innerHTML = '';
  if ($('historyCount')) {
    $('historyCount').textContent = `${total} registros`;
  }
  if ($('historyPageInfo')) {
    $('historyPageInfo').textContent = `Pagina ${page} de ${totalPages}`;
  }
  if ($('historyPrevPage')) {
    $('historyPrevPage').disabled = page <= 1;
  }
  if ($('historyNextPage')) {
    $('historyNextPage').disabled = page >= totalPages;
  }

  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="8">No hay tickets con esos filtros.</td></tr>';
    return;
  }

  rows.forEach((ticket) => {
    const row = document.createElement('tr');
    const isCanceled = ticket.estado === 'anulado';
    const reprintButton = canAction('reimprimir_historial')
      ? `<button type="button" data-action="reprint" data-id="${ticket.id}" ${isCanceled ? 'disabled' : ''}>Reimprimir</button>`
      : '';
    const cancelButton = canAction('anular')
      ? `<button type="button" class="danger" data-action="cancel" data-id="${ticket.id}" ${isCanceled ? 'disabled' : ''}>Anular</button>`
      : '';
    row.innerHTML = `
      <td>${escapeHtml(ticket.fecha)}</td>
      <td>${escapeHtml(ticket.hora)}</td>
      <td><span class="badge ${ticketTypeBadgeClass(ticket.tipo_ticket)}">${escapeHtml(ticket.tipo_ticket === 'Emergencia' ? 'Procedimiento' : ticket.tipo_ticket)}</span></td>
      <td><strong>${escapeHtml(ticket.codigo)}</strong></td>
      <td>${escapeHtml(historyDocumentText(ticket))}</td>
      <td>${escapeHtml(historySpecialtyText(ticket))}</td>
      <td class="state-${escapeHtml(ticket.estado_impresion || 'pendiente')}">${escapeHtml(ticket.estado_impresion || 'pendiente')}</td>
      <td>
        <div class="row-actions">
          ${reprintButton}
          ${cancelButton}
        </div>
      </td>
    `;
    body.appendChild(row);
  });
}

function historyQueryString() {
  const params = new URLSearchParams({ action: 'historial' });
  const date = $('historyDateFilter')?.value || '';
  const type = $('historyTypeFilter')?.value || '';
  const status = $('historyStatusFilter')?.value || '';
  const search = ($('historySearchFilter')?.value || '').trim();

  if (date) {
    params.set('fecha', date);
  }
  if (type) {
    params.set('tipo_ticket', type);
  }
  if (status) {
    params.set('estado', status);
  }
  if (search) {
    params.set('codigo', search);
  }
  params.set('pagina', String(state.historyPage));
  params.set('por_pagina', '25');

  return `tickets.php?${params.toString()}`;
}

function todayInputValue() {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
}

function firstMonthDayInputValue() {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-01`;
}

function setupReportDefaults() {
  if ($('reportFrom') && !$('reportFrom').value) {
    $('reportFrom').value = firstMonthDayInputValue();
  }
  if ($('reportTo') && !$('reportTo').value) {
    $('reportTo').value = todayInputValue();
  }
}

function reportQueryString() {
  setupReportDefaults();
  const params = new URLSearchParams({ action: 'reportes' });
  const from = $('reportFrom')?.value || '';
  const to = $('reportTo')?.value || '';
  const type = $('reportType')?.value || '';
  const status = $('reportStatus')?.value || '';

  if (from) {
    params.set('desde', from);
  }
  if (to) {
    params.set('hasta', to);
  }
  if (type) {
    params.set('tipo_ticket', type);
  }
  if (status) {
    params.set('estado', status);
  }
  params.set('pagina', String(state.reportPage));
  params.set('por_pagina', '25');

  return `tickets.php?${params.toString()}`;
}

async function loadReport() {
  if (!canView('reportes') || !$('reportTotal')) {
    return;
  }

  $('reportMessage').textContent = 'Cargando reporte...';
  const data = await apiFetch(reportQueryString());
  renderReport(data);
  $('reportMessage').textContent = '';
}

function renderReport(data) {
  const summary = data.resumen || {};
  const filters = data.filtros || {};
  const page = Number(data.pagina || state.reportPage);
  const totalPages = Number(data.total_paginas || 1);
  $('reportPeriod').textContent = `Periodo: ${filters.desde || '-'} al ${filters.hasta || '-'}`;
  $('reportTotal').textContent = summary.total || 0;
  $('reportValid').textContent = summary.validos || 0;
  $('reportCanceled').textContent = summary.anulados || 0;
  $('reportProcedures').textContent = summary.procedimientos || 0;
  $('reportReprints').textContent = summary.reimpresiones || 0;
  renderBarList('reportByHour', data.porHora || [], 'hora');
  renderBarList('reportByDay', data.porDia || [], 'fecha');
  renderReportDetail(data.detalle || [], Number(data.total || summary.total || 0));
  if ($('reportPageInfo')) {
    $('reportPageInfo').textContent = `Pagina ${page} de ${totalPages}`;
  }
  if ($('reportPrevPage')) {
    $('reportPrevPage').disabled = page <= 1;
  }
  if ($('reportNextPage')) {
    $('reportNextPage').disabled = page >= totalPages;
  }
}

function renderBarList(containerId, rows, labelKey) {
  const container = $(containerId);
  if (!container) {
    return;
  }
  container.innerHTML = '';

  if (!rows.length) {
    container.innerHTML = '<p class="empty-state">Sin datos en este periodo.</p>';
    return;
  }

  const max = Math.max(...rows.map((row) => Number(row.total || 0)), 1);
  rows.forEach((row) => {
    const value = Number(row.total || 0);
    const percent = Math.max(4, Math.round((value / max) * 100));
    const item = document.createElement('div');
    item.className = 'bar-row';
    item.innerHTML = `
      <span>${escapeHtml(row[labelKey] || '-')}</span>
      <div class="bar-track"><i style="width:${percent}%"></i></div>
      <strong>${value}</strong>
    `;
    container.appendChild(item);
  });
}

function renderReportDetail(rows, total = rows.length) {
  const body = $('reportDetailBody');
  body.innerHTML = '';
  $('reportDetailCount').textContent = `${total} registros`;

  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="6">Sin tickets para mostrar.</td></tr>';
    return;
  }

  rows.forEach((ticket) => {
    const row = document.createElement('tr');
    const typeLabel = ticket.tipo_ticket === 'Emergencia' ? 'Procedimiento' : ticket.tipo_ticket;
    row.innerHTML = `
      <td>${escapeHtml(ticket.fecha)}</td>
      <td>${escapeHtml(ticket.hora)}</td>
      <td><span class="badge ${ticketTypeBadgeClass(ticket.tipo_ticket)}">${escapeHtml(typeLabel)}</span></td>
      <td><strong>${escapeHtml(ticket.codigo)}</strong></td>
      <td class="state-${escapeHtml(ticket.estado || 'emitido')}">${escapeHtml(ticket.estado || 'emitido')}</td>
      <td>${Number(ticket.reimpresiones || 0)}</td>
    `;
    body.appendChild(row);
  });
}

async function reprintTicket(id) {
  if (!confirm('Reimprimir este ticket?')) {
    return;
  }

  setLoading(true, 'Preparando reimpresion...');

  try {
    const data = await apiFetch('tickets.php?action=reimprimir', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id) }),
    });

    renderTicket(data.ticket);
    printTicket(data.ticket);
    await confirmPrinted(data.ticket);
  } catch (error) {
    $('emitMessage').textContent = error.message;
  } finally {
    setLoading(false);
  }
}

async function searchDailyReprint() {
  if (!canAction('reimprimir_dia')) {
    return;
  }

  const query = ($('dailyReprintSearchInput')?.value || '').trim();
  const body = $('dailyReprintBody');
  const message = $('dailyReprintMessage');
  if (!body) {
    return;
  }

  if (message) {
    message.textContent = '';
  }
  if (!query) {
    body.innerHTML = '<tr><td colspan="4">Ingresa un codigo o documento.</td></tr>';
    return;
  }

  body.innerHTML = '<tr><td colspan="4">Buscando...</td></tr>';

  try {
    const params = new URLSearchParams({
      action: 'reimpresion-dia-buscar',
      codigo: query,
    });
    const data = await apiFetch(`tickets.php?${params.toString()}`);
    renderDailyReprintResults(data.tickets || []);
  } catch (error) {
    body.innerHTML = '<tr><td colspan="4">No se pudo completar la busqueda.</td></tr>';
    if (message) {
      message.textContent = error.message;
    }
  }
}

function renderDailyReprintResults(rows) {
  const body = $('dailyReprintBody');
  if (!body) {
    return;
  }

  body.innerHTML = '';
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="4">No hay tickets de hoy con ese dato.</td></tr>';
    return;
  }

  rows.forEach((ticket) => {
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${escapeHtml(ticket.hora || '-')}</td>
      <td><strong>${escapeHtml(ticket.codigo || '-')}</strong></td>
      <td>${escapeHtml(historyDocumentText(ticket))}</td>
      <td>
        <div class="row-actions">
          <button type="button" data-action="daily-reprint" data-id="${ticket.id}">Reimprimir</button>
        </div>
      </td>
    `;
    body.appendChild(row);
  });
}

async function cancelTicket(id) {
  const reason = prompt('Motivo de anulacion');
  if (!reason) {
    return;
  }

  if (!confirm('Confirmas anular este ticket? Esta accion queda registrada.')) {
    return;
  }

  setLoading(true, 'Anulando ticket...');

  try {
    await apiFetch('tickets.php?action=anular', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id), motivo: reason }),
    });

    setLoading(false);
    await refreshOperationalData({
      history: true,
      ranges: true,
      queue: true,
      screen: true,
    });
  } catch (error) {
    $('emitMessage').textContent = error.message;
  } finally {
    setLoading(false);
  }
}

function renderRanges(ranges) {
  const container = $('rangesList');
  if (!container) {
    return;
  }
  container.innerHTML = '';

  const byType = new Map((ranges || []).map((range) => [range.tipo, range]));
  const types = ['Normal', 'Preferencial', 'Procedimiento'];

  types.forEach((type) => {
    const range = byType.get(type);

    const row = document.createElement('div');
    row.className = 'range-row range-row-missing';

    if (!range) {
      row.innerHTML = `
        <div>
          <strong>${type}</strong>
          <small>Sin rango activo</small>
        </div>
        <div class="bar"><span style="width:0%"></span></div>
        <div class="range-amount">
          <b>-</b>
          <small>pendiente</small>
        </div>
      `;
      container.appendChild(row);
      return;
    }

    const total = Math.max(0, Number(range.hasta || 0) - Number(range.desde || 0) + 1);
    const next = Number(range.ultimo || 0) < Number(range.desde || 0)
      ? Number(range.desde || 0)
      : Number(range.ultimo || 0) + 1;
    const used = Math.max(0, Math.min(total, next - Number(range.desde || 0)));
    const available = Math.max(0, Number(range.disponible ?? (Number(range.hasta || 0) - Number(range.ultimo || 0))));
    const percent = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 100;
    const isInactive = String(range.activo || '').toUpperCase() !== 'SI';
    const stateClass = isInactive || available <= 0 ? 'bad' : available <= 20 ? 'warn' : 'ok';

    row.className = `range-row range-row-${stateClass}`;
    row.innerHTML = `
      <div>
        <strong>${escapeHtml(type)}</strong>
        <small>${Number(range.desde || 0)} - ${Number(range.hasta || 0)} - ultimo ${Number(range.ultimo || 0)}</small>
      </div>
      <div class="bar"><span style="width:${percent}%"></span></div>
      <div class="range-amount">
        <b>${available}</b>
        <small>${isInactive ? 'inactivo' : available <= 0 ? 'agotado' : 'quedan'}</small>
      </div>
    `;
    container.appendChild(row);
  });
}

async function emitTicket(type) {
  syncDocumentTypeFromNumber();

  const documentType = $('documentType').value;
  const documentNumber = $('documentNumber').value.trim().toUpperCase();
  const specialtyId = $('specialtySelect').value;

  $('emitMessage').textContent = '';

  if (!canUseEmitMode(state.emitMode)) {
    $('emitMessage').textContent = state.emitMode === 'solo_impresion'
      ? 'No tienes permiso para emitir solo impresion.'
      : 'No tienes permiso para emitir tickets de atencion normal.';
    applyEmitModePermissions();
    return;
  }

  if (!documentNumber || !specialtyId) {
    $('emitMessage').textContent = 'Ingrese documento y especialidad.';
    if (!documentNumber) {
      goEmitStep(1);
    } else {
      goEmitStep(2);
      $('specialtySearch').focus();
    }
    return;
  }

  setEmitBusy(true, `Generando ticket ${type}...`);

  try {
    const data = await apiFetch('tickets.php?action=emitir', {
      method: 'POST',
      body: JSON.stringify({
        tipo_ticket: type,
        tipo_documento: documentType,
        numero_documento: documentNumber,
        especialidad_id: Number(specialtyId),
        modo_emision: state.emitMode,
      }),
    });

    const ticket = data.ticket || data;
    if (!ticket?.codigo) {
      throw new Error('El servidor no devolvio el ticket emitido correctamente.');
    }
    ticket.id = Number(ticket.id || 0);

    state.lastTicket = ticket;
    renderTicket(ticket);
    $('emitMessage').textContent = state.emitMode === 'solo_impresion'
      ? `Enviando ${ticket.codigo} a impresion sin cola...`
      : `Enviando ${ticket.codigo} a impresion...`;
    printTicket(ticket);

    try {
      await confirmPrinted(ticket);
      $('emitMessage').textContent = state.emitMode === 'solo_impresion'
        ? `Ticket ${ticket.codigo} impreso sin ingresar al turnero.`
        : `Ticket ${ticket.codigo} enviado a impresion.`;
    } catch (confirmError) {
      $('emitMessage').textContent = `Ticket ${ticket.codigo} emitido. ${confirmError.message}`;
    }

    resetEmitFlow();
    await refreshOperationalData({ ranges: true });
  } catch (error) {
    $('emitMessage').textContent = error.message;
  } finally {
    setEmitBusy(false);
  }
}

function setTicketButtons(enabled) {
  document.querySelectorAll('.ticket-button').forEach((button) => {
    button.disabled = !enabled;
  });
}

function renderTicket(ticket) {
  if (!$('previewCode')) {
    return;
  }

  $('previewCode').textContent = normalizeDisplayText(ticket.codigo);
  $('previewDoc').textContent = ticket.tipo_documento || ticket.numero_documento
    ? `${normalizeDisplayText(ticket.tipo_documento || '')}: ${normalizeDisplayText(ticket.numero_documento || '')}`
    : '';
  $('previewSpecialty').textContent = normalizeDisplayText(ticket.especialidad || '');
  $('previewDate').textContent = normalizeDisplayText(ticket.fecha);
  $('previewIcon').innerHTML = ticketTypeIcon(ticket.tipo_ticket || ticket.tipo);
}

function printTicket(ticket) {
  const titulo = escapeHtml(state.ticketFormat.titulo).replaceAll('\n', '<br>');
  const logoIzquierdo = escapeHtml(state.ticketFormat.logo_izquierdo_url);
  const logoDerecho = escapeHtml(state.ticketFormat.logo_derecho_url);

  $('printArea').innerHTML = `
    <article class="print-ticket">
      <div class="print-header ticket-brand">
        <img class="print-logo ticket-logo visible" src="${logoIzquierdo}" alt="">
        <div class="print-title hospital">${titulo}</div>
        <img class="print-logo ticket-logo visible" src="${logoDerecho}" alt="">
      </div>
      <div class="print-code">${escapeHtml(ticket.codigo)}</div>
      ${ticket.tipo_documento || ticket.numero_documento ? `<div class="print-doc">${escapeHtml(ticket.tipo_documento || '')}: ${escapeHtml(ticket.numero_documento || '')}</div>` : ''}
      ${ticket.especialidad ? `<div class="print-specialty">${escapeHtml(ticket.especialidad)}</div>` : ''}
      <div class="print-date">${escapeHtml(ticket.fecha)}</div>
      <div class="print-icon">${ticketTypeIcon(ticket.tipo_ticket || ticket.tipo)}</div>
    </article>
  `;

  setTimeout(() => window.print(), 150);
}

async function confirmPrinted(ticket) {
  const payload = {
    id: Number(ticket.id),
    codigo: ticket.codigo || '',
    solo_impresion: state.emitMode === 'solo_impresion',
  };

  if ((state.printSettings.automatica || '').toUpperCase() === 'SI') {
    const delay = Number(state.printSettings.retraso_ms || 600);
    await new Promise((resolve) => setTimeout(resolve, delay));
    await apiFetch('tickets.php?action=confirmar-impresion', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    await refreshOperationalData({ specialties: false, history: true });
    return;
  }

  const printed = confirm('El ticket se imprimio correctamente?');
  const action = printed ? 'confirmar-impresion' : 'cancelar-impresion';

  try {
    await apiFetch(`tickets.php?action=${action}`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    await refreshOperationalData({
      specialties: !printed,
      history: true,
      ranges: !printed,
      queue: true,
      screen: true,
    });
  } catch (error) {
    $('emitMessage').textContent = error.message;
  }
}

async function loadRangeWorkspace() {
  if (!canView('rangos')) {
    return;
  }

  await Promise.all([loadRangeCalendar(), loadPendingBatches()]);
}

async function loadRangeCalendar() {
  if (!canView('rangos')) {
    return;
  }

  const year = rangeState.monthCursor.getFullYear();
  const month = rangeState.monthCursor.getMonth() + 1;
  const data = await apiFetch(`tickets.php?action=calendario-rangos&anio=${year}&mes=${month}`);
  renderRangeCalendar(data);
}

function moveRangeMonth(delta) {
  if (!canView('rangos')) {
    return;
  }

  rangeState.monthCursor = new Date(rangeState.monthCursor.getFullYear(), rangeState.monthCursor.getMonth() + delta, 1);
  rangeState.selectedDate = `${rangeState.monthCursor.getFullYear()}-${String(rangeState.monthCursor.getMonth() + 1).padStart(2, '0')}-01`;
  loadRangeCalendar().catch((error) => {
    $('rangeMessage').textContent = error.message;
  });
}

function goCurrentRangeMonth() {
  if (!canView('rangos')) {
    return;
  }

  const today = new Date();
  rangeState.monthCursor = new Date(today.getFullYear(), today.getMonth(), 1);
  selectRangeDate(today.toISOString().slice(0, 10));
  loadRangeCalendar().catch((error) => {
    $('rangeMessage').textContent = error.message;
  });
}

function renderRangeCalendar(data) {
  rangeState.calendar = {};
  (data.dias || []).forEach((day) => {
    rangeState.calendar[day.fecha] = { ranges: day.rangos || [], batches: day.lotes || [] };
  });

  const monthDate = new Date(Number(data.anio), Number(data.mes) - 1, 1);
  const monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  $('rangesMonthTitle').textContent = `${monthNames[monthDate.getMonth()]} ${monthDate.getFullYear()}`;

  const first = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1);
  const last = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0);
  const offset = (first.getDay() + 6) % 7;
  const cells = [];

  for (let i = 0; i < offset; i++) {
    cells.push('<button type="button" class="calendar-day empty" disabled></button>');
  }

  for (let day = 1; day <= last.getDate(); day++) {
    const date = `${data.anio}-${String(data.mes).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const dayData = rangeState.calendar[date] || { ranges: [], batches: [] };
    cells.push(renderRangeCalendarDay(date, day, dayData));
  }

  $('rangesCalendar').innerHTML = cells.join('');
  selectRangeDate(rangeState.selectedDate);
}

function renderRangeCalendarDay(date, day, dayData) {
  const ranges = dayData.ranges || [];
  const batches = dayData.batches || [];
  const pref = ranges.find((range) => range.tipo === 'Preferencial');
  const normal = ranges.find((range) => range.tipo === 'Normal');
  const proc = ranges.find((range) => range.tipo === 'Procedimiento');
  const pending = batches.filter((batch) => String(batch.estado || '').toLowerCase() !== 'impreso').length;

  return `
    <button type="button" class="calendar-day ${date === rangeState.selectedDate ? 'selected' : ''}" data-range-date="${date}">
      <strong>${day}</strong>
      <span class="calendar-tags">
        ${rangeCalendarTag('P', pref)}
        ${rangeCalendarTag('N', normal)}
        ${rangeCalendarTag('PR', proc)}
        ${pending ? `<small class="range-tag lote">L ${pending}</small>` : ''}
      </span>
    </button>
  `;
}

function rangeCalendarTag(label, range) {
  if (!range) {
    return `<small class="range-tag muted">${label}</small>`;
  }

  const total = Math.max(1, Number(range.hasta || 0) - Number(range.desde || 0) + 1);
  const available = Number(range.disponible || 0);
  const className = available <= 0 ? 'bad' : available / total <= 0.2 ? 'warn' : 'ok';
  return `<small class="range-tag ${className}">${label} ${available}/${total}</small>`;
}

function selectRangeDate(date) {
  rangeState.selectedDate = date;
  $('rangeDate').value = date;
  $('batchDate').value = date;
  document.querySelectorAll('[data-range-date]').forEach((button) => {
    button.classList.toggle('selected', button.dataset.rangeDate === date);
  });
  syncRangeForm();
}

function currentRange() {
  const date = $('rangeDate').value || rangeState.selectedDate;
  const type = $('rangeType').value;
  const ranges = rangeState.calendar[date]?.ranges || [];
  return ranges.find((range) => range.tipo === type) || null;
}

function nextRangeNumber(range) {
  if (!range) {
    return 1;
  }

  return Number(range.ultimo || 0) < Number(range.desde || 0)
    ? Number(range.desde || 1)
    : Number(range.ultimo || 0) + 1;
}

function syncRangeForm() {
  const date = $('rangeDate').value || rangeState.selectedDate;
  const type = $('rangeType').value;
  $('batchDate').value = date;
  $('batchType').value = type;

  const range = currentRange();
  $('rangeSummaryDate').textContent = date || '-';
  $('rangeSummaryType').textContent = type || '-';

  if (!range) {
    $('rangeFrom').disabled = false;
    $('rangeFrom').value = 1;
    $('rangeNext').value = '';
    $('rangeSummaryTitle').textContent = 'Sin rango configurado';
    $('rangeSummaryRange').textContent = '-';
    $('rangeSummaryUsed').textContent = '0';
    $('rangeSummaryNext').textContent = '-';
    $('rangeSummaryAvailable').textContent = '0';
    $('rangeSummaryState').textContent = 'Sin rango';
    $('rangeSummaryState').className = 'range-state warn';
    updateBatchSuggestions();
    return;
  }

  const next = nextRangeNumber(range);
  const total = Math.max(0, Number(range.hasta || 0) - Number(range.desde || 0) + 1);
  const used = Math.max(0, Math.min(total, next - Number(range.desde || 0)));
  const available = Math.max(0, Number(range.hasta || 0) - next + 1);

  $('rangeFrom').value = range.desde;
  $('rangeTo').value = range.hasta;
  $('rangeNext').value = next <= Number(range.hasta || 0) ? next : '';
  $('rangeFrom').disabled = used > 0;
  $('rangeSummaryTitle').textContent = `${type} - ${date}`;
  $('rangeSummaryRange').textContent = `${range.desde} - ${range.hasta}`;
  $('rangeSummaryUsed').textContent = used;
  $('rangeSummaryNext').textContent = next;
  $('rangeSummaryAvailable').textContent = available;
  $('rangeSummaryState').textContent = available <= 0 ? 'Agotado' : used > 0 ? 'En uso' : 'Sin uso';
  $('rangeSummaryState').className = available <= 0 ? 'range-state bad' : used > 0 ? 'range-state ok' : 'range-state warn';
  updateBatchSuggestions();
}

function updateBatchSuggestions() {
  const range = currentRange();
  const next = nextRangeNumber(range);
  const to = Number(range?.hasta || 0);
  const available = range ? Math.max(0, to - next + 1) : 0;
  const canManageBatches = canAction('lotes_admin');

  $('generateBatchButton').disabled = !canManageBatches || !range || available <= 0 || rangeState.batchInProgress;
  $('partialFrom').value = range && available > 0 ? next : '';
  $('partialTo').value = range && available > 0 ? Math.min(to, next + 49) : '';
  $('partialTo').disabled = !canManageBatches || !range || available <= 0;
  $('generatePartialBatchButton').disabled = !canManageBatches || !range || available <= 0 || rangeState.batchInProgress;
  $('batchStatus').textContent = range
    ? available > 0 ? `Imprimira ${available} tickets pendientes: ${next}-${to}.` : `Rango agotado. Ultimo emitido: ${range.ultimo}.`
    : 'No hay rango configurado para esta fecha y tipo.';
  $('partialBatchStatus').textContent = range && available > 0
    ? `Siguiente disponible: ${next}. Disponible hasta ${to}.`
    : 'No hay tickets disponibles para impresion parcial.';
}

async function saveRangeAndRefresh() {
  if (!canAction('rangos_admin')) {
    return;
  }

  await saveRange();
  await loadRangeWorkspace();
  syncRangeForm();
}

async function generateBatch(partial = false) {
  if (!canAction('lotes_admin') || rangeState.batchInProgress) {
    return;
  }

  const payload = {
    fecha: $('batchDate').value || $('rangeDate').value,
    tipo: $('batchType').value || $('rangeType').value,
  };

  if (partial) {
    payload.desde = Number($('partialFrom').value);
    payload.hasta = Number($('partialTo').value);
    if (!payload.desde || !payload.hasta || payload.hasta < payload.desde) {
      $('partialBatchStatus').textContent = 'Rango parcial invalido.';
      return;
    }
  }

  const pauseMs = Math.max(300, Math.min(3000, Number((partial ? $('partialPauseMs') : $('batchPauseMs')).value || 900)));
  const confirmText = partial
    ? `Se imprimira ${payload.tipo} ${payload.desde}-${payload.hasta} para ${payload.fecha}. Continuar?`
    : `Se imprimiran todos los tickets pendientes de ${payload.tipo} para ${payload.fecha}. Continuar?`;

  if (!confirm(confirmText)) {
    return;
  }

  setLoading(true, 'Generando lote...');
  try {
    const batch = await apiFetch('tickets.php?action=generar-lote', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    await loadPendingBatches();
    await printBatchSequential(batch, pauseMs);
  } catch (error) {
    $('batchStatus').textContent = error.message;
  } finally {
    setLoading(false);
  }
}

async function printBatchSequential(batch, pauseMs) {
  const tickets = batch.tickets || [];
  if (!tickets.length) {
    $('batchStatus').textContent = 'El lote no tiene tickets para imprimir.';
    return;
  }

  rangeState.batchInProgress = true;
  rangeState.cancelBatch = false;
  $('cancelBatchButton').disabled = false;
  updateBatchSuggestions();

  for (let index = 0; index < tickets.length; index++) {
    if (rangeState.cancelBatch) {
      $('batchStatus').textContent = 'Impresion cancelada. El lote queda pendiente para retomar.';
      break;
    }

    const ticket = tickets[index];
    renderTicket(ticket);
    $('batchStatus').textContent = `Imprimiendo ${index + 1}/${tickets.length}: ${ticket.codigo}`;
    printTicket(ticket);
    await apiFetch('tickets.php?action=actualizar-avance-lote', {
      method: 'POST',
      body: JSON.stringify({ loteId: Number(batch.loteId), ultimoEnviado: Number(ticket.numero) }),
    });
    await new Promise((resolve) => setTimeout(resolve, pauseMs));
  }

  if (!rangeState.cancelBatch) {
    const printed = confirm(`El lote ${batch.tipo} ${batch.desde}-${batch.hasta} se imprimio correctamente?`);
    await apiFetch('tickets.php?action=marcar-lote', {
      method: 'POST',
      body: JSON.stringify({ loteId: Number(batch.loteId), impreso: printed }),
    });
    $('batchStatus').textContent = printed ? 'Lote marcado como impreso.' : 'Lote pendiente de confirmacion.';
  }

  rangeState.batchInProgress = false;
  rangeState.cancelBatch = false;
  $('cancelBatchButton').disabled = true;
  await Promise.all([loadSummary(), loadHistory(), loadRangeWorkspace()]);
  updateBatchSuggestions();
}

async function loadPendingBatches() {
  const body = $('pendingBatchesBody');
  if (!canAction('lotes_admin') || !body) {
    return;
  }

  body.innerHTML = '<tr><td colspan="6">Cargando...</td></tr>';
  try {
    const data = await apiFetch('tickets.php?action=lotes-pendientes');
    renderPendingBatches(data.lotes || []);
  } catch (error) {
    body.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message)}</td></tr>`;
  }
}

function renderPendingBatches(batches) {
  const body = $('pendingBatchesBody');
  if (!batches.length) {
    body.innerHTML = '<tr><td colspan="6">No hay lotes pendientes.</td></tr>';
    return;
  }

  body.innerHTML = batches.map((batch) => `
    <tr>
      <td>${escapeHtml(batch.fecha)}</td>
      <td><span class="badge ${ticketTypeBadgeClass(batch.tipo)}">${escapeHtml(batch.tipo)}</span></td>
      <td><strong>${Number(batch.desde)}-${Number(batch.hasta)}</strong></td>
      <td>${Number(batch.cantidad)}</td>
      <td>${escapeHtml(batch.estado)}${renderBatchProgress(batch)}</td>
      <td>
        <div class="row-actions">
          <button type="button" data-batch-action="reprint" data-id="${Number(batch.id)}" data-resume="${Number(batch.retomarDesde || 0)}">${Number(batch.retomarDesde || 0) ? 'Retomar' : 'Reimprimir'}</button>
          <button type="button" data-batch-action="printed" data-id="${Number(batch.id)}">Marcar impreso</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderBatchProgress(batch) {
  const last = Number(batch.ultimoEnviado || 0);
  const resume = Number(batch.retomarDesde || 0);
  if (!last && !resume) {
    return '';
  }

  return `<small class="batch-progress">Ultimo: ${last || '-'}${resume ? ` / Retomar: ${resume}` : ''}</small>`;
}

async function reprintPendingBatch(id, resumeFrom) {
  let start = Number(resumeFrom || 0);
  if (start) {
    const value = prompt('Retomar desde el numero:', String(start));
    if (value === null) {
      return;
    }
    start = Number(value);
  } else if (!confirm('Se reimprimira este lote completo. Continuar?')) {
    return;
  }

  setLoading(true, 'Preparando reimpresion...');
  try {
    const batch = await apiFetch('tickets.php?action=lote-reimpresion', {
      method: 'POST',
      body: JSON.stringify({ loteId: Number(id), retomarDesde: start }),
    });
    const pauseMs = Math.max(300, Math.min(3000, Number($('batchPauseMs').value || 900)));
    await printBatchSequential(batch, pauseMs);
  } catch (error) {
    $('batchStatus').textContent = error.message;
  } finally {
    setLoading(false);
  }
}

async function markPendingBatchPrinted(id) {
  if (!confirm('Confirmas que este lote ya se imprimio correctamente?')) {
    return;
  }

  try {
    await apiFetch('tickets.php?action=marcar-lote', {
      method: 'POST',
      body: JSON.stringify({ loteId: Number(id), impreso: true }),
    });
    await loadRangeWorkspace();
    $('batchStatus').textContent = 'Lote marcado como impreso.';
  } catch (error) {
    $('batchStatus').textContent = error.message;
  }
}

async function loadQueue() {
  if (!canView('turnero') || !$('queueActiveList')) {
    return;
  }

  if (state.queueLoading) {
    state.queueRefreshPending = true;
    return;
  }

  state.queueLoading = true;

  try {
    const data = await apiFetch('tickets.php?action=cola');
    state.queue = {
      activos: data.activos || [],
      disponibles: data.disponibles || [],
      resumen: data.resumen || {},
    };
    renderQueue(data.fecha || todayInputValue());
  } finally {
    state.queueLoading = false;
    if (state.queueRefreshPending) {
      state.queueRefreshPending = false;
      window.setTimeout(() => loadQueue().catch(() => {}), 150);
    }
  }
}

function startQueueRefresh() {
  stopQueueRefresh();
  scheduleQueueRefresh();
}

function scheduleQueueRefresh() {
  if (activeViewName() !== 'turnero') {
    return;
  }

  state.queueTimer = window.setTimeout(() => {
    state.queueTimer = null;
    if (activeViewName() !== 'turnero') {
      return;
    }

    if (document.hidden) {
      scheduleQueueRefresh();
      return;
    }

    loadQueue()
      .then(() => {
        if (canView('ventanillas')) {
          return loadWindowsAdmin();
        }
        return null;
      })
      .catch(() => {})
      .finally(() => scheduleQueueRefresh());
  }, queueRefreshDelay());
}

function queueRefreshDelay() {
  if (document.hidden) {
    return 12000;
  }

  const summary = state.queue.resumen || {};
  const active = Number(summary.en_cola || 0) + Number(summary.llamando || 0) + Number(summary.atendiendo || 0);
  const recentChange = Date.now() - Number(state.queueLastChangeAt || 0) < 15000;

  if (recentChange || active > 0) {
    return 1200;
  }

  return 5000;
}

function stopQueueRefresh() {
  if (state.queueTimer) {
    window.clearTimeout(state.queueTimer);
    state.queueTimer = null;
  }
}

async function loadWindowsAdmin() {
  const panel = $('windowsAdminPanel');
  if (!panel) {
    return;
  }

  panel.hidden = !canView('ventanillas');
  if (panel.hidden) {
    return;
  }

  if (state.windowsAdminLoading) {
    return;
  }

  state.windowsAdminLoading = true;

  try {
    const data = await apiFetch('tickets.php?action=ventanillas');
    state.windowsAdmin = {
      ventanillas: data.ventanillas || [],
      usuarios: data.usuarios || [],
    };
    renderWindowsAdmin();
  } catch (error) {
    $('windowsAdminMessage').textContent = error.message;
  } finally {
    state.windowsAdminLoading = false;
  }
}

async function loadScreenDevices() {
  const panel = $('screenDevicesPanel');
  if (!panel) {
    return;
  }

  panel.hidden = !canView('pantallas');
  if (panel.hidden) {
    return;
  }

  try {
    const data = await apiFetch('tickets.php?action=pantallas-dispositivos');
    state.screenDevices = data.dispositivos || [];
    state.screenTicker = data.ticker || '';
    renderScreenDevices();
    renderScreenTickerConfig();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

function renderScreenTickerConfig() {
  const control = $('screenGlobalTickerInput');
  const wrapper = control?.closest('.screen-ticker-config');
  const canManage = canAction('pantallas_admin');
  if (wrapper) {
    wrapper.hidden = !canManage;
  }
  if (!control) {
    return;
  }
  control.value = state.screenTicker || '';
  control.disabled = !canManage;
  if ($('screenGlobalTickerSaveButton')) {
    $('screenGlobalTickerSaveButton').hidden = !canManage;
  }
}

function renderScreenDevices() {
  const list = $('screenDevicesList');
  if (!list) {
    return;
  }

  const canManage = canAction('pantallas_admin');
  const form = $('screenDeviceCreateButton')?.closest('.window-admin-form');
  if (form) {
    form.hidden = !canManage;
  }

  list.innerHTML = '';
  if (!state.screenDevices.length) {
    list.innerHTML = '<p class="empty-state">No hay pantallas registradas.</p>';
    return;
  }

  state.screenDevices.forEach((device) => {
    const key = state.screenDeviceKeys[device.id] || '';
    const status = screenDeviceStatusLabel(device.estado_conexion);
    const url = key ? publicScreenUrl(device.codigo, key) : '';
    const format = device.formato || 'turnos_clasico';
    const card = document.createElement('article');
    card.className = `screen-device-card is-${device.estado_conexion || 'sin_pulso'}`;
    card.innerHTML = `
      <div>
        <span>${escapeHtml(device.codigo)}</span>
        <strong>${escapeHtml(device.nombre)}</strong>
        <small>${escapeHtml(device.ubicacion || 'Sin ubicacion')}</small>
        <div class="screen-device-edit-fields" hidden>
          <label>
            Nombre
            <input type="text" value="${escapeHtml(device.nombre)}" data-screen-name-input="${device.id}" maxlength="120">
          </label>
          <label>
            Ubicacion
            <input type="text" value="${escapeHtml(device.ubicacion || '')}" data-screen-location-input="${device.id}" maxlength="160" placeholder="Sin ubicacion">
          </label>
        </div>
        <small>Formato: ${escapeHtml(screenDeviceFormatLabel(device.formato))}</small>
        <label class="screen-device-format-control">
          Contenido
          <select data-screen-format-select="${device.id}">
            <option value="turnos_clasico" ${format === 'turnos_clasico' || format === 'turnero' ? 'selected' : ''}>Turnero</option>
            <option value="citas_mes_doctor" ${format === 'citas_mes_doctor' ? 'selected' : ''}>Citas del mes por doctor</option>
            <option value="citas_mes_doctor_limpio" ${format === 'citas_mes_doctor_limpio' ? 'selected' : ''}>Citas del mes limpio</option>
            <option value="citas_mes_doctor_aviso" ${format === 'citas_mes_doctor_aviso' ? 'selected' : ''}>Citas del mes con aviso</option>
            <option value="imagen_url" ${format === 'imagen_url' ? 'selected' : ''}>Imagen por URL</option>
          </select>
        </label>
        <label class="screen-device-media-control" ${format === 'imagen_url' ? '' : 'hidden'}>
          URL de imagen
          <input type="url" value="${escapeHtml(device.media_url || '')}" data-screen-media-url="${device.id}" placeholder="https://...">
        </label>
      </div>
      <div class="screen-device-status">
        <b>${escapeHtml(status)}</b>
        <small>${device.ultimo_pulso_en ? `Ultimo pulso: ${escapeHtml(device.ultimo_pulso_en)}` : 'Sin pulso registrado'}</small>
        <small>${device.ultima_ip ? `IP: ${escapeHtml(device.ultima_ip)}` : 'IP pendiente'}</small>
      </div>
      <div class="row-actions">
        <button type="button" class="light" data-edit-screen-details="${device.id}">Editar</button>
        <button type="button" class="light" data-save-screen-format="${device.id}">Guardar contenido</button>
        ${url
          ? `<button type="button" class="light" data-open-screen-url="${device.id}">Abrir URL</button>`
          : ''}
        <details class="screen-action-menu" data-screen-actions-menu>
          <summary>Mas</summary>
          <div>
            ${url
              ? `<button type="button" data-copy-screen-url="${device.id}">Copiar URL</button>
                 <button type="button" data-regenerate-screen-key="${device.id}">Regenerar y copiar URL</button>
                 <button type="button" data-regenerate-open-screen-key="${device.id}">Regenerar y abrir URL</button>`
              : `<button type="button" data-regenerate-screen-key="${device.id}">Regenerar y copiar URL</button>
                 <button type="button" data-regenerate-open-screen-key="${device.id}">Regenerar y abrir URL</button>`}
            <button type="button" class="danger" data-delete-screen-device="${device.id}">Eliminar</button>
          </div>
        </details>
      </div>
    `;
    card.querySelectorAll('[data-edit-screen-details], [data-save-screen-format], [data-open-screen-url], [data-copy-screen-url], [data-regenerate-screen-key], [data-regenerate-open-screen-key], [data-delete-screen-device], [data-screen-actions-menu]').forEach((control) => {
      control.hidden = !canManage;
    });
    card.querySelectorAll('[data-screen-format-select]').forEach((control) => {
      control.disabled = !canManage;
    });
    card.querySelectorAll('[data-screen-media-url]').forEach((control) => {
      control.disabled = !canManage;
    });
    list.appendChild(card);
  });
}

function updateScreenMediaControls(id, format) {
  const mediaInput = Array.from(document.querySelectorAll('[data-screen-media-url]'))
    .find((item) => String(item.dataset.screenMediaUrl) === String(id));
  const control = mediaInput?.closest('.screen-device-media-control');
  if (control) {
    control.hidden = format !== 'imagen_url';
  }
}

async function saveGlobalScreenTicker() {
  const input = $('screenGlobalTickerInput');
  const ticker = input?.value.trim() || '';
  $('screenDevicesMessage').textContent = '';

  try {
    const data = await apiFetch('tickets.php?action=guardar-ticker-pantallas', {
      method: 'POST',
      body: JSON.stringify({ ticker }),
    });
    state.screenTicker = data.ticker || ticker;
    renderScreenTickerConfig();
    $('screenDevicesMessage').textContent = 'Ticker global actualizado. Las pantallas lo veran en el siguiente refresco.';
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

function openScreenDetailsModal(id) {
  const device = state.screenDevices.find((item) => Number(item.id) === Number(id));
  if (!device) {
    $('screenDevicesMessage').textContent = 'Pantalla no encontrada.';
    return;
  }

  state.screenDeviceEditingId = Number(id);
  $('screenDeviceEditId').value = String(id);
  $('screenDeviceEditCode').textContent = device.codigo || 'Pantalla';
  $('screenDeviceEditName').value = device.nombre || '';
  $('screenDeviceEditLocation').value = device.ubicacion || '';
  $('screenDeviceEditModal').hidden = false;
  $('screenDeviceEditName').focus();
}

function closeScreenDetailsModal() {
  state.screenDeviceEditingId = null;
  if ($('screenDeviceEditModal')) {
    $('screenDeviceEditModal').hidden = true;
  }
}

async function saveScreenDetails() {
  const id = Number($('screenDeviceEditId')?.value || state.screenDeviceEditingId || 0);
  const nameInput = $('screenDeviceEditName');
  const locationInput = $('screenDeviceEditLocation');
  const name = nameInput?.value.trim() || '';
  const location = locationInput?.value.trim() || '';
  $('screenDevicesMessage').textContent = '';

  if (!name) {
    $('screenDevicesMessage').textContent = 'Ingresa el nombre de la pantalla.';
    nameInput?.focus();
    return;
  }

  try {
    await apiFetch('tickets.php?action=actualizar-pantalla-dispositivo', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id), nombre: name, ubicacion: location }),
    });
    closeScreenDetailsModal();
    $('screenDevicesMessage').textContent = 'Datos de pantalla actualizados.';
    await loadScreenDevices();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

function screenDeviceStatusLabel(status) {
  return {
    en_linea: 'En linea',
    inestable: 'Inestable',
    sin_senal: 'Sin senal',
    sin_pulso: 'Sin pulso',
  }[status] || 'Sin pulso';
}

function screenDeviceFormatLabel(format) {
  return {
    turnos_clasico: 'Turnero',
    turnero: 'Turnero',
    citas_mes_doctor: 'Citas del mes por doctor',
    citas_mes_doctor_limpio: 'Citas del mes limpio',
    citas_mes_doctor_aviso: 'Citas del mes con aviso',
    imagen_url: 'Imagen por URL',
  }[format] || 'No disponible';
}

function publicScreenUrl(code, key) {
  const url = new URL('/ti_neo/public/pantalla.php', window.location.origin);
  url.searchParams.set('device', code);
  url.searchParams.set('key', key);
  return url.toString();
}

async function createScreenDevice() {
  const name = $('screenDeviceName')?.value.trim() || '';
  const location = $('screenDeviceLocation')?.value.trim() || '';
  const format = $('screenDeviceFormat')?.value || 'turnos_clasico';
  $('screenDevicesMessage').textContent = '';

  if (!name) {
    $('screenDevicesMessage').textContent = 'Ingresa el nombre de la pantalla.';
    return;
  }

  try {
    const data = await apiFetch('tickets.php?action=crear-pantalla-dispositivo', {
      method: 'POST',
      body: JSON.stringify({ nombre: name, ubicacion: location, formato: format }),
    });
    if (data.device?.id && data.key) {
      state.screenDeviceKeys[data.device.id] = data.key;
      await copyText(publicScreenUrl(data.device.codigo, data.key));
      $('screenDevicesMessage').textContent = 'Pantalla creada. URL copiada al portapapeles.';
    }
    $('screenDeviceName').value = '';
    $('screenDeviceLocation').value = '';
    if ($('screenDeviceFormat')) {
      $('screenDeviceFormat').value = 'turnos_clasico';
    }
    await loadScreenDevices();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

async function regenerateScreenKey(id, openAfter = false) {
  $('screenDevicesMessage').textContent = '';

  try {
    const data = await apiFetch('tickets.php?action=regenerar-clave-pantalla', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id) }),
    });
    if (data.device?.id && data.key) {
      state.screenDeviceKeys[data.device.id] = data.key;
      const url = publicScreenUrl(data.device.codigo, data.key);
      if (openAfter) {
        window.open(url, '_blank', 'noopener,noreferrer');
        $('screenDevicesMessage').textContent = 'Clave regenerada. URL abierta en una nueva pestana.';
      } else {
        await copyText(url);
        $('screenDevicesMessage').textContent = 'Clave regenerada. URL copiada al portapapeles.';
      }
    }
    await loadScreenDevices();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

async function saveScreenFormat(id) {
  const select = Array.from(document.querySelectorAll('[data-screen-format-select]'))
    .find((item) => String(item.dataset.screenFormatSelect) === String(id));
  const format = select?.value || 'turnos_clasico';
  const mediaInput = Array.from(document.querySelectorAll('[data-screen-media-url]'))
    .find((item) => String(item.dataset.screenMediaUrl) === String(id));
  const mediaUrl = mediaInput?.value.trim() || '';
  $('screenDevicesMessage').textContent = '';

  try {
    await apiFetch('tickets.php?action=actualizar-formato-pantalla', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id), formato: format, media_url: mediaUrl }),
    });
    $('screenDevicesMessage').textContent = 'Contenido actualizado. La pantalla cambiara en el siguiente refresco.';
    await loadScreenDevices();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

async function deleteScreenDevice(id) {
  const device = state.screenDevices.find((item) => Number(item.id) === Number(id));
  const name = device?.nombre || device?.codigo || 'esta pantalla';
  if (!window.confirm(`Eliminar ${name}? Esta URL dejara de funcionar.`)) {
    return;
  }

  $('screenDevicesMessage').textContent = '';
  try {
    await apiFetch('tickets.php?action=eliminar-pantalla-dispositivo', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id) }),
    });
    delete state.screenDeviceKeys[id];
    $('screenDevicesMessage').textContent = 'Pantalla eliminada.';
    await loadScreenDevices();
  } catch (error) {
    $('screenDevicesMessage').textContent = error.message;
  }
}

async function copyScreenUrl(id) {
  const device = state.screenDevices.find((item) => Number(item.id) === Number(id));
  const key = state.screenDeviceKeys[id] || '';
  if (!device || !key) {
    $('screenDevicesMessage').textContent = 'Regenera la clave para copiar una URL nueva.';
    return;
  }

  await copyText(publicScreenUrl(device.codigo, key));
  $('screenDevicesMessage').textContent = 'URL copiada al portapapeles.';
}

function openScreenUrl(id) {
  const device = state.screenDevices.find((item) => Number(item.id) === Number(id));
  const key = state.screenDeviceKeys[id] || '';
  if (!device || !key) {
    $('screenDevicesMessage').textContent = 'Regenera la clave para abrir una URL nueva.';
    return;
  }

  window.open(publicScreenUrl(device.codigo, key), '_blank', 'noopener,noreferrer');
    $('screenDevicesMessage').textContent = 'URL abierta en una nueva pestana.';
}

async function copyText(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const input = document.createElement('textarea');
  input.value = text;
  input.setAttribute('readonly', 'readonly');
  input.style.position = 'fixed';
  input.style.left = '-999px';
  document.body.appendChild(input);
  input.select();
  document.execCommand('copy');
  input.remove();
}

function renderWindowsAdmin() {
  const userSelect = $('windowAdminUserSelect');
  const windowSelect = $('windowAdminNumberSelect');
  const list = $('windowsAdminList');
  if (!userSelect || !windowSelect || !list) {
    return;
  }

  const canManage = canAction('ventanillas_admin');
  const form = $('windowAdminAssignButton')?.closest('.window-admin-form');
  if (form) {
    form.hidden = !canManage;
  }

  userSelect.innerHTML = '<option value="">Selecciona usuario</option>';
  state.windowsAdmin.usuarios.forEach((user) => {
    const option = document.createElement('option');
    option.value = String(user.id);
    option.textContent = user.nombre;
    userSelect.appendChild(option);
  });

  const previousWindowValue = windowSelect.value;
  windowSelect.innerHTML = '<option value="">Ventanilla</option>';
  state.windowsAdmin.ventanillas.forEach((windowItem) => {
    const assignment = windowItem.asignacion;
    const ticket = windowItem.ticket || null;
    const occupied = Boolean(ticket?.id);
    const windowNumber = Number(windowItem.numero);
    const option = document.createElement('option');
    option.value = String(windowNumber);
    option.disabled = Boolean(assignment || occupied);
    option.textContent = option.disabled
      ? `Ventanilla ${windowNumber} - en uso`
      : `Ventanilla ${windowNumber}`;
    windowSelect.appendChild(option);
  });
  const previousWindowOption = Array.from(windowSelect.options).find((option) => option.value === previousWindowValue && !option.disabled);
  if (previousWindowValue && previousWindowOption) {
    windowSelect.value = previousWindowValue;
  } else {
    windowSelect.value = '';
  }

  list.innerHTML = '';
  state.windowsAdmin.ventanillas.forEach((windowItem) => {
    const assignment = windowItem.asignacion;
    const ticket = windowItem.ticket || null;
    const occupied = Boolean(ticket?.id);
    const windowNumber = Number(windowItem.numero);
    const queueMode = windowItem.modo_cola || 'automatico';
    const windowState = occupied ? (ticket.cola_estado || 'ocupado') : assignment ? 'disponible' : 'sin_asignar';
    const statusLabel = occupied ? queueStateLabel(ticket.cola_estado) : assignment ? 'Disponible' : 'Sin asignar';
    const statusDetail = occupied
      ? `${escapeHtml(ticket.codigo)} - ${escapeHtml(ticket.especialidad || '-')}`
      : assignment
        ? 'Sin ticket activo'
        : 'Disponible para asignar';
    const card = document.createElement('article');
    card.className = `window-admin-card ${occupied ? 'is-occupied' : assignment ? 'is-assigned' : 'is-free'} state-${windowState}`;
    card.innerHTML = `
      <div>
        <span>Ventanilla ${windowNumber}</span>
        <strong>${assignment ? escapeHtml(assignment.usuario) : 'Sin asignar'}</strong>
        <small>${escapeHtml(windowItem.modo)}</small>
      </div>
      <div class="window-admin-status">
        <b><i class="window-status-dot" aria-hidden="true"></i>${escapeHtml(statusLabel)}</b>
        <small>${statusDetail}</small>
      </div>
      <label class="window-mode-control">
        <span>Cola</span>
        <select data-window-mode="${windowNumber}">
          <option value="automatico" ${queueMode === 'automatico' ? 'selected' : ''}>Automatico</option>
          <option value="preferencial" ${queueMode === 'preferencial' ? 'selected' : ''}>Preferencial</option>
          <option value="normal" ${queueMode === 'normal' ? 'selected' : ''}>Normal</option>
          <option value="manual" ${queueMode === 'manual' ? 'selected' : ''}>Manual</option>
        </select>
      </label>
      ${assignment ? `<button type="button" class="light" data-release-window="${windowNumber}" ${occupied ? 'disabled' : ''}>Liberar</button>` : ''}
    `;
    card.querySelectorAll('[data-window-mode]').forEach((control) => {
      control.disabled = !canManage;
    });
    card.querySelectorAll('[data-release-window]').forEach((control) => {
      control.hidden = !canManage;
    });
    list.appendChild(card);
  });
}

async function updateWindowModeAdmin(windowNumber, mode) {
  $('windowsAdminMessage').textContent = '';

  try {
    const data = await apiFetch('tickets.php?action=configurar-modo-ventanilla', {
      method: 'POST',
      body: JSON.stringify({ ventanilla: Number(windowNumber), modo: mode }),
    });
    state.windowsAdmin = {
      ventanillas: data.ventanillas || [],
      usuarios: data.usuarios || state.windowsAdmin.usuarios,
    };
    $('windowsAdminMessage').textContent = 'Modo de cola actualizado.';
    renderWindowsAdmin();
  } catch (error) {
    $('windowsAdminMessage').textContent = error.message;
    renderWindowsAdmin();
  }
}

async function assignWindowAdmin() {
  const userId = Number($('windowAdminUserSelect')?.value || 0);
  const windowNumber = Number($('windowAdminNumberSelect')?.value || 0);
  $('windowsAdminMessage').textContent = '';

  if (!userId || !windowNumber) {
    $('windowsAdminMessage').textContent = 'Selecciona usuario y ventanilla.';
    return;
  }

  try {
    const data = await apiFetch('tickets.php?action=asignar-ventanilla', {
      method: 'POST',
      body: JSON.stringify({ usuario_id: userId, ventanilla: windowNumber }),
    });
    state.windowsAdmin = {
      ventanillas: data.ventanillas || [],
      usuarios: data.usuarios || state.windowsAdmin.usuarios,
    };
    $('windowsAdminMessage').textContent = 'Ventanilla asignada.';
    renderWindowsAdmin();
  } catch (error) {
    $('windowsAdminMessage').textContent = error.message;
  }
}

async function releaseWindowAdmin(windowNumber) {
  $('windowsAdminMessage').textContent = '';

  try {
    const data = await apiFetch('tickets.php?action=liberar-ventanilla', {
      method: 'POST',
      body: JSON.stringify({ ventanilla: Number(windowNumber) }),
    });
    state.windowsAdmin = {
      ventanillas: data.ventanillas || [],
      usuarios: data.usuarios || state.windowsAdmin.usuarios,
    };
    $('windowsAdminMessage').textContent = 'Ventanilla liberada.';
    renderWindowsAdmin();
    notifyQueueChanged();
    await refreshOperationalData({
      summary: false,
      specialties: false,
      queue: true,
      screen: true,
    });
  } catch (error) {
    $('windowsAdminMessage').textContent = error.message;
  }
}

function renderQueue(date) {
  const summary = state.queue.resumen || {};
  $('queueOperationalDate').textContent = `Fecha operativa: ${date}`;
  $('queueStatWaiting').textContent = summary.en_cola || 0;
  $('queueStatCalling').textContent = summary.llamando || 0;
  $('queueStatServing').textContent = summary.atendiendo || 0;
  $('queueStatDone').textContent = summary.atendido || 0;
  $('queueStatAbsent').textContent = summary.ausente || 0;
  $('queueActiveCount').textContent = `${state.queue.activos.length} tickets`;
  renderQueueActiveList();
  renderQueueAvailableList();
}

function renderQueueActiveList() {
  const container = $('queueActiveList');
  container.innerHTML = '';

  if (!state.queue.activos.length) {
    container.innerHTML = '<p class="empty-state">No hay tickets en cola.</p>';
    return;
  }

  state.queue.activos.forEach((ticket) => {
    const item = document.createElement('article');
    item.className = `queue-item queue-${ticket.cola_estado}`;
    item.innerHTML = `
      <div>
        <span class="badge ${ticketTypeBadgeClass(ticket.tipo_ticket)}">${escapeHtml(ticket.tipo_ticket)}</span>
        <strong>${escapeHtml(ticket.codigo)}</strong>
        <small>${escapeHtml(ticket.especialidad || '-')} - ${escapeHtml(queueDocumentText(ticket))}</small>
      </div>
      <div class="queue-state">${queueStateLabel(ticket.cola_estado)}</div>
      <div class="row-actions">
        ${ticket.cola_estado !== 'llamando' ? `<button type="button" class="light" data-queue-action="llamando" data-id="${ticket.id}">Llamar</button>` : ''}
        ${ticket.cola_estado !== 'atendiendo' ? `<button type="button" data-queue-action="atendiendo" data-id="${ticket.id}">Atender</button>` : ''}
        <button type="button" class="light" data-queue-action="atendido" data-id="${ticket.id}">Finalizar</button>
        <button type="button" class="danger" data-queue-action="ausente" data-id="${ticket.id}">Ausente</button>
      </div>
    `;
    container.appendChild(item);
  });
}

function renderQueueAvailableList() {
  const container = $('queueAvailableList');
  const query = ($('queueSearchInput')?.value || '').trim().toLowerCase();
  const available = state.queue.disponibles.filter((ticket) => {
    if (!query) {
      return true;
    }

    return [
      ticket.codigo,
      ticket.numero_documento,
      ticket.especialidad,
      ticket.tipo_ticket,
    ].some((value) => String(value || '').toLowerCase().includes(query));
  });

  $('queueAvailableCount').textContent = `${available.length} impresos`;
  container.innerHTML = '';

  if (!available.length) {
    container.innerHTML = '<p class="empty-state">No hay tickets impresos pendientes de cola.</p>';
    return;
  }

  available.forEach((ticket) => {
    const item = document.createElement('article');
    item.className = 'queue-item';
    item.innerHTML = `
      <div>
        <span class="badge ${ticketTypeBadgeClass(ticket.tipo_ticket)}">${escapeHtml(ticket.tipo_ticket)}</span>
        <strong>${escapeHtml(ticket.codigo)}</strong>
        <small>${escapeHtml(ticket.especialidad || '-')} - ${escapeHtml(queueDocumentText(ticket))}</small>
      </div>
      <button type="button" data-enqueue-id="${ticket.id}">Enviar</button>
    `;
    container.appendChild(item);
  });
}

function queueDocumentText(ticket) {
  return `${ticket.tipo_documento || ''} ${ticket.numero_documento || ''}`.trim() || 'Sin documento';
}

function queueStateLabel(stateName) {
  const labels = {
    sin_cola: 'Sin cola',
    en_cola: 'En cola',
    llamando: 'Llamando',
    atendiendo: 'Atendiendo',
    atendido: 'Atendido',
    ausente: 'Ausente',
  };
  return labels[stateName] || stateName || '-';
}

async function enqueueTicket(id) {
  $('queueMessage').textContent = '';

  try {
    await apiFetch('tickets.php?action=encolar', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id) }),
    });
    notifyQueueChanged();
    await loadQueue();
  } catch (error) {
    $('queueMessage').textContent = error.message;
  }
}

async function callNextTicket() {
  const windowNumber = Number(state.turnero.window || 0);
  if (windowNumber < 1) {
    $('queueMessage').textContent = 'Selecciona ventanilla en el panel flotante.';
    return;
  }

  $('queueMessage').textContent = '';
  $('queueCallNextButton').disabled = true;

  try {
    const data = await apiFetch('tickets.php?action=llamar-siguiente', {
      method: 'POST',
      body: JSON.stringify({ fecha: todayInputValue(), ventanilla: windowNumber }),
    });
    $('queueMessage').textContent = data.ticket?.codigo
      ? `Llamando ${data.ticket.codigo}`
      : 'No hay tickets en cola para llamar.';
    notifyQueueChanged();
    await loadQueue();
  } catch (error) {
    $('queueMessage').textContent = error.message;
  } finally {
    $('queueCallNextButton').disabled = false;
  }
}

async function updateQueueTicketState(id, nextState) {
  $('queueMessage').textContent = '';

  try {
    await apiFetch('tickets.php?action=actualizar-cola', {
      method: 'POST',
      body: JSON.stringify({ id: Number(id), estado: nextState }),
    });
    notifyQueueChanged();
    if (['atendido', 'ausente'].includes(nextState)) {
      await refreshOperationalData({ ranges: true, screen: true });
    }
    await loadQueue();
  } catch (error) {
    $('queueMessage').textContent = error.message;
  }
}

async function loadScreen() {
  if (!canView('pantalla') || !$('screenRecentList')) {
    return;
  }

  if (state.screenLoading) {
    return;
  }

  state.screenLoading = true;

  try {
    const data = await apiFetch('tickets.php?action=pantalla');
    state.screenEtag = '';
    renderScreen(data || {});
  } finally {
    state.screenLoading = false;
  }
}

function renderScreen(data) {
  const calls = data.llamados || [];
  const current = calls[0] || null;
  const currentCode = current?.codigo || '';
  const currentState = current?.cola_estado || '';
  const updatedAt = data.actualizado_en || '';
  const changed = Boolean(currentCode && (
    currentCode !== state.screenLastCode
    || currentState !== state.screenLastState
    || updatedAt !== state.screenLastUpdatedAt
  ));
  $('screenOperationalDate').textContent = `Previsualizacion local - Fecha operativa: ${data.fecha || todayInputValue()}`;
  if ($('screenCurrentCode')) {
    $('screenCurrentCode').textContent = current?.codigo || '---';
    $('screenCurrentWindow').hidden = !current;
    $('screenCurrentWindow').textContent = current ? `Ventanilla ${current.ventanilla_numero || '-'}` : '';
    $('screenCurrentSpecialty').textContent = current
      ? `${current.especialidad || '-'} - ${current.prioridad_atencion || current.tipo_ticket}`
      : 'Esperando llamado';
    $('screenCurrentState').textContent = current ? queueStateLabel(current.cola_estado) : 'Sin llamado activo';
  }
  updateScreenStateClasses(currentState, changed);
  state.screenLastCode = currentCode;
  state.screenLastState = currentState;
  state.screenLastUpdatedAt = updatedAt;

  const container = $('screenRecentList');
  container.innerHTML = '';
  if (!calls.length) {
    container.innerHTML = '<p class="empty-state">Sin llamados activos.</p>';
    return;
  }

  calls.forEach((ticket, index) => {
    const windowNumber = ticket.ventanilla_numero || '-';
    const item = document.createElement('article');
    item.className = `screen-recent-item screen-state-${ticket.cola_estado || 'idle'} ${index === 0 ? 'is-current' : ''} ${changed && index === 0 ? 'is-flashing' : ''}`;
    item.innerHTML = `
      <strong>${escapeHtml(ticket.codigo)}</strong>
      <span class="screen-recent-arrow" aria-hidden="true">&rarr;</span>
      <span class="screen-recent-target">
        <span class="screen-recent-window">V${escapeHtml(windowNumber)}</span>
        <small>${queueStateLabel(ticket.cola_estado)}</small>
      </span>
    `;
    container.appendChild(item);
  });
}

function updateScreenStateClasses(currentState, flash) {
  const currentCard = document.querySelector('.screen-current');
  const mediaPanel = document.querySelector('.screen-media-panel');
  const states = ['llamando', 'atendiendo', 'ausente', 'idle'];
  states.forEach((stateName) => {
    currentCard?.classList.remove(`screen-state-${stateName}`);
    mediaPanel?.classList.remove(`screen-state-${stateName}`);
  });

  const stateClass = currentState || 'idle';
  currentCard?.classList.add(`screen-state-${stateClass}`);
  mediaPanel?.classList.add(`screen-state-${stateClass}`);

  if (!flash || !currentCard || !mediaPanel) {
    return;
  }

  currentCard.classList.remove('is-flashing');
  mediaPanel.classList.remove('is-flashing');
  void currentCard.offsetWidth;
  currentCard.classList.add('is-flashing');
  mediaPanel.classList.add('is-flashing');
  window.setTimeout(() => {
    currentCard.classList.remove('is-flashing');
    mediaPanel.classList.remove('is-flashing');
  }, 5200);
}

function startScreenRefresh() {
  stopScreenRefresh();
  scheduleScreenRefresh();
}

function scheduleScreenRefresh() {
  if (activeViewName() !== 'pantalla') {
    return;
  }

  state.screenTimer = window.setTimeout(() => {
    state.screenTimer = null;
    if (activeViewName() === 'pantalla') {
      loadScreen()
        .catch(() => {})
        .finally(() => scheduleScreenRefresh());
    }
  }, document.hidden ? 10000 : 2000);
}

function stopScreenRefresh() {
  if (state.screenTimer) {
    window.clearTimeout(state.screenTimer);
    state.screenTimer = null;
  }
}

function notifyQueueChanged() {
  state.queueLastChangeAt = Date.now();
  localStorage.setItem('ti_neo_queue_changed_at', String(Date.now()));
}

function initTurneroWidget() {
  const widget = $('turneroWidget');
  if (!widget) {
    return;
  }

  widget.hidden = !canAction('turnero');
  if (widget.hidden) {
    return;
  }

  applyTurneroWidgetPosition();
  renderTurneroWidget();
}

function applyTurneroWidgetPosition() {
  const widget = $('turneroWidget');
  if (!widget) {
    return;
  }

  const raw = localStorage.getItem('ti_neo_turnero_widget_pos');
  if (!raw) {
    return;
  }

  try {
    const position = JSON.parse(raw);
    const left = Math.max(8, Math.min(Number(position.left || 18), window.innerWidth - 80));
    const top = Math.max(8, Math.min(Number(position.top || 18), window.innerHeight - 56));
    widget.style.left = `${left}px`;
    widget.style.top = `${top}px`;
    widget.style.right = 'auto';
    widget.style.bottom = 'auto';
  } catch (error) {
    localStorage.removeItem('ti_neo_turnero_widget_pos');
  }
}

function startTurneroDrag(event) {
  const widget = $('turneroWidget');
  if (!widget || event.target.closest('button:not(#turneroToggleButton):not(#turneroMinimizeButton)')) {
    return;
  }

  const pointer = event.touches?.[0] || event;
  const rect = widget.getBoundingClientRect();
  const offsetX = pointer.clientX - rect.left;
  const offsetY = pointer.clientY - rect.top;
  let moved = false;

  widget.classList.add('dragging');
  event.preventDefault();

  function move(moveEvent) {
    const movePointer = moveEvent.touches?.[0] || moveEvent;
    const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
    const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
    const left = Math.max(8, Math.min(movePointer.clientX - offsetX, maxLeft));
    const top = Math.max(8, Math.min(movePointer.clientY - offsetY, maxTop));

    moved = true;
    widget.style.left = `${left}px`;
    widget.style.top = `${top}px`;
    widget.style.right = 'auto';
    widget.style.bottom = 'auto';
  }

  function stop() {
    widget.classList.remove('dragging');
    const nextRect = widget.getBoundingClientRect();
    localStorage.setItem('ti_neo_turnero_widget_pos', JSON.stringify({
      left: Math.round(nextRect.left),
      top: Math.round(nextRect.top),
    }));
    widget.dataset.dragMoved = moved ? '1' : '0';
    window.setTimeout(() => {
      delete widget.dataset.dragMoved;
    }, 0);
    document.removeEventListener('mousemove', move);
    document.removeEventListener('mouseup', stop);
    document.removeEventListener('touchmove', move);
    document.removeEventListener('touchend', stop);
  }

  document.addEventListener('mousemove', move);
  document.addEventListener('mouseup', stop);
  document.addEventListener('touchmove', move, { passive: false });
  document.addEventListener('touchend', stop);
}

function selectedWindowMode(windowNumber = state.turnero.window) {
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

function setTurneroWindow(windowNumber) {
  state.turnero.window = Number(windowNumber);
  state.turnero.current = null;
  state.turnero.paused = false;
  state.turnero.pauseAfter = false;
  localStorage.setItem('ti_neo_turnero_window', String(state.turnero.window));
  localStorage.removeItem('ti_neo_turnero_paused');
  renderTurneroWidget();
}

function renderTurneroWidget() {
  if (!$('turneroWidget') || $('turneroWidget').hidden) {
    return;
  }

  const windowNumber = Number(state.turnero.window || 0);
  const current = state.turnero.current;
  const hasWindow = windowNumber >= 1 && windowNumber <= 9;
  const isManual = [7, 8, 9].includes(windowNumber);
  const paused = state.turnero.paused;
  const currentState = current?.cola_estado || '';
  const hasCurrent = Boolean(current?.id);
  const isCalling = currentState === 'llamando';
  const isServing = currentState === 'atendiendo';

  $('turneroTabStatus').textContent = hasWindow ? `V${windowNumber}` : 'Sin ventanilla';
  $('turneroWindowTitle').textContent = hasWindow ? `Ventanilla ${windowNumber}` : 'Selecciona ventanilla';
  $('turneroOperatorState').textContent = hasWindow
    ? `${selectedWindowMode(windowNumber)} - ${paused ? 'Pausado' : hasCurrent ? queueStateLabel(currentState) : 'Disponible'}`
    : 'Elige una ventanilla para atender';
  $('turneroWindowPicker').hidden = hasWindow;
  $('turneroCurrentCode').textContent = current?.codigo || '---';
  $('turneroCurrentDetail').textContent = current
    ? `${current.prioridad_atencion || current.tipo_ticket} - ${current.especialidad || '-'}`
    : hasWindow ? 'Sin turno asignado' : 'Primero selecciona ventanilla';

  $('turneroCallButton').hidden = !hasWindow || paused || hasCurrent;
  $('turneroCallButton').disabled = state.turnero.callSubmitting || state.turnero.stateSubmitting;
  $('turneroCallButton').textContent = isManual ? 'Jalar ticket' : 'Llamar siguiente';
  $('turneroAttendButton').hidden = !hasCurrent || !isCalling;
  $('turneroAttendButton').disabled = state.turnero.stateSubmitting || state.turnero.callSubmitting;
  const recallButton = turneroRecallButton;
  const recallSeconds = turneroRecallSecondsLeft();
  recallButton.hidden = !hasCurrent || !isCalling;
  recallButton.disabled = state.turnero.recallSubmitting || state.turnero.stateSubmitting || state.turnero.callSubmitting || recallSeconds > 0;
  recallButton.classList.toggle('recall-waiting', recallSeconds > 0);
  recallButton.textContent = turneroRecallButtonText(recallSeconds, state.turnero.recallSubmitting);
  $('turneroFinishButton').hidden = !hasCurrent || !isServing;
  $('turneroFinishButton').disabled = state.turnero.stateSubmitting || state.turnero.callSubmitting;
  $('turneroAbsentButton').hidden = !hasCurrent || (!isCalling && !isServing);
  $('turneroAbsentButton').disabled = state.turnero.stateSubmitting || state.turnero.callSubmitting;
  $('turneroPauseAfterButton').hidden = !hasCurrent || !isServing;
  $('turneroPauseAfterButton').textContent = state.turnero.pauseAfter ? 'Pausa programada' : 'Pausar al finalizar';
  $('turneroResumeButton').hidden = !hasWindow || !paused;
  $('turneroChangeWindowButton').hidden = !hasWindow || hasCurrent;
}

async function turneroCallNext() {
  if (state.turnero.callSubmitting || state.turnero.stateSubmitting) {
    return;
  }

  const windowNumber = Number(state.turnero.window || 0);
  if (windowNumber < 1) {
    $('turneroWidgetMessage').textContent = 'Selecciona ventanilla.';
    return;
  }

  $('turneroWidgetMessage').textContent = '';
  state.turnero.callSubmitting = true;
  renderTurneroWidget();

  try {
    const data = await apiFetch('tickets.php?action=llamar-siguiente', {
      method: 'POST',
      body: JSON.stringify({ fecha: todayInputValue(), ventanilla: windowNumber }),
    });
    state.turnero.current = data.ticket || null;
    state.turnero.paused = false;
    localStorage.removeItem('ti_neo_turnero_paused');
    renderTurneroWidget();
    notifyQueueChanged();
    loadQueue().catch(() => {});
    loadScreen().catch(() => {});
  } catch (error) {
    $('turneroWidgetMessage').textContent = error.message;
  } finally {
    state.turnero.callSubmitting = false;
    renderTurneroWidget();
  }
}

async function turneroSetCurrentState(nextState) {
  const current = state.turnero.current;
  if (!current?.id || state.turnero.stateSubmitting || state.turnero.callSubmitting) {
    return;
  }

  $('turneroWidgetMessage').textContent = '';
  state.turnero.stateSubmitting = true;
  renderTurneroWidget();

  try {
    const data = await apiFetch('tickets.php?action=actualizar-cola', {
      method: 'POST',
      body: JSON.stringify({ id: current.id, estado: nextState }),
    });
    state.turnero.current = data.ticket || { ...current, cola_estado: nextState };

    if (['atendido', 'ausente'].includes(nextState)) {
      state.turnero.current = null;
      await refreshOperationalData({ ranges: true, queue: true, screen: true });
      if (state.turnero.pauseAfter) {
        state.turnero.paused = true;
        state.turnero.pauseAfter = false;
        localStorage.setItem('ti_neo_turnero_paused', '1');
      } else if (![7, 8, 9].includes(Number(state.turnero.window))) {
        renderTurneroWidget();
        await turneroCallNext();
        return;
      }
    }

    renderTurneroWidget();
    notifyQueueChanged();
    loadQueue().catch(() => {});
    loadScreen().catch(() => {});
  } catch (error) {
    $('turneroWidgetMessage').textContent = error.message;
  } finally {
    state.turnero.stateSubmitting = false;
    renderTurneroWidget();
  }
}

async function turneroRecallCurrent() {
  const current = state.turnero.current;
  const windowNumber = Number(state.turnero.window || 0);
  if (!current?.id || windowNumber < 1) {
    return;
  }
  const secondsLeft = turneroRecallSecondsLeft();
  if (secondsLeft > 0) {
    $('turneroWidgetMessage').textContent = `Ya se envio el llamado. Espera ${secondsLeft}s para volver a llamar.`;
    renderTurneroWidget();
    return;
  }
  if (state.turnero.recallSubmitting || state.turnero.stateSubmitting || state.turnero.callSubmitting) {
    $('turneroWidgetMessage').textContent = 'Espera a que termine la accion actual.';
    return;
  }

  $('turneroWidgetMessage').textContent = '';
  state.turnero.recallSubmitting = true;
  lockTurneroRecallButton();

  try {
    const data = await apiFetch('tickets.php?action=rellamar', {
      method: 'POST',
      body: JSON.stringify({ id: current.id, ventanilla: windowNumber }),
    });
    state.turnero.current = data.ticket || { ...current, cola_estado: 'llamando' };
    renderTurneroWidget();
    notifyQueueChanged();
    loadQueue().catch(() => {});
    loadScreen().catch(() => {});
  } catch (error) {
    $('turneroWidgetMessage').textContent = error.message;
  } finally {
    state.turnero.recallSubmitting = false;
    renderTurneroWidget();
  }
}

function turneroRecallSecondsLeft() {
  return Math.max(0, Math.ceil((state.turnero.recallLockedUntil - Date.now()) / 1000));
}

function turneroRecallButtonText(secondsLeft, submitting) {
  if (submitting) {
    return 'Re-llamando...';
  }

  return secondsLeft > 0 ? `Espera ${secondsLeft}s` : 'Re-llamar';
}

function lockTurneroRecallButton() {
  state.turnero.recallLockedUntil = Date.now() + TURNERO_RECALL_LOCK_MS;
  if (state.turnero.recallLockTimer) {
    window.clearInterval(state.turnero.recallLockTimer);
  }
  renderTurneroWidget();
  state.turnero.recallLockTimer = window.setInterval(() => {
    if (Date.now() >= state.turnero.recallLockedUntil) {
      state.turnero.recallLockedUntil = 0;
      window.clearInterval(state.turnero.recallLockTimer);
      state.turnero.recallLockTimer = null;
    }
    renderTurneroWidget();
  }, 500);
}

function escapeHtml(value) {
  return normalizeDisplayText(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function normalizeDisplayText(value) {
  let text = String(value ?? '')
    .replaceAll('\uFEFF', '')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');

  if (/[ÃƒÃ‚]/.test(text) && typeof TextDecoder !== 'undefined') {
    try {
      const bytes = Uint8Array.from(Array.from(text), (char) => char.charCodeAt(0) & 0xff);
      const decoded = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
      if (decoded && !/[ÃƒÃ‚]/.test(decoded)) {
        text = decoded;
      }
    } catch (error) {
      // Keep original text when the input was not actually mojibake.
    }
  }

  return typeof text.normalize === 'function' ? text.normalize('NFC') : text;
}

function showView(view) {
  if (!canView(view)) {
    view = firstAllowedView();
  }
  if (!view) {
    return;
  }

  stopScreenRefresh();
  stopQueueRefresh();
  document.body.classList.toggle('screen-kiosk', view === 'pantalla' && (isScreenOnlyUser() || screenKioskRequested()));

  document.querySelectorAll('[data-view-panel]').forEach((panel) => {
    panel.classList.toggle('active', !panel.hidden && panel.dataset.viewPanel === view);
  });

  document.querySelectorAll('[data-view]').forEach((link) => {
    link.classList.toggle('active', link.dataset.view === view);
  });

  if (view === 'rangos') {
    loadRangeWorkspace().catch((error) => {
      $('rangeMessage').textContent = error.message;
    });
  }

  if (view === 'historial') {
    loadHistory().catch((error) => {
      $('emitMessage').textContent = error.message;
    });
  }

  if (view === 'turnero') {
    loadQueue().catch((error) => {
      $('queueMessage').textContent = error.message;
    });
    if (canView('ventanillas')) {
      loadWindowsAdmin().catch(() => {});
    }
    startQueueRefresh();
  }

  if (view === 'pantalla') {
    loadScreen().catch(() => {});
    startScreenRefresh();
  }

  if (view === 'reportes') {
    loadReport().catch((error) => {
      $('reportMessage').textContent = error.message;
    });
  }

  if (view === 'perfil') {
    loadProfile().catch((error) => {
      $('profileMessage').textContent = error.message;
    });
  }

  if (view === 'ventanillas') {
    loadWindowsAdmin().catch(() => {});
  }

  if (view === 'pantallas') {
    loadScreenDevices().catch(() => {});
  }

  if (view === 'impresion') {
    applyPrintLayout(state.printSettings.layout);
  }

  if (view === 'emision') {
    goEmitStep(1);
    focusDocumentScanner();
  }
}

document.addEventListener('DOMContentLoaded', () => {
  consumePortalSession();
  const params = new URLSearchParams(window.location.search);
  if (params.has('email')) {
    $('loginEmail').value = params.get('email') || '';
  }
  if (params.has('password')) {
    $('loginPassword').value = params.get('password') || '';
  }
  if (params.has('email') || params.has('password')) {
    window.history.replaceState({}, document.title, window.location.pathname);
  }

  $('loginForm').addEventListener('submit', login);
  $('logoutButton').addEventListener('click', logout);
  $('menuToggle')?.addEventListener('click', () => {
    setSidebarOpen(!$('sidebar')?.classList.contains('open'));
  });
  $('sidebarScrim')?.addEventListener('click', closeSidebar);
  $('globalSearchInput')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      runGlobalSearch();
    }
  });
  $('topbarScanButton')?.addEventListener('click', goQuickEmission);
  $('openOperatorButton')?.addEventListener('click', openOperatorWindow);
  $('topbarRefreshButton')?.addEventListener('click', refreshActiveView);
  $('profileRefreshButton')?.addEventListener('click', () => loadProfile().catch((error) => {
    $('profileMessage').textContent = error.message;
  }));
  $('saveProfileButton')?.addEventListener('click', saveProfile);
  $('requestPasswordResetButton')?.addEventListener('click', requestPasswordReset);
  $('changeProfilePasswordButton')?.addEventListener('click', changeProfilePassword);
  document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => togglePasswordInput(button.dataset.togglePassword));
  });
  ['profileDisplayName', 'profileRoleArea', 'profileAccent', 'profileAvatarUrl'].forEach((id) => {
    $(id)?.addEventListener('input', syncProfilePreview);
  });
  $('profileAvatarPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-avatar-url]');
    if (!button) {
      return;
    }
    $('profileAvatarUrl').value = button.dataset.avatarUrl || '';
    syncProfilePreview();
  });
  $('profileBackgroundPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-background-url]');
    if (!button) return;
    applyProfileBackground(button.dataset.backgroundUrl || '', $('profileBackgroundOpacity')?.value || 12);
  });
  $('profileBackgroundUrl')?.addEventListener('input', (event) => {
    applyProfileBackground(event.target.value, $('profileBackgroundOpacity')?.value || 12);
  });
  $('profileBackgroundOpacity')?.addEventListener('input', (event) => {
    applyProfileBackground($('profileBackgroundUrl')?.value || '', event.target.value);
  });
  $('profileHue')?.addEventListener('input', (event) => {
    profileColorState.h = Number(event.target.value) || 0;
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
    syncProfilePreview();
  });
  bindColorPicker();
  $('refreshButton').addEventListener('click', () => Promise.all([loadSummary(), loadSpecialties()]).catch((error) => {
    $('emitMessage').textContent = error.message;
  }));
  $('saveRangeButton')?.addEventListener('click', () => {
    saveRangeAndRefresh().catch((error) => {
      $('rangeMessage').textContent = error.message;
    });
  });
  $('historyRefreshButton').addEventListener('click', () => loadHistory().catch((error) => {
    $('emitMessage').textContent = error.message;
  }));
  $('historyFilterButton')?.addEventListener('click', () => {
    state.historyPage = 1;
    loadHistory().catch((error) => {
      $('emitMessage').textContent = error.message;
    });
  });
  $('historyClearButton')?.addEventListener('click', () => {
    state.historyPage = 1;
    $('historyDateFilter').value = '';
    $('historyTypeFilter').value = '';
    $('historyStatusFilter').value = '';
    $('historySearchFilter').value = '';
    loadHistory().catch((error) => {
      $('emitMessage').textContent = error.message;
    });
  });
  $('historySearchFilter')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      state.historyPage = 1;
      loadHistory().catch((error) => {
        $('emitMessage').textContent = error.message;
      });
    }
  });
  $('historyPrevPage')?.addEventListener('click', () => {
    state.historyPage = Math.max(1, state.historyPage - 1);
    loadHistory().catch((error) => {
      $('emitMessage').textContent = error.message;
    });
  });
  $('historyNextPage')?.addEventListener('click', () => {
    state.historyPage += 1;
    loadHistory().catch((error) => {
      $('emitMessage').textContent = error.message;
    });
  });
  setupReportDefaults();
  $('reportRefreshButton')?.addEventListener('click', () => loadReport().catch((error) => {
    $('reportMessage').textContent = error.message;
  }));
  $('reportFilterButton')?.addEventListener('click', () => {
    state.reportPage = 1;
    loadReport().catch((error) => {
      $('reportMessage').textContent = error.message;
    });
  });
  $('reportClearButton')?.addEventListener('click', () => {
    state.reportPage = 1;
    $('reportFrom').value = firstMonthDayInputValue();
    $('reportTo').value = todayInputValue();
    $('reportType').value = '';
    $('reportStatus').value = '';
    loadReport().catch((error) => {
      $('reportMessage').textContent = error.message;
    });
  });
  $('reportPrevPage')?.addEventListener('click', () => {
    state.reportPage = Math.max(1, state.reportPage - 1);
    loadReport().catch((error) => {
      $('reportMessage').textContent = error.message;
    });
  });
  $('reportNextPage')?.addEventListener('click', () => {
    state.reportPage += 1;
    loadReport().catch((error) => {
      $('reportMessage').textContent = error.message;
    });
  });
  ['reportFrom', 'reportTo', 'reportType', 'reportStatus'].forEach((id) => {
    $(id)?.addEventListener('change', () => {
      state.reportPage = 1;
      loadReport().catch((error) => {
        $('reportMessage').textContent = error.message;
      });
    });
  });
  $('savePrintLayoutButton').addEventListener('click', savePrintLayout);
  $('resetPrintLayoutButton').addEventListener('click', () => {
    applyPrintLayout(DEFAULT_PRINT_LAYOUT);
    $('printLayoutMessage').textContent = 'Valores base cargados. Guarda para aplicarlos.';
  });
  document.querySelectorAll('[data-print-setting]').forEach((input) => {
    input.addEventListener('input', () => applyPrintLayout(readPrintLayoutForm()));
  });
  $('historyBody').addEventListener('click', (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) {
      return;
    }

    if (button.dataset.action === 'reprint') {
      reprintTicket(button.dataset.id);
    }

    if (button.dataset.action === 'cancel') {
      cancelTicket(button.dataset.id);
    }
  });
  $('dailyReprintSearchButton')?.addEventListener('click', () => searchDailyReprint());
  $('dailyReprintSearchInput')?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      searchDailyReprint();
    }
  });
  $('dailyReprintBody')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-action="daily-reprint"]');
    if (!button) {
      return;
    }
    reprintTicket(button.dataset.id);
  });
  $('queueRefreshButton')?.addEventListener('click', () => loadQueue().catch((error) => {
    $('queueMessage').textContent = error.message;
  }));
  $('queueCallNextButton')?.addEventListener('click', callNextTicket);
  $('queueSearchInput')?.addEventListener('input', renderQueueAvailableList);
  $('queueClearSearchButton')?.addEventListener('click', () => {
    $('queueSearchInput').value = '';
    renderQueueAvailableList();
  });
  $('queueActiveList')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-queue-action]');
    if (button) {
      updateQueueTicketState(button.dataset.id, button.dataset.queueAction);
    }
  });
  $('queueAvailableList')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-enqueue-id]');
    if (button) {
      enqueueTicket(button.dataset.enqueueId);
    }
  });
  $('windowsAdminRefreshButton')?.addEventListener('click', () => loadWindowsAdmin());
  $('screenDevicesRefreshButton')?.addEventListener('click', () => loadScreenDevices());
  $('screenDeviceCreateButton')?.addEventListener('click', createScreenDevice);
  $('screenGlobalTickerSaveButton')?.addEventListener('click', saveGlobalScreenTicker);
  $('screenDeviceEditSaveButton')?.addEventListener('click', saveScreenDetails);
  $('screenDeviceEditCancelButton')?.addEventListener('click', closeScreenDetailsModal);
  $('screenDeviceEditCloseButton')?.addEventListener('click', closeScreenDetailsModal);
  $('screenDeviceEditModal')?.addEventListener('click', (event) => {
    if (event.target === $('screenDeviceEditModal')) {
      closeScreenDetailsModal();
    }
  });
  $('windowAdminAssignButton')?.addEventListener('click', assignWindowAdmin);
  $('screenDevicesList')?.addEventListener('click', (event) => {
    const regenerateButton = event.target.closest('button[data-regenerate-screen-key]');
    if (regenerateButton) {
      regenerateScreenKey(regenerateButton.dataset.regenerateScreenKey);
      return;
    }

    const saveFormatButton = event.target.closest('button[data-save-screen-format]');
    if (saveFormatButton) {
      saveScreenFormat(saveFormatButton.dataset.saveScreenFormat);
      return;
    }

    const editDetailsButton = event.target.closest('button[data-edit-screen-details]');
    if (editDetailsButton) {
      openScreenDetailsModal(editDetailsButton.dataset.editScreenDetails);
      return;
    }

    const regenerateOpenButton = event.target.closest('button[data-regenerate-open-screen-key]');
    if (regenerateOpenButton) {
      regenerateScreenKey(regenerateOpenButton.dataset.regenerateOpenScreenKey, true);
      return;
    }

    const copyButton = event.target.closest('button[data-copy-screen-url]');
    if (copyButton) {
      copyScreenUrl(copyButton.dataset.copyScreenUrl);
      return;
    }

    const openButton = event.target.closest('button[data-open-screen-url]');
    if (openButton) {
      openScreenUrl(openButton.dataset.openScreenUrl);
      return;
    }

    const deleteButton = event.target.closest('button[data-delete-screen-device]');
    if (deleteButton) {
      deleteScreenDevice(deleteButton.dataset.deleteScreenDevice);
    }
  });
  $('screenDevicesList')?.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-screen-format-select]');
    if (select) {
      updateScreenMediaControls(select.dataset.screenFormatSelect, select.value);
    }
  });
  $('windowsAdminList')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-release-window]');
    if (button) {
      releaseWindowAdmin(button.dataset.releaseWindow);
    }
  });
  $('windowsAdminList')?.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-window-mode]');
    if (select) {
      updateWindowModeAdmin(select.dataset.windowMode, select.value);
    }
  });
  $('screenRefreshButton')?.addEventListener('click', () => loadScreen().catch(() => {}));
  const screenVideo = document.querySelector('.screen-media-video');
  const syncScreenVideoState = () => {
    screenVideo?.closest('.screen-media-panel')?.classList.toggle('has-video', Boolean(screenVideo?.currentSrc && screenVideo.readyState > 0));
  };
  screenVideo?.addEventListener('loadedmetadata', syncScreenVideoState);
  screenVideo?.addEventListener('loadeddata', syncScreenVideoState);
  screenVideo?.addEventListener('playing', syncScreenVideoState);
  screenVideo?.addEventListener('error', () => {
    screenVideo.closest('.screen-media-panel')?.classList.remove('has-video');
  });
  syncScreenVideoState();
  window.addEventListener('focus', () => focusDocumentScanner());
  window.addEventListener('storage', (event) => {
    if (event.key === 'ti_neo_queue_changed_at') {
      refreshAfterQueueSignal();
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      focusDocumentScanner();
      refreshAfterQueueSignal();
    }
  });
  document.querySelectorAll('[data-view]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showView(link.dataset.view);
      closeSidebar();
    });
  });
  document.querySelectorAll('.doc-type-button').forEach((button) => {
    button.addEventListener('click', () => setDocType(button.dataset.docType));
  });
  document.querySelectorAll('[data-emit-mode]').forEach((button) => {
    button.addEventListener('click', () => setEmitMode(button.dataset.emitMode));
  });
  setEmitMode(state.emitMode);
  document.querySelectorAll('[data-go-step]').forEach((button) => {
    button.addEventListener('click', () => goEmitStep(button.dataset.goStep));
  });
  $('goSpecialtyButton').addEventListener('click', () => {
    if (validateDocumentStep()) {
      goEmitStep(2);
      $('specialtySearch').focus();
    }
  });
  $('goTicketTypeButton').addEventListener('click', () => {
    if (validateSpecialtyStep()) {
      goEmitStep(3);
    }
  });
  $('documentNumber').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && validateDocumentStep()) {
      event.preventDefault();
      goEmitStep(2);
      $('specialtySearch').focus();
    }
  });
  $('documentNumber').addEventListener('input', scheduleDocumentAutoAdvance);
  $('specialtySearch').addEventListener('input', () => {
    $('specialtySelect').value = '';
    renderSpecialties();
    renderFrequentSpecialties();
  });
  $('specialtySearch').addEventListener('keydown', (event) => {
    if (/^[1-9]$/.test(event.key)) {
      const shortcutId = state.specialtyShortcutIds[Number(event.key) - 1];
      if (shortcutId) {
        event.preventDefault();
        selectSpecialty(shortcutId);
      }
      return;
    }

    if (event.key !== 'Enter') {
      return;
    }

    event.preventDefault();
    const firstChoice = $('specialtyList').querySelector('.specialty-choice');
    if (firstChoice) {
      firstChoice.click();
    }
  });
  document.querySelectorAll('.ticket-button').forEach((button) => {
    button.addEventListener('click', () => emitTicket(button.dataset.type));
  });
  $('rangePrevMonth')?.addEventListener('click', () => moveRangeMonth(-1));
  $('rangeNextMonth')?.addEventListener('click', () => moveRangeMonth(1));
  $('rangeToday')?.addEventListener('click', goCurrentRangeMonth);
  $('rangesCalendar')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-range-date]');
    if (button) {
      selectRangeDate(button.dataset.rangeDate);
    }
  });
  $('rangeDate')?.addEventListener('change', () => selectRangeDate($('rangeDate').value));
  $('rangeType')?.addEventListener('change', syncRangeForm);
  $('batchType')?.addEventListener('change', () => {
    $('rangeType').value = $('batchType').value;
    syncRangeForm();
  });
  $('generateBatchButton')?.addEventListener('click', () => generateBatch(false));
  $('generatePartialBatchButton')?.addEventListener('click', () => generateBatch(true));
  $('cancelBatchButton')?.addEventListener('click', () => {
    rangeState.cancelBatch = true;
    $('batchStatus').textContent = 'Cancelando impresion al terminar el ticket actual...';
  });
  $('pendingBatchesRefreshButton')?.addEventListener('click', () => loadPendingBatches());
  $('pendingBatchesBody')?.addEventListener('click', (event) => {
    const button = event.target.closest('button[data-batch-action]');
    if (!button) {
      return;
    }

    if (button.dataset.batchAction === 'reprint') {
      reprintPendingBatch(button.dataset.id, button.dataset.resume);
    }

    if (button.dataset.batchAction === 'printed') {
      markPendingBatchPrinted(button.dataset.id);
    }
  });
  document.addEventListener('keydown', handleTicketTypeShortcut);

  if (state.token && state.user) {
    bootApp()
      .then(() => showApp())
      .catch((error) => {
        logout();
        $('loginMessage').textContent = error.message;
      });
  } else {
    showLogin();
  }
});




