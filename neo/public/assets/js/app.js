const API_BASE = window.NEO_API_BASE;
const TOKEN_KEY = 'neo_portal_token';
const USER_KEY = 'neo_portal_user';
const REFRESH_TOKEN_KEY = 'neo_portal_refresh_token';
let refreshPromise = null;
let sessionWarningTimer = 0;

const loginScreen = document.getElementById('loginScreen');
const appShell = document.getElementById('appShell');
const loginForm = document.getElementById('loginForm');
const loginMessage = document.getElementById('loginMessage');
const logoutButton = document.getElementById('logoutButton');
const logoutButtonProfile = document.getElementById('logoutButtonProfile');
const currentUser = document.getElementById('currentUser');
const profileName = document.getElementById('profileName');
const profileLogin = document.getElementById('profileLogin');
const userSubtitle = document.getElementById('userSubtitle');
const summaryCards = document.getElementById('summaryCards');
const systemsGrid = document.getElementById('systemsGrid');
const profilePanel = document.getElementById('profilePanel');
const profileForm = document.getElementById('profileForm');
const profileMessage = document.getElementById('profileMessage');
const presetAvatars = Array.isArray(window.NEO_PRESET_AVATARS) ? window.NEO_PRESET_AVATARS : [];
const presetBackgrounds = Array.isArray(window.NEO_PRESET_BACKGROUNDS) ? window.NEO_PRESET_BACKGROUNDS : [];

function token() {
    return localStorage.getItem(TOKEN_KEY);
}

function setSession(data) {
    localStorage.setItem(TOKEN_KEY, data.access_token);
    if (data.refresh_token) {
        localStorage.setItem(REFRESH_TOKEN_KEY, data.refresh_token);
    }
    localStorage.setItem(USER_KEY, JSON.stringify(data.user));
    scheduleSessionWarning();
}

function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(REFRESH_TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    hideSessionWarning();
}

function showApp() {
    loginScreen.classList.add('hidden');
    appShell.classList.remove('hidden');
    applyGlobalProfile(JSON.parse(localStorage.getItem(USER_KEY) || 'null'));
    refreshGlobalProfile().catch(() => {});
    scheduleSessionWarning();
}

function showLogin() {
    appShell.classList.add('hidden');
    loginScreen.classList.remove('hidden');
}

function showPortalView(view) {
    const isProfile = view === 'profile';
    document.querySelectorAll('[data-portal-view]').forEach((button) => button.classList.toggle('active', button.dataset.portalView === view));
    document.querySelector('.dashboard-hero')?.classList.toggle('hidden', isProfile);
    summaryCards.classList.toggle('hidden', isProfile);
    systemsGrid.classList.toggle('hidden', isProfile);
    profilePanel.classList.toggle('hidden', !isProfile);
    if (isProfile) {
        window.location.hash = 'perfil';
        loadProfile().catch((error) => {
            profileMessage.textContent = error.message;
        });
    }
}

async function api(path, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };

    if (token()) {
        headers.Authorization = `Bearer ${token()}`;
    }

    const response = await fetch(`${API_BASE}/${path}`, {
        ...options,
        headers,
    });

    const payload = await response.json().catch(() => ({ ok: false, message: 'Respuesta invalida.' }));
    if (response.status === 401 && !options.retryingAuth && await refreshSession()) {
        return api(path, { ...options, retryingAuth: true });
    }

    if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'No se pudo completar la operacion.');
    }

    return payload.data;
}

async function refreshSession() {
    const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY) || '';
    if (!refreshToken) return false;
    if (refreshPromise) return refreshPromise;

    refreshPromise = fetch(`${API_BASE}/refresh-token.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refreshToken }),
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || (!payload.ok && !payload.success) || !payload.data?.access_token) {
                return false;
            }

            localStorage.setItem(TOKEN_KEY, payload.data.access_token);
            localStorage.setItem(USER_KEY, JSON.stringify(payload.data.user));
            scheduleSessionWarning();
            return true;
        })
        .catch(() => false)
        .finally(() => {
            refreshPromise = null;
        });

    return refreshPromise;
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
    const payload = decodeJwtPayload(token() || '');
    return Number(payload?.exp || 0) - Math.floor(Date.now() / 1000);
}

function scheduleSessionWarning() {
    window.clearTimeout(sessionWarningTimer);
    hideSessionWarning();
    const secondsLeft = sessionSecondsLeft();
    if (secondsLeft <= 0) {
        return;
    }
    const warningInMs = Math.max(0, (secondsLeft - 300) * 1000);
    sessionWarningTimer = window.setTimeout(showSessionWarning, warningInMs);
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
    if (!token()) {
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
    const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY) || '';
    if (refreshToken) {
        fetch(`${API_BASE}/logout.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refresh_token: refreshToken }),
        }).catch(() => {});
    }
    clearSession();
    showLogin();
}

async function profileApi(path, options = {}) {
    return api(path, options);
}

function initials(user) {
    const source = user?.perfil?.nombre_visible || user?.nombre || `${user.nombres || ''} ${user.apellidos || ''}`.trim() || displayLogin(user) || 'NEO';
    return source.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
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

    const picker = document.getElementById('profileColorPicker');
    if (picker) {
        picker.style.setProperty('--picker-hue-color', hsvToHex(profileColorState.h, 100, 100));
        picker.style.setProperty('--picker-saturation', String(profileColorState.s));
        picker.style.setProperty('--picker-value', String(profileColorState.v));
    }
}

function setColorFromMapEvent(event) {
    const map = document.getElementById('profileColorMap');
    if (!map) return;

    const rect = map.getBoundingClientRect();
    const x = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
    const y = Math.max(0, Math.min(rect.height, event.clientY - rect.top));
    profileColorState.s = Math.round((x / rect.width) * 100);
    profileColorState.v = Math.round((1 - (y / rect.height)) * 100);
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
}

function bindColorPicker() {
    const map = document.getElementById('profileColorMap');
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
    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    document.getElementById('profileAccent').value = accent.slice(1);
    document.getElementById('profileAccentPreview').style.background = accent;
    document.getElementById('profileHue').value = String(hexToHue(accent));
    syncColorPicker(accent);
    document.getElementById('profileColorSummary').textContent = accent;
    document.getElementById('topbarProfileColor') && (document.getElementById('topbarProfileColor').textContent = accent);
    return accent;
}

function updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl) {
    const avatar = document.getElementById('topbarProfileAvatar');
    avatar.textContent = initials({ perfil: { nombre_visible: displayName || 'Usuario' } });
    avatar.style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    avatar.classList.toggle('with-image', Boolean(avatarUrl));
    document.getElementById('topbarProfileName').textContent = displayName || 'Usuario';
    document.getElementById('topbarProfileRole').textContent = roleArea || 'Usuario';
    document.getElementById('topbarProfileColor') && (document.getElementById('topbarProfileColor').textContent = normalizeHexColor(accent));
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

function applyProfileAvatar(value, displayName = 'Usuario') {
    const avatarUrl = String(value || '').trim();
    const preview = document.getElementById('profileAvatarPreview');
    const input = document.getElementById('profileAvatarUrl');
    input.value = avatarUrl;
    preview.textContent = initials({ perfil: { nombre_visible: displayName } });
    preview.style.backgroundImage = avatarUrl ? `url("${avatarUrl.replaceAll('"', '%22')}")` : '';
    preview.classList.toggle('with-image', Boolean(avatarUrl));
    renderAvatarPresets(avatarUrl);
}

function renderAvatarPresets(selectedUrl = '') {
    const grid = document.getElementById('profileAvatarPresets');
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

function normalizeOpacity(value) {
    const raw = Number(value);
    if (!Number.isFinite(raw)) return 0.12;
    return Math.max(0, Math.min(0.35, raw > 1 ? raw / 100 : raw));
}

function applyProfileBackground(backgroundUrl = '', opacity = 0.12) {
    const url = String(backgroundUrl || '').trim();
    const normalizedOpacity = normalizeOpacity(opacity);
    document.documentElement.style.setProperty('--profile-bg-image', url ? `url("${url.replaceAll('"', '%22')}")` : 'none');
    document.documentElement.style.setProperty('--profile-bg-opacity', String(normalizedOpacity));

    const urlInput = document.getElementById('profileBackgroundUrl');
    const opacityInput = document.getElementById('profileBackgroundOpacity');
    const opacityValue = document.getElementById('profileBackgroundOpacityValue');
    if (urlInput) urlInput.value = url;
    if (opacityInput) opacityInput.value = String(Math.round(normalizedOpacity * 100));
    if (opacityValue) opacityValue.textContent = `${Math.round(normalizedOpacity * 100)}%`;
    renderBackgroundPresets(url);
}

function renderBackgroundPresets(selectedUrl = '') {
    const grid = document.getElementById('profileBackgroundPresets');
    if (!grid) return;

    const selected = normalizeAvatarForCompare(selectedUrl);
    grid.innerHTML = [
        '<button class="background-preset-button clear" type="button" data-background-url="" aria-label="Quitar fondo">Sin fondo</button>',
        ...presetBackgrounds.map((url, index) => {
            const active = normalizeAvatarForCompare(url) === selected ? ' active' : '';
            return `
                <button class="background-preset-button${active}" type="button" data-background-url="${url}" aria-label="Seleccionar fondo ${index + 1}">
                    <span style="background-image:url('${url}')"></span>
                </button>
            `;
        }),
    ].join('');
}

async function refreshGlobalProfile() {
    const data = await profileApi('profile.php?action=ver');
    updateStoredUserProfile(data.perfil || {});
}

function renderUser(user) {
    applyGlobalProfile(user);
    userSubtitle.textContent = `${user?.perfil?.nombre_visible || user?.nombre || user?.nombres || displayLogin(user)} / ${user?.perfil?.cargo_area || 'Usuario'}`;
}

function updateStoredUserProfile(profile) {
    const user = JSON.parse(localStorage.getItem(USER_KEY) || 'null') || {};
    user.perfil = {
        ...(user.perfil || {}),
        nombre_visible: profile.nombre_visible || '',
        cargo_area: profile.cargo_area || '',
        color_principal: profile.color_principal || '#7c3aed',
        avatar_url: profile.avatar_url || '',
        background_url: profile.background_url || '',
        background_opacity: normalizeOpacity(profile.background_opacity),
    };
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    renderUser(user);
}

function applyGlobalProfile(user) {
    const profile = user?.perfil || {};
    const accent = normalizeHexColor(profile.color_principal);
    const avatarUrl = String(profile.avatar_url || '').trim();
    const backgroundUrl = String(profile.background_url || '').trim();
    const backgroundOpacity = normalizeOpacity(profile.background_opacity);
    const displayName = profile.nombre_visible || user?.nombre || `${user?.nombres || ''} ${user?.apellidos || ''}`.trim() || displayLogin(user) || 'Usuario';
    currentUser.textContent = '⋮';
    currentUser.style.backgroundImage = '';
    currentUser.classList.remove('with-image');
    profileName.textContent = displayName;
    profileLogin.textContent = displayLogin(user) || profile.cargo_area || 'Usuario';
    document.documentElement.style.setProperty('--accent', accent);
    document.documentElement.style.setProperty('--personal-accent', accent);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    updateTopbarProfileSummary(displayName, profile.cargo_area || 'Usuario', accent, avatarUrl);
}

function renderStats(stats, systems = [], user = {}) {
    const adminSystems = systems.filter((system) => Number(system.puede_admin || 0) === 1).length;
    const role = user?.perfil?.cargo_area || 'Usuario';
    const items = [
        ['Sistemas asignados', stats.sistemas_asignados ?? systems.length, 'Con acceso', 'blue'],
        ['Administracion', stats.sistemas_admin ?? adminSystems, 'Sistemas administrables', 'teal'],
        ['Perfil', role, 'Perfil activo', 'violet'],
    ];

    summaryCards.innerHTML = items.map(([label, value, detail, tone], index) => `
        <article class="metric-card ${tone}" style="animation-delay:${index * 70}ms">
            <small>${label}</small>
            <strong>${value}</strong>
            <small>${detail}</small>
        </article>
    `).join('');
}

function renderSystems(systems) {
    if (!systems.length) {
        systemsGrid.innerHTML = '<div class="empty-state">No tienes sistemas asignados. Solicita permisos al administrador.</div>';
        return;
    }

    systemsGrid.innerHTML = systems.map((system, index) => `
        <article class="system-card ${systemThemeClass(system)}" style="animation-delay:${index * 70}ms">
            <div class="system-icon">${String(system.codigo || 'NE').slice(0, 2).toUpperCase()}</div>
            <div>
                <h3>${system.nombre || 'Sistema'}</h3>
                <p>${systemDescription(system)}</p>
            </div>
            <div class="system-footer">
                <span>${system.puede_admin ? 'Admin' : 'Usuario'}</span>
                <a href="${systemAccessUrl(system.url_base || '#')}">Abrir</a>
            </div>
        </article>
    `).join('');
}

function systemDescription(system) {
    const code = String(system?.codigo || '').trim().toLowerCase();
    const description = String(system?.descripcion || '').trim();

    if (code === 'gs') {
        return 'Administracion central de usuarios, grupos, politicas y accesos.';
    }

    return description || 'Sistema institucional NEO.';
}

function systemThemeClass(system) {
    const visualClass = String(system?.clase_visual || system?.visual_class || '').trim().toLowerCase();
    const classThemes = {
        core: 'theme-gs',
        atencion: 'theme-tickets',
        operaciones: 'theme-inventario',
        soporte: 'theme-soporte',
        documentos: 'theme-firmas',
    };
    if (classThemes[visualClass]) {
        return classThemes[visualClass];
    }

    const code = String(system?.codigo || '').trim().toLowerCase();
    const name = String(system?.nombre || '').trim().toLowerCase();
    const url = String(system?.url_base || '').trim().toLowerCase();
    const source = `${code} ${name} ${url}`;

    if (['gs', 'usuarios', 'accesos'].includes(code)) return 'theme-gs';
    if (['ti', 'tickets', 'ticket'].includes(code)) return 'theme-tickets';
    if (['so', 'soporte'].includes(code)) return 'theme-soporte';
    if (['in', 'inventario'].includes(code)) return 'theme-inventario';
    if (['fi', 'firmas', 'firma'].includes(code)) return 'theme-firmas';
    if (source.includes('soporte')) return 'theme-soporte';
    if (source.includes('inventario')) return 'theme-inventario';
    if (source.includes('firma')) return 'theme-firmas';
    if (source.includes('ticket')) return 'theme-tickets';
    if (source.includes('usuario') || source.includes('acceso')) return 'theme-gs';
    return 'theme-neo';
}

function systemAccessUrl(url) {
    if (!url || url === '#') {
        return '#';
    }

    try {
        const target = new URL(url, window.location.href);
        if (token()) {
            target.searchParams.set('sso_token', token() || '');
        }
        return target.toString();
    } catch {
        if (!token()) {
            return url;
        }
        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}sso_token=${encodeURIComponent(token() || '')}`;
    }
}

async function loadPortal() {
    const data = await api('portal.php?action=dashboard');
    renderUser(data.user);
    renderStats(data.stats || {}, data.systems || [], data.user || {});
    renderSystems(data.systems);
    showApp();
}

async function loadProfile() {
    profileMessage.textContent = 'Cargando perfil...';
    const data = await profileApi('profile.php?action=ver');
    renderProfileForm(data.perfil || {});
    renderPasswordResetStatus(data.password_reset || {});
    profileMessage.textContent = '';
}

function renderProfileForm(profile) {
    const displayName = profile.nombre_visible || 'Usuario';
    const roleArea = profile.cargo_area || 'Usuario';
    const accent = normalizeHexColor(profile.color_principal);
    const avatarUrl = String(profile.avatar_url || '').trim();
    const backgroundUrl = String(profile.background_url || '').trim();
    const backgroundOpacity = normalizeOpacity(profile.background_opacity);
    document.getElementById('profileDisplayName').value = profile.nombre_visible || '';
    document.getElementById('profileRoleArea').value = profile.cargo_area || '';
    document.getElementById('profileEmail').value = profile.correo || displayLogin(JSON.parse(localStorage.getItem(USER_KEY) || 'null')) || '';
    applyProfileAccent(accent);
    document.getElementById('profileHeroName').textContent = displayName;
    document.getElementById('profileHeroRole').textContent = roleArea;
    applyProfileAvatar(avatarUrl, displayName);
    applyProfileBackground(backgroundUrl, backgroundOpacity);
    updateTopbarProfileSummary(displayName, roleArea, accent, avatarUrl);
}

function renderPasswordResetStatus(status = {}) {
    document.getElementById('passwordResetPanel').classList.toggle('hidden', !status.habilitado);
}

async function saveProfile(event) {
    event.preventDefault();
    profileMessage.textContent = 'Guardando perfil...';
    const data = await profileApi('profile.php?action=guardar', {
        method: 'POST',
        body: JSON.stringify({
            nombre_visible: document.getElementById('profileDisplayName').value.trim(),
            color_principal: normalizeHexColor(document.getElementById('profileAccent').value),
            avatar_url: document.getElementById('profileAvatarUrl').value.trim(),
            background_url: document.getElementById('profileBackgroundUrl')?.value.trim() || '',
            background_opacity: normalizeOpacity(document.getElementById('profileBackgroundOpacity')?.value || 12),
        }),
    });
    renderProfileForm(data.perfil || {});
    renderPasswordResetStatus(data.password_reset || {});
    updateStoredUserProfile(data.perfil || {});
    profileMessage.textContent = 'Perfil guardado.';
}

async function requestPasswordReset() {
    profileMessage.textContent = 'Enviando solicitud...';
    await profileApi('profile.php?action=solicitar_reinicio_password', { method: 'POST' });
    profileMessage.textContent = 'Solicitud enviada. GS NEO debe aprobar el reinicio.';
}

async function changeProfilePassword() {
    const password = document.getElementById('newProfilePassword').value;
    const confirm = document.getElementById('confirmProfilePassword').value;
    const message = document.getElementById('changeProfilePasswordMessage');
    message.textContent = 'Actualizando contrasena...';
    await profileApi('profile.php?action=cambiar_password', {
        method: 'POST',
        body: JSON.stringify({ password, confirm_password: confirm }),
    });
    document.getElementById('newProfilePassword').value = '';
    document.getElementById('confirmProfilePassword').value = '';
    document.getElementById('passwordResetPanel').classList.add('hidden');
    message.textContent = 'Contrasena actualizada.';
}

function togglePasswordInput(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    loginMessage.textContent = '';
    const submit = loginForm.querySelector('button[type="submit"]');
    submit.disabled = true;

    try {
        const data = await api('login.php', {
            method: 'POST',
            body: JSON.stringify({
                login: document.getElementById('loginEmail').value.trim(),
                password: document.getElementById('loginPassword').value,
            }),
        });
        setSession(data);
        await loadPortal();
    } catch (error) {
        loginMessage.textContent = error.message;
    } finally {
        submit.disabled = false;
    }
});

logoutButton.addEventListener('click', () => {
    logout();
});

logoutButtonProfile.addEventListener('click', () => {
    logout();
});

document.querySelectorAll('[data-portal-view]').forEach((button) => {
    button.addEventListener('click', () => showPortalView(button.dataset.portalView || 'systems'));
});

document.getElementById('openProfileLink').addEventListener('click', (event) => {
    event.preventDefault();
    showPortalView('profile');
});

profileForm.addEventListener('submit', (event) => {
    saveProfile(event).catch((error) => {
        profileMessage.textContent = error.message;
    });
});

document.getElementById('requestPasswordResetButton').addEventListener('click', () => {
    requestPasswordReset().catch((error) => {
        profileMessage.textContent = error.message;
    });
});

document.getElementById('changeProfilePasswordButton').addEventListener('click', () => {
    changeProfilePassword().catch((error) => {
        document.getElementById('changeProfilePasswordMessage').textContent = error.message;
    });
});

document.querySelectorAll('[data-toggle-password]').forEach((button) => {
    button.addEventListener('click', () => togglePasswordInput(button.dataset.togglePassword));
});

document.getElementById('profileAccent')?.addEventListener('input', (event) => {
    const raw = event.target.value;
    const withHash = raw.startsWith('#') ? raw : `#${raw}`;
    if (/^#[0-9a-f]{6}$/i.test(withHash)) {
        applyProfileAccent(withHash);
    }
});

document.getElementById('profileAvatarPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-avatar-url]');
    if (!button) return;
    const displayName = document.getElementById('profileDisplayName').value.trim()
        || document.getElementById('profileHeroName').textContent
        || 'Usuario';
    applyProfileAvatar(button.dataset.avatarUrl || '', displayName);
    updateTopbarProfileSummary(
        displayName,
        document.getElementById('profileHeroRole').textContent || 'Usuario',
        normalizeHexColor(document.getElementById('profileAccent').value),
        button.dataset.avatarUrl || ''
    );
});

document.getElementById('profileAvatarUrl')?.addEventListener('input', (event) => {
    const displayName = document.getElementById('profileDisplayName').value.trim()
        || document.getElementById('profileHeroName').textContent
        || 'Usuario';
    applyProfileAvatar(event.target.value, displayName);
});

document.getElementById('profileBackgroundPresets')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-background-url]');
    if (!button) return;
    applyProfileBackground(
        button.dataset.backgroundUrl || '',
        document.getElementById('profileBackgroundOpacity')?.value || 12
    );
});

document.getElementById('profileBackgroundUrl')?.addEventListener('input', (event) => {
    applyProfileBackground(event.target.value, document.getElementById('profileBackgroundOpacity')?.value || 12);
});

document.getElementById('profileBackgroundOpacity')?.addEventListener('input', (event) => {
    applyProfileBackground(document.getElementById('profileBackgroundUrl')?.value || '', event.target.value);
});

document.getElementById('profileHue')?.addEventListener('input', (event) => {
    profileColorState.h = Number(event.target.value) || 0;
    applyProfileAccent(hsvToHex(profileColorState.h, profileColorState.s, profileColorState.v));
});

bindColorPicker();

if (token()) {
    loadPortal().catch(() => {
        clearSession();
        showLogin();
    });
} else {
    showLogin();
}
