const state = {
    token: localStorage.getItem('gs_neo_token') || '',
    refreshToken: localStorage.getItem('gs_neo_refresh_token') || '',
    user: JSON.parse(localStorage.getItem('gs_neo_user') || 'null'),
    catalogs: { roles: [], grupos: [], politicas: [] },
    users: [],
    usersPagination: { page: 1, per_page: 20, total: 0, pages: 1 },
    editingUser: null,
    groupsOverview: [],
    activeGroup: null,
    selectedPersonal: null,
    permissions: { admin: false, usuarios_ver: false },
    notificationTimer: null,
    sessionWarningTimer: null,
    refreshPromise: null,
};

const apiBase = window.GS_NEO_API_BASE || '../api/v1';
const presetAvatars = Array.isArray(window.GS_NEO_PRESET_AVATARS) ? window.GS_NEO_PRESET_AVATARS : [];
const presetBackgrounds = Array.isArray(window.GS_NEO_PRESET_BACKGROUNDS) ? window.GS_NEO_PRESET_BACKGROUNDS : [];
const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

function setMessage(element, text, type = '') {
    element.textContent = text;
    element.className = 'form-message ' + type;
}

async function api(path, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (state.token) {
        headers.Authorization = `Bearer ${state.token}`;
        headers['X-Auth-Token'] = state.token;
    }

    const response = await fetch(`${apiBase}/${path}`, { ...options, headers });
    const payload = await response.json().catch(() => null);
    if (response.status === 401 && !options.retryingAuth && await refreshSession()) {
        return api(path, { ...options, retryingAuth: true });
    }

    if (!response.ok || !payload?.success) {
        throw new Error(payload?.message || 'No se pudo completar la operacion.');
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
            if (!response.ok || payload.success === false || !payload.data?.access_token) {
                return false;
            }

            state.token = payload.data.access_token;
            state.user = payload.data.user || state.user;
            localStorage.setItem('gs_neo_token', state.token);
            localStorage.setItem('gs_neo_user', JSON.stringify(state.user));
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

function logout() {
    const refreshToken = state.refreshToken;
    if (refreshToken) {
        fetch(`${apiBase}/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: refreshToken }),
        }).catch(() => {});
    }
    localStorage.removeItem('gs_neo_token');
    localStorage.removeItem('gs_neo_refresh_token');
    localStorage.removeItem('gs_neo_user');
    state.token = '';
    state.refreshToken = '';
    state.user = null;
    hideSessionWarning();
    showLogin();
}

function showApp() {
    $('#loginScreen').classList.add('hidden');
    $('#appShell').classList.remove('hidden');
    applyGlobalProfile();
    refreshGlobalProfile().catch(() => {});
    startNotifications();
    scheduleSessionWarning();
}

function showLogin() {
    $('#appShell').classList.add('hidden');
    $('#loginScreen').classList.remove('hidden');
    stopNotifications();
    $('#loginEmail').focus();
}

function selectView(name) {
    const targetButton = document.querySelector(`.nav-item[data-view="${name}"]`);
    if (targetButton?.hidden) {
        name = 'dashboard';
    }

    $$('.nav-item').forEach((button) => button.classList.toggle('active', button.dataset.view === name));
    $$('[data-view-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.viewPanel === name));
    if (name === 'profile') {
        loadProfile().catch((error) => {
            $('#profileMessage').textContent = error.message;
        });
    }
    if (name === 'password-requests') {
        if (!state.permissions.admin) {
            $('#passwordRequestsMessage').textContent = 'No tienes permiso para administrar reinicios.';
            return;
        }
        loadPasswordResetRequests().catch((error) => {
            $('#passwordRequestsMessage').textContent = error.message;
        });
    }
}

function applyPermissions() {
    const adminOnlyViews = ['create', 'groups', 'catalog', 'audit', 'password-requests'];
    adminOnlyViews.forEach((view) => {
        document.querySelectorAll(`.nav-item[data-view="${view}"]`).forEach((button) => {
            button.hidden = !state.permissions.admin;
        });
    });
    if (document.querySelector('.nav-item.active[hidden]')) {
        selectView('dashboard');
    }
}

function applyGlobalProfile() {
    const profile = state.user?.perfil || {};
    const displayName = profile.nombre_visible || state.user?.nombre || displayLogin(state.user) || 'Super Admin';
    const accent = normalizeHexColor(profile.color_principal);
    const avatarUrl = String(profile.avatar_url || '').trim();
    const backgroundUrl = String(profile.background_url || '').trim();
    const backgroundOpacity = normalizeBackgroundOpacity(profile.background_opacity);
    const chip = $('#currentUser');
    const profileName = $('#profileName');
    const profileLogin = $('#profileLogin');

    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    chip.textContent = '⋮';
    chip.title = displayName;
    chip.style.backgroundImage = '';
    chip.classList.remove('with-image');
    profileName.textContent = displayName;
    profileLogin.textContent = displayLogin(state.user) || profile.cargo_area || 'Usuario';
    updateTopbarProfileSummary(displayName, profile.cargo_area || state.user?.rol_nombre || 'Usuario', accent, avatarUrl);
}

function updateStoredUserProfile(profile) {
    state.user = {
        ...(state.user || {}),
        perfil: {
            ...(state.user?.perfil || {}),
            nombre_visible: profile.nombre_visible || '',
            cargo_area: profile.cargo_area || '',
            color_principal: profile.color_principal || '#7c3aed',
            avatar_url: profile.avatar_url || '',
            background_url: profile.background_url || '',
            background_opacity: normalizeBackgroundOpacity(profile.background_opacity),
        },
    };
    localStorage.setItem('gs_neo_user', JSON.stringify(state.user));
    applyGlobalProfile();
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

    const picker = $('#profileColorPicker');
    if (picker) {
        picker.style.setProperty('--picker-hue-color', hsvToHex(profileColorState.h, 100, 100));
        picker.style.setProperty('--picker-saturation', String(profileColorState.s));
        picker.style.setProperty('--picker-value', String(profileColorState.v));
    }
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
    $('#profileAccent').value = accent.slice(1);
    $('#profileAccentPreview').style.background = accent;
    $('#profileHue').value = String(hexToHue(accent));
    syncColorPicker(accent);
    $('#profileColorSummary').textContent = accent;
    $('#topbarProfileColor') && ($('#topbarProfileColor').textContent = accent);
    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    return accent;
}

function updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl) {
    const avatar = $('#topbarProfileAvatar');
    avatar.textContent = initials(displayName || 'Usuario');
    avatar.style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    avatar.classList.toggle('with-image', Boolean(avatarUrl));
    $('#topbarProfileName').textContent = displayName || 'Usuario';
    $('#topbarProfileRole').textContent = roleArea || 'Usuario';
    $('#topbarProfileColor') && ($('#topbarProfileColor').textContent = normalizeHexColor(accent));
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
    applyProfileAccent(accent);
    $('#profileAvatarUrl').value = profile.avatar_url || '';
    $('#profileHeroName').textContent = displayName;
    $('#profileHeroRole').textContent = roleArea;
    $('#profileAvatarPreview').textContent = initials(displayName);
    $('#profileAvatarPreview').style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    $('#profileAvatarPreview').classList.toggle('with-image', Boolean(avatarUrl));
    renderAvatarPresets(avatarUrl);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl);
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
    const password = $('#newProfilePassword').value;
    const confirm = $('#confirmProfilePassword').value;
    $('#changeProfilePasswordMessage').textContent = 'Actualizando contrasena...';
    await api('profile.php?action=cambiar_password', {
        method: 'POST',
        body: JSON.stringify({ password, confirm_password: confirm }),
    });
    $('#newProfilePassword').value = '';
    $('#confirmProfilePassword').value = '';
    $('#passwordResetPanel').classList.add('hidden');
    $('#changeProfilePasswordMessage').textContent = 'Contrasena actualizada.';
}

function togglePasswordInput(id) {
    const input = $(`#${id}`);
    input.type = input.type === 'password' ? 'text' : 'password';
}

async function loadPasswordResetRequests() {
    $('#passwordRequestsMessage').textContent = 'Cargando solicitudes...';
    const payload = await api('users.php?action=password_reset_requests');
    renderPasswordResetRequests(payload.data.solicitudes || []);
    $('#passwordRequestsMessage').textContent = '';
}

function renderPasswordResetRequests(rows) {
    if (!rows.length) {
        $('#passwordRequestsTable').innerHTML = '<tr><td colspan="4"><div class="empty-state">No hay solicitudes pendientes.</div></td></tr>';
        return;
    }

    $('#passwordRequestsTable').innerHTML = rows.map((row) => `
        <tr>
            <td><strong>${escapeHtml(`${row.nombres || ''} ${row.apellidos || ''}`.trim() || row.email || 'Usuario')}</strong><br><small>${escapeHtml(row.email || '')}</small></td>
            <td>${escapeHtml(row.dni || '')}</td>
            <td>${escapeHtml(row.solicitado_en || '')}</td>
            <td><button class="ok-button" type="button" data-approve-password-reset="${Number(row.id)}">Aprobar</button></td>
        </tr>
    `).join('');
}

async function loadNotifications() {
    if (!state.token) {
        return;
    }

    const payload = await api('users.php?action=notifications');
    renderNotifications(payload.data || { total: 0, items: [] });
}

function renderNotifications(data) {
    const total = Number(data.total || 0);
    const items = data.items || [];
    const badge = $('#notificationBadge');
    const navCount = $('#passwordRequestsNavCount');

    badge.textContent = total;
    badge.classList.toggle('hidden', total <= 0);
    navCount.textContent = total;
    navCount.classList.toggle('hidden', total <= 0);

    if (items.length === 0) {
        $('#notificationList').innerHTML = '<span>Sin notificaciones pendientes.</span>';
        return;
    }

    $('#notificationList').innerHTML = items.map((item) => `
        <button type="button" class="notification-item" data-notification-view="${escapeHtml(item.view || '')}">
            <strong>${escapeHtml(item.title || 'Notificacion')}</strong>
            <span>${escapeHtml(item.message || '')}</span>
            <small>${escapeHtml(item.created_at || '')}</small>
        </button>
    `).join('');
}

function startNotifications() {
    stopNotifications();
    if (!state.permissions.admin) {
        renderNotifications({ total: 0, items: [] });
        return;
    }

    loadNotifications().catch(() => {});
    state.notificationTimer = window.setInterval(() => {
        loadNotifications().catch(() => {});
        const active = document.querySelector('[data-view-panel="password-requests"].active');
        if (active) {
            loadPasswordResetRequests().catch(() => {});
        }
    }, 30000);
}

function stopNotifications() {
    if (state.notificationTimer) {
        window.clearInterval(state.notificationTimer);
        state.notificationTimer = null;
    }
}

function renderSummary(summary) {
    state.permissions = {
        admin: Boolean(summary.permisos?.admin),
        usuarios_ver: Boolean(summary.permisos?.usuarios_ver),
    };
    applyPermissions();

    const groups = Array.isArray(summary.grupos) ? summary.grupos : [];
    const systems = groupBySystem(groups).map(({ system }) => system.name);
    const user = summary.usuario || state.user || {};
    const displayName = `${user.nombres || ''} ${user.apellidos || ''}`.trim() || state.user?.nombre || 'Usuario';
    const groupNames = groups.map((group) => readableGroupName(group.nombre, systemKeyForGroup(group.nombre))).filter(Boolean);
    const cards = [
        ['Usuario', displayName, displayLogin(user) || 'Cuenta activa', 'U', 'Perfil'],
        ['Rol asignado', summary.rol || 'Sin rol', 'Vigente para tu cuenta', 'R', 'Rol'],
        ['Sistemas', systems.length ? systems.join(', ') : 'Sin sistemas', `${systems.length} asignado${systems.length === 1 ? '' : 's'}`, 'S', 'Accesos'],
        ['Grupos', groupNames.length ? groupNames.join(', ') : 'Sin grupos', `${groups.length} grupo${groups.length === 1 ? '' : 's'} activo${groups.length === 1 ? '' : 's'}`, 'G', 'Grupos'],
    ];

    $('#summaryCards').innerHTML = cards.map(([label, value, detail, icon, chip]) => `
        <article class="metric-card" data-icon="${icon}">
            <span class="metric-chip">${chip}</span>
            <small>${label}</small>
            <strong class="metric-value">${escapeHtml(value)}</strong>
            <small>${detail}</small>
        </article>
    `).join('');
}

function renderUsers(users) {
    state.users = users;
    if (users.length === 0) {
        $('#usersTable').innerHTML = '<tr><td colspan="6"><div class="empty-state">No hay usuarios para mostrar.</div></td></tr>';
        renderUsersPagination();
        return;
    }

    $('#usersTable').innerHTML = users.map((user) => `
        <tr>
            <td>
                <strong>${escapeHtml(`${user.nombres || ''} ${user.apellidos || ''}`.trim())}</strong><br>
                <small>${escapeHtml(displayLogin(user))}</small>
            </td>
            <td>${escapeHtml(user.dni || '')}</td>
            <td>${renderGroupTags(user.grupos || '')}</td>
            <td><span class="status ${user.estado === 'activo' ? '' : 'inactivo'}">${escapeHtml(user.estado || '')}</span></td>
            <td>${escapeHtml(user.ultimo_login_en || '-')}</td>
            <td>
                <div class="row-actions">
                    <button class="ghost-button" data-edit-groups="${user.id}" type="button">Accesos</button>
                    ${user.estado === 'activo'
                        ? `<button class="danger-button" data-deactivate="${user.id}" type="button">Desactivar</button>`
                        : `<button class="ok-button" data-activate="${user.id}" type="button">Activar</button>`}
                    <button class="icon-danger-button" data-retire="${user.id}" type="button" title="Retirar y liberar correo">Eliminar</button>
                </div>
            </td>
        </tr>
    `).join('');
    renderUsersPagination();
}

function renderUsersPagination() {
    const pagination = state.usersPagination;
    const from = pagination.total === 0 ? 0 : ((pagination.page - 1) * pagination.per_page) + 1;
    const to = Math.min(pagination.page * pagination.per_page, pagination.total);
    $('#usersPageInfo').textContent = `${from}-${to} de ${pagination.total} usuarios`;
    $('#prevUsersPage').disabled = pagination.page <= 1;
    $('#nextUsersPage').disabled = pagination.page >= pagination.pages;
}

function renderCatalogs() {
    $('#groupsList').innerHTML = renderGroupedChecks(state.catalogs.grupos, 'grupo_ids');
    $('#directPoliciesList').innerHTML = renderPolicyChecks(state.catalogs.politicas, 'politica_ids');

    $('#groupsCatalog').innerHTML = state.catalogs.grupos.map((group) => `<span class="tag">${escapeHtml(group.nombre)}</span>`).join('');
    $('#policiesCatalog').innerHTML = state.catalogs.politicas.map((policy) => `
        <span class="tag policy-tag">
            <strong>${escapeHtml(policy.codigo || policy.nombre || '')}</strong>
            <small>${escapeHtml([policy.efecto, policy.accion, policy.recurso].filter(Boolean).join(' - '))}</small>
        </span>
    `).join('');
}

function renderGroupTags(groupsText) {
    const groups = String(groupsText || '').split(',').map((group) => group.trim()).filter(Boolean);
    if (groups.length === 0) {
        return '<small>Sin grupos</small>';
    }

    return `<div class="mini-tags">${groups.map((group) => `<span>${escapeHtml(group)}</span>`).join('')}</div>`;
}

function groupIdsForUser(user) {
    return String(user?.grupo_ids || '')
        .split(',')
        .map((id) => Number(id.trim()))
        .filter((id) => id > 0);
}

function policyIdsForUser(user) {
    return String(user?.politica_ids || '')
        .split(',')
        .map((id) => Number(id.trim()))
        .filter((id) => id > 0);
}

function openAccessPanel(userId) {
    const user = state.users.find((item) => Number(item.id) === Number(userId));
    if (!user) {
        return;
    }

    state.editingUser = user;
    const selected = new Set(groupIdsForUser(user));
    const selectedPolicies = new Set(policyIdsForUser(user));
    $('#accessUserTitle').textContent = `Accesos de ${`${user.nombres || ''} ${user.apellidos || ''}`.trim() || displayLogin(user)}`;
    $('#accessUserSubtitle').textContent = `${displayLogin(user)} - ${user.dni || ''}`;
    $('#editGroupsList').innerHTML = renderGroupedChecks(state.catalogs.grupos, 'edit_grupo_ids', selected);
    $('#editDirectPoliciesList').innerHTML = renderPolicyChecks(state.catalogs.politicas, 'edit_politica_ids', selectedPolicies);
    setMessage($('#accessMessage'), '');
    $('#userAccessPanel').classList.remove('hidden');
    $('#userAccessPanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function closeAccessPanel() {
    state.editingUser = null;
    $('#userAccessPanel').classList.add('hidden');
    $('#editGroupsList').innerHTML = '';
    $('#editDirectPoliciesList').innerHTML = '';
    setMessage($('#accessMessage'), '');
}

function fillPersonal(person) {
    state.selectedPersonal = person;
    $('#personalId').value = person.id || '';
    $('#dni').value = person.dni || '';
    $('#nombres').value = person.nombres || '';
    $('#apellidos').value = [person.apellido_paterno, person.apellido_materno].filter(Boolean).join(' ');
    $('#email').value = person.correo_institucional || person.correo_firma || '';
    setPersonalFieldsLocked(true);
    $('#personalResults').innerHTML = '';
    $('#password').focus();
}

function clearSelectedPersonal() {
    state.selectedPersonal = null;
    $('#personalId').value = '';
    $('#dni').value = '';
    $('#nombres').value = '';
    $('#apellidos').value = '';
    $('#email').value = '';
    setPersonalFieldsLocked(false);
    $('#personalSearch').focus();
}

function displayLogin(user) {
    const email = String(user?.email || '').trim();
    if (email !== '' && !email.toLowerCase().endsWith('@dni.local')) {
        return email;
    }

    return String(user?.dni || '').trim();
}

function permissionDescription(group) {
    const explicit = String(group?.descripcion || '').trim();
    if (explicit !== '') {
        return explicit;
    }

    const name = String(group?.nombre || '').toLowerCase();
    if (name.includes('super')) {
        return 'Acceso administrativo completo al ecosistema NEO y gestion de usuarios.';
    }
    if (name.includes('administrador')) {
        return 'Permite administrar configuracion, catalogos y operaciones del sistema.';
    }
    if (name.includes('consulta')) {
        return 'Permite revisar informacion y reportes sin modificar registros operativos.';
    }
    if (name.includes('emision')) {
        return 'Permite emitir tickets y usar el flujo operativo de atencion.';
    }
    if (name.includes('pantalla')) {
        return 'Permite acceder a la pantalla publica de turnos y visualizacion.';
    }
    if (name.includes('rangos')) {
        return 'Permite configurar rangos diarios, numeracion e impresion por lotes.';
    }
    if (name.includes('turnero')) {
        return 'Permite gestionar turnos, llamados y flujo de cola.';
    }
    if (name.includes('ventanilla')) {
        return 'Permite operar la atencion en ventanilla y registrar avance de turnos.';
    }
    if (name.includes('firma')) {
        return 'Permite acceder a funciones del sistema de firmas electronicas.';
    }

    return 'Grupo de acceso permanente. Sus permisos dependen de las politicas asociadas.';
}

function setPersonalFieldsLocked(locked) {
    ['dni', 'nombres', 'apellidos', 'email'].forEach((id) => {
        const input = $(`#${id}`);
        input.readOnly = locked;
        input.classList.toggle('readonly-field', locked);
    });

    $('#selectedPersonalNotice').classList.toggle('hidden', !locked);
}

function renderGroupedChecks(groups, inputName, selected = new Set()) {
    return groupBySystem(groups).map(({ system, items }) => {
        const selectedCount = items.filter((group) => selected.has(Number(group.id))).length;
        const open = selectedCount > 0 ? ' open' : '';

        return `
        <details class="check-system"${open}>
            <summary class="check-system-head">
                <span>${escapeHtml(system.code)}</span>
                <strong>${escapeHtml(system.name)}</strong>
                <small>${selectedCount > 0 ? `${selectedCount}/${items.length}` : `${items.length} grupos`}</small>
            </summary>
            <div class="check-grid">
                ${items.map((group) => `
                    <label class="check-pill permission-pill" title="${escapeHtml(permissionDescription(group))}" data-tooltip="${escapeHtml(permissionDescription(group))}">
                        <input type="checkbox" name="${inputName}" value="${group.id}" ${selected.has(Number(group.id)) ? 'checked' : ''}>
                        <span>${escapeHtml(readableGroupName(group.nombre, system))}</span>
                        <i aria-hidden="true">?</i>
                    </label>
                `).join('')}
            </div>
        </details>
    `;
    }).join('');
}

function renderPolicyChecks(policies, inputName, selected = new Set()) {
    return groupPoliciesBySystem(policies).map(({ system, items }) => {
        const selectedCount = items.filter((policy) => selected.has(Number(policy.id))).length;
        const open = selectedCount > 0 ? ' open' : '';

        return `
        <details class="check-system"${open}>
            <summary class="check-system-head">
                <span>${escapeHtml(system.code)}</span>
                <strong>${escapeHtml(system.name)}</strong>
                <small>${selectedCount > 0 ? `${selectedCount}/${items.length}` : `${items.length} permisos`}</small>
            </summary>
            <div class="check-grid">
                ${items.map((policy) => `
                    <label class="check-pill permission-pill" title="${escapeHtml(policyDescription(policy))}" data-tooltip="${escapeHtml(policyDescription(policy))}">
                        <input type="checkbox" name="${inputName}" value="${policy.id}" ${selected.has(Number(policy.id)) ? 'checked' : ''}>
                        <span>${escapeHtml(readablePolicyName(policy))}</span>
                        <i aria-hidden="true">?</i>
                    </label>
                `).join('')}
            </div>
        </details>
    `;
    }).join('');
}

function groupPoliciesBySystem(policies) {
    const systemOrder = ['global', 'gs', 'tickets', 'soporte', 'inventario', 'firmas', 'portal', 'otros'];
    const systems = {
        global: { code: 'SA', name: 'Super administradores', items: [] },
        gs: { code: 'GS', name: 'Gestion de usuarios', items: [] },
        tickets: { code: 'TI', name: 'Sistema de tickets', items: [] },
        soporte: { code: 'ST', name: 'Soporte tecnico', items: [] },
        inventario: { code: 'INV', name: 'Inventario NEO', items: [] },
        firmas: { code: 'SF', name: 'Sistema de firmas', items: [] },
        portal: { code: 'NEO', name: 'Portal NEO', items: [] },
        otros: { code: 'OTR', name: 'Otros permisos', items: [] },
    };

    policies.forEach((policy) => {
        systems[systemKeyForPolicy(policy)].items.push(policy);
    });

    return systemOrder
        .map((key) => ({ system: systems[key], items: systems[key].items }))
        .filter((section) => section.items.length > 0);
}

function systemKeyForPolicy(policy) {
    const system = String(policy?.sistema_codigo || '').toLowerCase();
    const code = String(policy?.codigo || '').toLowerCase();
    const resource = String(policy?.recurso || '').toLowerCase();

    if (code.includes('todo') || resource === 'sistema:*') {
        return 'global';
    }
    if (system === 'gs' || code.includes('.gs.') || code.startsWith('usuarios.') || code.startsWith('grupos.') || code.startsWith('politicas.')) {
        return 'gs';
    }
    if (system === 'tickets' || code.startsWith('tickets.') || resource.includes('tickets')) {
        return 'tickets';
    }
    if (system === 'soporte' || code.startsWith('soporte.') || resource.includes('soporte')) {
        return 'soporte';
    }
    if (system === 'inventario' || code.startsWith('inventario.') || resource.includes('inventario')) {
        return 'inventario';
    }
    if (system === 'firmas' || code.startsWith('firmas.') || resource.includes('firmas')) {
        return 'firmas';
    }
    if (system === 'portal' || code.includes('portal') || code.includes('neo')) {
        return 'portal';
    }

    return 'otros';
}

function readablePolicyName(policy) {
    const explicit = String(policy?.nombre || '').trim();
    if (explicit !== '') {
        return explicit
            .replace(/^Entrar a Gestion de Usuarios$/i, 'Entrar a GS')
            .replace(/^Entrar a Tickets$/i, 'Entrar a Tickets')
            .replace(/^Ver historial de tickets$/i, 'Ver historial')
            .replace(/^Ver reportes de tickets$/i, 'Ver reportes')
            .replace(/^Administrar rangos de tickets$/i, 'Administrar rangos')
            .replace(/^Ver rangos de tickets$/i, 'Ver rangos');
    }

    return String(policy?.codigo || '').replace(/^tickets\./, '').replaceAll('.', ' ');
}

function policyDescription(policy) {
    const description = String(policy?.descripcion || '').trim();
    const code = String(policy?.codigo || '').trim();
    const action = String(policy?.accion || '').trim();
    const resource = String(policy?.recurso || '').trim();

    return [description || 'Permiso unitario directo.', code, [action, resource].filter(Boolean).join(' - ')].filter(Boolean).join('\n');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renderAudit(logs) {
    const table = $('#auditTable');
    if (!table) {
        return;
    }

    if (logs.length === 0) {
        table.innerHTML = '<tr><td colspan="5"><div class="empty-state">Sin registros de auditoria.</div></td></tr>';
        return;
    }

    table.innerHTML = logs.map((log) => `
        <tr>
            <td>${escapeHtml(log.creado_en || '-')}</td>
            <td><span class="tag">${escapeHtml(log.accion || '')}</span></td>
            <td>${escapeHtml(log.usuario_email || log.usuario_id || '-')}</td>
            <td>${escapeHtml(log.descripcion || '')}</td>
            <td>${escapeHtml(log.ip || '-')}</td>
        </tr>
    `).join('');
}

function renderGroupsOverview(groups) {
    state.groupsOverview = groups;
    const container = $('#groupsOverview');
    if (!container) {
        return;
    }

    if (groups.length === 0) {
        container.innerHTML = '<div class="empty-state">No hay grupos activos.</div>';
        return;
    }

    const grouped = groupBySystem(groups);
    container.innerHTML = grouped.map(({ system, items }, sectionIndex) => `
        <section class="system-group" style="animation-delay: ${Math.min(sectionIndex * 0.04, 0.18)}s">
            <div class="system-group-head">
                <div>
                    <span>${system.code}</span>
                    <h3>${escapeHtml(system.name)}</h3>
                </div>
                <small>${items.length} grupos</small>
            </div>
            <div class="group-card-grid">
                ${items.map((group, index) => renderGroupCard(group, index)).join('')}
            </div>
        </section>
    `).join('');
}

function renderGroupCard(group, index) {
    return `
        <article class="group-card" style="animation-delay: ${Math.min(index * 0.035, 0.2)}s">
            <div class="avatar-badge">${initials(group.nombre)}</div>
            <h3>${escapeHtml(group.nombre)}</h3>
            <p>${escapeHtml(group.descripcion || 'Grupo permanente de acceso')}</p>
            <div class="group-card-tags">
                <span>${Number(group.usuarios_count || 0)} usuarios</span>
                <span>${Number(group.politicas_count || 0)} politicas</span>
            </div>
            <button class="ghost-button" data-open-group="${group.id}" type="button">Administrar</button>
        </article>
    `;
}

function groupBySystem(groups) {
    const systemOrder = ['global', 'tickets', 'soporte', 'inventario', 'firmas', 'portal', 'otros'];
    const systems = {
        global: { code: 'SA', name: 'Super administradores', items: [] },
        tickets: { code: 'TI', name: 'Sistema de tickets', items: [] },
        soporte: { code: 'ST', name: 'Soporte tecnico', items: [] },
        inventario: { code: 'INV', name: 'Inventario NEO', items: [] },
        firmas: { code: 'SF', name: 'Sistema de firmas', items: [] },
        portal: { code: 'NEO', name: 'Portal NEO', items: [] },
        otros: { code: 'OTR', name: 'Otros grupos', items: [] },
    };

    groups.forEach((group) => {
        systems[systemKeyForGroup(group.nombre)].items.push(group);
    });

    return systemOrder
        .map((key) => ({ system: systems[key], items: systems[key].items }))
        .filter((section) => section.items.length > 0);
}

function systemKeyForGroup(name) {
    const normalized = String(name || '').toLowerCase();
    if (normalized.includes('super')) {
        return 'global';
    }
    if (normalized.includes('ticket') || normalized.startsWith('ti ') || normalized.startsWith('ti_')) {
        return 'tickets';
    }
    if (normalized.includes('soporte') || normalized.startsWith('st ') || normalized.startsWith('st_')) {
        return 'soporte';
    }
    if (normalized.includes('inventario') || normalized.startsWith('inv ') || normalized.startsWith('inv_')) {
        return 'inventario';
    }
    if (normalized.includes('firma') || normalized.startsWith('sf ') || normalized.startsWith('sf_')) {
        return 'firmas';
    }
    if (normalized.includes('portal') || normalized.includes('neo')) {
        return 'portal';
    }
    return 'otros';
}

function readableGroupName(name, system) {
    const raw = String(name || '').trim();
    const patterns = {
        tickets: /^tickets?\s*[-_:]?\s*/i,
        soporte: /^soporte\s*[-_:]?\s*/i,
        inventario: /^inventario\s*[-_:]?\s*/i,
        firmas: /^(firmas?|sistema de firmas)\s*[-_:]?\s*/i,
        portal: /^(portal|neo)\s*[-_:]?\s*/i,
    };
    const cleaned = raw.replace(patterns[systemKeyForGroup(raw)] || /^$/, '').trim();
    return cleaned || raw;
}

function initials(value) {
    const parts = String(value || '')
        .replaceAll('-', ' ')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2);

    return (parts.map((part) => part[0]).join('') || 'G').toUpperCase();
}

async function loadGroupsOverview() {
    const payload = await api('users.php?action=groups_overview');
    renderGroupsOverview(payload.data.grupos || []);
    if (state.activeGroup) {
        const updated = state.groupsOverview.find((group) => Number(group.id) === Number(state.activeGroup.id));
        if (updated) {
            state.activeGroup = updated;
            updateActiveGroupHeader();
        }
    }
}

async function openGroupPanel(groupId) {
    const group = state.groupsOverview.find((item) => Number(item.id) === Number(groupId));
    if (!group) {
        return;
    }

    state.activeGroup = group;
    updateActiveGroupHeader();
    $('#groupUserSearch').value = '';
    $('#groupUserResults').innerHTML = '';
    setMessage($('#groupMessage'), '');
    $('#groupManagePanel').classList.remove('hidden');
    await loadGroupMembers();
    $('#groupManagePanel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function updateActiveGroupHeader() {
    if (!state.activeGroup) {
        return;
    }

    $('#groupManageTitle').textContent = state.activeGroup.nombre;
    $('#groupManageSubtitle').textContent = `${Number(state.activeGroup.usuarios_count || 0)} usuarios - ${Number(state.activeGroup.politicas_count || 0)} politicas`;
}

async function loadGroupMembers() {
    if (!state.activeGroup) {
        return;
    }

    const payload = await api(`users.php?action=group_members&group_id=${Number(state.activeGroup.id)}`);
    renderGroupMembers(payload.data.usuarios || []);
}

function renderGroupMembers(users) {
    const table = $('#groupMembersTable');
    if (users.length === 0) {
        table.innerHTML = '<tr><td colspan="3"><div class="empty-state">Sin usuarios asignados.</div></td></tr>';
        return;
    }

    table.innerHTML = users.map((user) => `
        <tr>
            <td>
                <strong>${escapeHtml(`${user.nombres || ''} ${user.apellidos || ''}`.trim())}</strong><br>
                <small>${escapeHtml(displayLogin(user))}</small>
            </td>
            <td>${escapeHtml(user.dni || '')}</td>
            <td><button class="danger-button" data-remove-group-user="${user.id}" type="button">Quitar</button></td>
        </tr>
    `).join('');
}

async function searchUsersForGroup() {
    const search = $('#groupUserSearch').value.trim();
    if (search.length < 2) {
        $('#groupUserResults').innerHTML = '';
        return;
    }

    const payload = await api(`users.php?action=group_search_users&search=${encodeURIComponent(search)}`);
    const users = payload.data.usuarios || [];
    if (users.length === 0) {
        $('#groupUserResults').innerHTML = '<div class="empty-state">No se encontraron usuarios.</div>';
        return;
    }

    $('#groupUserResults').innerHTML = users.map((user) => `
        <button class="personal-card" data-add-group-user="${user.id}" type="button">
            <strong>${escapeHtml(`${user.nombres || ''} ${user.apellidos || ''}`.trim())}</strong>
            <small>${escapeHtml(`${displayLogin(user)} - ${user.dni || ''}`)}</small>
        </button>
    `).join('');
}

async function loadInitialData() {
    const [summary, catalogs, users] = await Promise.all([
        api('users.php?action=summary'),
        api('users.php?action=catalogs'),
        api(`users.php?action=list&page=${state.usersPagination.page}&per_page=${state.usersPagination.per_page}`),
    ]);

    state.catalogs = catalogs.data;
    renderSummary(summary.data);
    renderCatalogs();
    state.usersPagination = users.data.pagination || state.usersPagination;
    renderUsers(users.data.usuarios || []);

    if (state.permissions.admin) {
        const audit = await api('users.php?action=audit');
        renderAudit(audit.data.logs || []);
        try {
            await loadGroupsOverview();
        } catch (error) {
            renderGroupsOverview([]);
            setMessage($('#groupMessage'), error.message, 'error');
        }
        startNotifications();
    } else {
        renderAudit([]);
        renderGroupsOverview([]);
    }
}

async function refreshUsers() {
    const search = encodeURIComponent($('#userSearch').value.trim());
    const payload = await api(`users.php?action=list&search=${search}&page=${state.usersPagination.page}&per_page=${state.usersPagination.per_page}`);
    state.usersPagination = payload.data.pagination || state.usersPagination;
    if (state.usersPagination.page > state.usersPagination.pages) {
        state.usersPagination.page = state.usersPagination.pages;
        return refreshUsers();
    }
    renderUsers(payload.data.usuarios || []);
}

async function resetUsersSearch() {
    state.usersPagination.page = 1;
    await refreshUsers();
}

$('#loginForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = $('#loginMessage');
    setMessage(message, 'Validando...');

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
        localStorage.setItem('gs_neo_token', state.token);
        localStorage.setItem('gs_neo_refresh_token', state.refreshToken);
        localStorage.setItem('gs_neo_user', JSON.stringify(state.user));
        showApp();
        await loadInitialData();
    } catch (error) {
        setMessage(message, error.message, 'error');
    }
});

$('#logoutButton').addEventListener('click', () => {
    logout();
});

$('#logoutButtonProfile').addEventListener('click', () => {
    logout();
});

$('#refreshNotificationsButton').addEventListener('click', () => {
    loadNotifications().catch(() => {});
});

$('#notificationList').addEventListener('click', (event) => {
    const item = event.target.closest('[data-notification-view]');
    if (!item || !item.dataset.notificationView) {
        return;
    }

    selectView(item.dataset.notificationView);
});

$$('.nav-item').forEach((button) => {
    button.addEventListener('click', () => selectView(button.dataset.view));
});

$('[data-profile-link]').addEventListener('click', (event) => {
    event.preventDefault();
    selectView('profile');
});

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

$$('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => togglePasswordInput(button.dataset.togglePassword));
});

$('#profileAccent')?.addEventListener('input', (event) => {
    const raw = event.target.value;
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    if (/^#[0-9a-f]{6}$/i.test(withHash)) {
        applyProfileAccent(withHash);
    }
});

$('#profileAvatarPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-avatar-url]');
    if (!button) return;
    $('#profileAvatarUrl').value = button.dataset.avatarUrl || '';
    const displayName = $('#profileDisplayName').value.trim() || $('#profileHeroName').textContent || 'Usuario';
    const roleArea = $('#profileRoleArea').value.trim() || $('#profileHeroRole').textContent || 'Usuario';
    const accent = normalizeHexColor($('#profileAccent').value);
    renderProfileForm({
        nombre_visible: displayName,
        cargo_area: roleArea,
        correo: $('#profileEmail').value,
        color_principal: accent,
        avatar_url: button.dataset.avatarUrl || '',
        background_url: $('#profileBackgroundUrl')?.value.trim() || '',
        background_opacity: normalizeBackgroundOpacity($('#profileBackgroundOpacity')?.value || 12),
    });
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

$('#passwordRequestsTable').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-approve-password-reset]');
    if (!button) {
        return;
    }

    if (!confirm('Aprobar reinicio de contrasena para este usuario?')) {
        return;
    }

    $('#passwordRequestsMessage').textContent = 'Aprobando solicitud...';
    await api('users.php?action=approve_password_reset', {
        method: 'POST',
        body: JSON.stringify({ id: Number(button.dataset.approvePasswordReset) }),
    });
    $('#passwordRequestsMessage').textContent = 'Reinicio aprobado.';
    await loadPasswordResetRequests();
    await loadNotifications();
});

$('#refreshUsers').addEventListener('click', () => {
    resetUsersSearch().catch(() => {});
});
$('#userSearch').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        resetUsersSearch().catch(() => {});
    }
});

$('#prevUsersPage').addEventListener('click', () => {
    if (state.usersPagination.page <= 1) {
        return;
    }
    state.usersPagination.page -= 1;
    refreshUsers().catch(() => {});
});

$('#nextUsersPage').addEventListener('click', () => {
    if (state.usersPagination.page >= state.usersPagination.pages) {
        return;
    }
    state.usersPagination.page += 1;
    refreshUsers().catch(() => {});
});

let personalSearchTimer = 0;
$('#personalSearch').addEventListener('input', () => {
    clearTimeout(personalSearchTimer);
    personalSearchTimer = setTimeout(async () => {
        const search = $('#personalSearch').value.trim();
        if (search.length < 2) {
            $('#personalResults').innerHTML = '';
            return;
        }

        try {
            const payload = await api(`users.php?action=personal&search=${encodeURIComponent(search)}`);
            const people = payload.data.personal || [];
            if (people.length === 0) {
                $('#personalResults').innerHTML = '<div class="empty-state">No se encontro personal con ese dato.</div>';
                return;
            }

            $('#personalResults').innerHTML = people.slice(0, 6).map((person, index) => `
                <button class="personal-card" type="button" data-person-index="${index}">
                    <strong>${escapeHtml(person.nombre_completo || `${person.nombres || ''}`)}</strong>
                    <small>${escapeHtml(person.dni || '')}</small>
                </button>
            `).join('');

            $$('.personal-card').forEach((card) => {
                card.addEventListener('click', () => fillPersonal(people[Number(card.dataset.personIndex)]));
            });
        } catch (error) {
            $('#personalResults').innerHTML = `<div class="empty-state error">${escapeHtml(error.message)}</div>`;
        }
    }, 220);
});

$('#clearSelectedPersonal').addEventListener('click', clearSelectedPersonal);

$('#createUserForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = $('#createMessage');
    setMessage(message, 'Creando usuario...');

    const groups = $$('input[name="grupo_ids"]:checked').map((input) => Number(input.value));
    const policies = $$('input[name="politica_ids"]:checked').map((input) => Number(input.value));
    const payload = {
        personal_id: $('#personalId').value,
        dni: $('#dni').value.trim(),
        nombres: $('#nombres').value.trim(),
        apellidos: $('#apellidos').value.trim(),
        email: $('#email').value.trim(),
        password: $('#password').value,
        must_change_password: $('#mustChangePassword').checked,
        grupo_ids: groups,
        politica_ids: policies,
    };

    try {
        await api('users.php?action=create', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        setMessage(message, 'Usuario creado correctamente.', 'ok');
        $('#createUserForm').reset();
        clearSelectedPersonal();
        state.usersPagination.page = 1;
        await loadInitialData();
    } catch (error) {
        setMessage(message, error.message, 'error');
    }
});

$('#usersTable').addEventListener('click', async (event) => {
    const editButton = event.target.closest('[data-edit-groups]');
    if (editButton) {
        openAccessPanel(Number(editButton.dataset.editGroups));
        return;
    }

    const retireButton = event.target.closest('[data-retire]');
    if (retireButton) {
        const id = Number(retireButton.dataset.retire);
        if (!id || !confirm('Retirar este usuario? Se liberara su correo y DNI para reutilizarlos.')) {
            return;
        }

        await api('users.php?action=retire', {
            method: 'POST',
            body: JSON.stringify({ id }),
        });
        await loadInitialData();
        return;
    }

    const deactivateButton = event.target.closest('[data-deactivate]');
    const activateButton = event.target.closest('[data-activate]');
    if (!deactivateButton && !activateButton) {
        return;
    }

    const isActivate = Boolean(activateButton);
    const id = Number((isActivate ? activateButton : deactivateButton).dataset[isActivate ? 'activate' : 'deactivate']);
    if (!id || !confirm(isActivate ? 'Activar este usuario?' : 'Desactivar este usuario?')) {
        return;
    }

    await api(`users.php?action=${isActivate ? 'activate' : 'deactivate'}`, {
        method: 'POST',
        body: JSON.stringify({ id }),
    });
    await refreshUsers();
    const summary = await api('users.php?action=summary');
    renderSummary(summary.data);
});

$('#groupsOverview').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-open-group]');
    if (!button) {
        return;
    }

    await openGroupPanel(Number(button.dataset.openGroup));
});

$('#closeGroupPanel').addEventListener('click', () => {
    state.activeGroup = null;
    $('#groupManagePanel').classList.add('hidden');
    $('#groupMembersTable').innerHTML = '';
    $('#groupUserResults').innerHTML = '';
    setMessage($('#groupMessage'), '');
});

$('#groupUserSearchButton').addEventListener('click', () => {
    searchUsersForGroup().catch((error) => setMessage($('#groupMessage'), error.message, 'error'));
});

let groupUserSearchTimer = 0;
$('#groupUserSearch').addEventListener('input', () => {
    clearTimeout(groupUserSearchTimer);
    groupUserSearchTimer = setTimeout(() => {
        searchUsersForGroup().catch((error) => setMessage($('#groupMessage'), error.message, 'error'));
    }, 220);
});

$('#groupUserSearch').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        searchUsersForGroup().catch((error) => setMessage($('#groupMessage'), error.message, 'error'));
    }
});

$('#groupUserResults').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-add-group-user]');
    if (!button || !state.activeGroup) {
        return;
    }

    setMessage($('#groupMessage'), 'Agregando usuario...');
    await api('users.php?action=group_add_user', {
        method: 'POST',
        body: JSON.stringify({
            group_id: Number(state.activeGroup.id),
            user_id: Number(button.dataset.addGroupUser),
        }),
    });
    setMessage($('#groupMessage'), 'Usuario agregado correctamente.', 'ok');
    $('#groupUserSearch').value = '';
    $('#groupUserResults').innerHTML = '';
    await loadGroupMembers();
    await loadGroupsOverview();
    await refreshUsers();
});

$('#groupMembersTable').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-remove-group-user]');
    if (!button || !state.activeGroup) {
        return;
    }

    if (!confirm('Quitar este usuario del grupo?')) {
        return;
    }

    await api('users.php?action=group_remove_user', {
        method: 'POST',
        body: JSON.stringify({
            group_id: Number(state.activeGroup.id),
            user_id: Number(button.dataset.removeGroupUser),
        }),
    });
    await loadGroupMembers();
    await loadGroupsOverview();
    await refreshUsers();
});

$('#closeAccessPanel').addEventListener('click', closeAccessPanel);

$('#saveUserGroups').addEventListener('click', async () => {
    if (!state.editingUser) {
        return;
    }

    const message = $('#accessMessage');
    setMessage(message, 'Guardando accesos...');
    const groups = $$('input[name="edit_grupo_ids"]:checked').map((input) => Number(input.value));
    const policies = $$('input[name="edit_politica_ids"]:checked').map((input) => Number(input.value));

    try {
        await api('users.php?action=groups', {
            method: 'POST',
            body: JSON.stringify({ id: Number(state.editingUser.id), grupo_ids: groups, politica_ids: policies }),
        });
        setMessage(message, 'Accesos actualizados correctamente.', 'ok');
        await refreshUsers();
        await loadGroupsOverview();
        const audit = await api('users.php?action=audit');
        renderAudit(audit.data.logs || []);
        closeAccessPanel();
    } catch (error) {
        setMessage(message, error.message, 'error');
    }
});

if (state.token) {
    showApp();
    loadInitialData().catch(() => {
        localStorage.removeItem('gs_neo_token');
        localStorage.removeItem('gs_neo_user');
        showLogin();
    });
} else {
    showLogin();
}
