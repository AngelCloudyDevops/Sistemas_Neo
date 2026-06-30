<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/bootstrap.php';
$baseUrl = rtrim((string) $config['app']['base_url'], '/');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) $config['app']['name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/app.css?v=20260630-neo-layout">
    <link rel="stylesheet" href="/neo_shared/assets/css/neo-standard.css?v=20260630-profile-grid-contract">
</head>
<body data-visual-class="core">
    <section class="login-screen" id="loginScreen">
        <div class="login-brand-panel" aria-hidden="true">
            <span class="brand-shine"></span>
            <div class="brand-emblem">NEO</div>
            <h2>Portal central</h2>
            <p>Un solo ingreso para los sistemas institucionales compartidos.</p>
            <div class="feature-list">
                <span>Directorio institucional</span>
                <span>Permisos por grupos</span>
                <span>Accesos auditados</span>
            </div>
            <div class="login-stats">
                <strong>GS</strong>
                <strong>TI</strong>
                <strong>SF</strong>
            </div>
        </div>

        <form class="login-card" id="loginForm" autocomplete="on">
            <div class="brand-row">
                <span class="brand-dot"></span>
                <strong>Portal NEO</strong>
            </div>
            <h1>Acceso al ecosistema</h1>
            <label>
                Correo o DNI
                <input id="loginEmail" type="text" inputmode="email" autocomplete="username" required autofocus>
            </label>
            <label>
                Contrasena
                <input id="loginPassword" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit">Ingresar</button>
            <p class="form-message" id="loginMessage"></p>
        </form>
    </section>

    <section class="app-shell hidden" id="appShell">
        <aside class="sidebar">
            <div class="app-mark">
                <span class="brand-dot"></span>
                <div>
                    <strong>Portal NEO</strong>
                    <small>Sistemas</small>
                </div>
            </div>
            <nav>
                <button class="nav-item active" type="button" data-portal-view="systems" data-icon="S">Sistemas</button>
                <button class="nav-item" type="button" data-portal-view="profile" data-icon="U">Mi Perfil</button>
            </nav>
            <button class="logout" id="logoutButton">Cerrar sesion</button>
        </aside>

        <main class="main-panel">
            <header class="topbar">
                <div>
                    <h1>Portal de sistemas</h1>
                    <p id="userSubtitle">Accesos disponibles segun permisos.</p>
                </div>
                <section class="topbar-profile-summary" aria-label="Perfil activo">
                    <span class="topbar-profile-avatar" id="topbarProfileAvatar">U</span>
                    <div>
                        <span>Perfil activo</span>
                        <strong id="topbarProfileName">Usuario</strong>
                        <small id="topbarProfileRole">Usuario</small>
                    </div>
                </section>
                <div class="topbar-actions">
                    <div class="topbar-search">
                        <span>Q</span>
                        <input type="search" placeholder="Buscar sistemas, accesos...">
                    </div>
                    <button type="button" class="topbar-icon" aria-label="Identificador">ID</button>
                    <button type="button" class="topbar-icon notification-button" aria-label="Notificaciones">!</button>
                    <div class="profile-menu">
                        <button type="button" class="user-chip profile-button account-menu-button" id="currentUser" aria-label="Menu de usuario">â‹®</button>
                        <div class="profile-dropdown">
                            <strong id="profileName">Usuario</strong>
                            <span id="profileLogin">Usuario</span>
                            <a href="#perfil" id="openProfileLink">Mi Perfil</a>
                            <button type="button" id="logoutButtonProfile">Cerrar sesion</button>
                        </div>
                    </div>
                </div>
            </header>

            <section class="dashboard-hero">
                <div>
                    <span class="hero-kicker">Acceso centralizado</span>
                    <h2>Elige el sistema al que necesitas ingresar</h2>
                    <p>Solo veras sistemas activos y permitidos por tus grupos o permisos directos.</p>
                </div>
                <div class="hero-badge">
                    <span>NEO</span>
                    <strong>Accesos</strong>
                </div>
            </section>

            <section class="metrics-grid" id="summaryCards"></section>
            <section class="systems-grid" id="systemsGrid"></section>
            <section class="profile-panel hidden" id="profilePanel">
                <div class="panel-head">
                    <h2>Mi Perfil</h2>
                    <p>Parametros generales compartidos en todos tus sistemas.</p>
                </div>
                <section class="profile-hero">
                    <div class="profile-identity">
                        <div class="profile-avatar" id="profileAvatarPreview">U</div>
                        <div>
                            <span>Perfil activo</span>
                            <strong id="profileHeroName">Usuario</strong>
                            <small id="profileHeroRole">Usuario</small>
                        </div>
                    </div>
                    <div class="profile-goal-summary">
                        <span>Color principal</span>
                        <strong id="profileColorSummary">#7c3aed</strong>
                        <small>Preferencia visual de tu perfil.</small>
                    </div>
                </section>
                <form class="profile-form" id="profileForm">
                    <label class="profile-display-field">Nombre visible<input id="profileDisplayName" type="text" maxlength="160"></label>
                    <label class="profile-role-field">Cargo o area<input id="profileRoleArea" type="text" maxlength="160" disabled></label>
                    <label class="profile-email-field">Correo<input id="profileEmail" type="text" disabled></label>
                    <div class="color-field">
                        <label for="profileAccent">Color principal</label>
                        <div class="color-picker" id="profileColorPicker">
                            <div class="color-map" id="profileColorMap" role="slider" aria-label="Seleccionar saturacion y brillo" tabindex="0">
                                <span class="color-map-cursor" id="profileColorCursor"></span>
                            </div>
                            <input class="color-slider" id="profileHue" type="range" min="0" max="360" value="262" aria-label="Deslizar color principal">
                            <div class="color-hex-row">
                                <span class="color-preview" id="profileAccentPreview"></span>
                                <span class="color-hash">#</span>
                                <input id="profileAccent" type="text" maxlength="6" value="7c3aed" inputmode="text" aria-label="Color hexadecimal">
                                <span class="color-format">Hex</span>
                            </div>
                        </div>
                    </div>
                    <small class="profile-note">El color principal se usa como preferencia visual de tu perfil.</small>
                    <div class="avatar-preset-field">
                        <span>Avatar predefinido</span>
                        <div class="avatar-preset-grid" id="profileAvatarPresets" aria-label="Seleccionar avatar predefinido"></div>
                    </div>
                    <label class="full profile-avatar-field">Foto avatar URL<input id="profileAvatarUrl" type="url" placeholder="https://..."></label>
                    <div class="background-preset-field">
                        <span>Fondo visual</span>
                        <div class="background-preset-grid" id="profileBackgroundPresets" aria-label="Seleccionar fondo predefinido"></div>
                    </div>
                    <label class="full profile-background-field">Fondo URL<input id="profileBackgroundUrl" type="url" placeholder="https://..."></label>
                    <label class="full profile-opacity-field">Opacidad del fondo
                        <span class="opacity-control">
                            <input id="profileBackgroundOpacity" type="range" min="0" max="35" step="1" value="12">
                            <strong id="profileBackgroundOpacityValue">12%</strong>
                        </span>
                    </label>
                    <button type="submit">Guardar perfil</button>
                    <button type="button" class="secondary-profile-button" id="requestPasswordResetButton">Solicitar reinicio de contrasena</button>
                    <p class="form-message" id="profileMessage"></p>
                </form>
                <div class="password-reset-panel hidden" id="passwordResetPanel">
                    <h3>Cambio de contrasena aprobado</h3>
                    <p>Ingresa y confirma tu nueva contrasena.</p>
                    <label>Nueva contrasena
                        <span class="password-field"><input id="newProfilePassword" type="password" minlength="8" autocomplete="new-password"><button type="button" data-toggle-password="newProfilePassword">Ver</button></span>
                    </label>
                    <label>Confirmar contrasena
                        <span class="password-field"><input id="confirmProfilePassword" type="password" minlength="8" autocomplete="new-password"><button type="button" data-toggle-password="confirmProfilePassword">Ver</button></span>
                    </label>
                    <button type="button" id="changeProfilePasswordButton">Cambiar contrasena</button>
                    <p class="form-message" id="changeProfilePasswordMessage"></p>
                </div>
            </section>
        </main>
    </section>

    <script>
        window.NEO_API_BASE = '<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/../api/v1';
        window.NEO_PUBLIC_BASE = '<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>';
        window.NEO_PRESET_AVATARS = [
            'avatar_01.jpg',
            'avatar_02.jpg',
            'avatar_03.jpg',
            'avatar_04.webp',
            'avatar_05.jpg',
            'avatar_06.webp'
        ].map((name) => `${window.NEO_PUBLIC_BASE}/assets/img/avatars/${name}`);
        window.NEO_PRESET_BACKGROUNDS = [
            'bg_01.svg',
            'bg_02.svg',
            'bg_03.svg',
            'bg_04.svg'
        ].map((name) => `${window.NEO_PUBLIC_BASE}/assets/img/backgrounds/${name}`);
    </script>
    <script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/app.js?v=20260630-neo-layout"></script>
</body>
</html>

