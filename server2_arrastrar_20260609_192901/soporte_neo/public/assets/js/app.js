const API = window.SOPORTE_NEO_API_BASE || '../api/v1';
const TOKEN_KEY = 'soporte_neo_token';
const USER_KEY = 'soporte_neo_user';
const PORTAL_URL = window.SOPORTE_NEO_PORTAL_URL || '../';
const PORTAL_TOKEN_KEYS = ['neo_portal_token', 'gs_neo_token', 'ti_neo_token'];

const state = {
  token: localStorage.getItem(TOKEN_KEY) || '',
  user: readStoredUser(),
  tickets: [],
  technicians: [],
  permissions: { views: {}, actions: {} },
  selectedId: null,
  loading: false,
  activeView: 'dashboard',
};

const $ = (id) => document.getElementById(id);

function readStoredUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(TOKEN_KEY);
    return null;
  }
}

function decodeJwtPayload(token) {
  try {
    const payload = token.split('.')[1] || '';
    const normalized = payload.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(normalized.length + ((4 - normalized.length % 4) % 4), '=');
    return JSON.parse(decodeURIComponent(escape(atob(padded))));
  } catch {
    return null;
  }
}

function applySessionToken(sessionToken) {
  const payload = decodeJwtPayload(sessionToken);
  const user = payload?.user;
  if (!user || Number(user.id || 0) <= 0 || Number(payload?.exp || 0) <= Math.floor(Date.now() / 1000)) return false;
  state.token = sessionToken;
  state.user = {
    id: Number(user.id || 0),
    email: user.email || '',
    dni: user.dni || '',
    nombre: user.nombre || `${user.nombres || ''} ${user.apellidos || ''}`.trim() || user.email || user.dni || 'Usuario',
    rol: user.rol || user.rol_nombre || '',
  };
  localStorage.setItem(TOKEN_KEY, state.token);
  localStorage.setItem(USER_KEY, JSON.stringify(state.user));
  return true;
}

function consumePortalSession() {
  const params = new URLSearchParams(window.location.search);
  const candidates = [
    params.get('sso_token') || '',
    ...PORTAL_TOKEN_KEYS.map((key) => localStorage.getItem(key) || ''),
    state.token,
  ].filter(Boolean);
  for (const candidate of [...new Set(candidates)]) {
    if (applySessionToken(candidate)) break;
  }
  if (params.has('sso_token')) {
    params.delete('sso_token');
    const query = params.toString();
    window.history.replaceState({}, document.title, `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
  }
}

async function apiRequest(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (state.token) headers.Authorization = `Bearer ${state.token}`;
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
  const response = await fetch(`${API}/${path}`, {
    ...options,
    headers,
    body: options.body && !(options.body instanceof FormData) ? JSON.stringify(options.body) : options.body,
  });
  const payload = await response.json().catch(() => ({ success: false, message: 'Respuesta invalida del servidor.' }));
  if (!response.ok || payload.success === false) {
    const error = new Error(payload.message || 'No se pudo completar la operacion.');
    error.status = response.status;
    throw error;
  }
  return payload.data || {};
}

async function api(action, options = {}) {
  return apiRequest(`support.php?action=${encodeURIComponent(action)}`, options);
}
function showMessage(message, type = 'info') {
  let box = $('supportMessage');
  if (!box) {
    box = document.createElement('div');
    box.id = 'supportMessage';
    box.className = 'support-message';
    document.querySelector('.main-panel')?.prepend(box);
  }
  box.textContent = message;
  box.dataset.type = type;
}

function clearMessage() {
  $('supportMessage')?.remove();
}

function setLoginMessage(message = '', type = 'info') {
  const box = $('loginMessage');
  if (!box) return;
  box.textContent = message;
  box.dataset.type = type;
}

function showLogin(message = '') {
  document.body.classList.remove('auth-boot');
  $('loginScreen')?.classList.remove('hidden');
  $('appShell')?.classList.add('hidden');
  if (message) setLoginMessage(message, 'error');
}

function hideLogin() {
  document.body.classList.remove('auth-boot');
  $('loginScreen')?.classList.add('hidden');
  $('appShell')?.classList.remove('hidden');
  setLoginMessage('');
}

function clearSession() {
  state.token = '';
  state.user = null;
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

async function loadDashboard() {
  if (!state.token) {
    showLogin('Ingresa con tu usuario NEO o desde Portal NEO.');
    renderEmpty();
    return;
  }
  try {
    const data = await api('dashboard');
    state.permissions = data.permissions || state.permissions;
    state.user = data.user || state.user;
    state.tickets = data.tickets || [];
    state.technicians = data.technicians || [];
    localStorage.setItem(USER_KEY, JSON.stringify(state.user));
    clearMessage();
    hideLogin();
    applyPermissions();
    renderProfileSummary();
    render();
  } catch (error) {
    if (error.status === 401) {
      clearSession();
      showLogin('Tu sesion vencio. Vuelve a ingresar.');
    } else if (error.status === 403) {
      clearSession();
      showLogin(error.message);
    } else {
      showLogin(error.message || 'No se pudo cargar Soporte NEO.');
    }
    renderEmpty();
  }
}

function initialsFromName(value) {
  const clean = String(value || '').trim();
  if (!clean) return 'SN';
  const parts = clean.split(/\s+/).filter(Boolean);
  return parts.slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function renderProfileSummary() {
  const user = state.user || {};
  const profile = user.perfil || {};
  const name = profile.nombre_visible || user.nombre_visible || user.nombre || user.email || user.dni || 'Soporte NEO';
  const role = profile.cargo_area || user.rol || user.rol_nombre || user.cargo || 'Mesa de ayuda TI';
  const email = user.email || user.dni || role;
  const avatar = profile.avatar_url || user.avatar_url || user.avatar || '';
  const accent = normalizeHexColor(profile.color_principal || user.color_principal || '#dc2626');
  const initials = initialsFromName(name);
  document.documentElement.style.setProperty('--personal-accent', accent);
  if ($('topbarProfileName')) $('topbarProfileName').textContent = name;
  if ($('topbarProfileRole')) $('topbarProfileRole').textContent = role;
  if ($('profileDropdownName')) $('profileDropdownName').textContent = name;
  if ($('profileDropdownEmail')) $('profileDropdownEmail').textContent = email;
  if ($('profileInitials')) {
    $('profileInitials').textContent = '...';
    $('profileInitials').title = name;
  }
  if ($('profileHeroName')) $('profileHeroName').textContent = name;
  if ($('profileHeroRole')) $('profileHeroRole').textContent = role;
  if ($('profileHeroColor')) $('profileHeroColor').textContent = accent;
  const avatarBox = $('topbarProfileAvatar');
  [avatarBox, $('profileHeroAvatar')].filter(Boolean).forEach((box) => {
    box.textContent = initials;
    box.style.backgroundImage = avatar ? `url("${String(avatar).replaceAll('"', '%22')}")` : '';
    box.classList.toggle('with-image', Boolean(avatar));
  });
}

function normalizeHexColor(value) {
  const text = String(value || '').trim();
  if (!/^#[0-9a-f]{6}$/i.test(text)) return '#dc2626';
  return text.toLowerCase();
}

function userProfileData() {
  const user = state.user || {};
  const profile = user.perfil || {};
  return {
    nombre_visible: profile.nombre_visible || user.nombre_visible || user.nombre || '',
    cargo_area: profile.cargo_area || user.rol || user.rol_nombre || user.cargo || 'Mesa de ayuda TI',
    email: user.email || user.dni || '',
    color_principal: normalizeHexColor(profile.color_principal || user.color_principal || '#dc2626'),
    avatar_url: profile.avatar_url || user.avatar_url || user.avatar || '',
  };
}

function renderProfileForm() {
  const profile = userProfileData();
  if ($('profileDisplayName')) $('profileDisplayName').value = profile.nombre_visible;
  if ($('profileRoleArea')) $('profileRoleArea').value = profile.cargo_area;
  if ($('profileEmail')) $('profileEmail').value = profile.email;
  if ($('profileAccent')) $('profileAccent').value = profile.color_principal;
  if ($('profileAvatarUrl')) $('profileAvatarUrl').value = profile.avatar_url;
  renderProfileSummary();
}

async function loadProfile() {
  if (!state.token) return;
  try {
    const data = await api('perfil');
    state.user = data.user || state.user;
    if (state.user) {
      state.user.perfil = data.perfil || state.user.perfil || {};
      localStorage.setItem(USER_KEY, JSON.stringify(state.user));
    }
    renderProfileForm();
  } catch (error) {
    setProfileMessage(error.message || 'No se pudo cargar el perfil.', 'error');
  }
}

function setProfileMessage(message = '', type = 'info') {
  const box = $('profileMessage');
  if (!box) return;
  box.textContent = message;
  box.dataset.type = type;
}

async function saveProfile(event) {
  event.preventDefault();
  const body = {
    nombre_visible: ($('profileDisplayName')?.value || '').trim(),
    color_principal: normalizeHexColor($('profileAccent')?.value || ''),
    avatar_url: ($('profileAvatarUrl')?.value || '').trim(),
  };
  const button = event.currentTarget.querySelector('button[type="submit"]');
  button.disabled = true;
  setProfileMessage('Guardando...', 'info');
  try {
    const data = await api('guardar-perfil', { method: 'POST', body });
    state.user = data.user || state.user;
    if (state.user) {
      state.user.perfil = data.perfil || state.user.perfil || {};
      localStorage.setItem(USER_KEY, JSON.stringify(state.user));
    }
    renderProfileForm();
    setProfileMessage('Perfil guardado.', 'ok');
  } catch (error) {
    setProfileMessage(error.message || 'No se pudo guardar el perfil.', 'error');
  } finally {
    button.disabled = false;
  }
}

function syncProfilePreview() {
  const user = state.user || {};
  state.user = {
    ...user,
    perfil: {
      ...(user.perfil || {}),
      nombre_visible: ($('profileDisplayName')?.value || '').trim(),
      color_principal: normalizeHexColor($('profileAccent')?.value || ''),
      avatar_url: ($('profileAvatarUrl')?.value || '').trim(),
    },
  };
  renderProfileSummary();
}

function setView(view) {
  state.activeView = view === 'profile' ? 'profile' : 'dashboard';
  document.querySelectorAll('[data-view-panel]').forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.viewPanel === state.activeView);
  });
  document.querySelectorAll('.nav-item[data-view]').forEach((item) => {
    item.classList.toggle('active', item.dataset.view === view || (state.activeView === 'dashboard' && item.dataset.view === view));
  });
  if (state.activeView === 'profile') {
    loadProfile();
    return;
  }
  const label = document.querySelector(`.nav-item[data-view="${view}"]`)?.textContent?.trim() || 'Vista general';
  if ($('activeFilter')) $('activeFilter').textContent = label;
}
function applyPermissions() {
  document.querySelectorAll('.nav-item[data-view]').forEach((button) => {
    const view = button.dataset.view;
    button.hidden = view !== 'profile' && state.permissions.views?.[view] === false;
  });
  $('newTicketButton').hidden = state.permissions.actions?.crear === false;
}

function matchesQuery(ticket, query) {
  if (!query) return true;
  return Object.values(ticket).some((value) => String(value || '').toLowerCase().includes(query));
}

function filteredTickets() {
  const query = ($('searchInput').value || '').trim().toLowerCase();
  return state.tickets.filter((ticket) => matchesQuery(ticket, query));
}

function renderStats(items) {
  const counts = {
    Total: items.length,
    Nuevos: items.filter((t) => t.estado === 'Nuevo').length,
    Asignados: items.filter((t) => t.estado === 'Asignado').length,
    'En Atencion': items.filter((t) => t.estado === 'En Atencion').length,
    Resueltos: items.filter((t) => t.estado === 'Resuelto').length,
  };
  const icons = { Total: 'T', Nuevos: 'N', Asignados: 'A', 'En Atencion': 'E', Resueltos: 'R' };
  $('statsGrid').innerHTML = Object.entries(counts).map(([label, value]) => `
    <button class="metric-card" type="button" data-status="${label}" data-icon="${icons[label] || 'S'}">
      <small>${label}</small><strong>${value}</strong><small class="metric-chip">Soporte</small>
    </button>
  `).join('');
}

function badge(value, type) {
  return `<span class="badge ${type}-${String(value).toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(value)}</span>`;
}

function actionButtons(ticket) {
  const canAssign = state.permissions.actions?.asignar !== false;
  const canResolve = state.permissions.actions?.resolver !== false;
  const canAdmin = state.permissions.actions?.admin === true;
  const buttons = [];
  if (!['Resuelto', 'Cerrado'].includes(ticket.estado) && canAssign) buttons.push(`<button class="ok-button" data-action="assign" data-id="${ticket.id}">${ticket.tecnico ? 'Reasignar' : 'Asignar'}</button>`);
  if (!['Resuelto', 'Cerrado'].includes(ticket.estado) && canResolve) buttons.push(`<button class="ghost-button" data-action="resolve" data-id="${ticket.id}">Resolver</button>`);
  if (canAdmin) buttons.push(`<button class="danger-button" data-action="delete" data-id="${ticket.id}">Anular</button>`);
  return buttons.join('') || '<span class="muted">Sin acciones</span>';
}

function renderTable(items) {
  $('ticketsBody').innerHTML = items.map((ticket) => `
    <tr class="${ticket.id === state.selectedId ? 'selected' : ''}">
      <td><strong>${escapeHtml(ticket.id)}</strong></td><td>${escapeHtml(ticket.usuario)}</td><td>${escapeHtml(ticket.area)}</td>
      <td>${escapeHtml(ticket.equipo)}</td><td>${escapeHtml(ticket.problema)}</td><td>${badge(ticket.prioridad, 'priority')}</td>
      <td>${badge(ticket.estado, 'state')}</td><td>${ticket.tecnico ? escapeHtml(ticket.tecnico) : '<span class="muted">Sin asignar</span>'}</td>
      <td>${escapeHtml(ticket.registrado)}</td><td class="actions">${actionButtons(ticket)}</td>
    </tr>
  `).join('') || '<tr><td colspan="10" class="empty">Sin tickets para mostrar.</td></tr>';
}

function renderInbox() {
  const query = ($('inboxSearch').value || '').trim().toLowerCase();
  const items = state.tickets.filter((ticket) => matchesQuery(ticket, query)).slice(0, 8);
  $('inboxCount').textContent = `${state.tickets.length} tickets`;
  $('ticketInbox').innerHTML = items.map((ticket) => `
    <button class="ticket-card ${ticket.id === state.selectedId ? 'active' : ''}" type="button" data-select="${ticket.id}">
      <strong>${escapeHtml(ticket.usuario)}</strong><span>${escapeHtml(ticket.equipo || 'Sin equipo')} - ${escapeHtml(ticket.area)}</span>
      <p>${escapeHtml(ticket.problema)}</p><footer><small>${escapeHtml(ticket.canal || 'Formulario')}</small>${badge(ticket.estado, 'state')}${ticket.prioridad === 'Urgente' ? badge('Urgente', 'priority') : ''}</footer>
    </button>
  `).join('') || '<p class="empty muted">Sin tickets en bandeja.</p>';
}

function render() {
  const items = filteredTickets();
  renderStats(items);
  renderTable(items);
  renderInbox();
}

function renderEmpty() {
  state.tickets = [];
  render();
}

async function runAction(action, body, successMessage) {
  try {
    await api(action, { method: 'POST', body });
    showMessage(successMessage, 'ok');
    await loadDashboard();
  } catch (error) {
    showMessage(error.message, 'error');
  }
}

function escapeHtml(value) {
  return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
}

async function handleLogin(event) {
  event.preventDefault();
  const button = event.currentTarget.querySelector('button[type="submit"]');
  const login = ($('loginEmail')?.value || '').trim();
  const password = $('loginPassword')?.value || '';
  if (!login || !password) {
    setLoginMessage('Ingresa correo o DNI y contrasena.', 'error');
    return;
  }
  button.disabled = true;
  setLoginMessage('Validando acceso...', 'info');
  try {
    const data = await apiRequest('login.php', { method: 'POST', body: { login, password } });
    state.token = data.access_token || '';
    state.user = data.user || null;
    state.permissions = data.permissions || state.permissions;
    localStorage.setItem(TOKEN_KEY, state.token);
    localStorage.setItem(USER_KEY, JSON.stringify(state.user));
    $('loginPassword').value = '';
    hideLogin();
    await loadDashboard();
  } catch (error) {
    clearSession();
    setLoginMessage(error.message || 'No se pudo iniciar sesion.', 'error');
  } finally {
    button.disabled = false;
  }
}

function logout() {
  clearSession();
  renderEmpty();
  showLogin('Sesion cerrada.');
}
document.addEventListener('DOMContentLoaded', () => {
  consumePortalSession();
  renderProfileSummary();
  renderProfileForm();
  if (!state.token) showLogin();
  $('loginForm')?.addEventListener('submit', handleLogin);
  $('logoutButton')?.addEventListener('click', logout);
  $('searchInput')?.addEventListener('input', render);
  $('inboxSearch')?.addEventListener('input', renderInbox);
  $('refreshButton')?.addEventListener('click', loadDashboard);
  $('profileRefreshButton')?.addEventListener('click', loadProfile);
  $('profileForm')?.addEventListener('submit', saveProfile);
  $('profileDisplayName')?.addEventListener('input', syncProfilePreview);
  $('profileAccent')?.addEventListener('input', syncProfilePreview);
  $('profileAvatarUrl')?.addEventListener('input', syncProfilePreview);
  document.querySelector('[data-open-profile]')?.addEventListener('click', () => setView('profile'));
  $('newTicketButton')?.addEventListener('click', () => $('ticketDialog')?.showModal());
  $('closeDialogButton')?.addEventListener('click', () => $('ticketDialog')?.close());
  $('cancelDialogButton')?.addEventListener('click', () => $('ticketDialog')?.close());
  $('ticketForm')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    try {
      await api('crear', { method: 'POST', body: data });
      event.currentTarget.reset();
      $('ticketDialog').close();
      showMessage('Ticket registrado.', 'ok');
      await loadDashboard();
    } catch (error) {
      showMessage(error.message, 'error');
    }
  });
  document.body.addEventListener('click', (event) => {
    const action = event.target.closest('[data-action]');
    if (action) {
      const id = action.dataset.id;
      if (action.dataset.action === 'assign') runAction('asignar', { codigo: id }, 'Ticket asignado.');
      if (action.dataset.action === 'resolve') runAction('resolver', { codigo: id }, 'Ticket resuelto.');
      if (action.dataset.action === 'delete') runAction('eliminar', { codigo: id }, 'Ticket anulado.');
    }
    const selected = event.target.closest('[data-select]');
    if (selected) {
      state.selectedId = selected.dataset.select;
      render();
    }
  });
  document.querySelectorAll('.nav-item[data-view]').forEach((button) => {
    button.addEventListener('click', () => {
      setView(button.dataset.view || 'dashboard');
    });
  });
  loadDashboard();
});

