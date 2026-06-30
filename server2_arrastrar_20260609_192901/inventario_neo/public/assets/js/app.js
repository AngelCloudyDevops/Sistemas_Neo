const state = {
    token: localStorage.getItem('inventario_neo_token') || '',
    refreshToken: ['inventario_neo_refresh_token', 'neo_portal_refresh_token', 'gs_neo_refresh_token', 'ti_neo_refresh_token']
        .map((key) => localStorage.getItem(key) || '')
        .find(Boolean) || '',
    user: readStoredUser(),
    activeView: 'dashboard',
    pages: {
        personal: { page: 1, per_page: 20, total: 0, pages: 1 },
        equipos: { page: 1, per_page: 20, total: 0, pages: 1 },
        impresoras: { page: 1, per_page: 20, total: 0, pages: 1 },
    },
    rows: { personal: [], equipos: [], impresoras: [] },
    meta: { personal: {}, equipos: {}, impresoras: {} },
    catalogs: null,
    editing: null,
    refreshPromise: null,
    personalSearchTimer: null,
};

const apiBase = window.INVENTARIO_NEO_API_BASE || '../api/v1';
const presetAvatars = Array.isArray(window.INVENTARIO_NEO_PRESET_AVATARS) ? window.INVENTARIO_NEO_PRESET_AVATARS : [];
const presetBackgrounds = Array.isArray(window.INVENTARIO_NEO_PRESET_BACKGROUNDS) ? window.INVENTARIO_NEO_PRESET_BACKGROUNDS : [];
const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));
const portalTokenKeys = ['neo_portal_token', 'gs_neo_token', 'ti_neo_token'];

function readStoredUser() {
    try {
        return JSON.parse(localStorage.getItem('inventario_neo_user') || 'null');
    } catch {
        localStorage.removeItem('inventario_neo_user');
        localStorage.removeItem('inventario_neo_token');
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
        rol_nombre: user.rol_nombre || user.rol || '',
        perfil: user.perfil || {},
    };
    localStorage.setItem('inventario_neo_token', state.token);
    localStorage.setItem('inventario_neo_user', JSON.stringify(state.user));
    return true;
}

function tokenCandidates(urlToken = '') {
    const candidates = [
        urlToken,
        ...portalTokenKeys.map((key) => localStorage.getItem(key) || ''),
        state.token || '',
    ].filter(Boolean);

    return [...new Set(candidates)];
}

function forgetRejectedToken(rejectedToken = '') {
    if (!rejectedToken) {
        return;
    }

    ['inventario_neo_token', ...portalTokenKeys].forEach((key) => {
        if (localStorage.getItem(key) === rejectedToken) {
            localStorage.removeItem(key);
        }
    });

    if (state.token === rejectedToken) {
        state.token = '';
        state.user = null;
        localStorage.removeItem('inventario_neo_user');
    }
}

function consumePortalSession(excludeToken = '') {
    const params = new URLSearchParams(window.location.search);
    const hasSsoParam = params.has('sso_token');
    const urlToken = params.get('sso_token') || '';
    let consumed = false;

    for (const candidate of tokenCandidates(urlToken)) {
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

async function api(path, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (state.token) {
        headers.Authorization = `Bearer ${state.token}`;
        headers['X-Auth-Token'] = state.token;
    }

    const response = await fetch(`${apiBase}/${path}`, { ...options, headers });
    const payload = await response.json().catch(() => null);

    if (response.status === 401) {
        const rejectedToken = state.token;
        if (!options.retryingAuth && await refreshSession()) {
            return api(path, { ...options, retryingAuth: true });
        }
        forgetRejectedToken(rejectedToken);
        if (!options.retryingAuth && consumePortalSession(rejectedToken)) {
            return api(path, { ...options, retryingAuth: true });
        }
    }

    if (!response.ok || !(payload?.ok || payload?.success)) {
        const error = new Error(payload?.message || 'No se pudo completar la operacion.');
        error.status = response.status;
        throw error;
    }

    return payload;
}

async function refreshSession() {
    if (!state.refreshToken) return false;
    if (state.refreshPromise) return state.refreshPromise;

    state.refreshPromise = fetch(`${apiBase}/refresh-token.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: state.refreshToken }),
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !(payload?.ok || payload?.success) || !payload.data?.access_token) {
                return false;
            }

            state.token = payload.data.access_token;
            state.user = payload.data.user || state.user;
            localStorage.setItem('inventario_neo_token', state.token);
            localStorage.setItem('inventario_neo_user', JSON.stringify(state.user));
            return true;
        })
        .catch(() => false)
        .finally(() => {
            state.refreshPromise = null;
        });

    return state.refreshPromise;
}

function isSessionError(error) {
    return Number(error?.status) === 401 || /sesion|session|iniciada|vencida|invalid/i.test(error?.message || '');
}

function handleLoadError(error, module = state.activeView) {
    if (isSessionError(error)) {
        logout();
        return;
    }

    if (['personal', 'equipos', 'impresoras'].includes(module)) {
        renderModuleError(module, error.message || 'No se pudo cargar la informacion.');
        return;
    }

    console.error(error);
}

function handleDialogError(error) {
    if (isSessionError(error)) {
        $('#recordDialog').close();
        logout();
        return;
    }

    setMessage($('#dialogMessage'), error.message || 'No se pudo completar la operacion.', 'error');
}

function renderModuleError(module, message) {
    const table = $(`#${module}Table`);
    const pagination = $(`#${module}Pagination`);
    const colspan = { personal: 5, equipos: equipoColumns().length, impresoras: 12 }[module] || 1;

    if (table) {
        table.innerHTML = `<tr><td colspan="${colspan}"><div class="empty-state error">${escapeHtml(message)}</div></td></tr>`;
    }

    if (pagination) {
        pagination.innerHTML = '';
    }
}

function setMessage(element, text, type = '') {
    element.textContent = text;
    element.className = `form-message ${type}`.trim();
}

function showLogin() {
    $('#appShell').classList.add('hidden');
    $('#loginScreen').classList.remove('hidden');
}

function showApp() {
    $('#loginScreen').classList.add('hidden');
    $('#appShell').classList.remove('hidden');
    applyGlobalProfile();
}

function initials(value) {
    return String(value || 'U').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'U';
}

function displayLogin(user) {
    const email = String(user?.email || '').trim();
    if (email !== '' && !email.toLowerCase().endsWith('@dni.local')) {
        return email;
    }

    return String(user?.dni || '').trim();
}

function normalizeHexColor(value, fallback = '#7c3aed') {
    const raw = String(value || '').trim();
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    if (!/^#[0-9a-f]{6}$/i.test(withHash)) return fallback;
    return withHash.toLowerCase();
}

function applyGlobalProfile() {
    const profile = state.user?.perfil || {};
    const displayName = profile.nombre_visible || state.user?.nombre || `${state.user?.nombres || ''} ${state.user?.apellidos || ''}`.trim() || displayLogin(state.user) || 'Usuario';
    const roleArea = profile.cargo_area || 'Inventario';
    const accent = normalizeHexColor(profile.color_principal);
    const avatarUrl = String(profile.avatar_url || '').trim();
    const backgroundUrl = String(profile.background_url || '').trim();
    const backgroundOpacity = normalizeBackgroundOpacity(profile.background_opacity);

    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    $('#topbarProfileName').textContent = displayName;
    $('#topbarProfileRole').textContent = roleArea;
    $('#topbarProfileAvatar').textContent = profile.iniciales || initials(displayName);
    $('#topbarProfileAvatar').style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    $('#topbarProfileAvatar').classList.toggle('with-image', Boolean(avatarUrl));
    $('#profileInitials').textContent = '...';
    $('#profileInitials').title = displayName;
    $('#profileInitials').style.backgroundImage = '';
    $('#profileInitials').classList.remove('with-image');
    $('#profileName').textContent = displayName;
    $('#currentUser').textContent = displayLogin(state.user) || roleArea;
}

function updateStoredUserProfile(profile) {
    state.user = {
        ...(state.user || {}),
        nombre: profile.nombre_visible || state.user?.nombre || '',
        perfil: {
            ...(state.user?.perfil || {}),
            nombre_visible: profile.nombre_visible || '',
            cargo_area: profile.cargo_area || '',
            color_principal: profile.color_principal || '#7c3aed',
            avatar_url: profile.avatar_url || '',
            background_url: profile.background_url || '',
            background_opacity: normalizeBackgroundOpacity(profile.background_opacity),
            iniciales: initials(profile.nombre_visible || state.user?.nombre || 'Usuario'),
        },
    };
    localStorage.setItem('inventario_neo_user', JSON.stringify(state.user));
    applyGlobalProfile();
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
    $('#profileColorPicker')?.style.setProperty('--picker-hue-color', hsvToHex(profileColorState.h, 100, 100));
    $('#profileColorPicker')?.style.setProperty('--picker-saturation', String(profileColorState.s));
    $('#profileColorPicker')?.style.setProperty('--picker-value', String(profileColorState.v));
}

function applyProfileAccent(value) {
    const accent = normalizeHexColor(value);
    $('#profileAccent').value = accent.slice(1);
    $('#profileAccentPreview').style.background = accent;
    $('#profileHue').value = String(hexToHue(accent));
    $('#profileColorSummary').textContent = accent;
    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    syncColorPicker(accent);
    return accent;
}

function setColorFromMapEvent(event) {
    const map = $('#profileColorMap');
    if (!map) return;
    const rect = map.getBoundingClientRect();
    const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
    const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
    profileColorState.s = Math.round((x / rect.width) * 100);
    profileColorState.v = Math.round((1 - (y / rect.height)) * 100);
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
}

function bindColorPicker() {
    const map = $('#profileColorMap');
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
}

function logout() {
    const refreshToken = state.refreshToken;
    if (refreshToken) {
        fetch(`${apiBase}/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: refreshToken }),
        }).catch(() => {});
    }

    localStorage.removeItem('inventario_neo_token');
    localStorage.removeItem('inventario_neo_refresh_token');
    localStorage.removeItem('inventario_neo_user');
    state.token = '';
    state.refreshToken = '';
    state.user = null;
    showLogin();
}

function selectView(view) {
    const createLabels = {
        personal: 'Nuevo personal',
        equipos: 'Nuevo equipo',
        impresoras: 'Nueva impresora',
    };
    state.activeView = view;
    $$('.nav-item').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
    $$('[data-view-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.viewPanel === view));
    $('#newRecordButton').classList.toggle('hidden', view === 'dashboard' || view === 'profile');
    $('#newRecordButton').title = createLabels[view] || 'Nuevo';
    $('#newRecordButton').setAttribute('aria-label', createLabels[view] || 'Nuevo');
    if (view === 'profile') {
        loadProfile().catch((error) => {
            $('#profileMessage').textContent = error.message;
        });
    } else if (view !== 'dashboard') {
        loadModule(view).catch((error) => handleLoadError(error, view));
    }
}

async function loadInitialData() {
    const summary = await api('inventory.php?action=summary');
    renderSummary(summary.data || {});
    await Promise.all(['personal', 'equipos', 'impresoras'].map((module) => loadModule(module)));
    await refreshGlobalProfile().catch(() => {});
}

async function refreshGlobalProfile() {
    const payload = await api('profile.php?action=ver');
    updateStoredUserProfile(payload.data.perfil || {});
}

async function loadProfile() {
    $('#profileMessage').textContent = 'Cargando perfil...';
    const payload = await api('profile.php?action=ver');
    renderProfileForm(payload.data.perfil || {});
    renderPasswordResetStatus(payload.data.password_reset || {});
    $('#profileMessage').textContent = '';
}

function renderProfileForm(profile) {
    const displayName = profile.nombre_visible || 'Usuario';
    const roleArea = profile.cargo_area || 'Usuario';
    const accent = normalizeHexColor(profile.color_principal);
    const avatarUrl = String(profile.avatar_url || '').trim();
    const backgroundUrl = String(profile.background_url || '').trim();
    const backgroundOpacity = normalizeBackgroundOpacity(profile.background_opacity);
    $('#profileDisplayName').value = profile.nombre_visible || '';
    $('#profileRoleArea').value = profile.cargo_area || '';
    $('#profileEmail').value = profile.correo || displayLogin(state.user) || '';
    $('#profileAvatarUrl').value = profile.avatar_url || '';
    $('#profileHeroName').textContent = displayName;
    $('#profileHeroRole').textContent = roleArea;
    $('#profileAvatarPreview').textContent = initials(displayName);
    $('#profileAvatarPreview').style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    $('#profileAvatarPreview').classList.toggle('with-image', Boolean(avatarUrl));
    renderAvatarPresets(avatarUrl);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    applyProfileAccent(accent);
}

function normalizeAvatarForCompare(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
        return new URL(raw, window.location.href).toString();
    } catch {
        return raw;
    }
}

function renderAvatarPresets(selectedUrl = '') {
    const grid = $('#profileAvatarPresets');
    if (!grid) return;
    const selected = normalizeAvatarForCompare(selectedUrl);
    grid.innerHTML = presetAvatars.map((url, index) => {
        const active = normalizeAvatarForCompare(url) === selected ? ' active' : '';
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
    if ($('#profileBackgroundUrl')) $('#profileBackgroundUrl').value = url;
    if ($('#profileBackgroundOpacity')) $('#profileBackgroundOpacity').value = String(Math.round(normalizedOpacity * 100));
    if ($('#profileBackgroundOpacityValue')) $('#profileBackgroundOpacityValue').textContent = `${Math.round(normalizedOpacity * 100)}%`;
    renderBackgroundPresets(url);
}

function renderBackgroundPresets(selectedUrl = '') {
    const grid = $('#profileBackgroundPresets');
    if (!grid) return;
    const selected = normalizeAvatarForCompare(selectedUrl);
    grid.innerHTML = [
        '<button class="background-preset-button clear" type="button" data-background-url="" aria-label="Quitar fondo">Sin fondo</button>',
        ...presetBackgrounds.map((url, index) => {
            const active = normalizeAvatarForCompare(url) === selected ? ' active' : '';
            return `<button class="background-preset-button${active}" type="button" data-background-url="${url}" aria-label="Seleccionar fondo ${index + 1}"><span style="background-image:url('${url}')"></span></button>`;
        }),
    ].join('');
}

function renderPasswordResetStatus(status = {}) {
    $('#passwordResetPanel').classList.toggle('hidden', !status.habilitado);
}

async function saveProfile(event) {
    event.preventDefault();
    $('#profileMessage').textContent = 'Guardando perfil...';
    const payload = await api('profile.php?action=guardar', {
        method: 'POST',
        body: JSON.stringify({
            nombre_visible: $('#profileDisplayName').value.trim(),
            color_principal: normalizeHexColor($('#profileAccent').value),
            avatar_url: $('#profileAvatarUrl').value.trim(),
            background_url: $('#profileBackgroundUrl')?.value.trim() || '',
            background_opacity: normalizeBackgroundOpacity($('#profileBackgroundOpacity')?.value || 12),
        }),
    });
    renderProfileForm(payload.data.perfil || {});
    renderPasswordResetStatus(payload.data.password_reset || {});
    updateStoredUserProfile(payload.data.perfil || {});
    $('#profileMessage').textContent = 'Perfil guardado.';
}

async function requestPasswordReset() {
    $('#profileMessage').textContent = 'Enviando solicitud...';
    await api('profile.php?action=solicitar_reinicio_password', { method: 'POST' });
    $('#profileMessage').textContent = 'Solicitud enviada. GS NEO debe aprobar el reinicio.';
}

async function changeProfilePassword() {
    $('#changeProfilePasswordMessage').textContent = 'Actualizando contrasena...';
    await api('profile.php?action=cambiar_password', {
        method: 'POST',
        body: JSON.stringify({
            password: $('#newProfilePassword').value,
            confirm_password: $('#confirmProfilePassword').value,
        }),
    });
    $('#newProfilePassword').value = '';
    $('#confirmProfilePassword').value = '';
    $('#passwordResetPanel').classList.add('hidden');
    $('#changeProfilePasswordMessage').textContent = 'Contrasena actualizada.';
}

function renderSummary(summary) {
    const cards = [
        ['Personal activo', summary.personal || 0, 'P'],
        ['Equipos', summary.equipos || 0, 'E'],
        ['Equipos asignados', summary.equipos_asignados || 0, 'A'],
        ['Impresoras', summary.impresoras || 0, 'I'],
    ];

    $('#summaryCards').innerHTML = cards.map(([label, value, icon]) => `
        <button class="metric-card" type="button" data-metric="${icon}">
            <small>${escapeHtml(label)}</small>
            <strong>${Number(value || 0)}</strong>
            <small class="metric-chip">Core</small>
        </button>
    `).join('');
}

async function loadModule(module) {
    const search = encodeURIComponent($('#globalSearch').value.trim());
    const pagination = state.pages[module];
    const payload = await api(`inventory.php?action=${module}&search=${search}&page=${pagination.page}&per_page=${pagination.per_page}`);
    state.rows[module] = payload.data.rows || [];
    state.pages[module] = payload.data.pagination || pagination;
    state.meta[module] = payload.data.meta || {};
    renderModule(module);
}

function renderModule(module) {
    if (module === 'personal') renderPersonal();
    if (module === 'equipos') renderEquipos();
    if (module === 'impresoras') renderImpresoras();
    renderPagination(module);
}

function renderPersonal() {
    const rows = state.rows.personal;
    $('#personalTable').innerHTML = rows.map((row) => `
        <tr>
            <td>${escapeHtml(row.dni)}</td>
            <td><strong>${escapeHtml(row.nombre_completo)}</strong><br><small>${escapeHtml([row.apellido_paterno, row.apellido_materno, row.nombres].filter(Boolean).join(' '))}</small></td>
            <td>${escapeHtml(row.correo_institucional || row.correo_firma || '')}</td>
            <td><span class="status ${row.estado === 'inactivo' ? 'inactivo' : ''}">${escapeHtml(row.estado || '')}</span></td>
            <td><button class="ghost-button" type="button" data-edit="personal" data-id="${Number(row.id)}">Editar</button></td>
        </tr>
    `).join('') || '<tr><td colspan="5"><div class="empty-state">Sin personal para mostrar.</div></td></tr>';
}

function renderEquipos() {
    const rows = state.rows.equipos;
    const columns = equipoColumns();
    $('.equipos-table').classList.toggle('has-detail', columns.length > 10);
    $('#equiposHead').innerHTML = `<tr>${columns.map((column) => `<th>${escapeHtml(column.label)}</th>`).join('')}</tr>`;
    $('#equiposTable').innerHTML = rows.map((row) => `
        <tr>
            ${columns.map((column) => `<td>${column.render(row)}</td>`).join('')}
        </tr>
    `).join('') || `<tr><td colspan="${columns.length}"><div class="empty-state">Sin equipos para mostrar.</div></td></tr>`;
}

function equipoColumns() {
    const detail = state.meta.equipos?.detail || {};
    const columns = [
        { label: 'Equipo', render: (row) => `<strong>${escapeHtml(row.nombre_pc)}</strong>` },
        { label: 'IP', render: (row) => escapeHtml(row.numero_ip || '') },
        { label: 'MAC', render: (row) => escapeHtml(row.mac_address || '') },
        { label: 'Usuario', render: (row) => escapeHtml(row.usuario_actual || '') },
        { label: 'Patrimonio', render: (row) => escapeHtml(row.cod_patrimonio || '') },
        { label: 'Fecha adquisicion', render: (row) => escapeHtml(row.fecha_adquisicion || '') },
        { label: 'Unidad', render: (row) => escapeHtml(row.unidad || '') },
        { label: 'Area', render: (row) => escapeHtml(row.area || '') },
        { label: 'Oficina', render: (row) => escapeHtml(row.oficina || '') },
    ];

    if (detail.hardware) {
        columns.push(
            { label: 'Marca CPU', render: (row) => escapeHtml(row.marca_cpu || '') },
            { label: 'Modelo CPU', render: (row) => escapeHtml(row.modelo_cpu || '') },
            { label: 'N serie CPU', render: (row) => escapeHtml(row.serie_cpu || '') },
            { label: 'Estado CPU', render: (row) => escapeHtml(row.estado_cpu || '') },
            { label: 'Marca mainboard', render: (row) => escapeHtml(row.marca_mainboard || '') },
            { label: 'Modelo mainboard', render: (row) => escapeHtml(row.modelo_mainboard || '') },
            { label: 'Marca procesador', render: (row) => escapeHtml(row.marca_procesador || '') },
            { label: 'Procesador', render: (row) => escapeHtml(row.procesador || '') },
            { label: 'Velocidad procesador', render: (row) => escapeHtml(row.velocidad_procesador || '') },
            { label: 'Generacion procesador', render: (row) => escapeHtml(row.generacion_procesador || '') },
            { label: 'Tipo memoria', render: (row) => escapeHtml(row.tipo_memoria || '') },
            { label: 'Total memoria GB', render: (row) => escapeHtml(row.total_memoria_gb || '') },
            { label: 'Marca disco', render: (row) => escapeHtml(row.marca_disco || '') },
            { label: 'Modelo disco', render: (row) => escapeHtml(row.modelo_disco || '') },
            { label: 'Capacidad disco', render: (row) => escapeHtml(row.capacidad_disco || '') },
            { label: 'Tipo disco', render: (row) => escapeHtml(row.tipo_disco || '') },
            { label: 'Marca video', render: (row) => escapeHtml(row.marca_video || '') },
            { label: 'Modelo video', render: (row) => escapeHtml(row.modelo_video || '') },
        );
    }

    if (detail.software) {
        columns.push(
            { label: 'Sistema operativo', render: (row) => escapeHtml(row.sistema_operativo || '') },
            { label: 'Licencia SO', render: (row) => escapeHtml(row.licencia_so || '') },
            { label: 'Software oficina', render: (row) => escapeHtml(row.software_oficina || '') },
            { label: 'Internet', render: (row) => escapeHtml(row.internet || '') },
            { label: 'Antivirus', render: (row) => escapeHtml(row.antivirus || '') },
        );
    }

    if (detail.peripherals) {
        columns.push(
            { label: 'Marca monitor', render: (row) => escapeHtml(row.marca_monitor || '') },
            { label: 'Tipo monitor', render: (row) => escapeHtml(row.tipo_monitor || '') },
            { label: 'Modelo monitor', render: (row) => escapeHtml(row.modelo_monitor || '') },
            { label: 'Monitor pulgadas', render: (row) => escapeHtml(row.monitor_pulgadas || '') },
            { label: 'N serie monitor', render: (row) => escapeHtml(row.serie_monitor || '') },
            { label: 'Cod monitor', render: (row) => escapeHtml(row.cod_monitor || '') },
            { label: 'Estado monitor', render: (row) => escapeHtml(row.estado_monitor || '') },
            { label: 'Marca teclado', render: (row) => escapeHtml(row.marca_teclado || '') },
            { label: 'Modelo teclado', render: (row) => escapeHtml(row.modelo_teclado || '') },
            { label: 'N serie teclado', render: (row) => escapeHtml(row.serie_teclado || '') },
            { label: 'Cod teclado', render: (row) => escapeHtml(row.cod_teclado || '') },
            { label: 'Estado teclado', render: (row) => escapeHtml(row.estado_teclado || '') },
            { label: 'Marca mouse', render: (row) => escapeHtml(row.marca_mouse || '') },
            { label: 'Modelo mouse', render: (row) => escapeHtml(row.modelo_mouse || '') },
            { label: 'N serie mouse', render: (row) => escapeHtml(row.serie_mouse || '') },
            { label: 'Estado mouse', render: (row) => escapeHtml(row.estado_mouse || '') },
            { label: 'Marca estabilizador', render: (row) => escapeHtml(row.marca_estabilizador || '') },
            { label: 'Modelo estabilizador', render: (row) => escapeHtml(row.modelo_estabilizador || '') },
            { label: 'N serie estabilizador', render: (row) => escapeHtml(row.serie_estabilizador || '') },
            { label: 'Cod estabilizador', render: (row) => escapeHtml(row.cod_estabilizador || '') },
            { label: 'Estado estabilizador', render: (row) => escapeHtml(row.estado_estabilizador || '') },
            { label: 'Marca grabadora DVD', render: (row) => escapeHtml(row.marca_grabadora_dvd || '') },
            { label: 'Tipo grabadora DVD', render: (row) => escapeHtml(row.tipo_grabadora_dvd || '') },
            { label: 'Velocidad grabadora', render: (row) => escapeHtml(row.velocidad_grabadora || '') },
        );
    }

    columns.push(
        { label: 'Estado', render: (row) => escapeHtml(row.estado || row.estado_id || '') },
        { label: '', render: (row) => `<button class="ghost-button" type="button" data-edit="equipos" data-id="${Number(row.id)}">Editar</button>` },
    );

    return columns;
}

function renderImpresoras() {
    const rows = state.rows.impresoras;
    $('#impresorasTable').innerHTML = rows.map((row) => `
        <tr>
            <td><strong>${Number(row.id)}</strong></td>
            <td>${escapeHtml(row.ubicacion || '')}</td>
            <td>${escapeHtml(row.unidad || '')}</td>
            <td>${escapeHtml(row.area || '')}</td>
            <td>${escapeHtml(row.marca || '')}</td>
            <td>${escapeHtml(row.modelo || '')}</td>
            <td>${escapeHtml(row.tipo_impresora || row.tipo_equipo || '')}</td>
            <td>${escapeHtml(row.codpat || '')}</td>
            <td>${escapeHtml(row.cod_inv || '')}</td>
            <td>${escapeHtml(row.serie || '')}</td>
            <td>${escapeHtml(row.estado || row.estado_id || '')}</td>
            <td><button class="ghost-button" type="button" data-edit="impresoras" data-id="${Number(row.id)}">Editar</button></td>
        </tr>
    `).join('') || '<tr><td colspan="12"><div class="empty-state">Sin impresoras para mostrar.</div></td></tr>';
}

function renderPagination(module) {
    const pagination = state.pages[module];
    const container = $(`#${module}Pagination`);
    const from = pagination.total === 0 ? 0 : ((pagination.page - 1) * pagination.per_page) + 1;
    const to = Math.min(pagination.total, pagination.page * pagination.per_page);
    container.innerHTML = `
        <span>${from}-${to} de ${pagination.total}</span>
        <div>
            <button class="ghost-button" type="button" data-page="${module}" data-dir="-1" ${pagination.page <= 1 ? 'disabled' : ''}>Anterior</button>
            <button class="ghost-button" type="button" data-page="${module}" data-dir="1" ${pagination.page >= pagination.pages ? 'disabled' : ''}>Siguiente</button>
        </div>
    `;
}

async function openDialog(module, row = {}) {
    state.editing = { module, row };
    $('#dialogTitle').textContent = `${row.id ? 'Editar' : 'Nuevo'} ${moduleLabel(module)}`;
    $('#dialogMessage').textContent = '';
    if (module === 'equipos' || module === 'impresoras') {
        setMessage($('#dialogMessage'), 'Cargando catalogos...');
        await loadCatalogs();
        setMessage($('#dialogMessage'), '');
    }
    $('#dialogFields').innerHTML = fieldsFor(module, row);
    $('#recordDialog').showModal();
}

async function loadCatalogs() {
    if (state.catalogs) return state.catalogs;
    const payload = await api('inventory.php?action=catalogs');
    state.catalogs = payload.data || {};
    return state.catalogs;
}

function moduleLabel(module) {
    return { personal: 'personal', equipos: 'equipo', impresoras: 'impresora' }[module] || 'registro';
}

function fieldsFor(module, row) {
    const input = (name, label, value = '', type = 'text') => `<label>${label}<input name="${name}" type="${type}" value="${escapeAttr(value)}"></label>`;
    const textarea = (name, label, value = '') => `<label class="wide">${label}<textarea name="${name}" rows="3">${escapeHtml(value || '')}</textarea></label>`;
    const hidden = row.id ? `<input name="id" type="hidden" value="${Number(row.id)}">` : '';
    const select = (name, label, value = '', rows = []) => `<label>${label}<select name="${name}"><option value=""></option>${rows.map((item) => `<option value="${Number(item.id)}" ${Number(item.id) === Number(value) ? 'selected' : ''}>${escapeHtml(item.nombre || '')}</option>`).join('')}</select></label>`;
    const optionSelect = (name, label, value = '', options = []) => `<label>${label}<select name="${name}">${options.map((item) => `<option value="${escapeAttr(item.value)}" ${String(item.value) === String(value) ? 'selected' : ''}>${escapeHtml(item.label)}</option>`).join('')}</select></label>`;

    if (module === 'personal') {
        return hidden + [
            input('dni', 'DNI', row.dni || ''),
            optionSelect('estado', 'Estado', row.estado || 'activo', [
                { value: 'activo', label: 'activo' },
                { value: 'inactivo', label: 'inactivo' },
            ]),
            input('nombres', 'Nombres', row.nombres || ''),
            input('apellido_paterno', 'Apellido paterno', row.apellido_paterno || ''),
            input('apellido_materno', 'Apellido materno', row.apellido_materno || ''),
            input('correo_institucional', 'Correo institucional', row.correo_institucional || '', 'email'),
            input('correo_firma', 'Correo firma', row.correo_firma || '', 'email'),
            input('nombre_completo', 'Nombre completo', row.nombre_completo || ''),
        ].join('');
    }

    if (module === 'equipos') {
        const catalogs = state.catalogs || {};
        const marcas = catalogs.marcas || [];
        const modelos = catalogs.modelos || [];
        const estados = catalogs.estados || [];
        const personalDisplay = looksLikeOnlyId(row.usuario_actual) ? '' : (row.usuario_actual || '');
        return hidden + `
            <div class="form-tabs">
                <button class="form-tab active" type="button" data-form-tab="general">General</button>
                <button class="form-tab" type="button" data-form-tab="hardware">Hardware</button>
                <button class="form-tab" type="button" data-form-tab="software">Software</button>
                <button class="form-tab" type="button" data-form-tab="perifericos">Perifericos</button>
            </div>
            <section class="form-tab-panel active" data-form-panel="general">
                ${input('nombre_pc', 'Nombre PC', row.nombre_pc || '')}
                ${input('numero_ip', 'IP', row.numero_ip || '')}
                ${input('mac_address', 'MAC', row.mac_address || '')}
                ${input('cod_patrimonio', 'Codigo patrimonio', row.cod_patrimonio || '')}
                ${select('estado_id', 'Estado equipo', row.estado_id || '', estados)}
                ${select('unidad_id', 'Unidad', row.unidad_id || '', catalogs.unidades || [])}
                ${select('area_id', 'Area', row.area_id || '', catalogs.areas || [])}
                ${select('oficina_id', 'Oficina', row.oficina_id || '', catalogs.oficinas || [])}
                <label class="wide personal-picker">Personal asignado
                    <input id="equipoPersonalSearch" type="search" value="${escapeAttr(personalDisplay)}" placeholder="Buscar por DNI o nombre">
                    <input name="usuario_actual_id" id="equipoPersonalId" type="hidden" value="${escapeAttr(row.usuario_actual_id || '')}">
                    <div class="selected-personal-notice ${personalDisplay ? '' : 'hidden'}" id="equipoPersonalSelected"><span>${escapeHtml(personalDisplay)}</span><button type="button" class="ghost-button" data-clear-personal>Quitar</button></div>
                    <div class="inline-results" id="equipoPersonalResults"></div>
                </label>
                ${input('fecha_adquisicion', 'Fecha adquisicion', normalizeDateValue(row.fecha_adquisicion || ''), 'date')}
                ${input('fecha_registro', 'Fecha registro', normalizeDateValue(row.fecha_registro || ''), 'date')}
                ${textarea('observacion', 'Observacion', row.observacion || '')}
            </section>
            <section class="form-tab-panel" data-form-panel="hardware">
                ${select('marca_cpu_id', 'Marca CPU', row.marca_cpu_id || '', marcas)}
                ${select('modelo_cpu_id', 'Modelo CPU', row.modelo_cpu_id || '', modelos)}
                ${input('serie_cpu', 'N serie CPU', row.serie_cpu || '')}
                ${select('estado_cpu_id', 'Estado CPU', row.estado_cpu_id || '', estados)}
                ${select('marca_mainboard_id', 'Marca mainboard', row.marca_mainboard_id || '', marcas)}
                ${select('modelo_mainboard_id', 'Modelo mainboard', row.modelo_mainboard_id || '', modelos)}
                ${select('marca_procesador_id', 'Marca procesador', row.marca_procesador_id || '', marcas)}
                ${input('procesador', 'Procesador', row.procesador || '')}
                ${input('velocidad_procesador', 'Velocidad procesador', row.velocidad_procesador || '')}
                ${input('generacion_procesador', 'Generacion procesador', row.generacion_procesador || '')}
                ${input('tipo_memoria', 'Tipo memoria', row.tipo_memoria || '')}
                ${input('total_memoria_gb', 'Total memoria GB', row.total_memoria_gb || '', 'number')}
                ${select('marca_disco_id', 'Marca disco', row.marca_disco_id || '', marcas)}
                ${select('modelo_disco_id', 'Modelo disco', row.modelo_disco_id || '', modelos)}
                ${input('capacidad_disco', 'Capacidad disco', row.capacidad_disco || '')}
                ${input('tipo_disco', 'Tipo disco', row.tipo_disco || '')}
                ${select('marca_video_id', 'Marca video', row.marca_video_id || '', marcas)}
                ${select('modelo_video_id', 'Modelo video', row.modelo_video_id || '', modelos)}
            </section>
            <section class="form-tab-panel" data-form-panel="software">
                ${select('sistema_operativo_id', 'Sistema operativo', row.sistema_operativo_id || '', catalogs.sistemas_operativos || [])}
                ${input('licencia_so', 'Licencia SO', row.licencia_so || '')}
                ${input('key_so', 'Key SO', row.key_so || '')}
                ${input('software_oficina', 'Software oficina', row.software_oficina || '')}
                ${input('key_software_oficina', 'Key software oficina', row.key_software_oficina || '')}
                ${input('internet', 'Internet', row.internet || '')}
                ${select('antivirus_id', 'Antivirus', row.antivirus_id || '', catalogs.antivirus || [])}
            </section>
            <section class="form-tab-panel" data-form-panel="perifericos">
                ${select('marca_monitor_id', 'Marca monitor', row.marca_monitor_id || '', marcas)}
                ${input('tipo_monitor', 'Tipo monitor', row.tipo_monitor || '')}
                ${select('modelo_monitor_id', 'Modelo monitor', row.modelo_monitor_id || '', modelos)}
                ${input('monitor_pulgadas', 'Monitor pulgadas', row.monitor_pulgadas || '')}
                ${input('serie_monitor', 'N serie monitor', row.serie_monitor || '')}
                ${input('cod_monitor', 'Cod monitor', row.cod_monitor || '')}
                ${select('estado_monitor_id', 'Estado monitor', row.estado_monitor_id || '', estados)}
                ${select('marca_teclado_id', 'Marca teclado', row.marca_teclado_id || '', marcas)}
                ${select('modelo_teclado_id', 'Modelo teclado', row.modelo_teclado_id || '', modelos)}
                ${input('serie_teclado', 'N serie teclado', row.serie_teclado || '')}
                ${input('cod_teclado', 'Cod teclado', row.cod_teclado || '')}
                ${select('estado_teclado_id', 'Estado teclado', row.estado_teclado_id || '', estados)}
                ${select('marca_mouse_id', 'Marca mouse', row.marca_mouse_id || '', marcas)}
                ${select('modelo_mouse_id', 'Modelo mouse', row.modelo_mouse_id || '', modelos)}
                ${input('serie_mouse', 'N serie mouse', row.serie_mouse || '')}
                ${select('estado_mouse_id', 'Estado mouse', row.estado_mouse_id || '', estados)}
                ${select('marca_estabilizador_id', 'Marca estabilizador', row.marca_estabilizador_id || '', marcas)}
                ${select('modelo_estabilizador_id', 'Modelo estabilizador', row.modelo_estabilizador_id || '', modelos)}
                ${input('serie_estabilizador', 'N serie estabilizador', row.serie_estabilizador || '')}
                ${input('cod_estabilizador', 'Cod estabilizador', row.cod_estabilizador || '')}
                ${select('estado_estabilizador_id', 'Estado estabilizador', row.estado_estabilizador_id || '', estados)}
                ${select('marca_grabadora_dvd_id', 'Marca grabadora DVD', row.marca_grabadora_dvd_id || '', marcas)}
                ${input('tipo_grabadora_dvd', 'Tipo grabadora DVD', row.tipo_grabadora_dvd || '')}
                ${input('velocidad_grabadora', 'Velocidad grabadora', row.velocidad_grabadora || '')}
            </section>
        `;
    }

    const catalogs = state.catalogs || {};
    return hidden + [
        input('ubicacion', 'Ubicacion', row.ubicacion || ''),
        select('unidad_id', 'Unidad', row.unidad_id || '', catalogs.unidades || []),
        select('area_id', 'Area', row.area_id || '', catalogs.areas || []),
        input('codpat', 'Codigo patrimonio', row.codpat || ''),
        input('cod_inv', 'Codigo inventario', row.cod_inv || ''),
        select('tipo_equipo_id', 'Tipo equipo', row.tipo_equipo_id || '', catalogs.tipos_equipo || []),
        select('marca_id', 'Marca', row.marca_id || '', catalogs.marcas || []),
        select('modelo_id', 'Modelo', row.modelo_id || '', catalogs.modelos || []),
        select('tipo_impresora_id', 'Tipo impresora', row.tipo_impresora_id || '', catalogs.tipos_impresora || []),
        input('serie', 'Serie', row.serie || ''),
        select('estado_id', 'Estado', row.estado_id || '', catalogs.estados || []),
        textarea('observacion', 'Observacion', row.observacion || ''),
        textarea('nota', 'Nota', row.nota || ''),
    ].join('');
}

async function saveCurrentRecord(event) {
    event.preventDefault();
    const module = state.editing?.module;
    if (!module) return;

    const data = Object.fromEntries(new FormData(event.currentTarget).entries());
    const action = { personal: 'save_personal', equipos: 'save_equipo', impresoras: 'save_impresora' }[module];
    setMessage($('#dialogMessage'), 'Guardando...');

    try {
        await api(`inventory.php?action=${action}`, {
            method: 'POST',
            body: JSON.stringify(data),
        });
        $('#recordDialog').close();
        await loadModule(module);
        const summary = await api('inventory.php?action=summary');
        renderSummary(summary.data || {});
    } catch (error) {
        setMessage($('#dialogMessage'), error.message, 'error');
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function joinLines(values) {
    const lines = values.filter((value) => value !== null && value !== undefined && String(value).trim() !== '');
    return lines.length > 0 ? lines.map((value) => `<span>${escapeHtml(value)}</span>`).join('') : '';
}

function normalizeDateValue(value) {
    value = String(value || '').trim();
    const slash = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (slash) return `${slash[3]}-${slash[2]}-${slash[1]}`;
    const isoDate = value.match(/^(\d{4}-\d{2}-\d{2})/);
    if (isoDate) return isoDate[1];
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : '';
}

function looksLikeOnlyId(value) {
    return /^\d+$/.test(String(value || '').trim());
}

function escapeAttr(value) {
    return escapeHtml(value).replaceAll('`', '&#096;');
}

$('#loginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    setMessage($('#loginMessage'), 'Validando...');

    try {
        const payload = await api('login.php', {
            method: 'POST',
            body: JSON.stringify({
                login: $('#loginEmail').value.trim(),
                password: $('#loginPassword').value,
            }),
        });
        state.token = payload.data.access_token;
        state.refreshToken = payload.data.refresh_token || '';
        state.user = payload.data.user;
        localStorage.setItem('inventario_neo_token', state.token);
        localStorage.setItem('inventario_neo_refresh_token', state.refreshToken);
        localStorage.setItem('inventario_neo_user', JSON.stringify(state.user));
        showApp();
        await loadInitialData();
    } catch (error) {
        setMessage($('#loginMessage'), error.message, 'error');
    }
});

$$('.nav-item').forEach((button) => button.addEventListener('click', () => selectView(button.dataset.view)));
$('#logoutButton').addEventListener('click', logout);
$('#newRecordButton').addEventListener('click', () => openDialog(state.activeView).catch(handleDialogError));
$('#closeDialogButton').addEventListener('click', () => $('#recordDialog').close());
$('#cancelDialogButton').addEventListener('click', () => $('#recordDialog').close());
$('#recordForm').addEventListener('submit', saveCurrentRecord);
$('[data-profile-link]').addEventListener('click', (event) => {
    event.preventDefault();
    selectView('profile');
});
$('#profileRefreshButton').addEventListener('click', () => loadProfile().catch((error) => {
    $('#profileMessage').textContent = error.message;
}));
$('#profileForm').addEventListener('submit', (event) => {
    saveProfile(event).catch((error) => {
        $('#profileMessage').textContent = error.message;
    });
});
$('#requestPasswordResetButton').addEventListener('click', () => {
    requestPasswordReset().catch((error) => {
        $('#profileMessage').textContent = error.message;
    });
});
$('#changeProfilePasswordButton').addEventListener('click', () => {
    changeProfilePassword().catch((error) => {
        $('#changeProfilePasswordMessage').textContent = error.message;
    });
});
['profileDisplayName', 'profileAccent', 'profileAvatarUrl'].forEach((id) => {
    $(`#${id}`)?.addEventListener('input', () => {
        const displayName = $('#profileDisplayName').value.trim() || 'Usuario';
        const accent = normalizeHexColor($('#profileAccent').value);
        const avatarUrl = $('#profileAvatarUrl').value.trim();
        $('#profileHeroName').textContent = displayName;
        $('#profileAvatarPreview').textContent = initials(displayName);
        $('#profileAvatarPreview').style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
        $('#profileAvatarPreview').classList.toggle('with-image', Boolean(avatarUrl));
        renderAvatarPresets(avatarUrl);
        applyProfileAccent(accent);
    });
});
$('#profileAvatarPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-avatar-url]');
    if (!button) return;
    $('#profileAvatarUrl').value = button.dataset.avatarUrl || '';
    $('#profileAvatarUrl').dispatchEvent(new Event('input'));
});
$('#profileBackgroundPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-background-url]');
    if (!button) return;
    applyProfileBackground(button.dataset.backgroundUrl || '', $('#profileBackgroundOpacity')?.value || 12);
});
$('#profileBackgroundUrl')?.addEventListener('input', (event) => {
    applyProfileBackground(event.target.value, $('#profileBackgroundOpacity')?.value || 12);
});
$('#profileBackgroundOpacity')?.addEventListener('input', (event) => {
    applyProfileBackground($('#profileBackgroundUrl')?.value || '', event.target.value);
});
$('#profileHue')?.addEventListener('input', (event) => {
    profileColorState.h = Number(event.target.value) || 0;
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
});
bindColorPicker();

$('#globalSearch').addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && state.activeView !== 'dashboard') {
        state.pages[state.activeView].page = 1;
        loadModule(state.activeView).catch((error) => handleLoadError(error, state.activeView));
    }
});

document.body.addEventListener('click', (event) => {
    const create = event.target.closest('[data-create]');
    if (create) {
        openDialog(create.dataset.create).catch(handleDialogError);
        return;
    }

    const edit = event.target.closest('[data-edit]');
    if (edit) {
        const module = edit.dataset.edit;
        const row = state.rows[module].find((item) => Number(item.id) === Number(edit.dataset.id)) || {};
        openDialog(module, row).catch(handleDialogError);
        return;
    }

    const tab = event.target.closest('[data-form-tab]');
    if (tab) {
        $$('.form-tab').forEach((button) => button.classList.toggle('active', button === tab));
        $$('[data-form-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.formPanel === tab.dataset.formTab));
        return;
    }

    const personalPick = event.target.closest('[data-personal-pick]');
    if (personalPick) {
        $('#equipoPersonalId').value = personalPick.dataset.personalId || '';
        $('#equipoPersonalSearch').value = personalPick.dataset.personalName || '';
        const selected = $('#equipoPersonalSelected');
        selected.classList.remove('hidden');
        selected.querySelector('span').textContent = personalPick.dataset.personalName || '';
        $('#equipoPersonalResults').innerHTML = '';
        return;
    }

    const clearPersonal = event.target.closest('[data-clear-personal]');
    if (clearPersonal) {
        $('#equipoPersonalId').value = '';
        $('#equipoPersonalSearch').value = '';
        $('#equipoPersonalSelected').classList.add('hidden');
        $('#equipoPersonalResults').innerHTML = '';
        return;
    }

    const refresh = event.target.closest('[data-refresh]');
    if (refresh) {
        loadModule(refresh.dataset.refresh).catch((error) => handleLoadError(error, refresh.dataset.refresh));
        return;
    }

    const page = event.target.closest('[data-page]');
    if (page) {
        const module = page.dataset.page;
        state.pages[module].page += Number(page.dataset.dir);
        loadModule(module).catch((error) => handleLoadError(error, module));
    }
});

document.body.addEventListener('input', (event) => {
    if (event.target.id !== 'equipoPersonalSearch') {
        return;
    }

    $('#equipoPersonalId').value = '';
    $('#equipoPersonalSelected')?.classList.add('hidden');
    clearTimeout(state.personalSearchTimer);
    state.personalSearchTimer = setTimeout(async () => {
        const query = event.target.value.trim();
        const results = $('#equipoPersonalResults');
        if (query.length < 2) {
            results.innerHTML = '';
            return;
        }

        try {
            const payload = await api(`inventory.php?action=personal_search&search=${encodeURIComponent(query)}`);
            const rows = payload.data.rows || [];
            results.innerHTML = rows.map((row) => `
                <button type="button" data-personal-pick data-personal-id="${Number(row.id)}" data-personal-name="${escapeAttr(row.nombre_completo || '')}">
                    <strong>${escapeHtml(row.nombre_completo || '')}</strong>
                    <small>${escapeHtml(row.dni || '')}</small>
                </button>
            `).join('') || '<span>Sin coincidencias.</span>';
        } catch (error) {
            results.innerHTML = '<span>No se pudo buscar personal.</span>';
        }
    }, 250);
});

consumePortalSession();

if (state.token) {
    showApp();
    loadInitialData().catch((error) => handleLoadError(error, state.activeView));
} else {
    showLogin();
}
